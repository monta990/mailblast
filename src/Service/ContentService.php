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
        $allowed_tags = '<b><strong><i><em><u><br><p><div><span><a>';
        $footer = strip_tags($footer, $allowed_tags);

        $footer = preg_replace_callback(
            '~<a\\b([^>]*)>~i',
            static function (array $match): string {
                $attrs = $match[1];

                if (!preg_match('~\\bhref\\s*=\\s*(["\\\'])(.*?)\\1~i', $attrs, $href)) {
                    return '<a>';
                }

                $url = trim(html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if (!preg_match('~^(?:https?://|mailto:)~i', $url)) {
                    return '<a>';
                }

                $safe_url = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="' . $safe_url . '" rel="noopener noreferrer">';
            },
            $footer
        );

        // Remove event handlers and attributes that can carry executable or embedded content.
        $footer = preg_replace('~\\s+on[a-z]+\\s*=\\s*(["\\\']).*?\\1~isu', '', $footer);
        $footer = preg_replace('~\\s+(?:style|srcdoc)\\s*=\\s*(["\\\']).*?\\1~isu', '', $footer);

        return $footer;
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
