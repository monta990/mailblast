<?php
/**
 * Mail Blast â€” front/send.php
 * Main compose & send page.
 */

// Buffer ALL output from the start so GLPI warnings or notices never corrupt
// AJAX JSON responses. Each AJAX action calls ob_end_clean() before outputting JSON,
// and the page render calls ob_end_flush() implicitly at script end.
ob_start();

// GLPI 11 always bootstraps via Symfony â€” GLPI_ROOT is defined before this file runs.
include_once GLPI_ROOT . '/inc/includes.php';

Session::checkRight('config', UPDATE);

// Discard all output buffers (including GLPI warnings) before sending JSON.
// Called before every JSON response to prevent HTML corruption.
function mb_clean_buffers(): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

// â”€â”€â”€ Handle POST â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF is validated automatically by GLPI (csrf_compliant hook in setup.php)

    $subject = trim(strip_tags($_POST['subject'] ?? ''));
    $body    = (string) ($_POST['body']   ?? '');  // HTML from TinyMCE â€” do NOT strip_tags
    $rawFooter = (string) ($_POST['footer'] ?? '');
    $rawFooter = strip_tags($rawFooter, ['b', 'i', 'u', 'strong', 'em', 'br']);
    $footer    = trim((string) preg_replace('/<(b|i|u|strong|em|br)(\s[^>]*)>/i', '<$1>', $rawFooter));
    $action  = (string) ($_POST['action'] ?? '');

    // â”€â”€ AJAX actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Handled first, always exit with JSON, never reach Html::back().

    if ($action === 'test_send') {
        // Capture any accidental output (e.g. GLPI warnings) so we always return clean JSON.

        // Persist subject + footer (body not persisted by design)
        PluginMailblastMailblast::saveFormConfig($subject, $body, $footer);

        if ($subject === '') {
            mb_clean_buffers();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => __('Subject is required', 'mailblast'), 'csrf' => Session::getNewCSRFToken()]);
            exit;
        }

        $testMode  = (string) ($_POST['test_mode'] ?? 'my_address');
        $testEmail = '';

        $testEmails = [];
        if ($testMode === 'specific') {
            $raw = trim((string) ($_POST['test_email'] ?? ''));
            if ($raw === '') {
                mb_clean_buffers();
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => __('Test address is required', 'mailblast'), 'csrf' => Session::getNewCSRFToken()]);
                exit;
            }
            // Parse comma-separated addresses, max 5
            $candidates = array_slice(array_map('trim', explode(',', $raw)), 0, 5);
            $invalid    = [];
            foreach ($candidates as $addr) {
                if ($addr === '') continue;
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    $invalid[] = $addr;
                } else {
                    $testEmails[] = $addr;
                }
            }
            if (empty($testEmails)) {
                mb_clean_buffers();
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => __('Test address is required', 'mailblast'), 'csrf' => Session::getNewCSRFToken()]);
                exit;
            }
        } else {
            $single = UserEmail::getDefaultForUser((int) $_SESSION['glpiID']);
            if (empty($single)) {
                mb_clean_buffers();
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => __('No email found for your account', 'mailblast'), 'csrf' => Session::getNewCSRFToken()]);
                exit;
            }
            $testEmails = [$single];
        }

        // Decode base64 attachments from JS RAM into per-request temp files
        $attRaw  = (string) ($_POST['attachments_b64'] ?? '');
        $attB64  = $attRaw !== '' ? (json_decode($attRaw, true) ?? []) : [];
        $tmpAtts = [];
        foreach ($attB64 as $att) {
            $bytes = base64_decode($att['data'] ?? '', true);
            if ($bytes === false || $bytes === '') continue;
            $tmp = @tempnam(sys_get_temp_dir(), 'mb_test_');
            if ($tmp !== false && @file_put_contents($tmp, $bytes) !== false) {
                $realMime  = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
                $tmpAtts[] = ['tmp' => $tmp, 'name' => $att['name'], 'mime' => $realMime];
            }
        }

        // Send to each test address
        $totalSent   = 0;
        $allErrors   = [];
        foreach ($testEmails as $testEmail) {
            $result = PluginMailblastMailblast::sendMails(
                $subject, $body, $footer, $tmpAtts, true, $testEmail
            );
            $totalSent += $result['sent'];
            $allErrors  = array_merge($allErrors, $result['errors']);
        }

        foreach ($tmpAtts as $t) { @unlink($t['tmp']); }

        mb_clean_buffers();
        header('Content-Type: application/json');
        $newToken = Session::getNewCSRFToken();
        if ($totalSent > 0) {
            echo json_encode(['ok' => true, 'errors' => $allErrors, 'csrf' => $newToken]);
        } else {
            $errDetail = !empty($allErrors) ? implode('; ', $allErrors) : '';
            echo json_encode(['ok' => false, 'error' => __('Test failed', 'mailblast') . ($errDetail ? ': ' . $errDetail : ''), 'csrf' => $newToken]);
        }
        exit;
    }

    if ($action === 'queue_init') {
        PluginMailblastMailblast::saveFormConfig($subject, $body, $footer);

        // Cooldown check â€” prevents accidental duplicate sends from concurrent tabs.
        $cooldownErr = PluginMailblastMailblast::checkCooldown();
        if ($cooldownErr !== null) {
            mb_clean_buffers();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $cooldownErr, 'csrf' => Session::getNewCSRFToken()]);
            exit;
        }

        // Body size guard â€” the body HTML is re-posted on every batch call.
        $maxBodyBytes = PluginMailblastMailblast::getMaxAttachmentMb() * 1024 * 1024;
        if (strlen($body) > $maxBodyBytes) {
            mb_clean_buffers();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => __('Message body is too large.', 'mailblast'), 'csrf' => Session::getNewCSRFToken()]);
            exit;
        }

        // Decode base64 attachments from JS RAM into per-request temp files.
        // Attachments travel as base64 JSON (same pattern as test_send),
        // never via $_FILES â€” avoids browser issues with DataTransfer file inputs.
        $attRaw  = (string) ($_POST['attachments_b64'] ?? '');
        $attB64  = $attRaw !== '' ? (json_decode($attRaw, true) ?? []) : [];
        $tmpAtts = [];
        foreach ($attB64 as $att) {
            $bytes = base64_decode($att['data'] ?? '', true);
            if ($bytes === false || $bytes === '') continue;
            $tmp = @tempnam(sys_get_temp_dir(), 'mb_qi_');
            if ($tmp !== false && @file_put_contents($tmp, $bytes) !== false) {
                $realMime  = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
                $tmpAtts[] = ['tmp' => $tmp, 'name' => $att['name'], 'mime' => $realMime];
            }
        }

        $init = PluginMailblastMailblast::initQueue($subject, $body, $footer, $tmpAtts);

        foreach ($tmpAtts as $t) { @unlink($t['tmp']); }

        mb_clean_buffers();
        header('Content-Type: application/json');
        echo json_encode([
            'ok'              => true,
            'send_id'         => $init['send_id'],
            'total'           => $init['total'],
            'html'            => $init['html'],
            'plain'           => $init['plain'],
            'attachments_b64' => $init['attachments_b64'],
            'csrf'            => Session::getNewCSRFToken(),
        ]);
        exit;
    }

    if ($action === 'generate_report') {
        $rows    = array_slice(json_decode((string) ($_POST['rows'] ?? '[]'), true) ?? [], 0, 10000);
        $subject = trim(strip_tags((string) ($_POST['subject'] ?? '')));
        $stamp   = date('Y-m-d H:i');

        $spread = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spread->getProperties()
            ->setCreator('Mail Blast - GLPI plugin')
            ->setTitle(__('Mail Blast sending report', 'mailblast'));

        $ws = $spread->getActiveSheet();
        $ws->setTitle(__('Report', 'mailblast'));

        // Header row
        $headers = [
            __('Date',    'mailblast'),
            __('Subject', 'mailblast'),
            __('Email',   'mailblast'),
            __('Status',  'mailblast'),
            __('Reason',  'mailblast'),
        ];
        foreach ($headers as $i => $h) {
            $ws->setCellValue([$i + 1, 1], $h);
        }

        // Bold + background on header
        $ws->getStyle('A1:E1')->getFont()->setBold(true);
        $ws->getStyle('A1:E1')->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF2F74B5');
        $ws->getStyle('A1:E1')->getFont()->getColor()
            ->setARGB('FFFFFFFF');

        // Data rows
        $sentLabel   = __('Sent',   'mailblast');
        $failedLabel = __('Failed', 'mailblast');
        $row = 2;
        foreach ($rows as $r) {
            $status = ($r['status'] ?? '') === 'sent' ? $sentLabel : $failedLabel;
            // Prefix cells with leading formula chars to prevent formula injection
            $safeEmail  = preg_replace('/^([=+\-@])/', "'" . '$1', (string)($r['email']  ?? ''));
            $safeReason = preg_replace('/^([=+\-@])/', "'" . '$1', (string)($r['reason'] ?? ''));
            $ws->setCellValue([1, $row], $stamp);
            $ws->setCellValue([2, $row], $subject);
            $ws->setCellValue([3, $row], $safeEmail);
            $ws->setCellValue([4, $row], $status);
            $ws->setCellValue([5, $row], $safeReason);

            // Zebra rows
            if ($row % 2 === 0) {
                $ws->getStyle("A{$row}:E{$row}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFDDEBF7');
            }
            $row++;
        }

        // Auto-width columns
        foreach (range('A', 'E') as $col) {
            $ws->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spread);
        $writer->save('php://output');
        $xlsx = ob_get_clean();

        mb_clean_buffers();
        header('Content-Type: application/json');
        echo json_encode([
            'ok'       => true,
            'data'     => base64_encode($xlsx),
            'filename' => 'mailblast_report_' . gmdate('Y-m-d_His') . '.xlsx',
            'csrf'     => Session::getNewCSRFToken(),
        ]);
        exit;
    }

    if ($action === 'queue_process') {
        $sendId = trim((string) ($_POST['send_id'] ?? ''));
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $html   = (string) ($_POST['html']  ?? '');
        $plain  = (string) ($_POST['plain'] ?? '');
        $attRaw = (string) ($_POST['attachments_b64'] ?? '');
        $attB64 = $attRaw !== '' ? (json_decode($attRaw, true) ?? []) : [];

        // Validate sendId: only hex chars and dashes, 8-40 chars
        if ($sendId === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $sendId)) {
            mb_clean_buffers();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => __('Missing send_id', 'mailblast')]);
            exit;
        }

        if (trim(strip_tags($html)) === '') {
            mb_clean_buffers();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => __('Body is required', 'mailblast')]);
            exit;
        }

        $result = PluginMailblastMailblast::processBatch($sendId, $html, $plain, $attB64, $offset, PluginMailblastMailblast::getBatchSize());
        $result['csrf'] = Session::getNewCSRFToken();
        mb_clean_buffers();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true] + $result);
        exit;
    }

    // â”€â”€ Standard form submit (action = 'test' or 'send_all') â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    PluginMailblastMailblast::saveFormConfig($subject, $body, $footer);

    $hasError = false;

    if ($subject === '') {
        Session::addMessageAfterRedirect(
            __('Subject is required', 'mailblast'),
            false,
            ERROR
        );
        $hasError = true;
    }

    if (trim(strip_tags($body)) === '') {
        Session::addMessageAfterRedirect(
            __('Body is required', 'mailblast'),
            false,
            ERROR
        );
        $hasError = true;
    }

    $attachments = [];

    if (!$hasError && !empty($_FILES['attachments']['name'][0])) {
        $docTypes = PluginMailblastMailblast::getAllowedDocumentTypes();
        $result   = PluginMailblastMailblast::validateUploadedFiles(
            $_FILES['attachments'],
            $docTypes['mimes']
        );

        $attachments = $result['accepted'];

        foreach ($result['rejected'] as $errMsg) {
            Session::addMessageAfterRedirect(
                __('Attachment rejected', 'mailblast') . ': ' . $errMsg,
                false,
                ERROR
            );
            $hasError = true;
        }
    }

    if (!$hasError) {

        if ($action === 'test') {

            $testMode = (string) ($_POST['test_mode'] ?? 'my_address');

            if ($testMode === 'specific') {
                $testEmail = trim((string) ($_POST['test_email'] ?? ''));
                if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    Session::addMessageAfterRedirect(
                        __('Test address is required', 'mailblast'),
                        false,
                        ERROR
                    );
                    $hasError = true;
                }
            } else {
                $testEmail = UserEmail::getDefaultForUser((int) $_SESSION['glpiID']);
                if (empty($testEmail)) {
                    Session::addMessageAfterRedirect(
                        __('No email found for your account', 'mailblast'),
                        false,
                        ERROR
                    );
                    $hasError = true;
                }
            }

            if (!$hasError) {
                $result = PluginMailblastMailblast::sendMails(
                    $subject, $body, $footer, $attachments, true, $testEmail
                );

                if ($result['sent'] > 0) {
                    Session::addMessageAfterRedirect(
                        __('Test sent successfully', 'mailblast'),
                        false,
                        INFO
                    );
                } else {
                    $errDetail = !empty($result['errors'])
                        ? ': ' . implode('; ', $result['errors'])
                        : '';
                    Session::addMessageAfterRedirect(
                        __('Test failed', 'mailblast') . $errDetail,
                        false,
                        ERROR
                    );
                }

                if (!empty($result['errors']) && $result['sent'] > 0) {
                    foreach ($result['errors'] as $attErr) {
                        Session::addMessageAfterRedirect(
                            __('Attachment warning', 'mailblast') . ': ' . $attErr,
                            false,
                            WARNING
                        );
                    }
                }
            }
        }

        if ($action === 'send_all') {
            Session::addMessageAfterRedirect(
                __('Mass send requires JavaScript to be enabled.', 'mailblast'),
                false,
                ERROR
            );
        }
    }

    Html::back();
    exit;
}

// â”€â”€â”€ GET: render form â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

$mb_body_rand  = uniqid();
$mb_body_id    = 'mb_body_' . $mb_body_rand;

$docTypes      = PluginMailblastMailblast::getAllowedDocumentTypes();
$userCount     = PluginMailblastMailblast::countActiveUsersWithEmail();
$myEmail       = UserEmail::getDefaultForUser((int) $_SESSION['glpiID']);
$savedForm     = PluginMailblastMailblast::loadFormConfig();
$cfgBatchDelay = PluginMailblastMailblast::getBatchDelayMs();
$cfgMaxAttMb   = PluginMailblastMailblast::getMaxAttachmentMb();
$formAction    = plugin_mailblast_web_dir() . '/front/send.php';
$pluginWebDir  = plugin_mailblast_web_dir();

Html::header(__('Mail Blast', 'mailblast'), $_SERVER['PHP_SELF'], 'admin', 'PluginMailblastMailblast');
Html::displayMessageAfterRedirect();

// Textarea HTML (plain string — no ob needed)
$editorHtml = '<textarea class="form-control" name="body" id="' . $mb_body_id . '" rows="15">'
    . htmlspecialchars($savedForm['body'], ENT_QUOTES, 'UTF-8')
    . '</textarea>';

// initEditorSystem echoes its jQuery-ready config registration immediately.
// Must run BEFORE our custom scriptBlock so tinymce_editor_configs[id] exists
// when our setup function reads it.
echo Html::initEditorSystem(
    $mb_body_id, $mb_body_rand,
    true, false, true, 200, [], 'top', false, '', true, false, '', false, ['link']
);

// Sanitize footer for contenteditable (server-side restore)
$footerRaw  = strip_tags($savedForm['footer'], ['b', 'i', 'u', 'strong', 'em', 'br']);
$footerHtml = ($footerRaw !== ''
    && strpos($footerRaw, '<br') === false
    && strpos($footerRaw, '<p')  === false)
    ? nl2br($footerRaw)
    : $footerRaw;

// Attachment accept hint â€” extensions only, max 20
$extHints    = array_values(array_filter(
    explode(',', $docTypes['accept'] ?? ''),
    static fn(string $s): bool => str_starts_with(trim($s), '.')
));
$extHintsStr = implode('  ', array_slice($extHints, 0, 20));
if (count($extHints) > 20) {
    $extHintsStr .= '  â€¦';
}

// Inject runtime config for external JS (window.mbConfig)
echo Html::scriptBlock('window.mbConfig = ' . json_encode([
    'formAction' => $formAction,
    'batchDelay' => $cfgBatchDelay,
    'maxAttMb'   => $cfgMaxAttMb,
    'i18n'       => [
        'remove'          => __('Remove', 'mailblast'),
        'subjectRequired' => __('Subject is required', 'mailblast'),
        'bodyRequired'    => __('Body is required', 'mailblast'),
        'bytes'           => __('B', 'mailblast'),
        'kilobytes'       => __('KB', 'mailblast'),
        'megabytes'       => __('MB', 'mailblast'),
        'networkError'    => __('Network error', 'mailblast'),
        'jsInitError'     => __('Initialization error', 'mailblast'),
        'cancelling'      => __('Cancellingâ€¦', 'mailblast'),
        'cancelConfirm'   => __('Cancel sending? Emails already sent will not be recalled.', 'mailblast'),
        'badResponse'     => __('Bad server response', 'mailblast'),
        'serverError'     => __('Server error', 'mailblast'),
        'queueInitFail'   => __('Could not start sending', 'mailblast'),
        'queueBatchFail'  => __('Batch failed', 'mailblast'),
        'attSizeLimit'    => __('Attachment size limit exceeded (%s MB max). File not added: %s', 'mailblast'),
        'sendingCancelled'=> __('Sending cancelled.', 'mailblast'),
        'sendingFailed'   => __('Sending failed â€” no emails were delivered.', 'mailblast'),
        'sent'            => __('sent', 'mailblast'),
        'failed'          => __('failed', 'mailblast'),
        'allSent'         => __('All emails sent successfully.', 'mailblast'),
        'generating'      => __('Generatingâ€¦', 'mailblast'),
        'cancel'          => __('Cancel', 'mailblast'),
        'sendingEmails'   => __('Sending emails', 'mailblast'),
        'noActiveUsers'   => __('No active users with registered email found', 'mailblast'),
        'sending'         => __('Sendingâ€¦', 'mailblast'),
        'testSent'        => __('Test sent successfully', 'mailblast'),
        'testFailed'      => __('Test failed', 'mailblast'),
    ],
]) . ';
window._mbMaxAttMb = ' . (int) $cfgMaxAttMb . ';');

// TinyMCE toolbar extension + image-size-limit guard.
// Uses jQuery ($._data) wrapped in try/catch for jQuery 3.x compatibility.
$jsonBodyId      = json_encode($mb_body_id);
$jsonImgLimitMsg = json_encode(
    __('Image not inserted: combined size would exceed the %s MB limit set in Configuration.', 'mailblast')
);
echo Html::scriptBlock('
$(function() {
    var id   = ' . $jsonBodyId . ';
    var conf = tinymce_editor_configs[id];
    if (!conf) return;

    if (typeof conf.toolbar === "string") {
        conf.toolbar = conf.toolbar + " | alignleft aligncenter alignright alignjustify";
    }

    var _imgLimitMsg = ' . $jsonImgLimitMsg . ';
    var _origSetup   = conf.setup;
    conf.setup = function(editor) {
        if (_origSetup) _origSetup(editor);
        editor.on("init", function() {
            try {
                var handlers = ($._data ? ($._data(document, "events") || {}).click : null) || [];
                handlers.forEach(function(h) {
                    if (h.handler && h.handler.toString().indexOf("tox-tbtn--enabled") !== -1) {
                        var orig = h.handler;
                        h.handler = function(e) {
                            var enabled = $(".tox-tbtn.tox-tbtn--enabled");
                            enabled.removeClass("tox-tbtn--enabled");
                            orig.call(this, e);
                            enabled.addClass("tox-tbtn--enabled");
                        };
                    }
                });
            } catch(_e) {}

            window._mbEmbeddedBytes = window._mbEmbeddedBytes || 0;
            var _origUpload = editor.options.get("images_upload_handler");
            if (typeof _origUpload === "function") {
                editor.options.set("images_upload_handler", function(blobInfo, progress) {
                    var imgBytes = blobInfo.blob().size;
                    var attBytes = window._mbSelectedFiles
                        ? Array.from(window._mbSelectedFiles.files).reduce(function(s, f) { return s + f.size; }, 0)
                        : 0;
                    var limitBytes = (window._mbMaxAttMb || 15) * 1024 * 1024;
                    if (window._mbEmbeddedBytes + attBytes + imgBytes > limitBytes) {
                        return Promise.reject({ message: _imgLimitMsg.replace("%s", window._mbMaxAttMb || 15), remove: true });
                    }
                    return _origUpload(blobInfo, progress).then(function(location) {
                        window._mbEmbeddedBytes += imgBytes;
                        return location;
                    });
                });
            }
        });
    };

    tinyMCE.init(conf);
});
');

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@mailblast/send.html.twig', [
    'form_action'    => $formAction,
    'doc_types'      => $docTypes,
    'ext_hints_str'  => $extHintsStr,
    'user_count'     => $userCount,
    'my_email'       => $myEmail,
    'saved_form'     => $savedForm,
    'max_att_mb'     => $cfgMaxAttMb,
    'editor_html'    => $editorHtml,
    'footer_html'    => $footerHtml,
    'can_config'     => Session::haveRight('config', UPDATE),
    'plugin_web_dir' => $pluginWebDir,
    'csrf_token'     => Session::getNewCSRFToken(),
]);

// Inline JS — avoids GLPI 11 static-file routing issues for plugin subdirectories
echo '<script>';
readfile(__DIR__ . '/../js/mailblast_send.js');
echo '</script>';

Html::footer();
