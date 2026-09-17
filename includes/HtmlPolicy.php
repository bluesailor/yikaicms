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
    private const DESCRIPTION_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'span', 'mark', 'small', 'sub', 'sup', 'ul', 'ol', 'li', 'a'];
    private const DESCRIPTION_DROP = ['iframe', 'video', 'audio', 'source', 'img', 'form', 'input', 'button', 'select', 'textarea'];

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
    public static function richText(?string $html, bool $allowInlineFormatting = false): string
    {
        return self::filterHtml($html, false, true, $allowInlineFormatting);
    }

    /** Short descriptions allow text formatting, never layout or executable embeds. */
    public static function description(?string $html, bool $allowLinks = true): string
    {
        return self::filterHtml($html, true, $allowLinks);
    }

    private static function filterHtml(?string $html, bool $description, bool $allowLinks, bool $allowInlineFormatting = false): string
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
        self::sanitizeChildren($root, $description, $allowLinks, $allowInlineFormatting);
        if ($description) {
            $remaining = 20000;
            foreach (iterator_to_array($root->childNodes) as $child) {
                self::limitDescriptionNode($child, $doc, $remaining);
            }
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child) ?: '';
        }
        return $out;
    }

    private static function sanitizeChildren(DOMNode $parent, bool $description = false, bool $allowLinks = true, bool $allowInlineFormatting = false): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (!in_array($tag, $description ? self::DESCRIPTION_TAGS : self::ALLOWED_TAGS, true)
                || ($description && !$allowLinks && $tag === 'a')) {
                if (in_array($tag, self::DROP_WITH_CONTENT, true)
                    || ($description && in_array($tag, self::DESCRIPTION_DROP, true))) {
                    $parent->removeChild($child);
                    continue;
                }
                self::sanitizeChildren($child, $description, $allowLinks, $allowInlineFormatting);
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }
            if (!self::sanitizeElement($child, $tag, $description, $allowInlineFormatting)) {
                $parent->removeChild($child);
                continue;
            }
            self::sanitizeChildren($child, $description, $allowLinks, $allowInlineFormatting);
        }
    }

    private static function sanitizeElement(DOMElement $element, string $tag, bool $description = false, bool $allowInlineFormatting = false): bool
    {
        $style = '';
        if ($element->hasAttribute('style')) {
            $style = $description
                ? self::descriptionStyle($element->getAttribute('style'))
                : ($allowInlineFormatting ? self::richTextStyle($element->getAttribute('style')) : '');
        }
        $allowed = array_merge($description ? ['title'] : self::GLOBAL_ATTRIBUTES, self::TAG_ATTRIBUTES[$tag] ?? []);
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttributeNode($attribute);
            }
        }

        if ($style !== '') {
            $element->setAttribute('style', $style);
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

    /**
     * 正文富文本允许的内联样式。
     *
     * 工具栏一直提供字号、前景/背景色和段落对齐，TinyMCE 把它们写成内联 style，
     * 而这里以前对非 description 分支一律清空 style —— 编辑器里设好的格式保存后
     * 在前台全部消失（2026-09-18 复审 R08）。放行范围严格限定为这几类排版属性，
     * 值再按白名单校验，url()/expression() 这类一律进不来。
     */
    private static function richTextStyle(string $style): string
    {
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            $pair = explode(':', $declaration, 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$property, $value] = array_map('trim', $pair);
            $property = strtolower($property);
            $value = strtolower(trim($value, ' '));
            if ($value === '' || str_contains($value, '(') && !preg_match('/^rgba?\(/', $value)) {
                continue;   // 只允许 rgb()/rgba() 这一种函数形式
            }
            if (in_array($property, ['color', 'background-color'], true)) {
                if (self::isSafeColor($value)) {
                    $safe[$property] = $value;
                }
                continue;
            }
            if ($property === 'font-size' && preg_match('/^(\d{1,3})(px|pt)$/D', $value, $m) === 1) {
                $size = (int) $m[1];
                if ($size >= 8 && $size <= 96) {
                    $safe[$property] = $value;
                }
                continue;
            }
            if ($property === 'text-align' && in_array($value, ['left', 'center', 'right', 'justify'], true)) {
                $safe[$property] = $value;
                continue;
            }
            if ($property === 'text-decoration' && in_array($value, ['underline', 'line-through', 'underline line-through'], true)) {
                $safe[$property] = $value;
            }
        }
        $out = [];
        foreach ($safe as $property => $value) {
            $out[] = $property . ': ' . $value;
        }
        return implode('; ', $out);
    }

    /** 颜色字面量白名单：十六进制、rgb()/rgba() 与少数关键字。 */
    private static function isSafeColor(string $value): bool
    {
        return preg_match(
            '/^(?:#[0-9a-f]{3,4}|#[0-9a-f]{6}|#[0-9a-f]{8}|rgba?\([0-9.,%\s]+\)|black|white|red|green|blue|yellow|gray|grey|orange|purple|teal|navy|transparent|currentcolor)$/D',
            $value
        ) === 1;
    }

    private static function descriptionStyle(string $style): string
    {
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            $pair = explode(':', $declaration, 2);
            if (count($pair) !== 2) continue;
            [$property, $value] = array_map('trim', $pair);
            $property = strtolower($property);
            $value = strtolower($value);
            if (in_array($property, ['color', 'background-color'], true) && self::isSafeColor($value)) {
                $safe[$property] = $value;
            } elseif ($property === 'text-decoration' && in_array($value, ['underline', 'line-through', 'underline line-through'], true)) {
                $safe[$property] = $value;
            }
        }
        $out = [];
        foreach ($safe as $property => $value) $out[] = $property . ': ' . $value;
        return implode('; ', $out);
    }

    /** Trim at DOM/text boundaries so repeated saves keep the same valid HTML. */
    private static function limitDescriptionNode(DOMNode $node, DOMDocument $doc, int &$remaining): void
    {
        $length = mb_strlen($doc->saveHTML($node) ?: '');
        if ($length <= $remaining) {
            $remaining -= $length;
            return;
        }
        if ($node instanceof DOMText) {
            $text = $node->data;
            $low = 0;
            $high = min(mb_strlen($text), $remaining);
            while ($low < $high) {
                $mid = (int) ceil(($low + $high) / 2);
                $candidate = $doc->createTextNode(mb_substr($text, 0, $mid));
                if (mb_strlen($doc->saveHTML($candidate) ?: '') <= $remaining) $low = $mid;
                else $high = $mid - 1;
            }
            $node->data = mb_substr($text, 0, $low);
            $remaining = 0;
            return;
        }
        $overhead = mb_strlen($doc->saveHTML($node->cloneNode(false)) ?: '');
        if ($overhead > $remaining || !$node instanceof DOMElement) {
            $node->parentNode?->removeChild($node);
            $remaining = 0;
            return;
        }
        $remaining -= $overhead;
        foreach (iterator_to_array($node->childNodes) as $child) {
            self::limitDescriptionNode($child, $doc, $remaining);
        }
    }

    /** iframe 嵌入地址是否可信（http(s)/协议相对 + 可信域或其子域） */
    public static function trustedEmbedSrc(string $src): bool
    {
        return UrlPolicy::isTrustedIframeHost($src);
    }
}
