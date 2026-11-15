<?php
/**
 * Mail Blast service.
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Mailblast\Service;


final class ContentService
{
    public function html2text(string $html): string
        {
            // Block-level elements → newlines before stripping tags
            $text = preg_replace('/<br\s*\/?>/i',          "\n",       $html);
            $text = preg_replace('/<\/p>/i',                "\n\n",    $text);
            $text = preg_replace('/<\/(?:div|section)>/i',  "\n",       $text);
            $text = preg_replace('/<hr[^>]*>/i',             "\n---\n", $text);
            $text = preg_replace('/<\/h[1-6]>/i',           "\n\n",    $text);
            $text = preg_replace('/<h[1-6][^>]*>/i',         "\n",       $text);
            $text = preg_replace('/<\/tr>/i',               "\n",       $text);
            $text = preg_replace('/<\/li>/i',               "\n",       $text);
            $text = preg_replace('/<li[^>]*>/i',             "  • ",      $text);
            $text = preg_replace('/<\/t[dh]>/i',              "\t",        $text);
            $text = preg_replace('/<t[dh][^>]*>/i',           '',          $text);
    
            // Strip remaining tags, then decode entities
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
            // Normalise whitespace
            $text = preg_replace('/[ \t]+/', ' ',        $text);
            $text = preg_replace('/\n{3,}/', "\n\n",   $text);
    
            return trim($text);
        }

    public function sanitizeFooter(string $footer): string
    {
        // Rebuild every allowed element instead of trying to remove dangerous
        // attributes. This also handles unquoted attributes and malformed input.
        $allowedTags = ['b', 'strong', 'i', 'em', 'u', 'br', 'p', 'div', 'span', 'a'];
        $footer = preg_replace('~<!--.*?-->~s', '', $footer) ?? '';

        return (string) preg_replace_callback(
            '~</?([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>~s',
            static function (array $match) use ($allowedTags): string {
                $tag = strtolower($match[1]);
                if (!in_array($tag, $allowedTags, true)) {
                    return '';
                }

                $closing = str_starts_with($match[0], '</');
                if ($closing) {
                    return $tag === 'br' ? '' : '</' . $tag . '>';
                }
                if ($tag !== 'a') {
                    return '<' . $tag . ($tag === 'br' ? '>' : '>');
                }

                // Accept quoted or unquoted href values, then validate the decoded URL.
                $attributes = $match[2];
                if (preg_match('~(?:^|\\s)href\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s>]+))~i', $attributes, $href) !== 1) {
                    return '<a>';
                }
                $rawUrl = $href[1] !== '' ? $href[1] : ($href[2] !== '' ? $href[2] : ($href[3] ?? ''));
                $url = trim(html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
                    return '<a>';
                }
                if ($scheme !== 'mailto' && !preg_match('~^https?://~i', $url)) {
                    return '<a>';
                }
                if ($scheme === 'mailto' && !preg_match('~^mailto:[^\\s]+$~i', $url)) {
                    return '<a>';
                }

                return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '" rel="noopener noreferrer">';
            },
            $footer
        );
    }

    public function buildHtmlBody(string $htmlBody, string $footer): string
        {
            if (trim(strip_tags($footer)) !== '') {
                $htmlBody .= '<br>'
                    . '<hr style="border:none;border-top:1px solid #cccccc;margin:24px 0">'
                    . '<div style="color:#666666;font-size:12px;line-height:1.5">'
                    . $footer
                    . '</div>';
            }
    
            // Wrap in a minimal but valid HTML5 document so email clients
            // interpret charset and base font correctly.
            return '<!DOCTYPE html>'
                . '<html><head>'
                . '<meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '</head><body style="margin:0;padding:16px;font-family:sans-serif;font-size:14px;line-height:1.6;color:#333333">'
                . $htmlBody
                . '</body></html>';
        }

}
