<?php
/** Blox 元素本地资源收集器：按实际渲染节点收集、校验并去重输出 CSS/JS。 */

declare(strict_types=1);

final class BloxAssetCollector
{
    /** @var array<string,true> */
    private static array $scripts = [];
    /** @var array<string,true> */
    private static array $styles = [];
    /** @var array<string,true> */
    private static array $renderedStyles = [];
    /** @var array<string,bool> 作者自定义 CSS（已净化）=> 是否已输出 */
    private static array $inlineCss = [];
    private static bool $booted = false;
    /** 前台 <head> 末尾开的缓冲所在层级；0 = 没开（见 openHeadBuffer） */
    private static int $headBufferLevel = 0;

    /** @psalm-suppress PossiblyUnusedMethod 测试专用（单测进程共享请求级收集状态时复位） */
    public static function resetForTests(): void
    {
        self::$scripts = [];
        self::$styles = [];
        self::$renderedStyles = [];
        self::$inlineCss = [];
    }

    public static function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (function_exists('add_action')) {
            add_action('ik_footer_scripts', [self::class, 'renderFooterAssets'], 5);
            add_action('ik_head', [self::class, 'openHeadBuffer'], 1000);
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
        $path = self::legacyPath($path);
        if (self::validLocalAsset($path, 'js')) {
            if (in_array($path, ['/assets/js/yikay-interactions.js', '/assets/js/yikay-counter.js', '/assets/js/yikay-carousel.js', '/assets/js/yikay-banner.js', '/assets/js/yikay-video-policy.js', '/assets/js/yikay-dot-nav.js'], true)) {
                self::addScript('/assets/js/scroll-anim.js');
            }
            self::$scripts[$path] = true;
        }
    }

    public static function addStyle(string $path): void
    {
        $path = self::legacyPath($path);
        if (self::validLocalAsset($path, 'css')) {
            self::$styles[$path] = true;
        }
    }

    /** 元素 / 页面 / 类的作者自定义 CSS：调用方负责先经 BloxCustomCode::checkCss 净化。 */
    public static function addInlineCss(string $css): void
    {
        $css = trim($css);
        if ($css !== '' && !isset(self::$inlineCss[$css])) {
            self::$inlineCss[$css] = false;
        }
    }

    public static function renderStyles(): string
    {
        $html = '';
        foreach (array_keys(self::$styles) as $path) {
            if (isset(self::$renderedStyles[$path])) {
                continue;
            }
            $html .= '<link rel="stylesheet" href="' . htmlspecialchars(self::assetUrl($path), ENT_QUOTES) . '">' . "\n";
            self::$renderedStyles[$path] = true;
        }
        $pending = array_keys(array_filter(self::$inlineCss, static fn(bool $rendered): bool => !$rendered));
        if ($pending !== []) {
            // 放在样式区最后：晚于主题、设计 token、全局类与元素资源，作者的自定义 CSS 才压得住它们
            $html .= '<style data-yk-custom-css>' . implode("\n", $pending) . '</style>' . "\n";
            foreach ($pending as $css) {
                self::$inlineCss[$css] = true;
            }
        }
        return $html;
    }

    /** Re-emit collected styles after a captured theme footer discarded hook output. */
    public static function rewindRenderedStyles(): void
    {
        self::$renderedStyles = [];
        self::$inlineCss = array_map(static fn(): bool => false, self::$inlineCss);
    }

    public static function renderScripts(): string
    {
        $html = '';
        foreach (array_keys(self::$scripts) as $path) {
            $html .= '<script src="' . htmlspecialchars(self::assetUrl($path), ENT_QUOTES) . '"></script>' . "\n";
        }
        return $html;
    }

    /**
     * 前台页面在 <head> 最后开一个输出缓冲。
     *
     * 元素用到的样式要渲染正文时才知道，那时 <head> 早已输出；原来这些样式只能放在页尾，
     * 轮播、遮罩等先按无样式画一遍再跳变（布局偏移）。页脚收齐后把缓冲取出、先写样式再写正文，
     * 样式就正好落在 </head> 前。不用占位符改写：整页缓存（HtmlCache）拿到的已经是排好的成品。
     * 后台（含 Blox 画布预览，它自己分段捕获页头页脚）与命令行（无 REQUEST_URI）不开。
     */
    public static function openHeadBuffer(): void
    {
        // 只对网页请求：命令行脚本没有 REQUEST_URI
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($uri) || self::$headBufferLevel > 0 || str_contains($uri, '/admin/')) {
            return;
        }
        ob_start();
        self::$headBufferLevel = ob_get_level();
    }

    /**
     * 把 <head> 缓冲原样交还（不重排）。HtmlCache::end() 在取整页之前调用：页面没走到页脚时，
     * 缓存不能只拿到半截。层级更深的缓冲到这时都已无人收尾，一并按顺序交出去。
     */
    public static function closeHeadBuffer(): void
    {
        if (self::$headBufferLevel === 0) {
            return;
        }
        while (ob_get_level() >= self::$headBufferLevel) {
            ob_end_flush();
        }
        self::$headBufferLevel = 0;
    }

    public static function renderFooterAssets(): void
    {
        if (self::$headBufferLevel > 0 && ob_get_level() === self::$headBufferLevel) {
            $body = (string) ob_get_clean();
            self::$headBufferLevel = 0;
            echo self::renderStyles();   // 落在 <head> 末尾
            echo $body;
        } else {
            // 中间有人没关缓冲：不动它，样式照旧放页尾（缓冲在 HtmlCache::end 或脚本结束时交还）
            echo self::renderStyles();
        }
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
        self::$renderedStyles = [];
        self::$inlineCss = [];
    }

    /**
     * 2.0.4 起核心构建器资源由 blox-* 改名为 yikay-*。客户自改的主题 / overrides 里写死的旧地址
     * （如 /assets/js/blox-counter.js）映射到新文件，避免效果静默失效。升级站可能残留旧文件，新文件存在就优先用新的。
     */
    private static function legacyPath(string $path): string
    {
        if (preg_match('#^/assets/(js|css)/blox-([a-z0-9-]+\.(?:js|css))$#D', $path, $m) !== 1) {
            return $path;
        }
        $renamed = '/assets/' . $m[1] . '/yikay-' . $m[2];
        return !defined('ROOT_PATH') || is_file(ROOT_PATH . $renamed) ? $renamed : $path;
    }

    private static function validLocalAsset(string $path, string $extension): bool
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }
        if (preg_match(
            '#^/(?:assets/|plugins/|themes/[a-zA-Z0-9_-]+/assets/)[a-zA-Z0-9_./-]+\.' . preg_quote($extension, '#') . '$#',
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
