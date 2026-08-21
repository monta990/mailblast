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

    public function validateUploadedFiles(array $files, array $allowedMimes): array
        {
            $accepted   = [];
            $rejected   = [];
            $maxBytes   = (new ConfigurationService())->getMaxAttachmentMb() * 1024 * 1024;
            $totalBytes = 0;
    
            $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
            for ($i = 0; $i < $count; $i++) {
                $errCode = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($errCode === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($errCode !== UPLOAD_ERR_OK) {
                    $rejected[] = (string) ($files['name'][$i] ?? '') . ': ' . __('Upload error', 'mailblast');
                    continue;
                }
    
                $tmpPath  = (string) ($files['tmp_name'][$i] ?? '');
                $realMime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream';
    
                if (!in_array($realMime, $allowedMimes, true)) {
                    $rejected[] = (string) ($files['name'][$i] ?? '') . ': ' . __('File type not allowed', 'mailblast');
                    continue;
                }
    
                $fileSize    = (int) ($files['size'][$i] ?? 0);
                $totalBytes += $fileSize;
                if ($totalBytes > $maxBytes) {
                    $rejected[] = (string) ($files['name'][$i] ?? '') . ': ' . __('Attachment size limit exceeded', 'mailblast');
                    continue;
                }
    
                $accepted[] = [
                    'tmp'  => $tmpPath,
                    'name' => (string) ($files['name'][$i] ?? 'attachment'),
                    'mime' => $realMime,
                ];
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
            global $DB;
    
            $iterator = $DB->request([
                'SELECT' => ['filepath', 'mime'],
                'FROM'   => 'glpi_documents',
                'WHERE'  => ['id' => $docId, 'is_deleted' => 0],
            ]);
    
            if (!$iterator->count()) {
                return null;
            }
    
            $row      = $iterator->current();
            $rawPath  = GLPI_DOC_DIR . '/' . $row['filepath'];
            $fullPath = realpath($rawPath);
            $docBase  = realpath(GLPI_DOC_DIR);
    
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
        $allowed = array_values(array_filter(array_map('strval', $allowedTypes['mimes'] ?? [])));
        $allowedExts = array_values(array_filter(array_map(static fn(array $type): string => strtolower((string) ($type['ext'] ?? '')), $allowedTypes['types'] ?? [])));
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
            $realMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mimeAllowed = $allowed !== [] && in_array($realMime, $allowed, true);
            $extensionAllowed = $extension !== '' && in_array($extension, $allowedExts, true);
            if (($allowed !== [] || $allowedExts !== []) && !$mimeAllowed && !$extensionAllowed) {
                $errors[] = sprintf(__('File type not allowed by GLPI: %s (%s).', 'mailblast'), $name ?: ($index + 1), $realMime);
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
            $realMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
            if (!str_starts_with($realMime, 'image/')) {
                $errors[] = sprintf(__('Inline image %d is not a valid image.', 'mailblast'), $index + 1);
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
