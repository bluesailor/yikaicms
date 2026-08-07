<?php
/** Blox 元素本地资源收集器：按实际渲染节点收集、校验并去重输出 CSS/JS。 */

declare(strict_types=1);

final class BloxAssetCollector
{
    /** @var array<string,true> */
    private static array $scripts = [];
    /** @var array<string,true> */
    private static array $styles = [];
    private static bool $booted = false;

    public static function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (function_exists('add_action')) {
            add_action('ik_footer_scripts', [self::class, 'renderFooterAssets'], 5);
        }
    }

    /** @param array<string,mixed> $data */
    public static function collectElement(AbstractElement $element, array $data): void
    {
        foreach ($element->stylesFor($data) as $path) {
            self::addStyle($path);
        }
        foreach ($element->scriptsFor($data) as $path) {
            self::addScript($path);
        }
    }

    public static function addScript(string $path): void
    {
        if (self::validLocalAsset($path, 'js')) {
            self::$scripts[$path] = true;
        }
    }

    public static function addStyle(string $path): void
    {
        if (self::validLocalAsset($path, 'css')) {
            self::$styles[$path] = true;
        }
    }

    public static function renderStyles(): string
    {
        $html = '';
        foreach (array_keys(self::$styles) as $path) {
            $html .= '<link rel="stylesheet" href="' . htmlspecialchars(self::assetUrl($path), ENT_QUOTES) . '">' . "\n";
        }
        return $html;
    }

    public static function renderScripts(): string
    {
        $html = '';
        foreach (array_keys(self::$scripts) as $path) {
            $html .= '<script src="' . htmlspecialchars(self::assetUrl($path), ENT_QUOTES) . '"></script>' . "\n";
        }
        return $html;
    }

    public static function renderFooterAssets(): void
    {
        echo self::renderStyles();
        echo self::renderScripts();
    }

    /** @return list<string> */
    /** @psalm-suppress PossiblyUnusedMethod Public inspection API for previews and extensions. */
    public static function scripts(): array
    {
        return array_keys(self::$scripts);
    }

    /** @return list<string> */
    /** @psalm-suppress PossiblyUnusedMethod Public inspection API for previews and extensions. */
    public static function styles(): array
    {
        return array_keys(self::$styles);
    }

    /** @psalm-suppress PossiblyUnusedMethod Used by isolated contract tests. */
    public static function reset(): void
    {
        self::$scripts = [];
        self::$styles = [];
    }

    private static function validLocalAsset(string $path, string $extension): bool
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }
        if (preg_match(
            '#^/(?:assets|plugins)/[a-zA-Z0-9_./-]+\.' . preg_quote($extension, '#') . '$#',
            $path
        ) !== 1) {
            return false;
        }
        return !defined('ROOT_PATH') || is_file(ROOT_PATH . $path);
    }

    private static function assetUrl(string $path): string
    {
        return function_exists('assetVer') ? assetVer($path) : $path;
    }
}
