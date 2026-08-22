<?php
/**
 * 全站 HTML 输出安全策略的唯一权威实现（v1.18.6，与 UrlPolicy 配对）。
 *
 * 三层语义（权限从宽到严）：
 *   1. richText()     普通编辑人员可用——标签白名单 + 事件/伪协议剥离 +
 *                     iframe 仅可信视频平台。sanitizeHtml() 即其函数门面。
 *   2. trustedEmbedSrc()  iframe 嵌入地址判定（委托 UrlPolicy 的可信域规则）。
 *   3. rawHtml        原样输出层：不在这里实现——由 BloxElementPolicy 的
 *                     blox_code 权限闸控制（保存时无权限直接拒绝提交），
 *                     保证 edit_page ≠ 任意 JavaScript。
 *
 * 纯静态、仅依赖 UrlPolicy（同为零依赖）：单测可独立加载。
 */

declare(strict_types=1);

require_once __DIR__ . '/UrlPolicy.php';

final class HtmlPolicy
{
    /** richText 层的标签白名单 */
    private const ALLOWED_TAGS = '<p><br><b><i><u><s><em><strong><small><sub><sup>'
        . '<h1><h2><h3><h4><h5><h6>'
        . '<ul><ol><li><dl><dt><dd>'
        . '<table><thead><tbody><tfoot><tr><th><td><caption><colgroup><col>'
        . '<a><img><figure><figcaption>'
        . '<blockquote><pre><code><hr><div><span>'
        . '<video><source><audio><iframe>';

    /**
     * 富文本净化：移除危险标签和属性，保留安全的格式化标签。
     * iframe 只放行可信视频平台（Host 精确比对，见 UrlPolicy）。
     */
    public static function richText(?string $html): string
    {
        if ($html === null || $html === '') return '';

        // 第一步：移除 script/style 标签及其内容
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

        // 第二步：strip_tags 保留白名单
        $html = strip_tags($html, self::ALLOWED_TAGS);

        // 第三步：移除事件属性（on*）与危险伪协议。
        // javascript/vbscript 全拦；data: 只拦非图片形态——data:text/html 可承载
        // 完整攻击页，data:image/* 是富文本粘贴内联图的合法场景（v1.18.6 补：
        // 此前只拦 javascript:，vbscript:/data:text/html 的 href 会被放行）
        $html = preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html) ?? $html;
        $html = preg_replace('/(?:href|src|action)\s*=\s*["\']?\s*(?:javascript|vbscript)\s*:/i', 'data-removed="1"', $html) ?? $html;
        $html = preg_replace('/(?:href|src|action)\s*=\s*["\']?\s*data\s*:(?!\s*image\/)/i', 'data-removed="1"', $html) ?? $html;

        // 第四步：iframe src 限定可信视频平台（Host 精确比对）
        $html = preg_replace_callback(
            '/<iframe\b([^>]*)>/i',
            static function (array $matches): string {
                $attrs = $matches[1];
                if (preg_match('/src\s*=\s*["\']([^"\']+)["\']/i', $attrs, $srcMatch)) {
                    if (!self::trustedEmbedSrc(html_entity_decode($srcMatch[1]))) {
                        return '<!-- iframe removed -->';
                    }
                }
                return $matches[0];
            },
            $html
        ) ?? $html;

        return $html;
    }

    /** iframe 嵌入地址是否可信（http(s)/协议相对 + 可信域或其子域） */
    public static function trustedEmbedSrc(string $src): bool
    {
        return UrlPolicy::isTrustedIframeHost($src);
    }
}
