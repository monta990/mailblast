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
        // Recognized tags are rebuilt from scratch. Any '<' that is not part of
        // a complete recognized tag is escaped afterwards, including the start
        // of malformed/unterminated tags. Placeholders keep rebuilt markup
        // separate from attacker-controlled text during that final escaping pass.
        $allowedTags = ['b', 'strong', 'i', 'em', 'u', 'br', 'p', 'div', 'span', 'a'];
        $footer = preg_replace('~<!--.*?-->~s', '', $footer) ?? '';
        $placeholderPrefix = 'MAILBLASTFOOTER_' . bin2hex(random_bytes(16)) . '_';
        $rebuiltTags = [];

        $sanitized = (string) preg_replace_callback(
            '~</?([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>~s',
            static function (array $match) use ($allowedTags, $placeholderPrefix, &$rebuiltTags): string {
                $tag = strtolower($match[1]);
                if (!in_array($tag, $allowedTags, true)) {
                    return '';
                }

                $closing = str_starts_with($match[0], '</');
                if ($closing) {
                    $rebuilt = $tag === 'br' ? '' : '</' . $tag . '>';
                } elseif ($tag !== 'a') {
                    $rebuilt = '<' . $tag . '>';
                } else {
                    // Accept quoted or unquoted href values, then validate the decoded URL.
                    $attributes = $match[2];
                    if (preg_match('~(?:^|\s)href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $attributes, $href) !== 1) {
                        $rebuilt = '<a>';
                    } else {
                        $rawUrl = $href[1] ?? '';
                        if ($rawUrl === '') {
                            $rawUrl = $href[2] ?? '';
                        }
                        if ($rawUrl === '') {
                            $rawUrl = $href[3] ?? '';
                        }
                        $url = trim(html_entity_decode($rawUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                        $validHttp = in_array($scheme, ['http', 'https'], true)
                            && preg_match('~^https?://[^\s]+$~i', $url) === 1;
                        $validMailto = $scheme === 'mailto'
                            && preg_match('~^mailto:[^\s]+$~i', $url) === 1;

                        $rebuilt = ($validHttp || $validMailto)
                            ? '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                                . '" rel="noopener noreferrer">'
                            : '<a>';
                    }
                }

                $placeholder = $placeholderPrefix . count($rebuiltTags) . '_END';
                $rebuiltTags[$placeholder] = $rebuilt;
                return $placeholder;
            },
            $footer
        );

        // This is the critical malformed-input guard: a '<' not consumed by a
        // complete matched tag cannot become markup when Twig renders the value.
        $sanitized = str_replace('<', '&lt;', $sanitized);

        return $rebuiltTags === [] ? $sanitized : strtr($sanitized, $rebuiltTags);
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
