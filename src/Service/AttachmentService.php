<?php
/**
 * Mail Blast service.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Mailblast\Service;

use GlpiPlugin\Mailblast\Service\ConfigurationService;

final class AttachmentService
{
    public function getAllowedDocumentTypes(): array
        {
            global $DB;
    
            $iterator = $DB->request([
                'SELECT' => ['name', 'ext', 'mime'],
                'FROM'   => 'glpi_documenttypes',
                'WHERE'  => ['is_uploadable' => 1],
                'ORDER'  => ['name ASC'],
            ]);
    
            $mimes = [];
            $exts  = [];
            $types = [];
    
            foreach ($iterator as $row) {
                $mime = trim((string) ($row['mime'] ?? ''));
                $ext  = strtolower(trim((string) ($row['ext'] ?? '')));
    
                if ($mime !== '' && !in_array($mime, $mimes, true)) {
                    $mimes[] = $mime;
                }
                if ($ext !== '' && !in_array('.' . $ext, $exts, true)) {
                    $exts[] = '.' . $ext;
                }
    
                $types[] = ['name' => (string) ($row['name'] ?? ''), 'ext' => $ext, 'mime' => $mime];
            }
    
            return [
                'mimes'  => $mimes,
                'accept' => implode(',', array_unique(array_merge($mimes, $exts))),
                'types'  => $types,
            ];
        }

    /**
     * Validate legacy multipart uploads using the same document type rules as AJAX uploads.
     * The allowedMimes argument is retained for backward compatibility; GLPI document types
     * are loaded directly so MIME remains optional when GLPI leaves that field blank.
     *
     * @param array<int, string> $allowedMimes Legacy MIME allowlist argument.
     */
    public function validateUploadedFiles(array $files, array $allowedMimes): array
    {
        $accepted = [];
        $rejected = [];
        $maxBytes = (new ConfigurationService())->getMaxAttachmentMb() * 1024 * 1024;
        $totalBytes = 0;

        $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
        for ($i = 0; $i < $count; $i++) {
            $name = (string) ($files['name'][$i] ?? 'attachment');
            $errCode = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($errCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($errCode !== UPLOAD_ERR_OK) {
                $rejected[] = $name . ': ' . __('Upload error', 'mailblast');
                continue;
            }

            $tmpPath = (string) ($files['tmp_name'][$i] ?? '');
            if ($tmpPath === '' || (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) || !is_readable($tmpPath)) {
                $rejected[] = $name . ': ' . __('Upload error', 'mailblast');
                continue;
            }

            $fileSize = (int) ($files['size'][$i] ?? filesize($tmpPath));
            $totalBytes += max(0, $fileSize);
            if ($totalBytes > $maxBytes) {
                $rejected[] = $name . ': ' . __('Attachment size limit exceeded', 'mailblast');
                continue;
            }

            $bytes = file_get_contents($tmpPath);
            if ($bytes === false || $bytes === '') {
                $rejected[] = $name . ': ' . __('File is empty or unreadable', 'mailblast');
                continue;
            }

            $validation = $this->validateBase64Attachments([[
                'name' => $name,
                'data' => base64_encode($bytes),
            ]]);
            if (!$validation['ok']) {
                $rejected[] = $name . ': ' . implode(' ', $validation['errors']);
                continue;
            }

            $realMime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream';
            $accepted[] = ['tmp' => $tmpPath, 'name' => $name, 'mime' => $realMime];
        }

        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    /**
     * Converts GLPI document-backed and data-URI images into inline CID data.
     * Source GLPI documents are never deleted by this method.
     */
    public function extractInlineImages(string $html): array
    {
            $pattern = "/(<img[^>]+src=[\"'])([^\"']*?docid=(\\d+)[^\"']*?)([\"'][^>]*>)/i";
            $images  = [];
    
            $html = (string) preg_replace_callback(
                $pattern,
                function (array $m) use (&$images) {
                    $docId  = (int) $m[3];
                    $result = $this->docIdToBytes($docId);
    
                    if ($result === null) {
                        return $m[0];
                    }
    
    
                    $cid          = 'mailblast_img_' . $docId;
                    $images[$cid] = ['cid' => $cid, 'data' => base64_encode($result['bytes']), 'mime' => $result['mime']];
    
                    return $m[1] . 'cid:' . $cid . $m[4];
                },
                $html
            );
    
            // Second pass: convert pasted data URI images (data:image/...;base64,...) to CID parts.
            // Gmail strips data: URIs; Outlook mobile clips large HTML bodies containing them.
            $dataPattern = '/(<img[^>]+src=["\'])(data:([a-zA-Z]+\/[a-zA-Z0-9+.\-]+);base64,([^"\']+))("[^>]*>|\'[^>]*>)/i';
            $counter     = 0;
            $html = (string) preg_replace_callback(
                $dataPattern,
                function (array $m) use (&$images, &$counter) {
                    $mime   = $m[3];
                    $bytes  = base64_decode($m[4], true);
                    if ($bytes === false || $bytes === '') {
                        return $m[0];
                    }
                    $cid          = 'mailblast_data_img_' . $counter++;
                    $images[$cid] = ['cid' => $cid, 'data' => base64_encode($bytes), 'mime' => $mime];
                    return $m[1] . 'cid:' . $cid . $m[5];
                },
                $html
            );
    
            return ['html' => $html, 'inlineImages' => array_values($images)];
        }

    private function docIdToBytes(int $docId): ?array
        {
            if ($docId <= 0) {
                return null;
            }

            // Reuse GLPI's document authorization before touching the file on disk.
            // Mail Blast itself is protected by config/UPDATE, but that right must
            // never become an implicit bypass of the user's Document READ/entity rights.
            $document = new \Document();
            if (!$document->getFromDB($docId)) {
                return null;
            }

            if ((int) ($document->fields['is_deleted'] ?? 0) !== 0) {
                return null;
            }

            if (!$document->can($docId, READ) || !$document->canViewItem()) {
                return null;
            }

            $row = $document->fields;
            $rawPath = GLPI_DOC_DIR . '/' . (string) ($row['filepath'] ?? '');
            $fullPath = realpath($rawPath);
            $docBase = realpath(GLPI_DOC_DIR);
    
            if ($fullPath === false || $docBase === false
                || !str_starts_with($fullPath, $docBase . DIRECTORY_SEPARATOR)) {
                return null;
            }
    
            if (!file_exists($fullPath) || !is_readable($fullPath)) {
                return null;
            }
    
            $bytes = file_get_contents($fullPath);
            if ($bytes === false) {
                return null;
            }
    
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)
                  ?: ((string) ($row['mime'] ?? 'application/octet-stream'));
    
            return ['bytes' => $bytes, 'mime' => $mime];
        }

    /**
     * Validate browser-provided base64 attachments against GLPI's allowed document MIME types.
     * The browser's accept attribute is only a UX hint; this validation is authoritative.
     *
     * @return array{ok:bool, errors:array<int,string>}
     */
    public function validateBase64Attachments(array $attachments): array
    {
        $allowedTypes = $this->getAllowedDocumentTypes();
        $allowedRules = [];
        foreach (($allowedTypes['types'] ?? []) as $type) {
            if (!is_array($type)) {
                continue;
            }

            $extensionRule = trim((string) ($type['ext'] ?? ''));
            if ($extensionRule === '') {
                continue;
            }

            $mimeValue = strtolower(trim((string) ($type['mime'] ?? '')));
            $mimes = $mimeValue === ''
                ? []
                : array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $mimeValue) ?: [])));

            $allowedRules[] = [
                'extension' => $extensionRule,
                'mimes' => $mimes,
            ];
        }

        $maxBytes = (new ConfigurationService())->getMaxAttachmentMb() * 1024 * 1024;
        $totalBytes = 0;
        $errors = [];

        foreach ($attachments as $index => $attachment) {
            if (!is_array($attachment)) {
                $errors[] = sprintf(__('Attachment %d is invalid.', 'mailblast'), $index + 1);
                continue;
            }

            $data = (string) ($attachment['data'] ?? '');
            $name = basename(trim((string) ($attachment['name'] ?? 'attachment')));
            if ($data === '') {
                $errors[] = sprintf(__('Attachment %s is empty or invalid.', 'mailblast'), $name ?: ($index + 1));
                continue;
            }

            $bytes = base64_decode($data, true);
            if ($bytes === false || $bytes === '') {
                $errors[] = sprintf(__('Attachment %s contains invalid data.', 'mailblast'), $name ?: ($index + 1));
                continue;
            }

            $realMime = strtolower(trim((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream'));
            $matchingRules = [];
            foreach ($allowedRules as $rule) {
                if ($this->matchesDocumentTypeExtension($name, $rule['extension'])) {
                    $matchingRules[] = $rule;
                }
            }

            if ($matchingRules === []) {
                $errors[] = sprintf(__('File extension not allowed by GLPI: %s.', 'mailblast'), $name ?: ($index + 1));
                continue;
            }

            // GLPI's MIME field is optional. When it is configured, enforce it
            // against the actual bytes; when it is blank, GLPI authorizes by the
            // configured extension rule, which may also be a regular expression.
            $mimeIsUnrestricted = false;
            $mimeMatches = false;
            foreach ($matchingRules as $rule) {
                if ($rule['mimes'] === []) {
                    $mimeIsUnrestricted = true;
                    continue;
                }
                if (in_array($realMime, $rule['mimes'], true)) {
                    $mimeMatches = true;
                }
            }

            if (!$mimeIsUnrestricted && !$mimeMatches) {
                $errors[] = sprintf(__('File MIME type not allowed by GLPI: %s (%s).', 'mailblast'), $name ?: ($index + 1), $realMime);
                continue;
            }

            $totalBytes += strlen($bytes);
            if ($totalBytes > $maxBytes) {
                $errors[] = __('Combined attachment size limit exceeded.', 'mailblast');
                break;
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Match a filename against a GLPI document type extension rule.
     * GLPI supports both plain extensions and slash-delimited regular expressions.
     */
    private function matchesDocumentTypeExtension(string $filename, string $extensionRule): bool
    {
        $filename = basename($filename);
        $extensionRule = trim($extensionRule);
        if ($filename === '' || $extensionRule === '') {
            return false;
        }

        if (preg_match('/^\/.+\/[a-z]*$/i', $extensionRule) === 1) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            if ($extension === '') {
                return false;
            }
            $lastDelimiter = strrpos($extensionRule, '/');
            $flags = substr($extensionRule, $lastDelimiter + 1);
            if (!str_contains($flags, 'i')) {
                $extensionRule .= 'i';
            }
            return @preg_match($extensionRule, $extension) === 1;
        }

        $extension = strtolower(ltrim($extensionRule, '.'));
        if ($extension === '') {
            return false;
        }

        return str_ends_with(strtolower($filename), '.' . $extension);
    }

    /**
     * Validate browser-provided inline images using their actual decoded MIME type.
     * Only image content is accepted.
     *
     * @return array{ok:bool, errors:array<int,string>}
     */
    public function validateInlineImages(array $images): array
    {
        $errors = [];
        foreach ($images as $index => $image) {
            if (!is_array($image)) {
                $errors[] = sprintf(__('Inline image %d is invalid.', 'mailblast'), $index + 1);
                continue;
            }
            $bytes = base64_decode((string) ($image['data'] ?? ''), true);
            if ($bytes === false || $bytes === '') {
                $errors[] = sprintf(__('Inline image %d contains invalid data.', 'mailblast'), $index + 1);
                continue;
            }
            $realMime = strtolower((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream');
            // SVG is active XML and may execute script in some mail clients/viewers.
            if (!in_array($realMime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'], true)) {
                $errors[] = sprintf(__('Inline image %d is not a supported safe raster image.', 'mailblast'), $index + 1);
            }
        }
        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Decode browser-held base64 attachments into request-local temporary files.
     * The files are never persisted by the plugin.
     *
     * @return array<array{tmp:string,name:string,mime:string}>
     */
    public function decodeTemporaryAttachments(array $attachments, string $prefix = 'mb_att_'): array
    {
        $result = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $bytes = base64_decode((string) ($attachment['data'] ?? ''), true);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $tmp = @tempnam(sys_get_temp_dir(), $prefix);
            if ($tmp === false || @file_put_contents($tmp, $bytes) === false) {
                continue;
            }
            $name = basename(trim((string) ($attachment['name'] ?? 'attachment')));
            if ($name === '' || $name === '.') {
                $name = 'attachment';
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
            $result[] = ['tmp' => $tmp, 'name' => $name, 'mime' => $mime];
        }
        return $result;
    }

    /** Remove only request-local temporary files created by this service. */
    public function cleanupTemporaryAttachments(array $attachments): void
    {
        foreach ($attachments as $attachment) {
            $tmp = (string) ($attachment['tmp'] ?? '');
            if ($tmp !== '' && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

}
