<?php
declare(strict_types=1);
use GlpiPlugin\Mailblast\Service\ContentService;
use PHPUnit\Framework\TestCase;

final class ContentServiceTest extends TestCase
{
    private ContentService $service;
    protected function setUp(): void { $this->service = new ContentService(); }

    public function testHtmlBodyIsWrappedAsHtml5(): void
    {
        $html = $this->service->buildHtmlBody('<p>Hello</p>', '');
        self::assertStringStartsWith('<!DOCTYPE html><html><head>', $html);
        self::assertStringContainsString('<meta charset="utf-8">', $html);
        self::assertStringContainsString('<p>Hello</p>', $html);
        self::assertStringEndsWith('</body></html>', $html);
    }

    public function testHtmlToTextPreservesBlockAndTableBoundaries(): void
    {
        $plain = $this->service->html2text(
            '<h1>Heading</h1><p>First <strong>paragraph</strong></p>'
            . '<table><tr><th>Column A</th><th>Column B</th></tr>'
            . '<tr><td>Value A</td><td>Value B</td></tr></table>'
        );
        self::assertStringContainsString('Heading', $plain);
        self::assertStringContainsString('First paragraph', $plain);
        self::assertStringContainsString('Column A Column B', $plain);
        self::assertStringContainsString('Value A Value B', $plain);
    }

    public function testHtmlToTextDecodesEntitiesAndConvertsLists(): void
    {
        $plain = $this->service->html2text('<ul><li>Tom &amp; Jerry</li><li>Second item</li></ul>');
        self::assertStringContainsString('• Tom & Jerry', $plain);
        self::assertStringContainsString('• Second item', $plain);
    }

    public function testFooterAllowsSafeLinksAndRemovesExecutableAttributes(): void
    {
        $footer = $this->service->sanitizeFooter(
            '<p onclick="run()">Contact <a href="https://example.com" target="_blank" '
            . 'onmouseover="run()">support</a></p>'
        );
        self::assertStringContainsString('<p>Contact', $footer);
        self::assertStringContainsString(
            '<a href="https://example.com" rel="noopener noreferrer">support</a>',
            $footer
        );
        self::assertStringNotContainsString('onclick', $footer);
        self::assertStringNotContainsString('onmouseover', $footer);
        self::assertStringNotContainsString('target=', $footer);
    }

    public function testFooterRejectsUnsafeUrlSchemesAndEmbeddedContent(): void
    {
        $footer = $this->service->sanitizeFooter(
            '<a href="javascript:alert(1)">unsafe</a><img src="x" onerror="run()">'
            . '<script>alert(1)</script><iframe src="https://example.com"></iframe>'
        );
        self::assertStringNotContainsString('javascript:', $footer);
        self::assertStringNotContainsString('<img', $footer);
        self::assertStringNotContainsString('<script', $footer);
        self::assertStringNotContainsString('<iframe', $footer);
        self::assertStringContainsString('<a>unsafe</a>', $footer);
    }

    public function testFooterAllowsMailtoLinks(): void
    {
        $footer = $this->service->sanitizeFooter('<a href="mailto:support@example.com">Email us</a>');
        self::assertStringContainsString(
            '<a href="mailto:support@example.com" rel="noopener noreferrer">Email us</a>',
            $footer
        );
    }
}
