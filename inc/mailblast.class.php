<?php
/**
 * Mail Blast — PluginMailblastMailblast class
 *
 * Requires GLPI 11.0+.
 *
 * @author  Edwin Elias Alvarez
 * @license GPL-3.0-or-later
 */

class PluginMailblastMailblast extends CommonGLPI
{
    public static $rightname = 'config';

    private const CONFIG_CONTEXT = 'plugin:mailblast';

    // ─── CommonGLPI overrides ─────────────────────────────────────────────

    public static function getTypeName($nb = 0): string
    {
        return __('Mail Blast', 'mailblast');
    }

    public static function getMenuContent(): array
    {
        $menu = [];

        if (Session::haveRight('config', UPDATE)) {
            $menu['title'] = self::getTypeName();
            $menu['page']  = Plugin::getWebDir('mailblast', false) . '/front/send.php';
            $menu['icon']  = 'ti ti-mail-forward';

            $menu['links']['config'] = Plugin::getWebDir('mailblast', false) . '/front/send.php';
        }

        return $menu;
    }

    // ─── Persistent form config ───────────────────────────────────────────

    /**
     * Persists subject and footer to glpi_configs.
     * Body is excluded — it may contain large base64 images and
     * survives theme-toggle reloads via the browser sessionStorage.
     */
    public static function saveFormConfig(string $subject, string $body, string $footer): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            'last_subject' => $subject,
            'last_footer'  => $footer,
        ]);
    }

    /** @return array{subject: string, body: string, footer: string} */
    public static function loadFormConfig(): array
    {
        $cfg = Config::getConfigurationValues(
            self::CONFIG_CONTEXT,
            ['last_subject', 'last_footer']
        );

        return [
            'subject' => (string) ($cfg['last_subject'] ?? ''),
            'body'    => '',  // never persisted — editor always starts blank
            'footer'  => (string) ($cfg['last_footer']  ?? ''),
        ];
    }

    // ─── User / email queries ─────────────────────────────────────────────

    /** @return array<array{email: string, name: string}> */
    public static function getActiveUsersWithEmail(): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'    => ['ue.email', 'u.name', 'u.firstname', 'u.realname'],
            'FROM'      => 'glpi_useremails AS ue',
            'LEFT JOIN' => [
                'glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']],
            ],
            'WHERE' => [
                'ue.is_default' => 1,
                'u.is_deleted'  => 0,
                'u.is_active'   => 1,
                'NOT'           => ['ue.email' => ''],
            ],
        ]);

        $users = [];
        foreach ($iterator as $row) {
            $displayName = trim($row['firstname'] . ' ' . $row['realname']);
            if ($displayName === '') {
                $displayName = $row['name'];
            }
            $users[] = ['email' => $row['email'], 'name' => $displayName];
        }

        return $users;
    }

    public static function countActiveUsersWithEmail(): int
    {
        return count(self::getActiveUsersWithEmail());
    }

    // ─── GLPI allowed document types ─────────────────────────────────────

    public static function getAllowedDocumentTypes(): array
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



    // ─── Queue management ─────────────────────────────────────────────────
    //
    // No custom DB table.  Recipients are fetched LIMIT/OFFSET at send time.
    // glpi_configs stores only {subject, total} — no HTML, no attachment bytes.
    // Both live exclusively in JS RAM and are re-posted on every batch call.

    /**
     * Initialises a send job and returns html/plain/attachments_b64 to the caller.
     * The JS layer holds these in RAM and re-submits them on every queue_process call.
     * Nothing is written to disk or the DB beyond the minimal metadata entry.
     *
     * @return array{
     *   send_id: string, total: int, html: string, plain: string,
     *   attachments_b64: array<array{name: string, mime: string, data: string}>
     * }
     */
    public static function initQueue(
        string $subject,
        string $htmlBody,
        string $footer,
        array  $attachments
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

        $htmlBody = self::embedImagesAsBase64($htmlBody);
        $fullHtml = self::buildHtmlBody($htmlBody, $footer);
        $total    = self::countActiveUsersWithEmail();

        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            'queue_' . $sendId => json_encode([
                'subject'    => $subject,
                'total'      => $total,
                'created_at' => time(),
            ]),
        ]);

        // Purge any jobs older than 2 hours before starting a new one.
        self::cleanupStaleJobs();

        return [
            'send_id'         => $sendId,
            'total'           => $total,
            'html'            => $fullHtml,
            'plain'           => self::html2text($fullHtml),
            'attachments_b64' => $attachmentsB64,
        ];
    }

    /**
     * Sends one batch of recipients.
     *
     * HTML and attachment bytes come from the JS layer (RAM) and are re-posted
     * on every call.  Attachments are decoded to per-request temp files,
     * attached via addAttachment(), then immediately unlinked.
     *
     * @return array{sent: int, errors: int, next_offset: int, done: bool, error_list: string[]}
     */
    public static function processBatch(
        string $sendId,
        string $html           = '',
        string $plain          = '',
        array  $attachmentsB64 = [],
        int    $offset         = 0,
        int    $batchSize      = 15
    ): array {
        global $DB;

        $cfg = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['queue_' . $sendId]);
        $raw = $cfg['queue_' . $sendId] ?? '';
        $job = $raw !== '' ? json_decode($raw, true) : [];

        if (empty($job)) {
            return ['sent' => 0, 'errors' => 0, 'next_offset' => 0,
                    'done' => true, 'error_list' => []];
        }

        $subject = $job['subject'] ?? '';
        $total   = (int) ($job['total'] ?? 0);

        $iterator = $DB->request([
            'SELECT'    => ['ue.email', 'u.firstname', 'u.realname', 'u.name AS login'],
            'FROM'      => 'glpi_useremails AS ue',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]],
            'WHERE'     => [
                'ue.is_default' => 1,
                'u.is_deleted'  => 0,
                'u.is_active'   => 1,
                'NOT'           => ['ue.email' => ''],
            ],
            'LIMIT' => $batchSize,
            'START' => $offset,
        ]);

        $errorList  = [];
        $sent = $errors = 0;

        // Decode attachment base64 strings to temp files once for the whole batch.
        // These are per-request temp files; they are unlinked after the loop.
        $batchTempFiles = [];
        foreach ($attachmentsB64 as $att) {
            $bytes = base64_decode($att['data'] ?? '', true);
            if ($bytes === false || $bytes === '') {
                continue;
            }
            $tmp = @tempnam(sys_get_temp_dir(), 'mb_att_');
            if ($tmp !== false && @file_put_contents($tmp, $bytes) !== false) {
                $batchTempFiles[] = [
                    'path' => $tmp,
                    'name' => $att['name'],
                    'mime' => $att['mime'],
                ];
            }
        }

        // Create transport once for the whole batch — one SMTP handshake per batch.
        $transport = \Symfony\Component\Mailer\Transport::fromDsn(GLPIMailer::buildDsn(true));

        foreach ($iterator as $row) {
            $displayName = trim($row['firstname'] . ' ' . $row['realname']);
            if ($displayName === '') $displayName = $row['login'];

            $toEmail = trim((string) $row['email']);

            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                $errorList[] = $toEmail . ': ' . __('Invalid address', 'mailblast');
                $errors++;
                continue;
            }

            $err = self::sendSymfonyEmail(
                $transport, $toEmail, $displayName, $subject, $html, $plain, $batchTempFiles
            );

            if ($err === null) {
                $sent++;
            } else {
                $errorList[] = $toEmail . ': ' . $err;
                $errors++;
            }
        }

        // Unlink per-request temp files.
        foreach ($batchTempFiles as $att) {
            @unlink($att['path']);
        }

        $nextOffset = $offset + $batchSize;
        $done       = ($nextOffset >= $total) || ($iterator->count() < $batchSize);

        if ($done) {
            self::cleanupJob($sendId);
        }

        return [
            'sent'        => $sent,
            'errors'      => $errors,
            'next_offset' => $nextOffset,
            'done'        => $done,
            'error_list'  => $errorList,
        ];
    }

    /**
     * Removes job entries older than $maxAgeSeconds from glpi_configs.
     * Called at the start of each new queue to prevent orphaned entries
     * accumulating if the browser was closed during a mass send.
     */
    public static function cleanupStaleJobs(int $maxAgeSeconds = 7200): void
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['name', 'value'],
            'FROM'   => 'glpi_configs',
            'WHERE'  => [
                'context' => self::CONFIG_CONTEXT,
                ['name' => ['LIKE', 'queue_%']],
            ],
        ]);

        foreach ($iterator as $row) {
            $job = json_decode((string) $row['value'], true);
            $createdAt = (int) ($job['created_at'] ?? 0);
            if ($createdAt > 0 && (time() - $createdAt) > $maxAgeSeconds) {
                $DB->delete('glpi_configs', [
                    'context' => self::CONFIG_CONTEXT,
                    'name'    => $row['name'],
                ]);
            }
        }
    }

    /** Removes the minimal job metadata from glpi_configs. */
    public static function cleanupJob(string $sendId): void
    {
        global $DB;
        $DB->delete('glpi_configs', [
            'context' => self::CONFIG_CONTEXT,
            'name'    => 'queue_' . $sendId,
        ]);
    }

    // ─── Image embedding ─────────────────────────────────────────────────

    /**
     * Converts any GLPI-document img src values to inline base64 URIs and
     * immediately deletes the document record and file from the server.
     *
     * Images inserted via TinyMCE are uploaded to glpi_documents for the
     * duration of composition. Once embedded as base64 in the email body they
     * serve no further purpose — leaving them orphaned in glpi_documents would
     * accumulate indefinitely with no way to identify them manually.
     */
    public static function embedImagesAsBase64(string $html): string
    {
        $pattern = '/(<img[^>]+src=["\'])([^"\']*document\.send\.php[^"\']*docid=(\d+)[^"\']*?)(["\'][^>]*>)/i';

        return preg_replace_callback(
            $pattern,
            function (array $m) {
                $docId    = (int) $m[3];
                $embedded = self::docIdToBase64($docId);

                if ($embedded === null) {
                    return $m[0];
                }

                // Delete the document now that it is embedded as base64.
                // It was uploaded solely for composing this email and would
                // otherwise remain as an orphan in glpi_documents indefinitely.
                self::purgeDocument($docId);

                return $m[1] . $embedded . $m[4];
            },
            $html
        );
    }

    private static function purgeDocument(int $docId): void
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['filepath'],
            'FROM'   => 'glpi_documents',
            'WHERE'  => ['id' => $docId],
        ]);

        if ($iterator->count()) {
            $fullPath = GLPI_DOC_DIR . '/' . $iterator->current()['filepath'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        $DB->delete('glpi_documents', ['id' => $docId]);
    }

    private static function docIdToBase64(int $docId): ?string
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
        $fullPath = GLPI_DOC_DIR . '/' . $row['filepath'];

        if (!file_exists($fullPath) || !is_readable($fullPath)) {
            return null;
        }

        $bytes = file_get_contents($fullPath);
        if ($bytes === false) {
            return null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)
              ?: ((string) ($row['mime'] ?? 'application/octet-stream'));

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    // ─── Mail helpers ─────────────────────────────────────────────────────

    /**
     * Sends a single email using Symfony Mailer directly.
     *
     * We bypass the GLPIMailer PHPMailer-compat layer entirely and use
     * Symfony\Component\Mime\Email + Transport directly — the same approach
     * GLPI 11 itself uses in NotificationEventMailing.php.
     *
     * Attachment bytes are read eagerly with file_get_contents() and passed to
     * Email::attach($bytes, $name, $mime) — the same approach GLPI core uses in
     * NotificationEventMailing::attachDocuments().  This avoids any lazy-load or
     * file-path timing issues that can silently drop attachments.
     *
     * @param  string $toEmail
     * @param  string $toName
     * @param  string $subject
     * @param  string $html         HTML body.
     * @param  string $plain        Plain-text AltBody.
     * @param  array  $attachments  [{path: string, name: string, mime: string}]
     * @return string|null  Error message on failure, null on success.
     */
    private static function sendSymfonyEmail(
        \Symfony\Component\Mailer\Transport\TransportInterface $transport,
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $plain,
        array  $attachments = []
    ): ?string {
        global $CFG_GLPI;

        try {
            $email = new \Symfony\Component\Mime\Email();

            // From address — same config GLPI reads for notifications
            $fromAddr = trim((string) ($CFG_GLPI['admin_email']      ?? ''));
            $fromName = trim((string) ($CFG_GLPI['admin_email_name'] ?? ''));
            if ($fromAddr !== '' && filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
                $email->from(new \Symfony\Component\Mime\Address($fromAddr, $fromName));
            }

            // Envelope sender (smtp_sender), if configured
            $smtpSender = trim((string) ($CFG_GLPI['smtp_sender'] ?? ''));
            if ($smtpSender !== '' && filter_var($smtpSender, FILTER_VALIDATE_EMAIL)) {
                $email->sender(new \Symfony\Component\Mime\Address($smtpSender));
            }

            $email->to(new \Symfony\Component\Mime\Address($toEmail, $toName))
                  ->subject($subject)
                  ->html($html)
                  ->text($plain);

            // Attach files: read bytes eagerly so there is no lazy-load / file-path
            // timing issue.  Email::attach($bytes, $name, $mime) is the most reliable
            // Symfony Mime API — identical to how GLPI core attaches documents.
            foreach ($attachments as $att) {
                if (!isset($att['path']) || !file_exists($att['path'])) {
                    throw new \RuntimeException(
                        sprintf('Attachment file not found: %s', $att['path'] ?? '(empty)')
                    );
                }
                $bytes = file_get_contents($att['path']);
                if ($bytes === false) {
                    throw new \RuntimeException(
                        sprintf('Could not read attachment: %s', $att['path'])
                    );
                }
                $email->attach($bytes, $att['name'], $att['mime']);
            }

            $transport->send($email);
            return null;

        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Converts HTML to plain text for the email AltBody. */
    public static function html2text(string $html): string
    {
        $text = str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</div>', '</h1>', '</h2>', '</h3>', '</li>'],
            "\n",
            $html
        );
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /** Appends the HTML footer to the body and returns the final HTML. */
    public static function buildHtmlBody(string $htmlBody, string $footer): string
    {
        if (trim(strip_tags($footer)) !== '') {
            $htmlBody .= '<br>'
                . '<hr style="border:none;border-top:1px solid #cccccc;margin:24px 0">'
                . '<div style="color:#666666;font-size:12px;line-height:1.5">'
                . $footer
                . '</div>';
        }

        return $htmlBody;
    }

    // ─── Send logic ───────────────────────────────────────────────────────

    /**
     * Sends the composed email (test mode or mass send).
     *
     * Attachments are passed as [{tmp, name, mime}] where tmp is the PHP upload
     * temp path, which is still valid for the duration of the request.
     *
     * @param  array<array{tmp: string, name: string, mime: string}> $attachments
     * @return array{sent: int, errors: string[], total: int}
     */
    public static function sendMails(
        string $subject,
        string $htmlBody,
        string $footer,
        array  $attachments = [],
        bool   $testMode    = false,
        string $testEmail   = ''
    ): array {
        $htmlBody  = self::embedImagesAsBase64($htmlBody);
        $fullHtml  = self::buildHtmlBody($htmlBody, $footer);
        $plainText = self::html2text($fullHtml);

        // Map attachments to the {path, name, mime} shape sendSymfonyEmail expects.
        $attPaths = array_map(
            fn($a) => ['path' => $a['tmp'], 'name' => $a['name'], 'mime' => $a['mime']],
            $attachments
        );

        // Test mode: single hardcoded recipient — no DB query needed.
        // Production: use getActiveUsersWithEmail() (processBatch handles
        // the LIMIT/OFFSET variant for mass sends via the queue).
        $recipients = $testMode
            ? [['email' => $testEmail, 'name' => 'Test']]
            : self::getActiveUsersWithEmail();

        $sent   = 0;
        $errors = [];

        // Create transport once — avoids a new SMTP handshake per recipient.
        $transport = \Symfony\Component\Mailer\Transport::fromDsn(GLPIMailer::buildDsn(true));

        foreach ($recipients as $recipient) {
            $toEmail = trim((string) $recipient['email']);
            $toName  = (string) ($recipient['name'] ?? '');

            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = $toEmail . ': ' . __('Invalid address', 'mailblast');
                continue;
            }

            $err = self::sendSymfonyEmail(
                $transport, $toEmail, $toName, $subject, $fullHtml, $plainText, $attPaths
            );

            if ($err === null) {
                $sent++;
            } else {
                $errors[] = $toEmail . ': ' . $err;
            }


        }

        return ['sent' => $sent, 'errors' => $errors, 'total' => count($recipients)];
    }
}
