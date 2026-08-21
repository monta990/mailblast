<?php
/**
 * Mail Blast service.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Mailblast\Service;

use Config;
use GlpiPlugin\Mailblast\Service\AttachmentService;
use GlpiPlugin\Mailblast\Service\ConfigurationService;
use GlpiPlugin\Mailblast\Service\ContentService;
use GlpiPlugin\Mailblast\Service\MailService;
use GlpiPlugin\Mailblast\Service\RecipientService;

final class QueueService
{
    public function initQueue(
            string $subject,
            string $htmlBody,
            string $footer,
            array  $attachments,
            array  $filter        = [],
            int    $replyToUserId = 0
        ): array {
            // Use cryptographically secure random bytes for the job ID.
            $sendId = sprintf(
                '%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(6))
            );
    
            // Read attachment bytes into memory — nothing written to disk or DB.
            $attachmentsB64 = [];
            foreach ($attachments as $att) {
                $bytes = @file_get_contents($att['tmp']);
                if ($bytes !== false && $bytes !== '') {
                    $attachmentsB64[] = [
                        'name' => $att['name'],
                        'mime' => $att['mime'],
                        'data' => base64_encode($bytes),
                    ];
                }
            }
    
            $inlineResult = (new AttachmentService())->extractInlineImages($htmlBody);
            $htmlBody     = $inlineResult['html'];
            $fullHtml     = (new ContentService())->buildHtmlBody($htmlBody, $footer);
            $plainText    = (new ContentService())->html2text($fullHtml);
            $payloadHash  = self::payloadHash($fullHtml, $plainText, $attachmentsB64, $inlineResult['inlineImages']);
    
            $safeFilter = (($filter['type'] ?? 'all') !== 'all') ? $filter : ['type' => 'all'];
            $total      = (new RecipientService())->countActiveUsersWithEmail($safeFilter);
    
            // Resolve "reply to" user email if requested — replies land here
            // regardless of what From address the recipient sees. When a "Reply
            // to" user is selected, that same mailbox is used for From: as well.
            // If no reply-to user is selected, GLPI's default notification
            // sender configuration (Setup > Notifications) is used unchanged.
            $replyTo   = (new RecipientService())->resolveUserEmail($replyToUserId);
            $fromEmail = $replyTo['email'];
            $fromName  = $replyTo['name'];
    
            // Purge stale jobs before registering the new one — prevents the new
            // job from being deleted if its created_at timestamp looks stale due to clock skew.
            static $cleanupDone = false;
            if (!$cleanupDone) {
                (new ConfigurationService())->cleanupStaleJobs();
                $cleanupDone = true;
            }
    
            Config::setConfigurationValues(ConfigurationService::CONFIG_CONTEXT, [
                'queue_' . $sendId => json_encode([
                    'subject'        => $subject,
                    'total'          => $total,
                    'created_at'     => time(),
                    'prev_sent'      => 0,
                    'prev_errors'    => 0,
                    'filter'         => $safeFilter,
                    'from_email'     => $fromEmail,
                    'from_name'      => $fromName,
                    'reply_to_email' => $replyTo['email'],
                    'reply_to_name'  => $replyTo['name'],
                    'payload_hash'   => $payloadHash,
                ]),
            ]);
    
            // Large campaign content stays in browser memory and is re-submitted
            // with every batch. glpi_configs stores only queue metadata.
            return [
                'ok'                => true,
                'send_id'           => $sendId,
                'total'             => $total,
                'html'              => $fullHtml,
                'plain'             => $plainText,
                'attachments_b64'   => $attachmentsB64,
                'inline_images_b64' => $inlineResult['inlineImages'],
            ];
        }

    public function processBatch(
            string $sendId,
            string $html = '',
            string $plain = '',
            array  $attachmentsB64 = [],
            int    $offset = 0,
            int    $batchSize = 15,
            array  $inlineImagesB64 = []
        ): array {
            global $DB;
    
            $cfg = Config::getConfigurationValues(ConfigurationService::CONFIG_CONTEXT, ['queue_' . $sendId]);
            $raw = $cfg['queue_' . $sendId] ?? '';
            $job = $raw !== '' ? json_decode($raw, true) : [];
    
            if (empty($job)) {
                return ['sent' => 0, 'errors' => 0, 'next_offset' => $offset,
                        'done' => true, 'error_list' => [__('The sending queue was not found or has expired.', 'mailblast')],
                        'sent_list' => [], 'queue_error' => true];
            }
    
            $subject      = $job['subject'] ?? '';
            $total        = (int) ($job['total'] ?? 0);
            $fromEmail    = (string) ($job['from_email']     ?? '');
            $fromName     = (string) ($job['from_name']      ?? '');
            $replyToEmail = (string) ($job['reply_to_email'] ?? '');
            $replyToName  = (string) ($job['reply_to_name']  ?? '');
            // Campaign content is intentionally supplied by the browser on every
            // batch. Never persist HTML, attachment bytes or inline images in
            // glpi_configs: its TEXT value is for queue metadata only.
            $html = (string) $html;
            $plain = (string) $plain;
            $attachmentsB64 = is_array($attachmentsB64) ? $attachmentsB64 : [];
            $inlineImagesB64 = is_array($inlineImagesB64) ? $inlineImagesB64 : [];

            $expectedHash = (string) ($job['payload_hash'] ?? '');
            if ($expectedHash === '' || !hash_equals($expectedHash, self::payloadHash($html, $plain, $attachmentsB64, $inlineImagesB64))) {
                \Toolbox::logInFile(
                    'mailblast',
                    sprintf("Queue payload validation failed for send_id %s at offset %d.\n", $sendId, $offset),
                    true
                );
                return ['sent' => 0, 'errors' => 0, 'done' => true, 'next_offset' => $offset,
                        'error_list' => [__('The campaign payload is invalid or has been modified. Restart the send.', 'mailblast')],
                        'sent_list' => [], 'queue_error' => true];
            }

            $storedFilter = (array) ($job['filter'] ?? ['type' => 'all']);
            $filterType   = $storedFilter['type'] ?? 'all';
            $filterIds    = array_values(array_filter(array_map('intval', (array) ($storedFilter['ids'] ?? [])), fn($id) => $id >= 0));
    
            $specificTypes = ['users', 'entities', 'profiles'];
            if (in_array($filterType, $specificTypes, true) && empty($filterIds)) {
                return ['sent' => 0, 'errors' => 0, 'done' => true, 'next_offset' => $offset, 'error_list' => [], 'sent_list' => []];
            }
            if (!in_array($filterType, array_merge(['all'], $specificTypes), true)) {
                return ['sent' => 0, 'errors' => 0, 'done' => true, 'next_offset' => $offset, 'error_list' => [], 'sent_list' => []];
            }

            $attachmentService = new AttachmentService();
            $attachmentValidation = $attachmentService->validateBase64Attachments($attachmentsB64);
            if (!$attachmentValidation['ok']) {
                return ['sent' => 0, 'errors' => 0, 'done' => true, 'next_offset' => $offset,
                        'error_list' => $attachmentValidation['errors'], 'sent_list' => [], 'queue_error' => true];
            }

            $inlineValidation = $attachmentService->validateInlineImages($inlineImagesB64);
            if (!$inlineValidation['ok']) {
                return ['sent' => 0, 'errors' => 0, 'done' => true, 'next_offset' => $offset,
                        'error_list' => $inlineValidation['errors'], 'sent_list' => [], 'queue_error' => true];
            }

            $maxBytes = (new ConfigurationService())->getMaxAttachmentMb() * 1024 * 1024;
            $attachmentBytes = 0;
            foreach ($attachmentsB64 as $att) {
                if (!is_array($att)) {
                    continue;
                }
                $encoded = (string) ($att['data'] ?? '');
                if ($encoded === '') {
                    continue;
                }
                $decoded = base64_decode($encoded, true);
                if ($decoded === false) {
                    return ['sent' => 0, 'errors' => 0, 'done' => true, 'next_offset' => $offset,
                            'error_list' => [__('Invalid attachment data.', 'mailblast')], 'sent_list' => []];
                }
                $attachmentBytes += strlen($decoded);
                if ($attachmentBytes > $maxBytes) {
                    return ['sent' => 0, 'errors' => 0, 'done' => true, 'next_offset' => $offset,
                            'error_list' => [__('Attachment size limit exceeded.', 'mailblast')], 'sent_list' => []];
                }
            }
    
            $inlineBytes = 0;
            foreach ($inlineImagesB64 as $img) {
                $decoded = base64_decode((string) ($img['data'] ?? ''), true);
                if ($decoded !== false) {
                    $inlineBytes += strlen($decoded);
                }
            }
            if ($attachmentBytes + $inlineBytes > $maxBytes) {
                return ['sent' => 0, 'errors' => 0, 'done' => true, 'next_offset' => $offset,
                        'error_list' => [__('Combined attachment and inline image size limit exceeded.', 'mailblast')], 'sent_list' => [], 'queue_error' => true];
            }

            $qWhere = ['ue.is_default' => 1, 'u.is_deleted' => 0, 'u.is_active' => 1, 'NOT' => ['ue.email' => '']];
            $qJoin  = ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]];
    
            if ($filterType === 'users') {
                $qWhere['u.id'] = $filterIds;
            } elseif ($filterType === 'entities' || $filterType === 'profiles') {
                $qJoin['glpi_profiles_users AS pu'] = ['ON' => ['pu' => 'users_id', 'u' => 'id']];
                if ($filterType === 'entities') {
                    $qWhere[] = (new RecipientService())->buildEntityWhere($filterIds);
                } else {
                    $qWhere['pu.profiles_id'] = $filterIds;
                }
            }
    
            $needsDedup = ($filterType === 'entities' || $filterType === 'profiles') && !empty($filterIds);
            $qSpec = [
                'SELECT'    => ['ue.email', 'u.firstname', 'u.realname', 'u.name AS login'],
                'FROM'      => 'glpi_useremails AS ue',
                'LEFT JOIN' => $qJoin,
                'WHERE'     => $qWhere,
                'ORDER'     => ['u.id ASC'],
                'LIMIT'     => $batchSize,
                'START'     => $offset,
            ];
            if ($needsDedup) {
                $qSpec['GROUPBY'] = ['u.id'];
            }
            $iterator = $DB->request($qSpec);
    
            $errorList  = [];
            $sentList   = [];
            $seenEmails = [];
            $sent = $errors = 0;
    
            // Decode attachment base64 strings to temp files once for the whole batch.
            // These are per-request temp files; they are unlinked after the loop.
            $batchTempFiles = [];
            foreach ($attachmentsB64 as $att) {
                $bytes = base64_decode($att['data'] ?? '', true);
                if ($bytes === false || $bytes === '') {
                    continue;
                }
                // Sanitize name: strip path components, fall back to safe default
                $safeName = basename(trim((string)($att['name'] ?? 'attachment')));
                if ($safeName === '' || $safeName === '.') $safeName = 'attachment';
    
                // Verify MIME against actual bytes — ignore whatever the browser sent
                $realMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)
                          ?: 'application/octet-stream';
    
                $tmp = @tempnam(sys_get_temp_dir(), 'mb_att_');
                if ($tmp !== false && @file_put_contents($tmp, $bytes) !== false) {
                    $batchTempFiles[] = [
                        'path' => $tmp,
                        'name' => $safeName,
                        'mime' => $realMime,
                    ];
                }
            }
    
            $inlineImages = [];
            foreach ($inlineImagesB64 as $img) {
                $imgBytes = base64_decode($img['data'] ?? '', true);
                if ($imgBytes !== false && $imgBytes !== '') {
                    $inlineImages[] = [
                        'cid'   => (string) ($img['cid']  ?? ''),
                        'bytes' => $imgBytes,
                        'mime'  => (new \finfo(FILEINFO_MIME_TYPE))->buffer($imgBytes) ?: 'application/octet-stream',
                    ];
                }
            }
    
            // Create transport once for the whole batch — one SMTP handshake per batch.
            try {
                $transport = \Symfony\Component\Mailer\Transport::fromDsn(\GLPIMailer::buildDsn(true));
            } catch (\Throwable $e) {
                foreach ($batchTempFiles as $att) { @unlink($att['path']); }
                \Toolbox::logInFile(
                    'mailblast',
                    sprintf("SMTP transport initialization failed for send_id %s: %s\n", $sendId, $e->getMessage()),
                    true
                );
                return ['sent' => 0, 'errors' => 0, 'done' => true, 'next_offset' => $offset,
                        'error_list' => [$e->getMessage()], 'sent_list' => [], 'queue_error' => true];
            }
    
            try {
            foreach ($iterator as $row) {
                $displayName = trim($row['firstname'] . ' ' . $row['realname']);
                if ($displayName === '') $displayName = $row['login'];
    
                $toEmail = trim((string) $row['email']);
    
                if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    $errorList[] = $toEmail . ': ' . __('Invalid address', 'mailblast');
                    $errors++;
                    continue;
                }
    
                // Skip duplicate email addresses within the same batch.
                if (in_array($toEmail, $seenEmails, true)) {
                    continue;
                }
                $seenEmails[] = $toEmail;
    
                // Replace placeholder variables per recipient.
                // Supported: {nombre} (first name or login), {email}, {nombre_completo}
                $firstName = trim((string)($row['firstname'] ?? '')) ?: $row['login'];
                $fullName  = trim($row['firstname'] . ' ' . $row['realname']) ?: $row['login'];
                $perHtml  = str_replace(
                    ['{nombre}', '{email}', '{nombre_completo}'],
                    [htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'),
                     htmlspecialchars($toEmail,   ENT_QUOTES, 'UTF-8'),
                     htmlspecialchars($fullName,  ENT_QUOTES, 'UTF-8')],
                    $html
                );
                $perPlain = str_replace(
                    ['{nombre}', '{email}', '{nombre_completo}'],
                    [$firstName, $toEmail, $fullName],
                    $plain
                );
                $perSubject = str_replace(
                    ['{nombre}', '{email}', '{nombre_completo}'],
                    [$firstName, $toEmail, $fullName],
                    $subject
                );
                $err = (new MailService())->sendSymfonyEmail(
                    $transport, $toEmail, $displayName, $perSubject, $perHtml, $perPlain, $batchTempFiles, $inlineImages,
                    $fromEmail, $fromName, $replyToEmail, $replyToName
                );
    
                if ($err === null) {
                    $sent++;
                    $sentList[] = $toEmail;
                } else {
                    $errorList[] = $toEmail . ': ' . $err;
                    $errors++;
                }
            }
            } finally {
                // Close SMTP connection and remove request-local files even if a
                // transport/stream exception escapes the per-recipient send.
                if (method_exists($transport, 'stop')) {
                    try { $transport->stop(); } catch (\Throwable $e) {}
                }
                foreach ($batchTempFiles as $att) {
                    @unlink($att['path']);
                }
            }
    
            $nextOffset = $offset + $batchSize;
            // Use actual row count returned as the authoritative signal — if fewer
            // rows came back than requested, we are at the end regardless of $total.
            // This handles users being activated/deactivated mid-send gracefully.
            $done = ($iterator->count() < $batchSize);
            // Also stop if we've passed the originally stored total (safety cap).
            if (!$done && $total > 0 && $nextOffset >= $total) $done = true;
    
            // Accumulate running totals across batches.
            // prev_sent/prev_errors start at 0 and are updated after every batch
            // so the final batch reads the correct cumulative total for addHistory.
            $runSent   = (int) ($job['prev_sent']   ?? 0) + $sent;
            $runErrors = (int) ($job['prev_errors'] ?? 0) + $errors;
    
            if (!$done) {
                Config::setConfigurationValues(ConfigurationService::CONFIG_CONTEXT, [
                    'queue_' . $sendId => json_encode(array_merge($job, [
                        'prev_sent'   => $runSent,
                        'prev_errors' => $runErrors,
                    ])),
                ]);
            }
    
            if ($done) {
                (new ConfigurationService())->cleanupJob($sendId);
                (new ConfigurationService())->addHistory($subject, $runSent, $runErrors);
                (new ConfigurationService())->recordSendCompleted();
            }
    
            return [
                'sent'        => $sent,
                'errors'      => $errors,
                'next_offset' => $nextOffset,
                'done'        => $done,
                'error_list'  => $errorList,
                'sent_list'   => $sentList,
            ];
        }

    private static function payloadHash(string $html, string $plain, array $attachments, array $inlineImages): string
    {
        // Do not hash the raw JSON representation of browser data. Multipart/form-data
        // may normalize line endings in text fields, and JSON object/index ordering can
        // differ after the browser -> PHP round trip. Hash a canonical representation
        // instead, while still detecting any actual content change.
        $canonicalAttachments = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                $canonicalAttachments[] = ['invalid' => true];
                continue;
            }

            $data = base64_decode((string) ($attachment['data'] ?? ''), true);
            $name = basename(trim((string) ($attachment['name'] ?? 'attachment')));
            if ($data === false) {
                $canonicalAttachments[] = [
                    'name' => $name,
                    'data' => 'invalid',
                ];
                continue;
            }

            $realMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'application/octet-stream';
            $canonicalAttachments[] = [
                'name' => $name,
                'mime' => $realMime,
                'sha256' => hash('sha256', $data),
                'size' => strlen($data),
            ];
        }

        $canonicalInlineImages = [];
        foreach ($inlineImages as $image) {
            if (!is_array($image)) {
                $canonicalInlineImages[] = ['invalid' => true];
                continue;
            }

            $data = base64_decode((string) ($image['data'] ?? ''), true);
            if ($data === false) {
                $canonicalInlineImages[] = [
                    'cid' => (string) ($image['cid'] ?? ''),
                    'data' => 'invalid',
                ];
                continue;
            }

            $realMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'application/octet-stream';
            $canonicalInlineImages[] = [
                'cid' => (string) ($image['cid'] ?? ''),
                'mime' => $realMime,
                'sha256' => hash('sha256', $data),
                'size' => strlen($data),
            ];
        }

        $normalizeText = static fn(string $value): string => str_replace(["\r\n", "\r"], "\n", $value);

        return hash('sha256', json_encode([
            'html' => $normalizeText($html),
            'plain' => $normalizeText($plain),
            'attachments' => $canonicalAttachments,
            'inline_images' => $canonicalInlineImages,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

}
