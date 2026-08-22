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

        // 第三步 · 前置：把 URL 属性里的 HTML 实体解码回来再检查。
        // 浏览器读属性值时会解码实体，所以 href="java&#x73;cript:..." 与
        // href="jav&#10;ascript:..." 在浏览器眼里都是 javascript:，而按原文做正则
        // 的话一个都拦不住。（codex 审计 P1-1，已复现）
        // 只对 href/src/action 的值解码——正文里的实体是内容，不能动。
        $html = preg_replace_callback(
            '/\b(href|src|action)\s*=\s*(["\'])(.*?)\2/is',
            static function (array $m): string {
                $decoded = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // 控制字符同样要去掉：jav\tascript: 之类靠它们绕过关键字匹配
                $decoded = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $decoded);
                return $m[1] . '=' . $m[2] . $decoded . $m[2];
            },
            $html
        ) ?? $html;

        // 第三步 · 前置二：srcdoc 整条删掉。它的值是一整份 HTML 文档，浏览器会
        // 解码实体后当页面执行——<iframe srcdoc="&lt;script&gt;…"> 是完整的存储型
        // XSS 载荷，而下面的 src 白名单只看 src，看不见它。（codex 审计 P1-1，已复现）
        $html = preg_replace('/\bsrcdoc\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html) ?? $html;

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
