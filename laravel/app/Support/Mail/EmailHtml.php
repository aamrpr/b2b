<?php

namespace App\Support\Mail;

/**
 * يُبقي من رسالة البريد ما يعرضه عميل البريد بأمان.
 *
 * المحرّر يُخرج فقرات وقوائم وروابط. وأي وسمٍ آخر — سكربت أو إطار —
 * يُحذف قبل الحفظ وقبل الإرسال، حتى لو وُضع في النص يدوياً.
 */
final class EmailHtml
{
    public static function clean(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? '';
        $html = strip_tags($html, '<p><br><strong><b><em><i><u><s><h2><h3><ul><ol><li><a><blockquote>');

        $html = preg_replace_callback('/<(p|h2|h3|blockquote|li)\b[^>]*>/i', function (array $match): string {
            $tag = strtolower($match[1]);

            if (preg_match('/text-align\s*:\s*(left|center|right)/i', $match[0], $align)) {
                return '<'.$tag.' style="text-align: '.strtolower($align[1]).'">';
            }

            return '<'.$tag.'>';
        }, $html) ?? $html;

        $html = preg_replace_callback('/<a\b[^>]*>/i', function (array $match): string {
            if (! preg_match('/href\s*=\s*("|\')(.*?)\1/i', $match[0], $href)) {
                return '<a>';
            }

            $url = trim(html_entity_decode($href[2], ENT_QUOTES));

            if (! preg_match('#^(https?://|mailto:)#i', $url)) {
                return '<a>';
            }

            return '<a href="'.e($url).'" target="_blank" rel="noopener" style="color:#16A6A1">';
        }, $html) ?? $html;

        return trim($html);
    }
}
