<?php
/**
 * 子目录部署的挂载点。
 *
 * 装在域名根目录时 get() === ''，本类的一切行为退化为空操作 —— 现有根目录站点逐字节不变。
 *
 * 模式：入口剥前缀、出口补前缀。应用内部始终按「装在根目录」的口径工作：
 *   · 入口：bootstrap() 把 REQUEST_URI 的挂载前缀剥掉（原值存 YK_ORIGINAL_REQUEST_URI）。
 *     路由、页面缓存键、自定义产品路由、语言识别、hreflang 看到的路径与根目录安装完全一致。
 *   · 出口：HTML 属性里的根相对 URL、重定向 Location 头补回前缀；
 *     绝对地址由 siteBaseUrl() 负责；PHP 生成的内联脚本在生成处调用 url()；
 *     静态 JS 文件读 window.YK_BASE（仅子目录部署时注入，根目录下为 undefined）。
 *
 * 前缀来源：config.php 的 SITE_BASE_PATH 常量优先；否则按入口脚本相对安装目录自动推导——
 * 拷进哪个子目录就认哪个，零配置。推导不出一律回落为根目录，即改造前的行为。
 *
 * 为什么「每个根相对 URL 无条件补一次前缀」，而不是「已带前缀就跳过」：
 * 后者在栏目别名恰好等于挂载目录名时会漏补（挂在 /shop，站内链接 /shop/x.html
 * 会被误判为已经补过）。所以约定进出口两侧各只做一次，喂给出口的 HTML 一律是站内相对口径；
 * 唯一的例外——抓取生成、本身已带前缀的静态 HTML——由 markOutputFinal() 显式声明跳过。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/HtmlTagRewriter.php';

final class BasePath
{
    /** 值整体是一个 URL 的属性。 */
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'xlink:href', 'data-src', 'data-href'];

    /** 值是「URL 描述符, URL 描述符」列表的属性。 */
    private const SRCSET_ATTRIBUTES = ['srcset', 'data-srcset'];

    private static ?string $base = null;
    private static bool $booted = false;
    private static bool $final = false;

    public static function get(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        if (defined('SITE_BASE_PATH')) {
            return self::$base = self::normalize((string) constant('SITE_BASE_PATH'));
        }
        if (PHP_SAPI === 'cli') {
            return self::$base = '';
        }
        // 不依赖 ROOT_PATH：index.php 在 init.php 定义它之前就要用到前缀。
        // 本文件固定在 <安装目录>/includes/ 下，上一级就是安装目录。
        return self::$base = self::detect($_SERVER, dirname(__DIR__));
    }

    /**
     * 挂载前缀 =「URL 中的脚本路径」减去「脚本文件相对安装目录的路径」。
     * 例：SCRIPT_NAME=/sub/admin/x.php，文件=<ROOT>/admin/x.php → /sub。
     *
     * 两者对不上（反代改写、非常规 fastcgi 参数）时返回空串，按根目录处理——宁可不改，不可改错。
     *
     * @param array<string,mixed> $server
     */
    public static function detect(array $server, string $root): string
    {
        $scriptName = is_string($server['SCRIPT_NAME'] ?? null) ? $server['SCRIPT_NAME'] : '';
        $scriptFile = is_string($server['SCRIPT_FILENAME'] ?? null) ? $server['SCRIPT_FILENAME'] : '';
        if ($scriptName === '' || $scriptFile === '') {
            return '';
        }
        $rootReal = realpath($root);
        $fileReal = realpath($scriptFile);
        if ($rootReal === false || $fileReal === false) {
            return '';
        }
        $rootReal = rtrim(str_replace('\\', '/', $rootReal), '/');
        $fileReal = str_replace('\\', '/', $fileReal);

        // Windows 文件系统不区分大小写，URL 里的脚本路径与磁盘上的写法可能差在大小写
        $windows = PHP_OS_FAMILY === 'Windows';
        $inside = $windows
            ? stripos($fileReal, $rootReal . '/') === 0
            : str_starts_with($fileReal, $rootReal . '/');
        if (!$inside) {
            return '';
        }

        $relative = substr($fileReal, strlen($rootReal));
        if (strlen($scriptName) < strlen($relative)) {
            return '';
        }
        $tail = substr($scriptName, -strlen($relative));
        if ($windows ? strcasecmp($tail, $relative) !== 0 : $tail !== $relative) {
            return '';
        }
        return self::normalize(substr($scriptName, 0, strlen($scriptName) - strlen($relative)));
    }

    /**
     * 规范为「/段/段」或空串。
     *
     * 前缀会原样写进 HTML 与 JS 字符串，所以字符集收得很紧：
     * 含任何意外字符、或出现 . / .. 段，一律按根目录处理。
     */
    public static function normalize(string $raw): string
    {
        $value = trim($raw);
        if ($value === '' || $value === '/') {
            return '';
        }
        $value = '/' . trim($value, '/');
        if (preg_match('~^(?:/[A-Za-z0-9._\~-]+)+$~D', $value) !== 1) {
            return '';
        }
        return preg_match('~/\.\.?(?:/|$)~', $value) === 1 ? '' : $value;
    }

    /** 以单个 / 开头的站内路径；// 开头的协议相对地址与反斜杠变体都不算。 */
    public static function isRootRelative(string $url): bool
    {
        if ($url === '' || $url[0] !== '/') {
            return false;
        }
        $second = $url[1] ?? '';
        return $second !== '/' && $second !== '\\';
    }

    /** 给 PHP 生成的内联脚本、JSON 等「输出改写够不着」的位置用。 */
    public static function url(string $path): string
    {
        $base = self::get();
        return $base !== '' && self::isRootRelative($path) ? $base . $path : $path;
    }

    /** '/sub' 与 '/sub/' → '/'；'/sub/x?q' → '/x?q'；'/sub?q' → '/?q'；不在前缀下的原样返回。 */
    public static function strip(string $uri, ?string $base = null): string
    {
        $base ??= self::get();
        if ($base === '') {
            return $uri;
        }
        if ($uri === $base) {
            return '/';
        }
        $length = strlen($base);
        if (!str_starts_with($uri, $base) || !isset($uri[$length])) {
            return $uri;
        }
        $next = $uri[$length];
        if ($next === '/') {
            return substr($uri, $length);
        }
        return $next === '?' ? '/' . substr($uri, $length) : $uri;
    }

    /**
     * 入口调用一次（index.php 与 init.php 最先执行，幂等）。
     * 必须早于任何读取 REQUEST_URI 的代码，也必须早于其它 ob_start ——
     * 最先注册的缓冲在最外层、最后处理，才能拿到伪静态兼容改写、繁体转换之后的成品。
     */
    public static function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (PHP_SAPI === 'cli') {
            return;
        }
        $base = self::get();
        if ($base === '') {
            return;
        }
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $_SERVER['YK_ORIGINAL_REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_URI'] = self::strip($uri, $base);
        header_register_callback([self::class, 'prefixLocationHeader']);
        ob_start([self::class, 'output']);
    }

    /** 直出抓取生成的静态 HTML 时调用：那些文件在抓取时就带好了前缀，不能再补一次。 */
    public static function markOutputFinal(): void
    {
        self::$final = true;
    }

    /**
     * ob_start 回调（bootstrap() 以 [self::class, 'output'] 注册，静态分析看不到调用点）。
     * PHP 会额外传入缓冲阶段标志；本方法只在整段输出上工作，不需要它。
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function output(string $buffer): string
    {
        $base = self::get();
        if ($base === '' || self::$final || !self::isHtmlResponse()) {
            return $buffer;
        }
        return self::rewriteHtml($buffer, $base);
    }

    public static function rewriteHtml(string $html, string $base): string
    {
        if ($base === '' || $html === '') {
            return $html;
        }
        $rewriter = new HtmlTagRewriter($html);
        while ($rewriter->nextTag()) {
            foreach (self::URL_ATTRIBUTES as $name) {
                $value = $rewriter->getAttribute($name);
                if (is_string($value) && self::isRootRelative($value)) {
                    $rewriter->setAttribute($name, $base . $value);
                }
            }
            foreach (self::SRCSET_ATTRIBUTES as $name) {
                $value = $rewriter->getAttribute($name);
                if (is_string($value) && $value !== '') {
                    $rewritten = self::rewriteSrcset($value, $base);
                    if ($rewritten !== $value) {
                        $rewriter->setAttribute($name, $rewritten);
                    }
                }
            }
            $style = $rewriter->getAttribute('style');
            if (is_string($style) && stripos($style, 'url(') !== false) {
                $rewritten = self::rewriteCssUrls($style, $base);
                if ($rewritten !== $style) {
                    $rewriter->setAttribute('style', $rewritten);
                }
            }
            // og:image、og:url 等社交卡片：只改根相对的，已是绝对地址的不动
            if ($rewriter->getTag() === 'META') {
                $content = $rewriter->getAttribute('content');
                if (is_string($content) && self::isRootRelative($content)) {
                    $rewriter->setAttribute('content', $base . $content);
                }
            }
        }
        return self::injectBaseVariable($rewriter->getUpdatedHtml(), $base);
    }

    /** "/a.jpg 1x, /b.jpg 2x" → 每个候选单独判断，描述符原样保留。 */
    public static function rewriteSrcset(string $srcset, string $base): string
    {
        $candidates = explode(',', $srcset);
        foreach ($candidates as $index => $candidate) {
            $trimmed = ltrim($candidate);
            if (self::isRootRelative($trimmed)) {
                $candidates[$index] = substr($candidate, 0, strlen($candidate) - strlen($trimmed)) . $base . $trimmed;
            }
        }
        return implode(',', $candidates);
    }

    /** style 里的 url(/x)、url('/x')、url("/x")。 */
    public static function rewriteCssUrls(string $css, string $base): string
    {
        return (string) preg_replace_callback(
            '~url\(\s*([\'"]?)(/(?![/\\\\])[^\'")\s]*)\1\s*\)~i',
            static fn(array $m): string => 'url(' . $m[1] . $base . $m[2] . $m[1] . ')',
            $css
        );
    }

    /**
     * 内容数据里的图片路径（/uploads/…）按约定不带前缀入库，经 JSON 交给脚本渲染时没有出口改写
     * 可以经过——媒体库网格、编辑器控件缩略图、相册列表都是这样。数据本身不能改（前台出口会再补一次），
     * 所以只在显示端兜底：程序自有目录下的图片加载失败时，补上挂载前缀重试一次（data-yk-base 防循环）。
     */
    private const IMAGE_RETRY_JS = 'document.addEventListener("error",function(e){var t=e.target,s;'
        . 'if(!t||t.tagName!=="IMG"||t.getAttribute("data-yk-base"))return;s=t.getAttribute("src")||"";'
        . 'if(/^\/(?:uploads|assets|plugins|themes)\//.test(s)){t.setAttribute("data-yk-base","1");'
        . 't.setAttribute("src",window.YK_BASE+s);}},true);';

    /**
     * 给静态 JS 文件一个读前缀的地方：插在 <head> 之后、所有脚本之前。
     * 只在子目录部署时注入——根目录下页面逐字节不变，JS 侧按 (window.YK_BASE || '') 取用。
     */
    private static function injectBaseVariable(string $html, string $base): string
    {
        if (preg_match('~<head\b[^>]*>~i', $html, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $html;
        }
        $insertAt = $match[0][1] + strlen($match[0][0]);
        // normalize() 已把字符集收紧到 URL 安全集，这里可以直接写进 JS 字符串
        return substr($html, 0, $insertAt) . '<script>window.YK_BASE="' . $base . '";' . self::IMAGE_RETRY_JS . '</script>'
            . substr($html, $insertAt);
    }

    /**
     * header_register_callback：在响应头真正发出前，统一给根相对的重定向补前缀。
     * 一处收口覆盖全部重定向——包括绕过 redirect() 直接写 header('Location: /…') 的调用点。
     */
    public static function prefixLocationHeader(): void
    {
        $base = self::get();
        if ($base === '') {
            return;
        }
        foreach (headers_list() as $header) {
            if (stripos($header, 'location:') !== 0) {
                continue;
            }
            $target = trim(substr($header, 9));
            $prefixed = self::prefixLocation($target, $base);
            if ($prefixed !== $target) {
                // 状态码已是 3xx 时 header() 不会改动它，原来的 301/302/303 保持不变
                header('Location: ' . $prefixed, true);
            }
            return;
        }
    }

    /** 重定向目标的前缀规则（纯函数，便于测试；CLI 下 headers_list() 恒为空）。 */
    public static function prefixLocation(string $target, string $base): string
    {
        return $base !== '' && self::isRootRelative($target) ? $base . $target : $target;
    }

    private static function isHtmlResponse(): bool
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return stripos($header, 'html') !== false;
            }
        }
        $default = (string) ini_get('default_mimetype');
        return $default === '' || stripos($default, 'html') !== false;
    }
}
