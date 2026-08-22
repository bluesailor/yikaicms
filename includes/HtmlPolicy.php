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
    private const ALLOWED_TAGS = [
        'p', 'br', 'b', 'i', 'u', 's', 'em', 'strong', 'small', 'sub', 'sup',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'a', 'img', 'figure', 'figcaption', 'blockquote', 'pre', 'code', 'hr', 'div', 'span',
        'video', 'source', 'audio', 'iframe',
    ];

    private const DROP_WITH_CONTENT = ['script', 'style', 'template', 'object', 'embed', 'svg', 'math'];

    private const GLOBAL_ATTRIBUTES = ['class', 'title', 'lang', 'dir'];

    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy'],
        'video' => ['src', 'poster', 'width', 'height', 'controls', 'autoplay', 'loop', 'muted', 'preload'],
        'audio' => ['src', 'controls', 'autoplay', 'loop', 'muted', 'preload'],
        'source' => ['src', 'type', 'media'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'td' => ['colspan', 'rowspan'],
        'col' => ['span'],
        'ol' => ['start', 'reversed', 'type'],
        'li' => ['value'],
        'blockquote' => ['cite'],
    ];

    /**
     * 富文本净化：移除危险标签和属性，保留安全的格式化标签。
     * iframe 只放行可信视频平台（Host 精确比对，见 UrlPolicy）。
     */
    public static function richText(?string $html): string
    {
        if ($html === null || $html === '') return '';
        if (!class_exists('DOMDocument')) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadHTML(
                '<?xml encoding="UTF-8"><div id="yk-richtext-root">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return '';
        }

        $root = null;
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'yk-richtext-root') {
                $root = $div;
                break;
            }
        }
        if (!$root instanceof DOMElement) {
            return '';
        }
        self::sanitizeChildren($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child) ?: '';
        }
        return $out;
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $parent->removeChild($child);
                    continue;
                }
                self::sanitizeChildren($child);
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }
            if (!self::sanitizeElement($child, $tag)) {
                $parent->removeChild($child);
                continue;
            }
            self::sanitizeChildren($child);
        }
    }

    private static function sanitizeElement(DOMElement $element, string $tag): bool
    {
        $allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::TAG_ATTRIBUTES[$tag] ?? []);
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttributeNode($attribute);
            }
        }

        if ($element->hasAttribute('href')) {
            $safe = UrlPolicy::href($element->getAttribute('href'));
            $safe === '' ? $element->removeAttribute('href') : $element->setAttribute('href', $safe);
        }
        if ($element->hasAttribute('cite')) {
            $safe = UrlPolicy::href($element->getAttribute('cite'), false);
            $safe === '' ? $element->removeAttribute('cite') : $element->setAttribute('cite', $safe);
        }
        if ($element->hasAttribute('src')) {
            $src = $element->getAttribute('src');
            $safe = match ($tag) {
                'iframe' => self::trustedEmbedSrc($src) ? trim($src) : '',
                'img' => self::safeImageSrc($src),
                default => UrlPolicy::href($src, false),
            };
            $safe === '' ? $element->removeAttribute('src') : $element->setAttribute('src', $safe);
        }
        if ($tag === 'iframe' && !$element->hasAttribute('src')) {
            return false;
        }
        if ($tag === 'video' && $element->hasAttribute('poster')) {
            $poster = UrlPolicy::image($element->getAttribute('poster'));
            $poster === '' ? $element->removeAttribute('poster') : $element->setAttribute('poster', $poster);
        }
        if ($element->hasAttribute('target')) {
            $target = strtolower($element->getAttribute('target'));
            if (!in_array($target, ['_blank', '_self', '_parent', '_top'], true)) {
                $element->removeAttribute('target');
            } elseif ($target === '_blank') {
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
        return true;
    }

    private static function safeImageSrc(string $src): string
    {
        $src = trim($src);
        if (preg_match('#^data:image/(?:png|gif|jpe?g|webp);base64,[a-z0-9+/=\r\n]+$#i', $src) === 1) {
            return strlen($src) <= 5_000_000 ? $src : '';
        }
        return UrlPolicy::image($src);
    }

    /** iframe 嵌入地址是否可信（http(s)/协议相对 + 可信域或其子域） */
    public static function trustedEmbedSrc(string $src): bool
    {
        return UrlPolicy::isTrustedIframeHost($src);
    }
}
