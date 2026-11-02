<?php
declare(strict_types=1);
use GlpiPlugin\Mailblast\Service\QueueService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class QueueServiceTest extends TestCase
{
    public function testPayloadHashNormalizesLineEndingsAcrossBrowserRequests(): void
    {
        $method = new ReflectionMethod(QueueService::class, 'payloadHash');
        $windowsHash = $method->invoke(null, "<p>Hello</p>\r\n", "Hello\r\n", [], []);
        $unixHash = $method->invoke(null, "<p>Hello</p>\n", "Hello\n", [], []);
        self::assertSame($windowsHash, $unixHash);
    }

    public function testPayloadHashChangesWhenMessageContentChanges(): void
    {
        $method = new ReflectionMethod(QueueService::class, 'payloadHash');
        $original = $method->invoke(null, '<p>Original</p>', 'Original', [], []);
        $modified = $method->invoke(null, '<p>Modified</p>', 'Modified', [], []);
        self::assertNotSame($original, $modified);
    }

    public function testPayloadHashChangesWhenAttachmentBytesChange(): void
    {
        $method = new ReflectionMethod(QueueService::class, 'payloadHash');
        $first = $method->invoke(
            null, '<p>Body</p>', 'Body',
            [['name' => 'a.txt', 'data' => base64_encode('first')]], []
        );
        $second = $method->invoke(
            null, '<p>Body</p>', 'Body',
            [['name' => 'a.txt', 'data' => base64_encode('second')]], []
        );
        self::assertNotSame($first, $second);
    }
}
