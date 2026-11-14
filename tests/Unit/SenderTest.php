<?php
declare(strict_types=1);

use GlpiPlugin\Mailblast\Service\MailService;
use GlpiPlugin\Mailblast\Service\QueueService;
use PHPUnit\Framework\TestCase;

// Minimal GLPI doubles, declared only when the real classes are not loaded.
if (!class_exists('Config', false)) {
    class Config
    {
        /** @var array{email:?string,name:?string} */
        public static array $sender = ['email' => null, 'name' => null];
        /** @var array<int, array{email:?string,name:?string}> */
        public static array $sendersByEntity = [];
        public static ?int $askedEntity = null;

        public static function getEmailSender(?int $entities_id = null, bool $no_reply = false): array
        {
            self::$askedEntity = $entities_id;
            return $entities_id !== null && isset(self::$sendersByEntity[$entities_id])
                ? self::$sendersByEntity[$entities_id]
                : self::$sender;
        }
    }
}
if (!class_exists('Toolbox', false)) {
    class Toolbox
    {
        public static function logInFile(string $name, string $text, bool $force = false): bool { return true; }
    }
}

require_once dirname(__DIR__, 2) . '/src/Service/MailService.php';
require_once dirname(__DIR__, 2) . '/src/Service/RecipientService.php';

/**
 * From: identity rules: the explicit sender, else GLPI's notification
 * sender — never the "Reply to" user, never nothing.
 */
final class SenderTest extends TestCase
{
    protected function setUp(): void
    {
        Config::$sender = ['email' => 'notifications@example.org', 'name' => 'Service desk'];
        Config::$sendersByEntity = [];
        Config::$askedEntity = null;
        unset($_SESSION['glpiactive_entity']);
        $GLOBALS['CFG_GLPI'] = ['admin_email' => 'admin.person@example.org', 'smtp_sender' => 'bounce@example.org'];
    }

    public function testDefaultSenderIsGlpiNotificationSenderNotAdminEmail(): void
    {
        $from = (new MailService())->resolveSender();
        self::assertSame('notifications@example.org', $from['email']);
        self::assertSame('Service desk', $from['name']);
    }

    public function testExplicitSenderWins(): void
    {
        $from = (new MailService())->resolveSender('entity-desk@example.org', 'Entity desk');
        self::assertSame(['email' => 'entity-desk@example.org', 'name' => 'Entity desk'], $from);
    }

    public function testInvalidExplicitSenderFallsBackToGlpiSender(): void
    {
        foreach (['not-an-address', "a@example.org\r\nBcc: x@evil.test", ' '] as $bad) {
            self::assertSame('notifications@example.org', (new MailService())->resolveSender($bad, 'X')['email']);
        }
    }

    public function testControlCharactersAreStrippedFromDisplayName(): void
    {
        $from = (new MailService())->resolveSender('desk@example.org', "Desk\r\nBcc: x@evil.test");
        self::assertStringNotContainsString("\n", $from['name']);
        self::assertStringNotContainsString("\r", $from['name']);
    }

    public function testEntitySpecificNotificationSenderIsResolved(): void
    {
        Config::$sendersByEntity[7] = [
            'email' => 'entity-seven@example.org',
            'name' => 'Entity seven',
        ];

        $from = (new MailService())->resolveSender('', '', 7);

        self::assertSame(7, Config::$askedEntity);
        self::assertSame('entity-seven@example.org', $from['email']);
        self::assertSame('Entity seven', $from['name']);
    }

    public function testActiveSessionEntityIsUsedWhenNoEntityIsPassed(): void
    {
        $_SESSION['glpiactive_entity'] = 7;
        Config::$sendersByEntity[7] = [
            'email' => 'active-entity@example.org',
            'name' => 'Active entity',
        ];

        $from = (new MailService())->resolveSender();

        self::assertSame(7, Config::$askedEntity);
        self::assertSame('active-entity@example.org', $from['email']);
    }

    public function testExplicitEntityTakesPrecedenceOverActiveSessionEntity(): void
    {
        $_SESSION['glpiactive_entity'] = 7;
        Config::$sendersByEntity[8] = [
            'email' => 'explicit-entity@example.org',
            'name' => 'Explicit entity',
        ];

        $from = (new MailService())->resolveSender('', '', 8);

        self::assertSame(8, Config::$askedEntity);
        self::assertSame('explicit-entity@example.org', $from['email']);
    }

    public function testNoUsableSenderGivesEmptyIdentity(): void
    {
        Config::$sender = ['email' => null, 'name' => null];
        self::assertSame('', (new MailService())->resolveSender()['email']);
        Config::$sender = ['email' => 'broken', 'name' => null];
        self::assertSame('', (new MailService())->resolveSender()['email']);
    }

    public function testTestSendIsRefusedWithoutSender(): void
    {
        Config::$sender = ['email' => null, 'name' => null];
        $res = (new MailService())->sendMails('Subject', '<p>Body</p>', '', [], true, 'to@example.org');
        self::assertSame(0, $res['sent']);
        self::assertNotEmpty($res['errors']);
    }

    public function testQueueIsRefusedWithoutSender(): void
    {
        require_once dirname(__DIR__, 2) . '/src/Service/QueueService.php';
        Config::$sender = ['email' => null, 'name' => null];
        $res = (new QueueService())->initQueue('Subject', '<p>Body</p>', '', [], ['type' => 'all']);
        self::assertFalse($res['ok']);
    }

    /** The contract an API bridge detects by reflection (mb-api canImposeSender). */
    public function testEntryPointsExposeFromEmailAndFromName(): void
    {
        require_once dirname(__DIR__, 2) . '/src/Service/QueueService.php';
        foreach ([[QueueService::class, 'initQueue'], [MailService::class, 'sendMails']] as [$class, $method]) {
            $names = array_map(
                static fn(ReflectionParameter $p): string => $p->getName(),
                (new ReflectionMethod($class, $method))->getParameters()
            );
            self::assertContains('fromEmail', $names, "$class::$method");
            self::assertContains('fromName', $names, "$class::$method");
            self::assertContains('entityId', $names, "$class::$method");
        }

        $batchParameters = array_map(
            static fn(ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(QueueService::class, 'processBatch'))->getParameters()
        );
        self::assertContains('entityId', $batchParameters);
    }

    /** @return array{0:object,1:?string} capturing transport and send result */
    private function sendThroughCapture(
        string $from,
        string $fromName,
        string $replyTo,
        string $replyToName,
        ?int $entityId = null
    ): array
    {
        $transport = new class implements \Symfony\Component\Mailer\Transport\TransportInterface {
            public ?\Symfony\Component\Mime\Email $last = null;
            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
            {
                $this->last = $message;
                return null;
            }
            public function __toString(): string { return 'capture://'; }
        };
        $err = (new MailService())->sendSymfonyEmail(
            $transport, 'to@example.org', 'To', 'Subject', '<p>Hi</p>', 'Hi', [], [],
            $from, $fromName, $replyTo, $replyToName, $entityId
        );

        return [$transport, $err];
    }

    private function requireSymfonyMime(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $ready = false;
        foreach ([getenv('GLPI_ROOT') ?: '', dirname(__DIR__, 4)] as $root) {
            if ($root !== '' && is_file($root . '/vendor/symfony/mime/Email.php')) {
                // Only the mail libraries: GLPI's full autoloader also defines
                // __() and the core classes, which these tests replace.
                $map = require $root . '/vendor/composer/autoload_psr4.php';
                spl_autoload_register(static function (string $class) use ($map): void {
                    foreach ($map as $prefix => $dirs) {
                        if (!preg_match('/^(Symfony|Egulias|Doctrine)\\\\/', $prefix)) {
                            continue;
                        }
                        if (str_starts_with($class, $prefix)) {
                            foreach ($dirs as $dir) {
                                $file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                                if (is_file($file)) {
                                    require_once $file;
                                    return;
                                }
                            }
                        }
                    }
                });
                return $ready = interface_exists(\Symfony\Component\Mailer\Transport\TransportInterface::class);
            }
        }
        return false;
    }

    public function testMessageFromIsGlpiSenderAndReplyToIsTheSelectedUser(): void
    {
        if (!$this->requireSymfonyMime()) {
            self::markTestSkipped('symfony/mime not found: set GLPI_ROOT to a GLPI install.');
        }
        // What the queue stores since this change: From = GLPI sender, Reply-To = user.
        [$t, $err] = $this->sendThroughCapture('notifications@example.org', 'Service desk', 'agent@example.org', 'An agent');
        self::assertNull($err);
        self::assertSame('notifications@example.org', $t->last->getFrom()[0]->getAddress());
        self::assertSame('agent@example.org', $t->last->getReplyTo()[0]->getAddress());
        self::assertSame('bounce@example.org', $t->last->getSender()?->getAddress());
        $envelope = \\Symfony\\Component\\Mailer\\Envelope::create($t->last);
        self::assertSame('bounce@example.org', $envelope->getSender()->getAddress());
    }

    public function testMessageUsesTheConfiguredSenderForItsEntity(): void
    {
        if (!$this->requireSymfonyMime()) {
            self::markTestSkipped('symfony/mime not found: set GLPI_ROOT to a GLPI install.');
        }

        Config::$sendersByEntity[7] = [
            'email' => 'entity-seven@example.org',
            'name' => 'Entity seven',
        ];
        [$t, $err] = $this->sendThroughCapture('', '', '', '', 7);

        self::assertNull($err);
        self::assertSame(7, Config::$askedEntity);
        self::assertSame('entity-seven@example.org', $t->last->getFrom()[0]->getAddress());
    }

    public function testMessageWithoutExplicitSenderUsesGlpiSenderNotAdminEmail(): void
    {
        if (!$this->requireSymfonyMime()) {
            self::markTestSkipped('symfony/mime not found: set GLPI_ROOT to a GLPI install.');
        }
        [$t, $err] = $this->sendThroughCapture('', '', '', '');
        self::assertNull($err);
        self::assertSame('notifications@example.org', $t->last->getFrom()[0]->getAddress());
        self::assertSame([], $t->last->getReplyTo());
    }

    public function testMessageIsNotSentWithoutSender(): void
    {
        if (!$this->requireSymfonyMime()) {
            self::markTestSkipped('symfony/mime not found: set GLPI_ROOT to a GLPI install.');
        }
        Config::$sender = ['email' => null, 'name' => null];
        [$t, $err] = $this->sendThroughCapture('', '', 'agent@example.org', '');
        self::assertNotNull($err);
        self::assertNull($t->last);
    }
}
