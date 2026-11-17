<?php
namespace GlpiPlugin\Mailblast\Service;

use Session;
use UserEmail;

final class ViewService
{
    public function getSendViewData(string $actionUrl, string $configUrl, string $reportUrl, string $editorConfigUrl): array
    {
        $attachmentService = new AttachmentService();
        $recipientService = new RecipientService();
        $configurationService = new ConfigurationService();
        $docTypes = $attachmentService->getAllowedDocumentTypes();
        $userCount = $recipientService->countActiveUsersWithEmail();
        $entities = $recipientService->getEntities();
        $profiles = $recipientService->getProfiles();
        $users = $recipientService->getUsersWithEmail();
        $myEmail = UserEmail::getDefaultForUser((int) Session::getLoginUserID());
        $savedForm = $configurationService->loadFormConfig();
        $cfgBatchDelay = $configurationService->getBatchDelayMs();
        $cfgMaxAttMb = $configurationService->getMaxAttachmentMb();
        $mbBodyId = 'mb_body_' . bin2hex(random_bytes(6));

        $editorHtml = '<textarea class="form-control" name="body" id="'
            . htmlspecialchars($mbBodyId, ENT_QUOTES, 'UTF-8') . '" rows="15">'
            . htmlspecialchars($savedForm['body'], ENT_QUOTES, 'UTF-8') . '</textarea>';

        $footerHtml = $savedForm['footer'];
        $extHints = array_values(array_filter(
            explode(',', $docTypes['accept'] ?? ''),
            static fn(string $value): bool => str_starts_with(trim($value), '.')
        ));
        $extHintsStr = implode('  ', array_slice($extHints, 0, 20));
        if (count($extHints) > 20) {
            $extHintsStr .= '  …';
        }

        $runtimeConfig = [
            'formAction' => $actionUrl,
            'reportAction' => $reportUrl,
            'batchDelay' => $cfgBatchDelay,
            'maxAttMb' => $cfgMaxAttMb,
            'i18n' => [
                'remove'=>__('Remove','mailblast'),'subjectRequired'=>__('Subject is required','mailblast'),'bodyRequired'=>__('Body is required','mailblast'),
                'bytes'=>__('B','mailblast'),'kilobytes'=>__('KB','mailblast'),'megabytes'=>__('MB','mailblast'),'networkError'=>__('Network error','mailblast'),
                'jsInitError'=>__('Initialization error','mailblast'),'cancelling'=>__('Cancelling…','mailblast'),'cancelConfirm'=>__('Cancel sending? Emails already sent will not be recalled.','mailblast'),
                'badResponse'=>__('Bad server response','mailblast'),'serverError'=>__('Server error','mailblast'),'queueInitFail'=>__('Could not start sending','mailblast'),
                'queueBatchFail'=>__('Batch failed','mailblast'),'attSizeLimit'=>__('Attachment size limit exceeded (%s MB max). File not added: %s','mailblast'),
                'sendingCancelled'=>__('Sending cancelled.','mailblast'),'sendingFailed'=>__('Sending failed — no emails were delivered.','mailblast'),
                'sent'=>__('sent','mailblast'),'failed'=>__('failed','mailblast'),'allSent'=>__('All emails sent successfully.','mailblast'),
                'generating'=>__('Generating…','mailblast'),'cancel'=>__('Cancel','mailblast'),'sendingEmails'=>__('Sending emails','mailblast'),
                'noActiveUsers'=>__('No active users with registered email found','mailblast'),'sending'=>__('Sending…','mailblast'),
                'selectFilter'=>__('Select at least one item to send to specific recipients.','mailblast'),'testSent'=>__('Test sent successfully','mailblast'),
                'testFailed'=>__('Test failed','mailblast'),'senderPreviewReply'=>__('Replies will go to %s; the From address stays the GLPI notification sender.','mailblast'),
                'senderPreviewDefault'=>__('This send will use the default GLPI configuration for both From and replies.','mailblast'),
            ],
        ];
        // JSON_HEX_* ensures the data value stays inert even if a future translated
        // string contains HTML-significant characters. Twig escapes the attribute.
        $runtimeConfigJson = json_encode(
            $runtimeConfig,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $editorConfigUrl .= (str_contains($editorConfigUrl, '?') ? '&' : '?')
            . 'body_id=' . rawurlencode($mbBodyId);
        $imageLimitMessage = __('Image not inserted: combined size would exceed the %s MB limit set in Configuration.', 'mailblast');

        return [
            'title' => __('Mail Blast', 'mailblast'),
            'menu' => ['admin', 'plugin', 'mailblast'],
            'form_action' => $actionUrl,
            'action_url' => $actionUrl,
            'config_url' => $configUrl,
            'report_url' => $reportUrl,
            'doc_types' => $docTypes,
            'ext_hints_str' => $extHintsStr,
            'user_count' => $userCount,
            'my_email' => $myEmail,
            'saved_form' => $savedForm,
            'max_att_mb' => $cfgMaxAttMb,
            'mb_body_id' => $mbBodyId,
            'editor_html' => $editorHtml,
            'footer_html' => $footerHtml,
            'can_config' => Session::haveRight('config', UPDATE),
            'csrf_token' => Session::getNewCSRFToken(),
            'entities' => $entities,
            'profiles' => $profiles,
            'users' => $users,
            'cfg_batch_delay' => $cfgBatchDelay,
            'runtime_config_json' => $runtimeConfigJson,
            'image_limit_message' => $imageLimitMessage,
            'editor_config_url' => $editorConfigUrl,
            'plugin_version' => PLUGIN_MAILBLAST_VERSION,
        ];
    }
}
