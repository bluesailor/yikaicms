<?php
/**
 * 全站 URL 安全策略的唯一权威实现（v1.18.6，审计第三/四轮「统一 UrlPolicy」）。
 *
 * 此前同一语义散落五处：functions.php safeUrl() / AbstractElement::safeHref() /
 * security.php trustedIframeHost() / VideoElement::isTrustedEmbedUrl() /
 * HomeBannerItemElement::safeUrl()。规则一旦要改（如新增可信平台），
 * 五处各改一遍必然漂移。现在全部委托到这里，原函数名与调用方零改动。
 *
 * 纯静态类、零外部依赖（与 security.php 同一模式）：单测可独立加载，
 * builder 引擎的自包含约束靠「文件可独立 require」满足。
 */

declare(strict_types=1);

final class UrlPolicy
{
    /** href 上限：超长 URL 视为异常输入 */
    public const MAX_LENGTH = 2000;

    /** sanitizeHtml 的 iframe 可信域（等于或子域命中即放行） */
    public const IFRAME_TRUSTED_DOMAINS = [
        'youtube.com', 'youtube-nocookie.com', 'youtu.be',
        'bilibili.com', 'v.qq.com', 'youku.com', 'vimeo.com',
    ];

    /** 视频元素 iframe 嵌入的精确 Host 白名单（比 iframe 域更严：不收子域通配） */
    public const VIDEO_EMBED_HOSTS = [
        'www.youtube.com', 'youtube.com',
        'www.youtube-nocookie.com', 'youtube-nocookie.com',
        'player.bilibili.com',
        'player.vimeo.com',
        'v.qq.com',
        'player.youku.com',
    ];

    /**
     * 可安全写入 href 的链接。htmlspecialchars 只能防属性逃逸，防不了
     * javascript: 伪协议——转义后仍是可点击执行的存储型 XSS。
     *
     * 允许：站内相对路径（排除协议相对 //）、锚点、查询串、http(s)；
     * $allowActionSchemes 时另允许 mailto/tel；$allowLoopPlaceholder 时
     * 放行动态循环模板的 {yk:field name=x /} 占位符（整串精确匹配、
     * 不含 fallback 等附加属性，无注入载体）。
     * 不合法返回空串，调用方据此不渲染链接。
     */
    public static function href(
        mixed $value,
        bool $allowActionSchemes = true,
        bool $allowLoopPlaceholder = false
    ): string {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return '';
        }
        if ($value[0] === '/' && !str_starts_with($value, '//')) {
            return $value;
        }
        if ($value[0] === '#' || $value[0] === '?') {
            return $value;
        }
        if ($allowLoopPlaceholder && preg_match('/^\{yk:field name=[a-z0-9_]+ \/\}$/i', $value) === 1) {
            return $value;
        }
        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        return $allowActionSchemes && preg_match('#^(mailto|tel):#i', $value) === 1
            ? $value
            : '';
    }

    /**
     * 可用于 img src 的图片地址。
     * 仅站内绝对路径与 http(s)；协议相对、data/javascript 及控制字符一律拒绝。
     */
    public static function image(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim(strip_tags($value));
        if ($value === '' || mb_strlen($value) > 1000
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return '';
        }
        if ($value[0] === '/' && !str_starts_with($value, '//')) {
            return $value;
        }
        return preg_match('#^https?://#i', $value) === 1 ? $value : '';
    }

    /**
     * 可安全写入 CSS background-image 的 url() 字面量。
     *
     * HTML 转义不能阻止引号在浏览器解析 style 属性后逃出 url()；先走图片
     * URL 白名单，再编码 CSS 字符串中的引号与反斜杠。返回空串表示拒绝渲染。
     */
    public static function cssImageLiteral(mixed $value): string
    {
        $url = self::image($value);
        if ($url === '') {
            return '';
        }

        $url = str_replace(
            ['\\', '"', "'"],
            ['%5C', '%22', '%27'],
            $url
        );
        return 'url("' . $url . '")';
    }

    /**
     * 兼容早期 Blox 文档保存过的图片地址形态，仅用于已存图片字段。
     * 新输入仍应走 image()；这里额外接受协议相对 URL、uploads 裸路径和栅格 data URI。
     */
    public static function storedImage(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        $current = self::image($value);
        if ($current !== '') {
            return $current;
        }
        if (preg_match('#^data:image/(?:png|gif|jpe?g|webp);base64,([a-z0-9+/=]+)$#i', $value, $matches) === 1) {
            return strlen($value) <= 5_000_000 && base64_decode($matches[1], true) !== false ? $value : '';
        }
        if (mb_strlen($value) > self::MAX_LENGTH
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $value) === 1) {
            return '';
        }
        if (str_starts_with($value, '//')) {
            $parts = parse_url('https:' . $value);
            if (is_array($parts) && !isset($parts['user']) && !isset($parts['pass'])
                && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
                && (string) ($parts['host'] ?? '') !== '') {
                return 'https:' . $value;
            }
            return '';
        }
        if (preg_match('#^uploads/#i', $value) === 1
            && mb_strlen($value) <= 1000) {
            $path = rawurldecode((string) parse_url('/' . $value, PHP_URL_PATH));
            $segments = explode('/', trim($path, '/'));
            return in_array('..', $segments, true) ? '' : '/' . $value;
        }
        return '';
    }

    /**
     * HTTP Location 目标：仅站内绝对路径或与站点同源的 http(s) URL。
     * 前缀比较不能作为同源判断，example.com.evil.test 会通过 starts_with。
     */
    public static function redirect(mixed $value, string $siteUrl): string
    {
        if (!is_string($value)) return '';
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $value) === 1) {
            return '';
        }
        if ($value[0] === '/') {
            return str_starts_with($value, '//') ? '' : $value;
        }

        $target = parse_url($value);
        $site = parse_url(trim($siteUrl));
        if (!is_array($target) || !is_array($site) || isset($target['user']) || isset($target['pass'])) {
            return '';
        }
        $targetScheme = strtolower((string) ($target['scheme'] ?? ''));
        $siteScheme = strtolower((string) ($site['scheme'] ?? ''));
        if (!in_array($targetScheme, ['http', 'https'], true) || $targetScheme !== $siteScheme) {
            return '';
        }
        if (strtolower((string) ($target['host'] ?? '')) !== strtolower((string) ($site['host'] ?? ''))) {
            return '';
        }
        $defaultPort = $targetScheme === 'https' ? 443 : 80;
        $targetPort = (int) ($target['port'] ?? $defaultPort);
        $sitePort = (int) ($site['port'] ?? $defaultPort);
        return $targetPort === $sitePort ? $value : '';
    }

    /** 视频元素 iframe 嵌入地址：https + 精确 Host 白名单 */
    public static function isTrustedVideoEmbed(string $url): bool
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        return $scheme === 'https' && in_array($host, self::VIDEO_EMBED_HOSTS, true);
    }

    /**
     * 富文本 iframe src 是否指向可信视频平台：http(s) 或协议相对，
     * 且 Host 等于可信域或其子域。相对路径、其它协议、Host 解析失败一律拒绝。
     * 不能用 str_contains——youtube.com.evil.com 字符串里同样包含 youtube.com。
     */
    public static function isTrustedIframeHost(string $src): bool
    {
        $parts = parse_url(trim($src));
        if ($parts === false) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !in_array($scheme, ['http', 'https', ''], true)) {
            return false;
        }
        foreach (self::IFRAME_TRUSTED_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }
}
