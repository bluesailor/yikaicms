<?php
/**
 * 插件图标（2.0.3）：后台「已安装」与「插件市场」两处卡片共用。
 *
 * 只用项目自托管的 Tabler 图标字体（类名 ti-xxx），不加载图片、不连外部 CDN。取值顺序：
 *   1. 插件 plugin.json 的 "icon"（第三方插件可自己声明，例如 "ti-truck"）；
 *   2. 官方插件的内置对照表——与更新服务器插件目录（data/plugins.json 的 icon 字段）一致，
 *      存量站不必更新每个插件也能显示；
 *   3. 默认拼图 ti-puzzle。
 * 社区市场条目不采用目录里的图标，只认官方条目，避免冒用官方图标。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class PluginIcons
{
    public const DEFAULT = 'ti-puzzle';

    /** 官方插件 → 图标（与线上插件目录同步，2026-10-02） */
    private const OFFICIAL = [
        'announcement' => 'ti-speakerphone',
        'back-to-top' => 'ti-arrow-up',
        'cookie-consent' => 'ti-cookie',
        'menu-sort' => 'ti-arrows-sort',
        'product-carousel' => 'ti-carousel-horizontal',
        'search-replace' => 'ti-replace',
        'stats' => 'ti-chart-bar',
        'product-import' => 'ti-file-import',
        'logo-maker' => 'ti-palette',
        'seo' => 'ti-world-search',
        'dologin' => 'ti-login-2',
        'yikai-builder' => 'ti-layout-dashboard',
        'shop' => 'ti-shopping-bag',
        'stay-inquiry' => 'ti-home-question',
        'zh-tw' => 'ti-language',
    ];

    public static function valid(mixed $icon): bool
    {
        return is_string($icon) && preg_match('/^ti-[a-z0-9-]{2,48}$/D', $icon) === 1;
    }

    /** 已安装插件：plugin.json 声明 > 官方对照表 > 默认。 */
    public static function forInstalled(string $slug, array $manifest): string
    {
        if (self::valid($manifest['icon'] ?? null)) {
            return (string) $manifest['icon'];
        }
        return self::OFFICIAL[$slug] ?? self::DEFAULT;
    }

    /** 市场条目：官方条目用目录给的图标（缺则用对照表），社区条目一律默认。 */
    public static function forMarket(array $item): string
    {
        if (($item['source'] ?? 'official') !== 'official') {
            return self::DEFAULT;
        }
        if (self::valid($item['icon'] ?? null)) {
            return (string) $item['icon'];
        }
        return self::OFFICIAL[(string) ($item['slug'] ?? '')] ?? self::DEFAULT;
    }
}
