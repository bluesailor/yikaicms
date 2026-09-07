<?php

declare(strict_types=1);

/** Share the theme's presentation shell without executing its page scripts in the editor. */
final class BloxPreviewShell
{
    public static string $styles = '';
    public static bool $captured = false;
    public static string $body = '<body class="yk-site-body min-h-screen flex flex-col">';
    public static string $main = '<main class="flex-1">';

    public static function capture(string $html): void
    {
        self::$captured = true;
        $head = preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $match) === 1 ? $match[1] : '';
        preg_match_all('/<style\b[^>]*>.*?<\/style>|<link\b[^>]*>/is', $head, $matches);
        self::$styles = '';
        foreach ($matches[0] as $tag) {
            if (str_starts_with(strtolower($tag), '<style')) {
                self::$styles .= $tag;
                continue;
            }
            $processor = new HtmlTagRewriter($tag);
            if ($processor->nextTag() && strtolower((string) $processor->getAttribute('rel')) === 'stylesheet') {
                self::$styles .= $tag;
            }
        }
        foreach (['body', 'main'] as $name) {
            if (preg_match('/<' . $name . '\b[^>]*>/i', $html, $match) !== 1) continue;
            $processor = new HtmlTagRewriter($match[0]);
            if (!$processor->nextTag()) continue;
            $opening = '<' . $name;
            foreach (['class', 'style'] as $attr) {
                $value = $processor->getAttribute($attr);
                if (is_string($value)) $opening .= ' ' . $attr . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
            }
            self::${$name} = $opening . '>';
        }
    }
}
