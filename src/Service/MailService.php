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
    /**
     * The From: identity of a Mail Blast message.
     *
     * An explicit, valid $fromEmail wins (callers such as API bridges pass the
     * sender they resolved for an entity). Otherwise GLPI's own notification
     * sender is used — Config::getEmailSender(): "from_email", then
     * "admin_email" — exactly as GLPI does for its notifications.
     *
     * A "Reply to" user never becomes the From: address: sending under a
     * personal address through GLPI's SMTP account is spoofing for most mail
     * servers (SPF/DMARC, sender/login mismatch). That user goes to Reply-To:.
     *
     * @return array{email:string,name:string} email '' = no usable sender
     */
    public function resolveSender(string $fromEmail = '', string $fromName = '', ?int $entityId = null): array
    {
        $fromEmail = trim($fromEmail);
        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $fromEmail, 'name' => self::headerSafe($fromName)];
        }

        $sender = [];
        try {
            if (class_exists('\Config') && method_exists('\Config', 'getEmailSender')) {
                $sender = (array) @\Config::getEmailSender($entityId);
            }
        } catch (\Throwable $e) {
            $sender = [];
        }
        $email = trim((string) ($sender['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => '', 'name' => ''];
        }

        return ['email' => $email, 'name' => self::headerSafe((string) ($sender['name'] ?? ''))];
    }

    /** A display name safe for a mail header: no control characters. */
    private static function headerSafe(string $name): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
    }

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
    
                // From: the caller's explicit sender, else GLPI's notification
                // sender (never a user's address). No sender = nothing leaves.
                $from = $this->resolveSender($fromOverride, $fromNameOverride);
                if ($from['email'] === '') {
                    throw new \RuntimeException(
                        'No valid sender: configure the administrator / sender email in Setup > Notifications.'
                    );
                }
                $email->from(new \Symfony\Component\Mime\Address($from['email'], $from['name']));

                // Envelope sender (smtp_sender), as GLPIMailer sets it.
                $smtpSender = trim((string) ($CFG_GLPI['smtp_sender'] ?? ''));
                if ($smtpSender !== '' && filter_var($smtpSender, FILTER_VALIDATE_EMAIL)) {
                    $email->sender(new \Symfony\Component\Mime\Address($smtpSender));
                }

                // Reply-To — the selected "Reply to" user: replies land in this
                // mailbox while From: stays GLPI's sender.
                if ($replyToEmail !== '' && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL)) {
                    $replyToName = self::headerSafe($replyToName);
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
            int    $replyToUserId = 0,
            string $fromEmail     = '',
            string $fromName      = ''
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
    
            // Same rule as the mass-send queue: From: is the explicit sender or
            // GLPI's; the "Reply to" user only goes to Reply-To:.
            $from = $this->resolveSender($fromEmail, $fromName);
            if ($from['email'] === '') {
                return ['sent' => 0, 'errors' => [__('No valid sender email is configured in GLPI notifications.', 'mailblast')], 'total' => count($recipients)];
            }
            $fromOverride     = $from['email'];
            $fromNameOverride = $from['name'];
    
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
