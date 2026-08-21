<?php
namespace GlpiPlugin\Mailblast\Service;

use Session;
use UserEmail;

final class ViewService
{
    public function getSendViewData(string $actionUrl, string $configUrl, string $reportUrl): array
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

        // Html::initEditorSystem() emits the TinyMCE bootstrap markup.
        // A Controller renders through Twig, so capturing that output is required
        // to prevent it from being sent before GLPI has rendered its page headers.
        // The captured markup is inserted later through the Twig response.
        ob_start();
        $editorInitResult = \Html::initEditorSystem(
            $mbBodyId, substr($mbBodyId, -12), true, false, true, 200, [], 'top', false, '', true, false, '', false, ['link']
        );
        $editorInitOutput = ob_get_clean();
        $editorInit = $editorInitOutput . (is_string($editorInitResult) ? $editorInitResult : '');
        $footerHtml = $savedForm['footer'];
        $extHints = array_values(array_filter(
            explode(',', $docTypes['accept'] ?? ''),
            static fn(string $value): bool => str_starts_with(trim($value), '.')
        ));
        $extHintsStr = implode('  ', array_slice($extHints, 0, 20));
        if (count($extHints) > 20) {
            $extHintsStr .= '  …';
        }

        $runtimeConfig = 'window.mbConfig = ' . json_encode([
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
                'testFailed'=>__('Test failed','mailblast'),'senderPreviewReply'=>__('This send will use %s as both the From and Reply-To address.','mailblast'),
                'senderPreviewDefault'=>__('This send will use the default GLPI configuration for both From and replies.','mailblast'),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $jsonBodyId = json_encode($mbBodyId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $jsonImgLimitMsg = json_encode(__('Image not inserted: combined size would exceed the %s MB limit set in Configuration.', 'mailblast'));
        $customEditorScript = <<<'JS'
$(function() {
    var id = %s;
    var conf = tinymce_editor_configs[id];
    if (!conf) return;
    if (typeof conf.toolbar === "string") conf.toolbar += " | alignleft aligncenter alignright alignjustify";
    var _imgLimitMsg = %s;
    var _origSetup = conf.setup;
    conf.setup = function(editor) {
        if (_origSetup) _origSetup(editor);
        editor.on("init", function() {
            window._mbEmbeddedBytes = window._mbEmbeddedBytes || 0;
            var _origUpload = editor.options.get("images_upload_handler");
            if (typeof _origUpload === "function") {
                editor.options.set("images_upload_handler", function(blobInfo, progress) {
                    var imgBytes = blobInfo.blob().size;
                    var attBytes = window._mbSelectedFiles ? Array.from(window._mbSelectedFiles.files).reduce(function(s, f) { return s + f.size; }, 0) : 0;
                    var limitBytes = (window._mbMaxAttMb || 15) * 1024 * 1024;
                    if (window._mbEmbeddedBytes + attBytes + imgBytes > limitBytes) {
                        return Promise.reject({ message: _imgLimitMsg.replace("%%s", window._mbMaxAttMb || 15), remove: true });
                    }
                    return _origUpload(blobInfo, progress).then(function(location) { window._mbEmbeddedBytes += imgBytes; return location; });
                });
            }
        });
        editor.on("PastePostProcess", function(e) {
            Array.from(e.node.querySelectorAll("img")).forEach(function(img) {
                if (!img.getAttribute("width") && !img.getAttribute("height")) {
                    var applyDims = function() { if (img.naturalWidth) { img.setAttribute("width", img.naturalWidth); img.setAttribute("height", img.naturalHeight); } };
                    img.complete ? applyDims() : img.addEventListener("load", applyDims, {once:true});
                }
            });
        });
    };
    tinyMCE.init(conf);
});
JS;
        $customEditorScript = sprintf($customEditorScript, $jsonBodyId, $jsonImgLimitMsg);
        $jsFile = __DIR__ . '/../../js/mailblast_send.js';
        $runtimeJs = '<script>' . $runtimeConfig . ';window._mbMaxAttMb=' . (int) $cfgMaxAttMb . ';'
            . '</script>' . $editorInit . '<script>' . $customEditorScript . '</script>'
            . '<script>' . (is_file($jsFile) ? file_get_contents($jsFile) : '') . '</script>';

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
            'runtime_script' => $runtimeJs,
        ];
    }
}
