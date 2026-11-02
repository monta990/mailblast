<?php
declare(strict_types=1);
use GlpiPlugin\Mailblast\Service\AttachmentService;
use PHPUnit\Framework\TestCase;

final class AttachmentServiceTest extends TestCase
{
    private AttachmentService $service;
    protected function setUp(): void { $this->service = new AttachmentService(); }

    public function testInlineImageValidatorAcceptsRealImageBytes(): void
    {
        $gif = 'R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
        $result = $this->service->validateInlineImages([
            ['cid' => 'pixel', 'data' => $gif, 'mime' => 'image/gif'],
        ]);
        self::assertTrue($result['ok']);
        self::assertSame([], $result['errors']);
    }

    public function testInlineImageValidatorRejectsNonImagePayloads(): void
    {
        $result = $this->service->validateInlineImages([
            ['cid' => 'not-image', 'data' => base64_encode('plain text'), 'mime' => 'image/png'],
        ]);
        self::assertFalse($result['ok']);
        self::assertNotEmpty($result['errors']);
    }

    public function testInlineImageValidatorRejectsMalformedBase64(): void
    {
        $result = $this->service->validateInlineImages([
            ['cid' => 'broken', 'data' => '%%%not-base64%%%', 'mime' => 'image/png'],
        ]);
        self::assertFalse($result['ok']);
        self::assertNotEmpty($result['errors']);
    }

    public function testDataUriIsConvertedToInlineCidWithoutKeepingBase64InHtml(): void
    {
        $gif = 'R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
        $html = '<p><img src="data:image/gif;base64,' . $gif . '" alt="pixel"></p>';
        $result = $this->service->extractInlineImages($html);
        self::assertStringContainsString('cid:mailblast_data_img_0', $result['html']);
        self::assertStringNotContainsString('data:image/gif;base64', $result['html']);
        self::assertCount(1, $result['inlineImages']);
        self::assertSame('mailblast_data_img_0', $result['inlineImages'][0]['cid']);
        self::assertSame($gif, $result['inlineImages'][0]['data']);
    }
}
