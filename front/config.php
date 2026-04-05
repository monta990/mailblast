<?php
/**
 * Mail Blast — front/config.php
 * Plugin configuration page (accessible via the gear icon in Setup → Plugins).
 *
 * @author  Edwin Elias Alvarez
 * @license GPL-3.0-or-later
 */

include_once GLPI_ROOT . '/inc/includes.php';

Session::checkRight('config', UPDATE);

global $CFG_GLPI;

$plugin = new Plugin();
if (!$plugin->isActivated('mailblast')) {
    Html::displayNotFoundError();
}

$saved = false;
$errors = [];

// ── Handle save ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF is validated automatically by GLPI 11 via the csrf_compliant hook
    // in setup.php — calling Session::checkCSRF() manually causes a double
    // validation failure because the token is consumed on the first check.

    $batchSize     = (int) ($_POST['batch_size']      ?? 15);
    $batchDelay    = (int) ($_POST['batch_delay_ms']  ?? 120);
    $maxAttachment = (int) ($_POST['max_attachment_mb'] ?? 15);

    if ($batchSize < 1 || $batchSize > 100) {
        $errors[] = __('Batch size must be between 1 and 100.', 'mailblast');
    }
    if ($batchDelay < 0 || $batchDelay > 5000) {
        $errors[] = __('Batch delay must be between 0 and 5000 ms.', 'mailblast');
    }
    if ($maxAttachment < 1 || $maxAttachment > 100) {
        $errors[] = __('Maximum attachment size must be between 1 and 100 MB.', 'mailblast');
    }

    if (empty($errors)) {
        Config::setConfigurationValues('plugin:mailblast', [
            'batch_size'        => $batchSize,
            'batch_delay_ms'    => $batchDelay,
            'max_attachment_mb' => $maxAttachment,
        ]);
        $saved = true;
    }
}

// ── Load current values ──────────────────────────────────────────────────────
$batchSize     = PluginMailblastMailblast::getBatchSize();
$batchDelay    = PluginMailblastMailblast::getBatchDelayMs();
$maxAttachment = PluginMailblastMailblast::getMaxAttachmentMb();

// ── Render ───────────────────────────────────────────────────────────────────
Html::header(
    __('Mail Blast — Configuration', 'mailblast'),
    '',
    'config',
    'PluginMailblastMailblast'
);
?>
<div class="container-fluid mt-3">

  <div class="d-flex align-items-center mb-4 gap-2">
    <i class="ti ti-settings fs-3 text-primary"></i>
    <h2 class="mb-0"><?php echo __('Mail Blast — Configuration', 'mailblast'); ?></h2>
    <span class="ms-auto badge bg-primary fs-6">
      <i class="ti ti-users me-1"></i>
      <?php echo PluginMailblastMailblast::countActiveUsersWithEmail(); ?>
      <?php echo __('active recipients', 'mailblast'); ?>
    </span>
  </div>

  <?php if ($saved): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="ti ti-check me-1"></i>
      <?php echo __('Settings saved successfully.', 'mailblast'); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert">
      <i class="ti ti-alert-circle me-1"></i><?php echo htmlspecialchars($err, ENT_QUOTES); ?>
    </div>
  <?php endforeach; ?>

  <form method="POST" action="">
    <?php echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]); ?>

    <div class="card mb-4">
      <div class="card-header fw-bold">
        <i class="ti ti-send me-1"></i><?php echo __('Sending', 'mailblast'); ?>
      </div>
      <div class="card-body">

        <div class="mb-3">
          <label class="form-label fw-semibold" for="mb_cfg_batch_size">
            <?php echo __('Batch size', 'mailblast'); ?>
          </label>
          <input
            type="number" id="mb_cfg_batch_size" name="batch_size"
            class="form-control" style="max-width:160px"
            min="1" max="100"
            value="<?php echo (int) $batchSize; ?>"
          >
          <div class="form-text text-muted">
            <?php echo __('Number of emails sent per batch (1–100). Default: 15. Lower values reduce SMTP load; higher values speed up sending.', 'mailblast'); ?>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold" for="mb_cfg_delay">
            <?php echo __('Delay between batches (ms)', 'mailblast'); ?>
          </label>
          <input
            type="number" id="mb_cfg_delay" name="batch_delay_ms"
            class="form-control" style="max-width:160px"
            min="0" max="5000"
            value="<?php echo (int) $batchDelay; ?>"
          >
          <div class="form-text text-muted">
            <?php echo __('Milliseconds to wait between batches (0–5000). Default: 120. Increase this if your SMTP server enforces rate limits.', 'mailblast'); ?>
          </div>
        </div>

      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header fw-bold">
        <i class="ti ti-paperclip me-1"></i><?php echo __('Attachments', 'mailblast'); ?>
      </div>
      <div class="card-body">

        <div class="mb-3">
          <label class="form-label fw-semibold" for="mb_cfg_max_att">
            <?php echo __('Maximum total attachment size (MB)', 'mailblast'); ?>
          </label>
          <input
            type="number" id="mb_cfg_max_att" name="max_attachment_mb"
            class="form-control" style="max-width:160px"
            min="1" max="100"
            value="<?php echo (int) $maxAttachment; ?>"
          >
          <div class="form-text text-muted">
            <?php echo __('Maximum combined size of all attached files (1–100 MB). Default: 15 MB. Files exceeding this limit will be rejected in the browser before upload.', 'mailblast'); ?>
          </div>
        </div>

      </div>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary">
        <i class="ti ti-device-floppy me-1"></i><?php echo _sx('button', 'Save'); ?>
      </button>
      <a href="<?php echo $CFG_GLPI['root_doc']; ?>/plugins/mailblast/front/send.php" class="btn btn-secondary">
        <i class="ti ti-arrow-left me-1"></i><?php echo __('Back to Mail Blast', 'mailblast'); ?>
      </a>
    </div>

  </form>

  <?php $history = PluginMailblastMailblast::getHistory(); ?>
  <div class="card mt-4">
    <div class="card-header fw-bold">
      <i class="ti ti-history me-1"></i><?php echo __('Recent sends', 'mailblast'); ?>
      <span class="text-muted fw-normal small ms-2"><?php echo __('Last 5 mass sends', 'mailblast'); ?></span>
    </div>
    <div class="card-body p-0">
      <?php if (empty($history)): ?>
        <p class="text-muted p-3 mb-0">
          <i class="ti ti-info-circle me-1"></i>
          <?php echo __('No sends recorded yet. History is populated after each mass mailing.', 'mailblast'); ?>
        </p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead>
              <tr>
                <th><?php echo __('Date', 'mailblast'); ?></th>
                <th><?php echo __('Subject', 'mailblast'); ?></th>
                <th class="text-success text-end"><?php echo __('Sent', 'mailblast'); ?></th>
                <th class="text-danger text-end"><?php echo __('Failed', 'mailblast'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($history as $h): ?>
              <tr>
                <td class="text-muted small"><?php echo htmlspecialchars($h['date'], ENT_QUOTES); ?></td>
                <td><?php echo htmlspecialchars($h['subject'], ENT_QUOTES); ?></td>
                <td class="text-success fw-bold text-end"><?php echo (int)$h['sent']; ?></td>
                <td class="text-end <?php echo (int)$h['errors'] > 0 ? 'text-danger fw-bold' : 'text-muted'; ?>">
                  <?php echo (int)$h['errors']; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /container-fluid -->
<?php
Html::footer();
