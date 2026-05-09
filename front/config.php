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
    throw new \Glpi\Exception\Http\NotFoundHttpException();
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

Html::header(__('Mail Blast — Configuration', 'mailblast'), '', 'config', 'PluginMailblastMailblast');

$history = PluginMailblastMailblast::getHistory();

\Glpi\Application\View\TemplateRenderer::getInstance()->display('@mailblast/config.html.twig', [
    'saved'         => $saved,
    'errors'        => $errors,
    'batch_size'    => $batchSize,
    'batch_delay'   => $batchDelay,
    'max_attachment'=> $maxAttachment,
    'user_count'    => PluginMailblastMailblast::countActiveUsersWithEmail(),
    'history'       => $history,
    'timezone'      => date_default_timezone_get(),
    'send_url'      => plugin_mailblast_web_dir() . '/front/send.php',
    'csrf_token'    => Session::getNewCSRFToken(),
    'save_label'    => _sx('button', 'Save'),
]);

Html::footer();
