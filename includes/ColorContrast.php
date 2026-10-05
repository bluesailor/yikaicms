<?php
/**
 * 颜色对比度（WCAG 2.x 相对亮度公式）。2.0.5 前台无障碍：用品牌色 / 自选色写字的地方（时间线日期等），
 * 把颜色同色相调暗到浅底上够读，圆点、渐变这类非文字仍用原色。纯函数，不依赖站点上下文。
 * PHP 8.0+
 */

declare(strict_types=1);

final class ColorContrast
{
    /** 最严的浅底：gray-50 比纯白更暗一点，在它上面够读，白底上一定够读 */
    public const LIGHT_BACKGROUND = '#f9fafb';

    /** 相对亮度（0–1）；不是 #rrggbb 时返回 null */
    public static function luminance(string $hex): ?float
    {
        if (preg_match('/^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m) !== 1) return null;
        $channel = static function (string $pair): float {
            $s = hexdec($pair) / 255;
            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $channel($m[1]) + 0.7152 * $channel($m[2]) + 0.0722 * $channel($m[3]);
    }

    /** 两色对比度（1–21）；任一不是 #rrggbb 时返回 0 */
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        if ($la === null || $lb === null) return 0.0;
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * 作文字用的同色相颜色：每步按 8% 调暗，直到在浅底上达到 $ratio（WCAG AA 正文 4.5）。
     * 本来就够深的、或不是 #rrggbb 的原样返回。
     */
    public static function readableText(string $hex, float $ratio = 4.5): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex) !== 1) return $hex;
        $color = strtolower($hex);
        for ($i = 0; $i < 30 && self::ratio($color, self::LIGHT_BACKGROUND) < $ratio; $i++) {
            $color = self::darken($color, 0.08);
        }
        return $color;
    }

    /** 每个通道按比例变暗（与 shadeHex 的变暗分支同一口径） */
    private static function darken(string $hex, float $pct): string
    {
        $out = '#';
        foreach (str_split(substr($hex, 1), 2) as $pair) {
            $out .= sprintf('%02x', max(0, min(255, (int) round(hexdec($pair) * (1 - $pct)))));
        }
        return $out;
    }
}
