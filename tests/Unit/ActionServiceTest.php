<?php
declare(strict_types=1);
use GlpiPlugin\Mailblast\Service\ActionService;
use PHPUnit\Framework\TestCase;

final class ActionServiceTest extends TestCase
{
    public function testTestSendRejectsAnEmptySubjectBeforeAccessingGlpiServices(): void
    {
        $result = (new ActionService())->testSend('', '<p>Body</p>', '', 'specific', '', [], 0);
        self::assertFalse($result['ok']);
        self::assertSame('Subject is required', $result['error']);
    }

    public function testQueueProcessingRejectsMalformedSendIdentifiers(): void
    {
        $service = new ActionService();
        foreach (['', '--------', 'not-a-uuid', '12345678-1234-1234-1234-12345678901Z'] as $sendId) {
            $result = $service->processQueue($sendId, 0);
            self::assertFalse($result['ok']);
            self::assertSame('Missing send_id', $result['error']);
        }
    }
}
