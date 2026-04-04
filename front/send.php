<?php
/**
 * Mail Blast — front/send.php
 * Main compose & send page.
 */

// GLPI 11 always bootstraps via Symfony — GLPI_ROOT is defined before this file runs.
include_once GLPI_ROOT . '/inc/includes.php';

Session::checkRight('config', UPDATE);

// ─── Handle POST ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF is validated automatically by GLPI (csrf_compliant hook in setup.php)

    $subject = trim(strip_tags($_POST['subject'] ?? ''));
    $body    = (string) ($_POST['body']   ?? '');  // HTML from TinyMCE — do NOT strip_tags
    $footer  = trim((string) ($_POST['footer'] ?? ''));
    $action  = (string) ($_POST['action'] ?? '');

    // ── AJAX actions ─────────────────────────────────────────────────────
    // Handled first, always exit with JSON, never reach Html::back().

    if ($action === 'test_send') {
        // Capture any accidental output (e.g. GLPI warnings) so we always return clean JSON.
        ob_start();

        // Persist subject + footer (body not persisted by design)
        PluginMailblastMailblast::saveFormConfig($subject, $body, $footer);

        if ($subject === '') {
            ob_end_clean();
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
                ob_end_clean();
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
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => __('Test address is required', 'mailblast'), 'csrf' => Session::getNewCSRFToken()]);
                exit;
            }
        } else {
            $single = UserEmail::getDefaultForUser((int) $_SESSION['glpiID']);
            if (empty($single)) {
                ob_end_clean();
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
                $tmpAtts[] = ['tmp' => $tmp, 'name' => $att['name'], 'mime' => $att['mime']];
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

        ob_end_clean();
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
        ob_start();
        PluginMailblastMailblast::saveFormConfig($subject, $body, $footer);

        // Decode base64 attachments from JS RAM into per-request temp files.
        // Attachments travel as base64 JSON (same pattern as test_send),
        // never via $_FILES — avoids browser issues with DataTransfer file inputs.
        $attRaw  = (string) ($_POST['attachments_b64'] ?? '');
        $attB64  = $attRaw !== '' ? (json_decode($attRaw, true) ?? []) : [];
        $tmpAtts = [];
        foreach ($attB64 as $att) {
            $bytes = base64_decode($att['data'] ?? '', true);
            if ($bytes === false || $bytes === '') continue;
            $tmp = @tempnam(sys_get_temp_dir(), 'mb_qi_');
            if ($tmp !== false && @file_put_contents($tmp, $bytes) !== false) {
                $tmpAtts[] = ['tmp' => $tmp, 'name' => $att['name'], 'mime' => $att['mime']];
            }
        }

        $init = PluginMailblastMailblast::initQueue($subject, $body, $footer, $tmpAtts);

        foreach ($tmpAtts as $t) { @unlink($t['tmp']); }

        ob_end_clean();
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

    if ($action === 'queue_process') {
        ob_start();
        $sendId = trim((string) ($_POST['send_id'] ?? ''));
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $html   = (string) ($_POST['html']  ?? '');
        $plain  = (string) ($_POST['plain'] ?? '');
        $attRaw = (string) ($_POST['attachments_b64'] ?? '');
        $attB64 = $attRaw !== '' ? (json_decode($attRaw, true) ?? []) : [];

        if ($sendId === '') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => __('Missing send_id', 'mailblast')]);
            exit;
        }

        if (trim(strip_tags($html)) === '') {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => __('Body is required', 'mailblast')]);
            exit;
        }

        $result = PluginMailblastMailblast::processBatch($sendId, $html, $plain, $attB64, $offset, PluginMailblastMailblast::getBatchSize());
        $result['csrf'] = Session::getNewCSRFToken();
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true] + $result);
        exit;
    }

    // ── Standard form submit (action = 'test' or 'send_all') ─────────────

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
    }

    Html::back();
    exit;
}

// ─── GET: render form ─────────────────────────────────────────────────────────

Html::header(
    __('Mail Blast', 'mailblast'),
    $_SERVER['PHP_SELF'],
    'admin',
    'PluginMailblastMailblast'
);

Html::displayMessageAfterRedirect();

$docTypes   = PluginMailblastMailblast::getAllowedDocumentTypes();
$userCount  = PluginMailblastMailblast::countActiveUsersWithEmail();
$myEmail    = UserEmail::getDefaultForUser((int) $_SESSION['glpiID']);
$savedForm     = PluginMailblastMailblast::loadFormConfig();
$cfgBatchSize  = PluginMailblastMailblast::getBatchSize();
$cfgBatchDelay = PluginMailblastMailblast::getBatchDelayMs();
$cfgMaxAttMb   = PluginMailblastMailblast::getMaxAttachmentMb();

$formAction = Plugin::getWebDir('mailblast') . '/front/send.php';

?>
<div class="container-fluid">
  <div class="card mb-4">

    <div class="card-header d-flex align-items-center">
      <h3 class="card-title mb-0">
        <i class="ti ti-mail-forward me-2"></i>
        <?php echo __('Send bulk email', 'mailblast'); ?>
      </h3>
      <?php if (Session::haveRight('config', UPDATE)): ?>
      <a href="<?php echo Plugin::getWebDir('mailblast'); ?>/front/config.php"
         class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="ti ti-settings me-1"></i><?php echo __('Configuration', 'mailblast'); ?>
      </a>
      <?php endif; ?>
    </div>

    <div class="card-body">
      <form
        id="mb_sendForm"
        method="post"
        action="<?php echo htmlspecialchars($formAction, ENT_QUOTES); ?>"
        enctype="multipart/form-data"
      >
        <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>

        <!-- Inline validation message — replaces native alert() -->
        <div id="mb_formAlert" class="alert alert-danger py-2 mb-3" style="display:none" role="alert"></div>

        <!-- ── Compose ─────────────────────────────────────────────────── -->
        <h4 class="mb-3">
          <i class="ti ti-pencil me-1"></i>
          <?php echo __('Compose message', 'mailblast'); ?>
        </h4>

        <div class="mb-3">
          <label class="form-label fw-bold" for="mb_subject">
            <?php echo __('Subject', 'mailblast'); ?>
            <span class="text-danger">*</span>
          </label>
          <input
            id="mb_subject"
            type="text"
            name="subject"
            class="form-control"
            maxlength="250"
            value="<?php echo htmlspecialchars($savedForm['subject'], ENT_QUOTES, 'UTF-8'); ?>"
            required
          >
        </div>

        <div class="mb-3">
          <label class="form-label fw-bold">
            <?php echo __('Message body', 'mailblast'); ?>
            <span class="text-danger">*</span>
          </label>
          <?php
          $mb_body_rand = uniqid();
          $mb_body_id   = 'mb_body_' . $mb_body_rand;

          echo '<textarea class="form-control" name="body" id="' . $mb_body_id . '" rows="15">'
             . htmlspecialchars($savedForm['body'], ENT_QUOTES, 'UTF-8')
             . '</textarea>';
          // init=false: GLPI stores the config but does NOT call tinyMCE.init.
          // Our scriptBlock (registered after this one in the ready queue) modifies
          // the config and calls tinyMCE.init manually.
          echo Html::initEditorSystem(
              $mb_body_id, $mb_body_rand,
              true,   // display
              false,  // readonly
              true,   // enable_images
              200,    // editor_height (px)
              [],     // add_body_classes
              'top',  // toolbar_location
              false,  // init ← we init manually below
              '',     // placeholder
              true,   // toolbar
              false,  // statusbar
              '',     // content_style
              false,  // init_on_demand
              ['link'] // remove link plugin
          );
          // Our scriptBlock runs AFTER GLPI's in the jQuery ready queue.
          // We modify the config object and init manually.
          echo Html::scriptBlock('
$(function() {
    var id   = ' . json_encode($mb_body_id) . ';
    var conf = tinymce_editor_configs[id];
    if (!conf) return;

    // 1. Add alignment buttons to toolbar
    if (typeof conf.toolbar === "string") {
        conf.toolbar = conf.toolbar
            + " | alignleft aligncenter alignright alignjustify";
    }

    // 2. Fix GLPI\'s document click handler that removes formatting.
    //    GLPI registers a handler that calls .trigger("click") on active
    //    toolbar buttons (.tox-tbtn--enabled), which removes lists, indentation
    //    and formatting when clicking outside the editor.
    //    We wrap the setup to neutralise that specific behaviour after init.
    var _origSetup = conf.setup;
    conf.setup = function(editor) {
        if (_origSetup) _origSetup(editor);
        editor.on("init", function() {
            // Find and patch GLPI\'s anonymous click handler on document
            var handlers = ($._data(document, "events") || {}).click || [];
            handlers.forEach(function(h) {
                if (h.handler.toString().indexOf("tox-tbtn--enabled") !== -1) {
                    var orig = h.handler;
                    h.handler = function(e) {
                        // Temporarily remove --enabled so trigger("click")
                        // acts on buttons with no active state → no-op for format
                        var enabled = $(".tox-tbtn.tox-tbtn--enabled");
                        enabled.removeClass("tox-tbtn--enabled");
                        orig.call(this, e);
                        enabled.addClass("tox-tbtn--enabled");
                    };
                }
            });
        });
    };

    tinyMCE.init(conf);
});
          ');
          ?>
        </div>

        <div class="mb-4">
          <label class="form-label fw-bold" for="mb_footer">
            <?php echo __('Footer', 'mailblast'); ?>
          </label>
          <!-- contenteditable footer — renders B/I/U visually, preserves line breaks.
               A hidden textarea syncs the HTML value for form submission. -->
          <div class="border rounded" style="overflow:hidden">
            <div class="d-flex gap-1 p-1 border-bottom" id="mb_footerToolbar">
              <button type="button" class="btn btn-sm btn-ghost-secondary fw-bold px-2" data-cmd="bold"
                title="<?php echo htmlspecialchars(__('Bold', 'mailblast'), ENT_QUOTES); ?>"><b>N</b></button>
              <button type="button" class="btn btn-sm btn-ghost-secondary fst-italic px-2" data-cmd="italic"
                title="<?php echo htmlspecialchars(__('Italic', 'mailblast'), ENT_QUOTES); ?>"><i>C</i></button>
              <button type="button" class="btn btn-sm btn-ghost-secondary px-2" data-cmd="underline"
                title="<?php echo htmlspecialchars(__('Underline', 'mailblast'), ENT_QUOTES); ?>"
                style="text-decoration:underline">S</button>
            </div>
            <div
              id="mb_footerEdit"
              contenteditable="true"
              spellcheck="true"
              class="p-2"
              style="min-height:80px;outline:none;font-family:inherit;font-size:inherit;line-height:1.5;white-space:pre-wrap;word-break:break-word"
            ><?php
              // Convert saved HTML to safe contenteditable content
              $footerHtml = $savedForm['footer'];
              // Restore line breaks as <br> if not already present
              if ($footerHtml !== '' && strpos($footerHtml, '<br') === false && strpos($footerHtml, '<p') === false) {
                  $footerHtml = nl2br($footerHtml);
              }
              echo $footerHtml;
            ?></div>
            <!-- Hidden textarea synced on every change for form submission -->
            <textarea name="footer" id="mb_footer" class="d-none"></textarea>
          </div>
        </div>

        <hr class="my-4">

        <!-- ── Attachments ─────────────────────────────────────────────── -->
        <h4 class="mb-3">
          <i class="ti ti-paperclip me-1"></i>
          <?php echo __('Attachments', 'mailblast'); ?>
        </h4>

        <p class="text-muted mb-2 small">
          <i class="ti ti-shield-check me-1 text-success"></i>
          <?php echo __('Only file types allowed by GLPI are accepted', 'mailblast'); ?>
        </p>

        <!-- Allowed types summary (collapsible) -->
        <?php if (!empty($docTypes['types'])): ?>
        <div class="mb-3">
          <a
            class="btn btn-sm btn-outline-secondary"
            data-bs-toggle="collapse"
            href="#mb_allowedTypes"
            role="button"
            aria-expanded="false"
          >
            <i class="ti ti-list me-1"></i>
            <?php echo __('View allowed file types', 'mailblast'); ?>
            <span style="background:#2fb344;color:#fff;padding:.15rem .45rem;border-radius:20px;font-size:.75rem;font-weight:700;margin-left:.25rem"><?php echo count($docTypes['types']); ?></span>
          </a>
          <div class="collapse mt-2" id="mb_allowedTypes">
            <div class="border rounded p-2" style="max-height:180px;overflow-y:auto">
              <div class="row row-cols-2 row-cols-md-4 g-1">
                <?php foreach ($docTypes['types'] as $t): ?>
                  <div class="col">
                    <span class="mb-badge-type w-100 text-start">
                      <code class="me-1">.<?php echo htmlspecialchars($t['ext'], ENT_QUOTES); ?></code>
                      <small class="text-muted"><?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?></small>
                    </span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Drop zone / file picker -->
        <div
          id="mb_dropZone"
          class="mb-2 rounded border border-2 border-dashed d-flex flex-column align-items-center justify-content-center gap-2 p-4"
          style="cursor:pointer;min-height:120px;border-color:var(--tblr-border-color)!important;transition:background .15s"
        >
          <i class="ti ti-cloud-upload fs-1 text-muted"></i>
          <p class="mb-0 text-muted">
            <?php echo __('Drag & drop files here or', 'mailblast'); ?>
            <span class="text-primary text-decoration-underline" style="cursor:pointer" id="mb_browseLabel">
              <?php echo __('browse', 'mailblast'); ?>
            </span>
          </p>
          <p class="mb-0 small text-muted" id="mb_acceptHint">
            <?php
            if (!empty($docTypes['accept'])) {
                // Show only extensions in the hint (cleaner than full MIME list)
                $extHints = array_filter(
                    explode(',', $docTypes['accept']),
                    fn($s) => str_starts_with($s, '.')
                );
                echo htmlspecialchars(implode('  ', array_slice($extHints, 0, 20)), ENT_QUOTES);
                if (count($extHints) > 20) {
                    echo '  …';
                }
            }
            ?>
          </p>
          <input
            id="mb_fileInput"
            type="file"
            name="attachments[]"
            multiple
            accept="<?php echo htmlspecialchars($docTypes['accept'], ENT_QUOTES); ?>"
            class="d-none"
          >
        </div>

        <!-- Attached file list -->
        <ul id="mb_fileList" class="list-group mb-4" style="display:none!important"></ul>

        <hr class="my-4">

        <!-- ── Test email ──────────────────────────────────────────────── -->
        <h4 class="mb-3">
          <i class="ti ti-flask me-1"></i>
          <?php echo __('Test email', 'mailblast'); ?>
        </h4>

        <div class="mb-3">
          <div class="form-check mb-2">
            <input
              class="form-check-input"
              type="radio"
              name="test_mode"
              id="mb_testMe"
              value="my_address"
              checked
            >
            <label class="form-check-label" for="mb_testMe">
              <?php echo __('Send to my address (administrator)', 'mailblast'); ?>
              <?php if (!empty($myEmail)): ?>
                <span class="mb-email-badge ms-1">
                  <?php echo htmlspecialchars($myEmail, ENT_QUOTES, 'UTF-8'); ?>
                </span>
              <?php endif; ?>
            </label>
          </div>
          <div class="form-check">
            <input
              class="form-check-input"
              type="radio"
              name="test_mode"
              id="mb_testSpecific"
              value="specific"
            >
            <label class="form-check-label" for="mb_testSpecific">
              <?php echo __('Send to a specific address', 'mailblast'); ?>
            </label>
          </div>
        </div>

        <div id="mb_testEmailField" class="mb-3" style="display:none;max-width:480px">
          <label class="form-label fw-bold" for="mb_testEmail">
            <?php echo __('Test address', 'mailblast'); ?>
          </label>
          <input
            id="mb_testEmail"
            type="text"
            name="test_email"
            class="form-control"
            placeholder="<?php echo htmlspecialchars(__('user@company.com, other@company.com', 'mailblast'), ENT_QUOTES); ?>"
            autocomplete="off"
          >
          <div class="form-text text-muted">
            <?php echo __('Separate multiple addresses with commas. Maximum 5 addresses.', 'mailblast'); ?>
          </div>
        </div>

        <div class="mb-4">
          <button type="button" id="mb_sendTest" class="btn btn-secondary">
            <i class="ti ti-send me-1"></i>
            <?php echo __('Send test email', 'mailblast'); ?>
          </button>
        </div>

        <hr class="my-4">

        <!-- ── Mass mailing ────────────────────────────────────────────── -->
        <h4 class="mb-3">
          <i class="ti ti-users me-1"></i>
          <?php echo __('Mass mailing', 'mailblast'); ?>
        </h4>

        <?php if ($userCount > 0): ?>
          <p class="mb-3 d-flex align-items-center gap-2">
            <i class="ti ti-circle-check text-success fs-4"></i>
            <span>
              <?php echo __('Active users with registered email', 'mailblast'); ?>:
              <span class="badge mb-count-badge ms-1"><?php echo $userCount; ?></span>
            </span>
          </p>
        <?php else: ?>
          <p class="text-warning mb-3">
            <i class="ti ti-alert-triangle me-1"></i>
            <?php echo __('No active users with registered email found', 'mailblast'); ?>
          </p>
        <?php endif; ?>

        <button
          type="button"
          class="btn btn-danger"
          id="mb_sendAll"
          <?php echo $userCount === 0 ? 'disabled' : ''; ?>
        >
          <i class="ti ti-mail-forward me-1"></i>
          <?php echo __('Send to all users', 'mailblast'); ?>
        </button>


        <!-- ── Confirmation modal ───────────────────────────────────────── -->
        <div class="modal fade" id="mb_confirmModal" tabindex="-1"
             data-bs-backdrop="static" data-bs-keyboard="false"
             aria-labelledby="mb_confirmLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="mb_confirmLabel">
                  <i class="ti ti-mail-forward me-2 text-danger"></i>
                  <?php echo __('Send to all users', 'mailblast'); ?>
                </h5>
              </div>
              <div class="modal-body">
                <p class="mb-2"><?php echo __('You are about to send an email to', 'mailblast'); ?>:</p>
                <div class="d-flex align-items-center gap-3 p-3 rounded border">
                  <i class="ti ti-users fs-1 text-danger flex-shrink-0"></i>
                  <div>
                    <div class="fw-bold fs-3 lh-1" id="mb_confirmCount"><?php echo $userCount; ?></div>
                    <div class="text-muted small mt-1"><?php echo __('Active users with registered email', 'mailblast'); ?></div>
                  </div>
                </div>
                <p class="mt-3 mb-0 text-muted small">
                  <i class="ti ti-info-circle me-1"></i>
                  <?php echo __('This action cannot be undone. Each recipient will receive one email.', 'mailblast'); ?>
                </p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                  <?php echo __('Cancel', 'mailblast'); ?>
                </button>
                <button type="button" class="btn btn-danger" id="mb_confirmSend">
                  <i class="ti ti-send me-1"></i>
                  <?php echo __('Yes, send now', 'mailblast'); ?>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Progress modal (shown during mass-send) ────────────────── -->
        <div class="modal fade" id="mb_progressModal" tabindex="-1"
             data-bs-backdrop="static" data-bs-keyboard="false"
             aria-labelledby="mb_progressLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="mb_progressLabel">
                  <i class="ti ti-mail-forward me-2"></i>
                  <?php echo __('Sending emails', 'mailblast'); ?>
                </h5>
              </div>
              <div class="modal-body">

                <!-- Sending X of Y label -->
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span class="text-muted small"><?php echo __('Progress', 'mailblast'); ?></span>
                  <span class="small fw-bold" id="mb_progressLabel2">0 / 0</span>
                </div>

                <!-- Progress bar -->
                <div class="progress mb-3" style="height:22px;border-radius:6px">
                  <div
                    id="mb_progressBar"
                    class="progress-bar progress-bar-striped progress-bar-animated fw-semibold"
                    role="progressbar"
                    style="width:0%;font-size:.8rem"
                    aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"
                  >0%</div>
                </div>

                <!-- Live counters -->
                <div class="row text-center g-2 mb-3">
                  <div class="col-4">
                    <div class="text-success fw-bold fs-3" id="mb_countSent">0</div>
                    <div class="text-muted small"><?php echo __('Sent', 'mailblast'); ?></div>
                  </div>
                  <div class="col-4">
                    <div class="text-danger fw-bold fs-3" id="mb_countError">0</div>
                    <div class="text-muted small"><?php echo __('Errors', 'mailblast'); ?></div>
                  </div>
                  <div class="col-4">
                    <div class="text-warning fw-bold fs-3" id="mb_countPending">0</div>
                    <div class="text-muted small"><?php echo __('Pending', 'mailblast'); ?></div>
                  </div>
                </div>

                <!-- Elapsed time -->
                <div class="text-muted small text-center mb-2">
                  <i class="ti ti-clock me-1"></i>
                  <?php echo __('Elapsed', 'mailblast'); ?>:
                  <span id="mb_elapsed">0s</span>
                </div>

                <!-- Status line (info / warning / danger) -->
                <div id="mb_statusLine" class="alert py-2 small mb-3" style="display:none"></div>

                <!-- Per-address errors -->
                <div id="mb_errorSection" style="display:none">
                  <hr class="my-2">
                  <p class="text-danger small fw-semibold mb-1">
                    <i class="ti ti-alert-triangle me-1"></i>
                    <?php echo __('Failed addresses', 'mailblast'); ?>
                  </p>
                  <ul id="mb_errorList" class="list-unstyled small text-danger mb-0"
                      style="max-height:120px;overflow-y:auto"></ul>
                </div>

              </div>
              <div class="modal-footer">
                <!-- Cancel button — visible while sending, hidden after finish -->
                <button type="button" class="btn btn-outline-danger" id="mb_cancelSend">
                  <i class="ti ti-x me-1"></i>
                  <?php echo __('Cancel', 'mailblast'); ?>
                </button>
                <!-- Close button — hidden while sending, shown after finish -->
                <button type="button" class="btn btn-primary d-none" id="mb_closeProgress" data-bs-dismiss="modal">
                  <?php echo __('Close', 'mailblast'); ?>
                </button>
              </div>
            </div>
          </div>
        </div>

      </form>
    </div><!-- /card-body -->
  </div><!-- /card -->
</div>

<style>
/* Drop zone hover */
#mb_dropZone.dragover {
  background: rgba(var(--tblr-primary-rgb), .07) !important;
  border-color: var(--tblr-primary) !important;
}

/* File-type badges — theme-aware */
.mb-badge-type {
  display: inline-flex;
  align-items: center;
  gap: .25rem;
  padding: .25rem .45rem;
  border-radius: var(--tblr-border-radius-sm, 4px);
  font-size: .75rem;
  color: var(--tblr-body-color);
  border: 1px solid var(--tblr-border-color);
  line-height: 1.3;
}
.mb-badge-type code {
  color: var(--tblr-body-color);
  background: none;
  padding: 0;
  font-size: inherit;
  font-weight: 700;
}
.mb-badge-type small {
  color: var(--tblr-muted, var(--tblr-secondary));
}

/* Admin email badge — visible in both themes */
.mb-email-badge {
  display: inline-block;
  padding: .2rem .55rem;
  border-radius: var(--tblr-border-radius-sm, 4px);
  font-size: .75rem;
  font-weight: 500;
  background-color: var(--tblr-primary);
  color: #fff;
  vertical-align: middle;
  word-break: break-all;
}

/* User count badge — solid green, white text, visible in both themes */
.mb-count-badge {
  display: inline-block;
  padding: .15rem .5rem;
  border-radius: 20px;
  font-size: .8rem;
  font-weight: 700;
  background-color: #2fb344;
  color: #fff !important;
  vertical-align: middle;
  line-height: 1.4;
}

/* TinyMCE statusbar disabled via initEditorSystem(statusbar=false) */
</style>

<script>
(function () {
  'use strict';

  // ── Translatable strings (injected from PHP) ───────────────────────────
  const i18n = window._mbI18n = {
    remove:          <?php echo json_encode(__('Remove',                    'mailblast')); ?>,
    subjectRequired: <?php echo json_encode(__('Subject is required',       'mailblast')); ?>,
    bodyRequired:    <?php echo json_encode(__('Body is required',          'mailblast')); ?>,
    bytes:           <?php echo json_encode(__('B',                         'mailblast')); ?>,
    kilobytes:       <?php echo json_encode(__('KB',                        'mailblast')); ?>,
    megabytes:       <?php echo json_encode(__('MB',                        'mailblast')); ?>,
    networkError:    <?php echo json_encode(__('Network error',              'mailblast')); ?>,
    jsInitError:     <?php echo json_encode(__('Initialization error',       'mailblast')); ?>,
    cancelling:      <?php echo json_encode(__('Cancelling…',           'mailblast')); ?>,
    cancelConfirm:   <?php echo json_encode(__('Cancel sending? Emails already sent will not be recalled.', 'mailblast')); ?>,
    badResponse:     <?php echo json_encode(__('Bad server response',    'mailblast')); ?>,
    serverError:     <?php echo json_encode(__('Server error',           'mailblast')); ?>,
    queueInitFail:   <?php echo json_encode(__('Could not start sending','mailblast')); ?>,
    queueBatchFail:  <?php echo json_encode(__('Batch failed',           'mailblast')); ?>,
  };

  // Config values from PHP
  const _mbBatchDelay = <?php echo (int) $cfgBatchDelay; ?>;
  const _mbMaxAttMb   = <?php echo (int) $cfgMaxAttMb; ?>;

  // ── File management ────────────────────────────────────────────────────
  const input    = document.getElementById('mb_fileInput');
  const dropZone = document.getElementById('mb_dropZone');
  const fileList = document.getElementById('mb_fileList');

  // Click on drop zone or browse label opens picker
  dropZone.addEventListener('click', () => input.click());

  // Drag & drop
  dropZone.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.classList.add('dragover');
  });
  ['dragleave', 'dragend', 'drop'].forEach(ev =>
    dropZone.addEventListener(ev, () => dropZone.classList.remove('dragover'))
  );
  dropZone.addEventListener('drop', e => {
    e.preventDefault();
    mergeFiles(e.dataTransfer.files);
  });

  // Track selected files across multiple picker opens
  // Exposed on window so the test-send script (separate <script> block) can read it.
  let selectedFiles = window._mbSelectedFiles = new DataTransfer();

  input.addEventListener('change', () => {
    mergeFiles(input.files);
    input.value = ''; // allow re-selecting same file
  });

  function totalAttachmentSize() {
    return [...selectedFiles.files].reduce((sum, f) => sum + f.size, 0);
  }

  function mergeFiles(newFiles) {
    const limitBytes = _mbMaxAttMb * 1024 * 1024;
    for (const f of newFiles) {
      // Deduplicate by name+size
      const dup = [...selectedFiles.files].some(
        x => x.name === f.name && x.size === f.size
      );
      if (dup) continue;
      if (totalAttachmentSize() + f.size > limitBytes) {
        const msg = <?php echo json_encode(__('Attachment size limit exceeded (%s MB max). File not added: %s', 'mailblast')); ?>;
        alert(msg.replace('%s', _mbMaxAttMb).replace('%s', f.name));
        continue;
      }
      selectedFiles.items.add(f);
    }
    syncInput();
    renderList();
  }

  function removeFile(index) {
    const updated = new DataTransfer();
    [...selectedFiles.files].forEach((f, i) => {
      if (i !== index) updated.items.add(f);
    });
    selectedFiles = updated;
    window._mbSelectedFiles = selectedFiles;
    syncInput();
    renderList();
  }

  function syncInput() {
    // Keep the actual <input> in sync so it submits
    const dt = new DataTransfer();
    for (const f of selectedFiles.files) dt.items.add(f);
    input.files = dt.files;
  }

  function humanSize(bytes) {
    if (bytes < 1024)     return bytes + ' ' + i18n.bytes;
    if (bytes < 1048576)  return (bytes / 1024).toFixed(1) + ' ' + i18n.kilobytes;
    return (bytes / 1048576).toFixed(1) + ' ' + i18n.megabytes;
  }

  function iconForMime(mime) {
    if (mime.startsWith('image/'))       return 'ti-photo';
    if (mime === 'application/pdf')      return 'ti-file-type-pdf';
    if (mime.includes('word') || mime.includes('document')) return 'ti-file-type-doc';
    if (mime.includes('sheet') || mime.includes('excel'))   return 'ti-file-type-xls';
    if (mime.includes('presentation') || mime.includes('powerpoint')) return 'ti-file-type-ppt';
    if (mime.startsWith('text/'))        return 'ti-file-type-txt';
    if (mime.includes('zip') || mime.includes('compressed')) return 'ti-file-zip';
    return 'ti-file';
  }

  function renderList() {
    fileList.innerHTML = '';
    const files = [...selectedFiles.files];

    if (files.length === 0) {
      fileList.style.setProperty('display', 'none', 'important');
      return;
    }

    fileList.style.removeProperty('display');

    files.forEach((f, idx) => {
      const li = document.createElement('li');
      li.className = 'list-group-item d-flex align-items-center gap-2 py-2';
      li.innerHTML = `
        <i class="ti ${iconForMime(f.type)} text-muted fs-4 flex-shrink-0"></i>
        <span class="flex-grow-1 text-truncate" title="${escHtml(f.name)}">${escHtml(f.name)}</span>
        <small class="text-muted flex-shrink-0">${humanSize(f.size)}</small>
        <button type="button" class="btn btn-sm btn-ghost-danger ms-1 flex-shrink-0" data-idx="${idx}" title="${i18n.remove}">
          <i class="ti ti-x"></i>
        </button>
      `;
      li.querySelector('button').addEventListener('click', () => removeFile(idx));
      fileList.appendChild(li);
    });
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── Footer contenteditable toolbar ─────────────────────────────────────────
  (function() {
    const edit   = document.getElementById('mb_footerEdit');
    const hidden = document.getElementById('mb_footer');
    const toolbar = document.getElementById('mb_footerToolbar');
    if (!edit || !hidden || !toolbar) return;

    // Sync contenteditable → hidden textarea on every change
    function syncFooter() {
      hidden.value = edit.innerHTML;
    }
    edit.addEventListener('input', syncFooter);
    // Initial sync
    syncFooter();

    // Enter key inserts <br> instead of <div> (cleaner HTML for email)
    edit.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        document.execCommand('insertHTML', false, '<br>');
      }
    });

    // B/I/U buttons use execCommand (works reliably with contenteditable)
    toolbar.querySelectorAll('[data-cmd]').forEach(function(btn) {
      btn.addEventListener('mousedown', function(e) {
        e.preventDefault(); // keep focus in contenteditable
        document.execCommand(btn.dataset.cmd, false, null);
        syncFooter();
        edit.focus();
      });
    });
  }());

  // ── Test address toggle ────────────────────────────────────────────────
  const testMe       = document.getElementById('mb_testMe');
  const testSpecific = document.getElementById('mb_testSpecific');
  const testField    = document.getElementById('mb_testEmailField');

  testSpecific?.addEventListener('change', () => {
    testField.style.display = testSpecific.checked ? 'block' : 'none';
  });
  testMe?.addEventListener('change', () => {
    testField.style.display = 'none';
  });


  // ── Mass-send: validate → confirmation modal → progress modal ────────
  (function () {
    'use strict';

    let _confirmModal  = null;
    let _progressModal = null;
    let _cancelBound   = false;   // cancel listener added only once
    let _cancelled     = false;
    let _ticker        = null;

    function $$(id) { return document.getElementById(id); }
    function csrfToken() { var el = document.querySelector('input[name="_glpi_csrf_token"]'); return el ? el.value : ''; }
    function updateCsrf(token) {
      if (!token) return;
      var el = document.querySelector('input[name="_glpi_csrf_token"]');
      if (el) el.value = token;
    }

    function showFormAlert(msg) {
      var el = $$('mb_formAlert');
      if (!el) { return; }
      el.textContent = msg;
      el.style.display = '';
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function hideFormAlert() {
      var el = $$('mb_formAlert'); if (el) el.style.display = 'none';
    }

    // ── helpers ──────────────────────────────────────────────────────────

    function setStatus(msg, type) {
      var sl = $$('mb_statusLine');
      if (!sl) return;
      if (!msg) { sl.style.display = 'none'; return; }
      sl.className   = 'alert py-2 small mb-3 alert-' + (type || 'info');
      sl.textContent = msg;
      sl.style.display = '';
    }

    function setCounters(sent, errors, pending) {
      var eS = $$('mb_countSent');  if (eS) eS.textContent = sent;
      var eE = $$('mb_countError'); if (eE) eE.textContent = errors;
      var eP = $$('mb_countPending'); if (eP) eP.textContent = pending;
    }

    function setBar(pct) {
      var b = $$('mb_progressBar');
      if (!b) return;
      b.style.width = pct + '%';
      b.textContent = pct + '%';
      b.setAttribute('aria-valuenow', pct);
    }

    function addErrorItem(msg) {
      var es = $$('mb_errorSection');
      var el = $$('mb_errorList');
      if (es) es.style.display = '';
      if (el) { var li = document.createElement('li'); li.textContent = msg; el.appendChild(li); }
    }

    // ── finish: stop ticker, swap buttons, colour bar ────────────────────

    function finish(errMsg) {
      if (_ticker) { clearInterval(_ticker); _ticker = null; }

      // Swap Cancel → Close
      var cb = $$('mb_cancelSend');
      var cl = $$('mb_closeProgress');
      if (cb) cb.classList.add('d-none');
      if (cl) cl.classList.remove('d-none');

      // Colour bar
      var b = $$('mb_progressBar');
      if (b) {
        b.classList.remove('progress-bar-animated', 'progress-bar-striped');
        if (_cancelled)         b.classList.add('bg-secondary');
        else if (errMsg)        b.classList.add('bg-danger');
        else                    b.classList.add('bg-success');
      }

      if (errMsg)    addErrorItem(errMsg);
      if (_cancelled) setStatus(<?php echo json_encode(__('Sending cancelled.', 'mailblast')); ?>, 'warning');
    }

    // ── safe fetch wrapper ───────────────────────────────────────────────

    function doFetch(fd, onOk, onFail) {
      var url = <?php echo json_encode($formAction); ?>;
      fetch(url, { method: 'POST', body: fd })
        .then(function(r) { return r.text(); })
        .then(function(text) {
          var data;
          try { data = JSON.parse(text); } catch(e) {
            onFail((i18n.badResponse || 'Bad server response') + ': ' + text.substring(0, 150));
            return;
          }
          if (!data.ok) { onFail(data.error || i18n.serverError || 'Server error'); return; }
          onOk(data);
        })
        .catch(function(e) { onFail((i18n.networkError || 'Network error') + ': ' + (e.message || e)); });
    }

    // ── batch loop ───────────────────────────────────────────────────────

    let _sendId = '', _qHtml = '', _qPlain = '', _qAttB64 = [];
    let _totalSent = 0, _totalErrors = 0, _total = 0, _startTime = 0;

    function processNext(offset) {
      if (_cancelled) { finish(); return; }

      var fd = new FormData();
      fd.append('_glpi_csrf_token', csrfToken());
      fd.append('action',           'queue_process');
      fd.append('send_id',          _sendId);
      fd.append('offset',           offset);
      fd.append('html',             _qHtml);
      fd.append('plain',            _qPlain);
      fd.append('attachments_b64',  JSON.stringify(_qAttB64));

      doFetch(fd,
        function(data) {
          if (_cancelled) { finish(); return; }
          updateCsrf(data.csrf);
          _totalSent   += data.sent   || 0;
          _totalErrors += data.errors || 0;
          var processed = Math.min(data.next_offset || offset, _total);
          var pct = _total > 0 ? Math.round((processed / _total) * 100) : 0;
          setBar(pct);
          setCounters(_totalSent, _totalErrors, Math.max(0, _total - processed));
          var lbl = $$('mb_progressLabel2');
          if (lbl) lbl.textContent = processed + ' / ' + _total;

          if (data.error_list && data.error_list.length) {
            data.error_list.forEach(addErrorItem);
          }

          if (data.done || _cancelled) {
            finish();
          } else {
            if (_mbBatchDelay > 0) {
              setTimeout(function() { processNext(data.next_offset); }, _mbBatchDelay);
            } else {
              processNext(data.next_offset);
            }
          }
        },
        function(err) { finish(err); }
      );
    }

    // ── start: init queue then begin batches ─────────────────────────────

    function startSend() {
      var form = $$('mb_sendForm');
      if (!form) { return; }

      // Reset all state
      _cancelled   = false;
      _cancelStep  = 0;
      _totalSent   = 0;
      _totalErrors = 0;
      _total       = 0;
      _sendId      = '';
      _qHtml       = '';
      _qPlain      = '';
      _qAttB64     = [];
      _startTime   = Date.now();

      // Reset UI
      setStatus('');
      setBar(0);
      setCounters(0, 0, 0);
      var lbl = $$('mb_progressLabel2'); if (lbl) lbl.textContent = '0 / 0';
      var el  = $$('mb_elapsed');        if (el)  el.textContent  = '0s';
      var es  = $$('mb_errorSection');   if (es)  es.style.display = 'none';
      var eli = $$('mb_errorList');      if (eli) eli.innerHTML   = '';
      var b   = $$('mb_progressBar');
      if (b) {
        b.className = 'progress-bar progress-bar-striped progress-bar-animated fw-semibold';
        b.style.width = '0%'; b.textContent = '0%';
        b.setAttribute('aria-valuenow', 0);
      }

      // Reset cancel/close buttons
      var cb = $$('mb_cancelSend'); var cl = $$('mb_closeProgress');
      if (cb) { cb.classList.remove('d-none', 'btn-danger'); cb.classList.add('btn-outline-danger'); cb.disabled = false; cb.innerHTML = '<i class="ti ti-x me-1"></i>' + <?php echo json_encode(__('Cancel', 'mailblast')); ?>; }
      if (cl) { cl.classList.add('d-none'); }

      // Ticker
      if (_ticker) clearInterval(_ticker);
      _ticker = setInterval(function() {
        var el2 = $$('mb_elapsed');
        if (el2) el2.textContent = Math.floor((Date.now() - _startTime) / 1000) + 's';
      }, 1000);

      // Show progress modal
      if (!_progressModal) _progressModal = new bootstrap.Modal($$('mb_progressModal'));
      _progressModal.show();

      // Build FormData manually — never use new FormData(form) because it
      // includes the file input (files assigned via DataTransfer) which causes
      // the request to fail silently in many browsers or exceed post_max_size.
      // Attachments travel via attachments_b64 JSON, not via file upload.
      var fd = new FormData();
      fd.append('_glpi_csrf_token', csrfToken());
      fd.append('action',  'queue_init');
      fd.append('subject', ($$('mb_subject') || {}).value || '');

      // Sync TinyMCE and read textareas directly
      if (typeof tinymce !== 'undefined') {
        try { tinymce.triggerSave(); } catch(e) {}
      }
      var bodyEl   = document.querySelector('textarea[name="body"]');
      var footerEl = document.querySelector('textarea[name="footer"]');
      fd.append('body',   bodyEl   ? bodyEl.value   : '');
      fd.append('footer', footerEl ? footerEl.value : '');

      // Attachments: read from window._mbSelectedFiles (same as test-send)
      var attFiles = window._mbSelectedFiles ? Array.from(window._mbSelectedFiles.files) : [];
      // We pass them as base64 JSON — no file upload needed
      // (queue_init reads attachments_b64 from POST, not $_FILES)
      // Encode them now and append as JSON string
      var attB64Pending = attFiles.length;
      var attB64List    = [];

      function _doQueueInit() {
        fd.append('attachments_b64', JSON.stringify(attB64List));
        setStatus(<?php echo json_encode(__('Sending emails', 'mailblast')); ?> + '…', 'info');
        doFetch(fd,
          function(data) {
            updateCsrf(data.csrf);
            _total  = data.total || 0;
            _sendId = data.send_id || '';
            _qHtml  = data.html   || '';
            _qPlain = data.plain  || '';
            _qAttB64 = data.attachments_b64 || [];
            setCounters(0, 0, _total);
            var lbl2 = $$('mb_progressLabel2'); if (lbl2) lbl2.textContent = '0 / ' + _total;
            if (_total === 0) {
              finish();
              setStatus(<?php echo json_encode(__('No active users with registered email found', 'mailblast')); ?>, 'warning');
              return;
            }
            processNext(0);
          },
          function(err) { finish(err); }
        );
      }

      if (attB64Pending === 0) {
        _doQueueInit();
      } else {
        attFiles.forEach(function(file) {
          var reader = new FileReader();
          reader.onload = function(ev) {
            var parts = ev.target.result.split(',');
            attB64List.push({ name: file.name, mime: file.type || 'application/octet-stream', data: parts[1] || '' });
            attB64Pending--;
            if (attB64Pending === 0) _doQueueInit();
          };
          reader.onerror = function() { attB64Pending--; if (attB64Pending === 0) _doQueueInit(); };
          reader.readAsDataURL(file);
        });
      }
    } // end startSend

    // ── wire: Send All button ────────────────────────────────────────────

    const sendAllBtn = $$('mb_sendAll');
    if (sendAllBtn) {
      sendAllBtn.addEventListener('click', function() {
        var subjectEl = $$('mb_subject');
        if (!subjectEl || !subjectEl.value.trim()) { showFormAlert(i18n.subjectRequired); return; }
        if (typeof tinymce !== 'undefined') { try { tinymce.triggerSave(); } catch(e) {} }
        var bodyEl = document.querySelector('textarea[name="body"]');
        var bodyText = bodyEl ? bodyEl.value.replace(/<[^>]*>/g, '').trim() : '';
        if (!bodyText) { hideFormAlert(); showFormAlert(i18n.bodyRequired); return; }

        hideFormAlert();
        if (!_confirmModal) _confirmModal = new bootstrap.Modal($$('mb_confirmModal'));
        _confirmModal.show();
      });
    }

    // ── wire: Confirm Send button ─────────────────────────────────────────

    const confirmBtn = $$('mb_confirmSend');
    if (confirmBtn) {
      confirmBtn.addEventListener('click', function() {
        if (_confirmModal) _confirmModal.hide();
        var modalEl = $$('mb_confirmModal');
        if (modalEl) {
          modalEl.addEventListener('hidden.bs.modal', function() { startSend(); }, { once: true });
        } else {
          startSend();
        }
      });
    }

    // ── wire: Cancel button (bound once) ─────────────────────────────────

    const cancelBtn = $$('mb_cancelSend');
    let _cancelStep = 0;
    if (cancelBtn && !_cancelBound) {
      _cancelBound = true;
      cancelBtn.addEventListener('click', function() {
        _cancelStep++;
        if (_cancelStep === 1) {
          // First click: show warning text on button itself, auto-revert after 4s
          cancelBtn.classList.replace('btn-outline-danger', 'btn-danger');
          cancelBtn.innerHTML = '<i class="ti ti-alert-triangle me-1"></i>'
                              + (window._mbI18n ? window._mbI18n.cancelConfirm : '');
          setTimeout(function() {
            if (!_cancelled) {
              _cancelStep = 0;
              cancelBtn.classList.replace('btn-danger', 'btn-outline-danger');
              cancelBtn.innerHTML = '<i class="ti ti-x me-1"></i>' + <?php echo json_encode(__('Cancel', 'mailblast')); ?>;
            }
          }, 4000);
        } else {
          // Second click: cancel immediately
          _cancelled   = true;
          _cancelStep  = 0;
          cancelBtn.disabled = true;
          cancelBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>'
                              + (window._mbI18n ? window._mbI18n.cancelling : <?php echo json_encode(__('Cancelling…', 'mailblast')); ?>);
          // Finish immediately — don't wait for in-flight fetch
          finish();
        }
      });
    }

  }()); // end mass-send IIFE

  // ── sessionStorage: survive theme switches ────────────────────────────────
  //
  // When the user toggles GLPI's dark/light theme the page reloads.
  // TinyMCE content is lost because it was never POSTed.
  // We auto-save to sessionStorage on every change and restore after TinyMCE
  // finishes initialising, giving the illusion of persistence.

  const SS_SUBJECT = 'mb_subject';

  // Save subject field on every keystroke
  const subjectEl = document.getElementById('mb_subject');
  if (subjectEl) {
    subjectEl.addEventListener('input', () => {
      try { sessionStorage.setItem(SS_SUBJECT, subjectEl.value); } catch(_) {}
    });

    // Restore subject if sessionStorage has a more recent value
    try {
      const saved = sessionStorage.getItem(SS_SUBJECT);
      if (saved !== null && saved !== '') subjectEl.value = saved;
    } catch(_) {}
  }

  // ── TinyMCE: intercept EVERY editor at init time ─────────────────────────
  //
  // We hook tinymce.on('AddEditor') so our base64 image handler is installed
  // the instant each editor is created — BEFORE any image upload can fire.
  // This is more reliable than patching after the fact with a polling loop,
  // because GLPI's initEditorSystem() sets images_upload_url at init time and
  // our handler needs to take precedence from the very first paste/drop event.
  //
  // The handler converts every pasted/dropped/inserted image to an inline
  // base64 data-URI so nothing is sent to glpi_documents or the filesystem.



  // Footer is restored server-side from glpi_configs ($savedForm['footer']).
  // Body is intentionally not persisted — it may contain large base64 images.
  // No setContent() calls — they caused formatting loss on every poll cycle.

}());
</script>
<script>
(function() {
  'use strict';

  try {
    var _testBtn = document.getElementById('mb_sendTest');
    if (!_testBtn) { return; }

    // Shared DataTransfer from the main script — accessed via the file input
    var _fileInput = document.getElementById('mb_fileInput');

    // CSRF token: read from the hidden form field so it's always current.
    // After each AJAX response the server returns a fresh token we store here.
    var _csrfToken = (function() {
      var el = document.querySelector('input[name="_glpi_csrf_token"]');
      return el ? el.value : '';
    }());

    function _updateCsrf(newToken) {
      if (!newToken) return;
      _csrfToken = newToken;
      // Also update the form's hidden field so normal submits still work
      var el = document.querySelector('input[name="_glpi_csrf_token"]');
      if (el) el.value = newToken;
    }

    _testBtn.addEventListener('click', function() {
      // Validate subject
      var subjectEl = document.getElementById('mb_subject');
      var subjectVal = subjectEl ? subjectEl.value.trim() : '';
      if (!subjectVal) {
        var _fa = document.getElementById('mb_formAlert');
        if (_fa) { _fa.textContent = <?php echo json_encode(__('Subject is required', 'mailblast')); ?>; _fa.style.display = ''; }
        return;
      }

      var btn      = this;
      btn.disabled = true;
      var origHTML = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>'
                    + <?php echo json_encode(__('Sending…', 'mailblast')); ?>;

      // Read from the shared DataTransfer exposed by the main IIFE
      var dt    = window._mbSelectedFiles;
      var files = dt ? Array.from(dt.files) : [];
      var attB64  = [];
      var pending = files.length;

      function doSend() {
        var fd = new FormData();
        fd.append('_glpi_csrf_token', _csrfToken);
        fd.append('action',           'test_send');
        fd.append('subject',          subjectVal);
        fd.append('attachments_b64',  JSON.stringify(attB64));

        // Sync TinyMCE content into the underlying <textarea> elements first,
        // then read from the textarea directly — works regardless of editor order.
        if (typeof tinymce !== 'undefined') {
          try { tinymce.triggerSave(); } catch(e) {}
        }

        // Read body and footer directly from their textarea elements by name
        var bodyEl   = document.querySelector('textarea[name="body"]');
        var footerEl = document.querySelector('textarea[name="footer"]');
        var bodyVal  = bodyEl ? bodyEl.value.replace(/<[^>]*>/g, '').trim() : '';

        if (!bodyVal) {
          btn.disabled  = false;
          btn.innerHTML = origHTML;
          mbShowResult(false, <?php echo json_encode(__('Body is required', 'mailblast')); ?>);
          return;
        }

        fd.append('body',   bodyEl   ? bodyEl.value   : '');
        fd.append('footer', footerEl ? footerEl.value : '');

        // Test mode
        var modeEl = document.querySelector('input[name="test_mode"]:checked');
        fd.append('test_mode', modeEl ? modeEl.value : 'my_address');
        var specEl = document.getElementById('mb_testEmail');
        if (specEl && specEl.value.trim()) {
          fd.append('test_email', specEl.value.trim());
        }

        fetch(<?php echo json_encode($formAction); ?>, { method: 'POST', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            _updateCsrf(data.csrf || '');
            mbShowResult(
              data.ok,
              data.ok
                ? <?php echo json_encode(__('Test sent successfully', 'mailblast')); ?>
                : (data.error || <?php echo json_encode(__('Test failed', 'mailblast')); ?>)
            );
          })
          .catch(function(err) {
            mbShowResult(false, (window._mbI18n ? window._mbI18n.networkError : 'Network error') + ': ' + (err.message || err));
          })
          .then(function() {          // always runs (no .finally polyfill needed)
            btn.disabled  = false;
            btn.innerHTML = origHTML;
          });
      }

      if (pending === 0) {
        doSend();
        return;
      }

      files.forEach(function(file) {
        var reader = new FileReader();
        reader.onload = function(ev) {
          var parts = ev.target.result.split(',');
          attB64.push({
            name: file.name,
            mime: file.type || 'application/octet-stream',
            data: parts[1] || ''
          });
          pending--;
          if (pending === 0) { doSend(); }
        };
        reader.onerror = function() {
          pending--;
          if (pending === 0) { doSend(); }
        };
        reader.readAsDataURL(file);
      });
    });

  } catch(e) {
    // Surface any setup error so it is not silent
    var errBtn = document.getElementById('mb_sendTest');
    if (errBtn) {
      errBtn.insertAdjacentHTML('afterend',
        '<div class="alert alert-danger mt-2">' + (window._mbI18n ? window._mbI18n.jsInitError : 'Initialization error') + ': ' + e.message + '</div>');
    }
  }

  function mbShowResult(ok, msg) {
    // Remove previous
    var prev = document.querySelectorAll('.mb-test-result');
    for (var i = 0; i < prev.length; i++) { prev[i].remove(); }

    var div = document.createElement('div');
    div.className = 'alert mb-test-result mt-2 ' + (ok ? 'alert-success' : 'alert-danger');
    div.textContent = msg;

    var btn = document.getElementById('mb_sendTest');
    if (btn) { btn.insertAdjacentElement('afterend', div); }

    setTimeout(function() {
      if (div.parentNode) { div.parentNode.removeChild(div); }
    }, 8000);
  }

}());
</script>


<?php
Html::footer();
