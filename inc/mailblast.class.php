<?php
/**
 * Mail Blast — PluginMailblastMailblast class
 *
 * Requires GLPI 11.0+.
 *
 * @author  Edwin Elias Alvarez
 * @license GPL-3.0-or-later
 */

// GLPI 11 declares $rightname without a type; GLPI 12 adds `string`.
// PHP forbids adding or removing a type annotation in a child class,
// so we use a version-conditional abstract shim as the direct parent.
// Use (int) cast — version_compare treats '12.0.0-dev' < '12.0.0' (false negative).
if ((int) GLPI_VERSION >= 12) {
    abstract class PluginMailblastBase extends CommonGLPI {
        public static string $rightname = 'config';
    }
} else {
    abstract class PluginMailblastBase extends CommonGLPI {
        public static $rightname = 'config';
    }
}

class PluginMailblastMailblast extends PluginMailblastBase
{

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
            $menu['page']  = plugin_mailblast_web_dir() . '/front/send.php';
            $menu['icon']  = 'ti ti-mail-forward';

            $menu['links']['config'] = plugin_mailblast_web_dir() . '/front/send.php';
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

    public static function countActiveUsersWithEmail(array $filter = []): int
    {
        global $DB;

        $type = $filter['type'] ?? 'all';
        $ids  = array_values(array_filter(array_map('intval', (array) ($filter['ids'] ?? [])), fn($id) => $id >= 0));

        $baseWhere = [
            'ue.is_default' => 1,
            'u.is_deleted'  => 0,
            'u.is_active'   => 1,
            'NOT'           => ['ue.email' => ''],
        ];

        if ($type !== 'all' && empty($ids)) {
            return 0;
        }

        if ($type === 'all') {
            $result = $DB->request([
                'COUNT'     => 'cpt',
                'FROM'      => 'glpi_useremails AS ue',
                'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]],
                'WHERE'     => $baseWhere,
            ]);
            return (int) ($result->current()['cpt'] ?? 0);
        }

        $query = [
            'SELECT'    => ['u.id'],
            'FROM'      => 'glpi_useremails AS ue',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]],
            'WHERE'     => $baseWhere,
        ];

        if ($type === 'users') {
            $query['WHERE']['u.id'] = $ids;
        } elseif ($type === 'profiles') {
            $query['LEFT JOIN']['glpi_profiles_users AS pu'] = ['ON' => ['pu' => 'users_id', 'u' => 'id']];
            $query['WHERE']['pu.profiles_id'] = $ids;
        } elseif ($type === 'entities') {
            $query['LEFT JOIN']['glpi_profiles_users AS pu'] = ['ON' => ['pu' => 'users_id', 'u' => 'id']];
            $query['WHERE'][] = self::buildEntityWhere($ids);
        }

        // Deduplicate in PHP — avoids GROUP BY quoting concerns and handles
        // users with multiple profile assignments appearing more than once.
        $seen = [];
        foreach ($DB->request($query) as $row) {
            $seen[(int) $row['id']] = true;
        }
        return count($seen);
    }

    /**
     * Builds a WHERE sub-condition that correctly resolves GLPI entity membership,
     * including recursive profile assignments from ancestor entities.
     * Users are "in" entity X if:
     *   (a) directly assigned to X or any of its descendants, OR
     *   (b) assigned to an ancestor of X with is_recursive = 1.
     */
    private static function buildEntityWhere(array $entityIds): array
    {
        $directAndSons = [];
        $ancestors     = [];
        foreach ($entityIds as $id) {
            foreach (array_keys(getSonsOf('glpi_entities', $id)) as $sonId) {
                $directAndSons[] = $sonId;
            }
            foreach (array_keys(getAncestorsOf('glpi_entities', $id)) as $ancId) {
                $ancestors[] = $ancId;
            }
        }
        $directAndSons = array_values(array_unique($directAndSons));
        $ancestors     = array_values(array_unique($ancestors));

        $orParts = [];
        if (!empty($directAndSons)) {
            $orParts[] = ['pu.entities_id' => $directAndSons];
        }
        if (!empty($ancestors)) {
            $orParts[] = ['pu.entities_id' => $ancestors, 'pu.is_recursive' => 1];
        }

        if (empty($orParts)) {
            return ['pu.entities_id' => [-1]]; // match nothing
        }
        return count($orParts) === 1 ? $orParts[0] : ['OR' => $orParts];
    }

    // ─── Plugin configuration ────────────────────────────────────────────

    public static function getBatchSize(): int
    {
        $cfg = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['batch_size']);
        $v   = (int) ($cfg['batch_size'] ?? 0);
        return ($v >= 1 && $v <= 100) ? $v : 15;
    }

    public static function getBatchDelayMs(): int
    {
        $cfg = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['batch_delay_ms']);
        // Use -1 as sentinel: if key is not in DB yet, ?? returns -1 which
        // fails the >= 0 check and the default 120 is returned instead.
        $v   = (int) ($cfg['batch_delay_ms'] ?? -1);
        return ($v >= 0 && $v <= 5000) ? $v : 120;
    }

    public static function getMaxAttachmentMb(): int
    {
        $cfg = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['max_attachment_mb']);
        $v   = (int) ($cfg['max_attachment_mb'] ?? 0);
        return ($v >= 1 && $v <= 100) ? $v : 15;
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



    // ─── UI data helpers ──────────────────────────────────────────────────

    public static function getEntities(): array
    {
        global $DB;
        $result = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename', 'email'], 'FROM' => 'glpi_entities', 'ORDER' => ['completename ASC']]) as $row) {
            $result[] = ['id' => (int) $row['id'], 'name' => (string) $row['completename'], 'email' => (string) ($row['email'] ?? '')];
        }
        return $result;
    }

    public static function getProfiles(): array
    {
        global $DB;
        $result = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => ['name ASC']]) as $row) {
            $result[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        return $result;
    }

    public static function getUsersWithEmail(): array
    {
        global $DB;
        $result = [];
        foreach ($DB->request([
            'SELECT'    => ['u.id', 'u.name', 'u.firstname', 'u.realname', 'ue.email'],
            'FROM'      => 'glpi_useremails AS ue',
            'LEFT JOIN' => ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]],
            'WHERE'     => ['ue.is_default' => 1, 'u.is_deleted' => 0, 'u.is_active' => 1, 'NOT' => ['ue.email' => '']],
            'ORDER'     => ['u.realname ASC', 'u.firstname ASC'],
        ]) as $row) {
            $name     = trim($row['firstname'] . ' ' . $row['realname']) ?: (string) $row['name'];
            $result[] = ['id' => (int) $row['id'], 'name' => $name, 'email' => (string) $row['email']];
        }
        return $result;
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
        array  $attachments,
        array  $filter       = [],
        int    $fromEntityId = 0
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

        $inlineResult = self::extractInlineImages($htmlBody);
        $htmlBody     = $inlineResult['html'];
        $fullHtml     = self::buildHtmlBody($htmlBody, $footer);

        $safeFilter = (($filter['type'] ?? 'all') !== 'all') ? $filter : ['type' => 'all'];
        $total      = self::countActiveUsersWithEmail($safeFilter);

        // Resolve "send from" entity email if requested.
        $fromEmail = '';
        $fromName  = '';
        if ($fromEntityId > 0) {
            global $DB;
            $iter = $DB->request(['SELECT' => ['completename', 'email'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $fromEntityId]]);
            if ($iter->count()) {
                $row = $iter->current();
                $candidate = trim((string) ($row['email'] ?? ''));
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $fromEmail = $candidate;
                    $fromName  = (string) ($row['completename'] ?? '');
                }
            }
        }

        // Purge stale jobs before registering the new one — prevents the new
        // job from being deleted if its created_at timestamp looks stale due to clock skew.
        static $cleanupDone = false;
        if (!$cleanupDone) {
            self::cleanupStaleJobs();
            $cleanupDone = true;
        }

        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            'queue_' . $sendId => json_encode([
                'subject'     => $subject,
                'total'       => $total,
                'created_at'  => time(),
                'prev_sent'   => 0,
                'prev_errors' => 0,
                'filter'      => $safeFilter,
                'from_email'  => $fromEmail,
                'from_name'   => $fromName,
            ]),
        ]);

        return [
            'send_id'           => $sendId,
            'total'             => $total,
            'html'              => $fullHtml,
            'plain'             => self::html2text($fullHtml),
            'attachments_b64'   => $attachmentsB64,
            'inline_images_b64' => $inlineResult['inlineImages'],
        ];
    }

    /**
     * Sends one batch of recipients.
     *
     * HTML and attachment bytes come from the JS layer (RAM) and are re-posted
     * on every call.  Attachments are decoded to per-request temp files,
     * attached via addAttachment(), then immediately unlinked.
     *
     * @return array{sent: int, errors: int, next_offset: int, done: bool, error_list: string[], sent_list: string[]}
     */
    public static function processBatch(
        string $sendId,
        string $html            = '',
        string $plain           = '',
        array  $attachmentsB64  = [],
        int    $offset          = 0,
        int    $batchSize       = 15,
        array  $inlineImagesB64 = []
    ): array {
        global $DB;

        $cfg = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['queue_' . $sendId]);
        $raw = $cfg['queue_' . $sendId] ?? '';
        $job = $raw !== '' ? json_decode($raw, true) : [];

        if (empty($job)) {
            return ['sent' => 0, 'errors' => 0, 'next_offset' => 0,
                    'done' => true, 'error_list' => [], 'sent_list' => []];
        }

        $subject   = $job['subject'] ?? '';
        $total     = (int) ($job['total'] ?? 0);
        $fromEmail = (string) ($job['from_email'] ?? '');
        $fromName  = (string) ($job['from_name']  ?? '');

        $storedFilter = (array) ($job['filter'] ?? ['type' => 'all']);
        $filterType   = $storedFilter['type'] ?? 'all';
        $filterIds    = array_values(array_filter(array_map('intval', (array) ($storedFilter['ids'] ?? [])), fn($id) => $id >= 0));

        $qWhere = ['ue.is_default' => 1, 'u.is_deleted' => 0, 'u.is_active' => 1, 'NOT' => ['ue.email' => '']];
        $qJoin  = ['glpi_users AS u' => ['ON' => ['ue' => 'users_id', 'u' => 'id']]];

        if ($filterType === 'users' && !empty($filterIds)) {
            $qWhere['u.id'] = $filterIds;
        } elseif (!empty($filterIds) && ($filterType === 'entities' || $filterType === 'profiles')) {
            $qJoin['glpi_profiles_users AS pu'] = ['ON' => ['pu' => 'users_id', 'u' => 'id']];
            if ($filterType === 'entities') {
                $qWhere[] = self::buildEntityWhere($filterIds);
            } else {
                $qWhere['pu.profiles_id'] = $filterIds;
            }
        }

        $iterator = $DB->request([
            'SELECT'    => ['ue.email', 'u.firstname', 'u.realname', 'u.name AS login'],
            'FROM'      => 'glpi_useremails AS ue',
            'LEFT JOIN' => $qJoin,
            'WHERE'     => $qWhere,
            'ORDER'     => ['u.id ASC'],
            'LIMIT'     => $batchSize,
            'START'     => $offset,
        ]);

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
                    'mime'  => (string) ($img['mime'] ?? 'application/octet-stream'),
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
            $err = self::sendSymfonyEmail(
                $transport, $toEmail, $displayName, $perSubject, $perHtml, $perPlain, $batchTempFiles, $inlineImages, $fromEmail, $fromName
            );

            if ($err === null) {
                $sent++;
                $sentList[] = $toEmail;
            } else {
                $errorList[] = $toEmail . ': ' . $err;
                $errors++;
            }
        }

        // Close SMTP connection after the batch — avoids leaking open connections
        // on servers that limit concurrent SMTP sessions.
        if (method_exists($transport, 'stop')) {
            try { $transport->stop(); } catch (\Throwable $e) {}
        }

        // Unlink per-request temp files.
        foreach ($batchTempFiles as $att) {
            @unlink($att['path']);
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
            Config::setConfigurationValues(self::CONFIG_CONTEXT, [
                'queue_' . $sendId => json_encode(array_merge($job, [
                    'prev_sent'   => $runSent,
                    'prev_errors' => $runErrors,
                ])),
            ]);
        }

        if ($done) {
            self::cleanupJob($sendId);
            self::addHistory($subject, $runSent, $runErrors);
            self::recordSendCompleted();
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
                'name'    => ['LIKE', 'queue_%'],
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

    // ─── Send history ────────────────────────────────────────────────────

    /** Appends one entry to the send history (last 10 sends). */
    public static function addHistory(string $subject, int $sent, int $errors): void
    {
        $cfg  = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['send_history']);
        $list = json_decode((string) ($cfg['send_history'] ?? '[]'), true);
        if (!is_array($list)) $list = [];

        array_unshift($list, [
            'date'    => date('Y-m-d H:i'),
            'subject' => strip_tags(html_entity_decode($subject, ENT_QUOTES, 'UTF-8')),
            'sent'    => $sent,
            'errors'  => $errors,
        ]);

        $list = array_slice($list, 0, 10);
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [
            'send_history' => json_encode($list),
        ]);
    }

    /** Returns the last 10 sends as array. */
    public static function getHistory(): array
    {
        $cfg  = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['send_history']);
        $list = json_decode((string) ($cfg['send_history'] ?? '[]'), true);
        return is_array($list) ? $list : [];
    }

    // ─── Cooldown protection ─────────────────────────────────────────────

    /**
     * Returns an error string if a new send cannot start yet, null otherwise.
     * Prevents accidental duplicate mass sends from concurrent browser tabs.
     */
    public static function checkCooldown(int $cooldownSeconds = 30): ?string
    {
        $cfg     = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['last_send_at']);
        $lastAt  = (int) ($cfg['last_send_at'] ?? 0);
        $elapsed = time() - $lastAt;

        if ($lastAt > 0 && $elapsed < $cooldownSeconds) {
            $remaining = $cooldownSeconds - $elapsed;
            return sprintf(
                __('Please wait %d seconds before sending again.', 'mailblast'),
                $remaining
            );
        }

        return null;
    }

    /** Records the completion timestamp of a mass send for cooldown enforcement. */
    public static function recordSendCompleted(): void
    {
        Config::setConfigurationValues(self::CONFIG_CONTEXT, ['last_send_at' => time()]);
    }

    // ─── Upload validation ────────────────────────────────────────────────

    /**
     * Validates a multi-file upload from $_FILES.
     *
     * @param  array $files        The $_FILES['field'] structure (multi-file).
     * @param  array $allowedMimes MIME types allowed by GLPI.
     * @return array{accepted: array<array{tmp:string,name:string,mime:string}>, rejected: string[]}
     */
    public static function validateUploadedFiles(array $files, array $allowedMimes): array
    {
        $accepted   = [];
        $rejected   = [];
        $maxBytes   = self::getMaxAttachmentMb() * 1024 * 1024;
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
    public static function extractInlineImages(string $html): array
    {
        $pattern = "/(<img[^>]+src=[\"'])([^\"']*?docid=(\\d+)[^\"']*?)([\"'][^>]*>)/i";
        $images  = [];

        $html = (string) preg_replace_callback(
            $pattern,
            function (array $m) use (&$images) {
                $docId  = (int) $m[3];
                $result = self::docIdToBytes($docId);

                if ($result === null) {
                    return $m[0];
                }

                self::purgeDocument($docId);

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

    private static function purgeDocument(int $docId): void
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => ['filepath'],
            'FROM'   => 'glpi_documents',
            'WHERE'  => ['id' => $docId],
        ]);

        if ($iterator->count()) {
            $filepath = (string) ($iterator->current()['filepath'] ?? '');
            if ($filepath !== '') {
                $fullPath = realpath(GLPI_DOC_DIR . '/' . $filepath);
                $docBase  = realpath(GLPI_DOC_DIR);
                // Only delete if resolved path stays inside GLPI_DOC_DIR
                if ($fullPath !== false && $docBase !== false
                    && str_starts_with($fullPath, $docBase . DIRECTORY_SEPARATOR)) {
                    @unlink($fullPath);
                }
            }
        }

        $DB->delete('glpi_documents', ['id' => $docId]);
    }

    private static function docIdToBytes(int $docId): ?array
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
        array  $attachments      = [],
        array  $inlineImages     = [],
        string $fromOverride     = '',
        string $fromNameOverride = ''
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

            // Override From with entity email if configured
            if ($fromOverride !== '' && filter_var($fromOverride, FILTER_VALIDATE_EMAIL)) {
                $email->from(new \Symfony\Component\Mime\Address($fromOverride, $fromNameOverride ?: $fromOverride));
            }

            // Envelope sender (smtp_sender), if configured
            $smtpSender = trim((string) ($CFG_GLPI['smtp_sender'] ?? ''));
            if ($smtpSender !== '' && filter_var($smtpSender, FILTER_VALIDATE_EMAIL)) {
                $email->sender(new \Symfony\Component\Mime\Address($smtpSender));
            }

            $email->to(new \Symfony\Component\Mime\Address($toEmail, $toName))
                  ->subject($subject)
                  ->text($plain);

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

            if ($inlineImages) {
                foreach ($inlineImages as $img) {
                    $email->embed($img['bytes'], $img['cid'], $img['mime']);
                }
                $allParts = $email->getAttachments();
                $imgStart = count($allParts) - count($inlineImages);
                $cidMap   = [];
                foreach ($inlineImages as $i => $img) {
                    $cidMap['cid:' . $img['cid']] = 'cid:' . $allParts[$imgStart + $i]->getContentId();
                }
                $html = str_replace(array_keys($cidMap), array_values($cidMap), $html);
            }

            $email->html($html);
            $transport->send($email);
            return null;

        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Converts HTML to plain text for the email AltBody. */
    public static function html2text(string $html): string
    {
        // Block-level elements → newlines before stripping tags
        $text = preg_replace('/<br\s*\/?>/i',          "\n",       $html);
        $text = preg_replace('/<\/p>/i',                "\n\n",    $text);
        $text = preg_replace('/<\/(?:div|section)>/i',  "\n",       $text);
        $text = preg_replace('/<hr[^>]*>/i',             "\n---\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i',           "\n\n",    $text);
        $text = preg_replace('/<h[1-6][^>]*>/i',         "\n",       $text);
        $text = preg_replace('/<\/tr>/i',               "\n",       $text);
        $text = preg_replace('/<\/li>/i',               "\n",       $text);
        $text = preg_replace('/<li[^>]*>/i',             "  • ",      $text);
        $text = preg_replace('/<\/t[dh]>/i',              "\t",        $text);
        $text = preg_replace('/<t[dh][^>]*>/i',           '',          $text);

        // Strip remaining tags, then decode entities
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalise whitespace
        $text = preg_replace('/[ \t]+/', ' ',        $text);
        $text = preg_replace('/\n{3,}/', "\n\n",   $text);

        return trim($text);
    }

    /** Appends the HTML footer to the body and returns a well-formed HTML email. */
    public static function buildHtmlBody(string $htmlBody, string $footer): string
    {
        if (trim(strip_tags($footer)) !== '') {
            $htmlBody .= '<br>'
                . '<hr style="border:none;border-top:1px solid #cccccc;margin:24px 0">'
                . '<div style="color:#666666;font-size:12px;line-height:1.5">'
                . $footer
                . '</div>';
        }

        // Wrap in a minimal but valid HTML5 document so email clients
        // interpret charset and base font correctly.
        return '<!DOCTYPE html>'
            . '<html><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '</head><body style="margin:0;padding:16px;font-family:sans-serif;font-size:14px;line-height:1.6;color:#333333">'
            . $htmlBody
            . '</body></html>';
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
        $inlineResult = self::extractInlineImages($htmlBody);
        $htmlBody     = $inlineResult['html'];
        $inlineImages = [];
        foreach ($inlineResult['inlineImages'] as $img) {
            $imgBytes = base64_decode($img['data'], true);
            if ($imgBytes !== false && $imgBytes !== '') {
                $inlineImages[] = ['cid' => $img['cid'], 'bytes' => $imgBytes, 'mime' => $img['mime']];
            }
        }
        $fullHtml  = self::buildHtmlBody($htmlBody, $footer);
        $plainText = self::html2text($fullHtml);

        $attPaths = array_map(
            fn($a) => ['path' => $a['tmp'], 'name' => $a['name'], 'mime' => $a['mime']],
            $attachments
        );

        // sendMails is only called for test sends — single recipient always.
        $recipients = [['email' => $testEmail, 'name' => $testEmail]];

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
                $transport, $toEmail, $toName, $subject, $fullHtml, $plainText, $attPaths, $inlineImages
            );

            if ($err === null) {
                $sent++;
            } else {
                $errors[] = $toEmail . ': ' . $err;
            }


        }

        try { $transport->stop(); } catch (\Throwable $e) {}

        return ['sent' => $sent, 'errors' => $errors, 'total' => count($recipients)];
    }
}
