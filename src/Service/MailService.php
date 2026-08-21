<?php
/**
 * Mail Blast service.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Mailblast\Service;

use GlpiPlugin\Mailblast\Service\AttachmentService;
use GlpiPlugin\Mailblast\Service\ContentService;
use GlpiPlugin\Mailblast\Service\RecipientService;

final class MailService
{
    public function sendSymfonyEmail(
            \Symfony\Component\Mailer\Transport\TransportInterface $transport,
            string $toEmail,
            string $toName,
            string $subject,
            string $html,
            string $plain,
            array  $attachments      = [],
            array  $inlineImages     = [],
            string $fromOverride     = '',
            string $fromNameOverride = '',
            string $replyToEmail     = '',
            string $replyToName      = ''
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
    
                // Override From when a "Reply to" user was selected — the same
                // mailbox is used for both, so replies land where recipients
                // expect. Empty when no Reply to user is selected.
                if ($fromOverride !== '' && filter_var($fromOverride, FILTER_VALIDATE_EMAIL)) {
                    $email->from(new \Symfony\Component\Mime\Address($fromOverride, $fromNameOverride ?: $fromOverride));
                }
    
                // Envelope sender (smtp_sender), if configured — only applied when
                // no From override is active. Setting an explicit Sender: header
                // that differs from From: makes Outlook (and other clients) show
                // "sender@x.com on behalf of override@x.com", which defeats the
                // purpose of the From override. When From is overridden, the
                // envelope sender simply follows the From address instead.
                if ($fromOverride === '') {
                    $smtpSender = trim((string) ($CFG_GLPI['smtp_sender'] ?? ''));
                    if ($smtpSender !== '' && filter_var($smtpSender, FILTER_VALIDATE_EMAIL)) {
                        $email->sender(new \Symfony\Component\Mime\Address($smtpSender));
                    }
                }
    
                // Reply-To override — when set, replies from the recipient land in
                // this mailbox regardless of which address appears in From:.
                if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                    $email->replyTo(new \Symfony\Component\Mime\Address($replyToEmail, $replyToName ?: $replyToEmail));
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
                \Toolbox::logInFile(
                    'mailblast',
                    sprintf("Mail send failure to %s: %s\n", $toEmail, $e->getMessage()),
                    true
                );
                return $e->getMessage();
            }
        }

    public function sendMails(
            string $subject,
            string $htmlBody,
            string $footer,
            array  $attachments   = [],
            bool   $testMode      = false,
            string $testEmail     = '',
            int    $replyToUserId = 0
        ): array {
            $attachmentService = new AttachmentService();
            $inlineResult = $attachmentService->extractInlineImages($htmlBody);
            $inlineValidation = $attachmentService->validateInlineImages($inlineResult['inlineImages']);
            if (!$inlineValidation['ok']) {
                return ['sent' => 0, 'errors' => $inlineValidation['errors'], 'total' => 1];
            }
            $htmlBody     = $inlineResult['html'];
            $inlineImages = [];
            foreach ($inlineResult['inlineImages'] as $img) {
                $imgBytes = base64_decode($img['data'], true);
                if ($imgBytes !== false && $imgBytes !== '') {
                    $inlineImages[] = ['cid' => $img['cid'], 'bytes' => $imgBytes, 'mime' => $img['mime']];
                }
            }
            $fullHtml  = (new ContentService())->buildHtmlBody($htmlBody, $footer);
            $plainText = (new ContentService())->html2text($fullHtml);
    
            $attPaths = array_map(
                fn($a) => ['path' => $a['tmp'], 'name' => $a['name'], 'mime' => $a['mime']],
                $attachments
            );
    
            // sendMails is only called for test sends — single recipient always.
            $recipients = [['email' => $testEmail, 'name' => $testEmail]];
    
            $replyTo = (new RecipientService())->resolveUserEmail($replyToUserId);
    
            // Same priority rule as the mass-send queue: when a "Reply to" user
            // is selected, it is used for From: as well.
            $fromOverride     = $replyTo['email'];
            $fromNameOverride = $replyTo['name'];
    
            $sent   = 0;
            $errors = [];
    
            // Create transport once — avoids a new SMTP handshake per recipient.
            try {
                $transport = \Symfony\Component\Mailer\Transport::fromDsn(\GLPIMailer::buildDsn(true));
            } catch (\Throwable $e) {
                \Toolbox::logInFile(
                    'mailblast',
                    sprintf("SMTP transport initialization failed: %s\n", $e->getMessage()),
                    true
                );
                return ['sent' => 0, 'errors' => [$e->getMessage()], 'total' => count($recipients)];
            }
    
            foreach ($recipients as $recipient) {
                $toEmail = trim((string) $recipient['email']);
                $toName  = (string) ($recipient['name'] ?? '');
    
                if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = $toEmail . ': ' . __('Invalid address', 'mailblast');
                    continue;
                }
    
                $err = $this->sendSymfonyEmail(
                    $transport, $toEmail, $toName, $subject, $fullHtml, $plainText, $attPaths, $inlineImages,
                    $fromOverride, $fromNameOverride, $replyTo['email'], $replyTo['name']
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
