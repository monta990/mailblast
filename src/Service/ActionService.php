<?php
namespace GlpiPlugin\Mailblast\Service;

use Session;
use UserEmail;

final class ActionService
{
    public function countRecipients(string $filterType, array $filterIds): int
    {
        $filterType = preg_replace('/[^a-z_]/', '', $filterType) ?: 'all';
        return (new RecipientService())->countActiveUsersWithEmail([
            'type' => $filterType,
            'ids'  => $filterIds,
        ]);
    }

    public function testSend(
        string $subject,
        string $body,
        string $footer,
        string $testMode,
        string $testEmail,
        array $attachments,
        int $replyToUserId
    ): array {
        if ($subject === '') {
            return ['ok' => false, 'error' => __('Subject is required', 'mailblast')];
        }

        $testEmails = [];
        if ($testMode === 'specific') {
            $candidates = array_slice(array_map('trim', explode(',', $testEmail)), 0, 5);
            foreach ($candidates as $email) {
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $testEmails[] = $email;
                }
            }
            if ($testEmails === []) {
                return ['ok' => false, 'error' => __('Test address is required', 'mailblast')];
            }
        } else {
            $email = UserEmail::getDefaultForUser((int) Session::getLoginUserID());
            if (empty($email)) {
                return ['ok' => false, 'error' => __('No email found for your account', 'mailblast')];
            }
            $testEmails[] = $email;
        }

        $configuration = new ConfigurationService();
        $configuration->saveFormConfig($subject, $body, $footer);
        $attachmentService = new AttachmentService();
        $attachmentValidation = $attachmentService->validateBase64Attachments($attachments);
        if (!$attachmentValidation['ok']) {
            return ['ok' => false, 'error' => implode(' ', $attachmentValidation['errors'])];
        }
        $tempAttachments = $attachmentService->decodeTemporaryAttachments($attachments, 'mb_test_');

        $sent = 0;
        $errors = [];
        $mailService = new MailService();
        foreach ($testEmails as $email) {
            $result = $mailService->sendMails($subject, $body, $footer, $tempAttachments, true, $email, $replyToUserId);
            $sent += $result['sent'];
            $errors = array_merge($errors, $result['errors']);
        }

        $attachmentService->cleanupTemporaryAttachments($tempAttachments);
        return [
            'ok' => $sent > 0,
            'sent' => $sent,
            'errors' => $errors,
            'error' => $sent === 0
                ? __('Test failed', 'mailblast') . (!empty($errors) ? ': ' . implode('; ', $errors) : '')
                : null,
        ];
    }

    public function initializeQueue(
        string $subject,
        string $body,
        string $footer,
        array $attachments,
        string $filterType,
        array $filterIds,
        int $replyToUserId
    ): array {
        $configuration = new ConfigurationService();
        $configuration->saveFormConfig($subject, $body, $footer);
        $cooldownError = $configuration->checkCooldown();
        if ($cooldownError !== null) {
            return ['ok' => false, 'error' => $cooldownError];
        }

        if (strlen($body) > $configuration->getMaxAttachmentMb() * 1024 * 1024) {
            return ['ok' => false, 'error' => __('Message body is too large.', 'mailblast')];
        }

        $filterType = preg_replace('/[^a-z_]/', '', $filterType) ?: 'all';
        $attachmentService = new AttachmentService();
        $attachmentValidation = $attachmentService->validateBase64Attachments($attachments);
        if (!$attachmentValidation['ok']) {
            return ['ok' => false, 'error' => implode(' ', $attachmentValidation['errors'])];
        }
        $tempAttachments = $attachmentService->decodeTemporaryAttachments($attachments, 'mb_qi_');
        try {
            return (new QueueService())->initQueue(
                $subject,
                $body,
                $footer,
                $tempAttachments,
                ['type' => $filterType, 'ids' => $filterIds],
                $replyToUserId
            );
        } finally {
            $attachmentService->cleanupTemporaryAttachments($tempAttachments);
        }
    }

    public function processQueue(
        string $sendId,
        int $offset,
        string $html = '',
        string $plain = '',
        array $attachments = [],
        array $inlineImages = []
    ): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $sendId)) {
            return ['ok' => false, 'error' => __('Missing send_id', 'mailblast')];
        }

        $result = (new QueueService())->processBatch(
            $sendId,
            $html,
            $plain,
            $attachments,
            max(0, $offset),
            (new ConfigurationService())->getBatchSize(),
            $inlineImages
        );

        $ok = empty($result['queue_error']);
        if (!$ok && empty($result['error'])) {
            $messages = array_values(array_filter((array) ($result['error_list'] ?? []), 'is_string'));
            $result['error'] = $messages !== []
                ? implode('; ', $messages)
                : __('Server error while processing the sending queue.', 'mailblast');
        }

        return ['ok' => $ok] + $result;
    }
}
