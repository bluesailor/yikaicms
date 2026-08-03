<?php
/**
 * YikaiCMS —— 标签级 HTML 属性改写器。
 *
 * 用途：在**不重排 HTML**的前提下，定位起始标签并读写其属性
 * （懒加载、alt 兜底、外链 rel、标题锚点、区块 style 等）。
 * 未被改写的字节原样保留——这是黄金对拍的前提，也是不用 DOMDocument
 * 的原因：DOM 会补全标签、重排属性、改变实体写法。
 *
 * 实现边界（有意为之，不做完整 HTML5 解析）：
 *   - 只处理起始标签的属性，不构建节点树、不匹配闭合关系；
 *   - script / style / textarea 的原始文本内容整体跳过，避免把
 *     JS 字符串里的 "<img …>" 当成真标签改写；
 *   - 注释、DOCTYPE、结束标签直接跳过；
 *   - 同名属性以第一个为准（与浏览器一致）。
 *
 * 读取属性时解码字符实体，写入时重新转义，两端对称。
 */

declare(strict_types=1);

final class HtmlTagRewriter
{
    /** 原始 HTML，全程只读 */
    private string $html;

    /** 扫描游标 */
    private int $cursor = 0;

    /** 当前标签名（大写，与调用方既有约定一致），无当前标签为 null */
    private ?string $tagName = null;

    /** 当前标签内的属性表：小写名 => ['ns','ne','vs','ve','quoted','hasValue'] */
    private array $attrs = [];

    /** 本轮 setAttribute 写入的值，供同一标签内再次读取 */
    private array $overlay = [];

    /** 当前标签名结束位置——新属性插在此处（与既有输出保持一致） */
    private int $tagNameEnd = 0;

    /** 待应用的替换：[start, end, text]，end 为开区间 */
    private array $edits = [];

    /** 原始文本元素：其内容不参与标签扫描 */
    private const RAW_TEXT = ['SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE'];

    public function __construct(string $html)
    {
        $this->html = $html;
    }

    /**
     * 移动到下一个起始标签；传入标签名则只匹配该标签（大小写不敏感）。
     */
    public function nextTag(?string $name = null): bool
    {
        $want = $name === null ? null : strtoupper($name);
        $len = strlen($this->html);

        while ($this->cursor < $len) {
            $lt = strpos($this->html, '<', $this->cursor);
            if ($lt === false) {
                break;
            }

            // 注释
            if (substr($this->html, $lt, 4) === '<!--') {
                $end = strpos($this->html, '-->', $lt + 4);
                $this->cursor = $end === false ? $len : $end + 3;
                continue;
            }
            // DOCTYPE / CDATA / 处理指令 / 结束标签
            $next = $this->html[$lt + 1] ?? '';
            if ($next === '!' || $next === '?' || $next === '/') {
                $end = strpos($this->html, '>', $lt + 1);
                $this->cursor = $end === false ? $len : $end + 1;
                continue;
            }
            // 起始标签名必须以字母开头，否则 '<' 只是普通文本
            if ($next === '' || !ctype_alpha($next)) {
                $this->cursor = $lt + 1;
                continue;
            }

            $parsed = $this->parseStartTag($lt);
            if ($parsed === null) {
                $this->cursor = $lt + 1;
                continue;
            }

            [$tag, $attrs, $afterTag, $nameEnd] = $parsed;

            // 原始文本元素：内容整体跳过（自身仍可被匹配到）
            $resume = $afterTag;
            if (in_array($tag, self::RAW_TEXT, true)) {
                $closeTag = '</' . strtolower($tag);
                $pos = stripos($this->html, $closeTag, $afterTag);
                $resume = $pos === false ? $len : $pos;
            }

            $this->cursor = $resume;

            if ($want !== null && $tag !== $want) {
                continue;
            }

            $this->tagName = $tag;
            $this->attrs = $attrs;
            $this->tagNameEnd = $nameEnd;
            $this->overlay = [];
            return true;
        }

        $this->tagName = null;
        $this->attrs = [];
        $this->overlay = [];
        return false;
    }

    /** 当前标签名（大写）；无当前标签返回 null */
    public function getTag(): ?string
    {
        return $this->tagName;
    }

    /**
     * 读取属性值：字符串（已解码）、true（无值属性）、null（不存在）。
     */
    public function getAttribute(string $name): string|bool|null
    {
        if ($this->tagName === null) {
            return null;
        }
        $key = strtolower($name);
        if (array_key_exists($key, $this->overlay)) {
            return $this->overlay[$key];
        }
        if (!isset($this->attrs[$key])) {
            return null;
        }
        $a = $this->attrs[$key];
        if (!$a['hasValue']) {
            return true;
        }
        $raw = substr($this->html, $a['vs'], $a['ve'] - $a['vs']);
        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 写入属性。已存在则就地替换，不存在则插入到标签结束符之前。
     * $value 传 true 输出无值属性。
     */
    public function setAttribute(string $name, string|bool $value): void
    {
        if ($this->tagName === null) {
            return;
        }
        $key = strtolower($name);
        $this->overlay[$key] = $value;

        $text = $value === true
            ? $name
            : $name . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';

        if (isset($this->attrs[$key])) {
            $a = $this->attrs[$key];
            // 覆盖「名=值」整体，保留原名的书写位置
            $end = $a['hasValue'] ? $a['ve'] + ($a['quoted'] ? 1 : 0) : $a['ne'];
            $this->edits[] = [$a['ns'], $end, $text];
            return;
        }

        $this->edits[] = [$this->tagNameEnd, $this->tagNameEnd, ' ' . $text];
    }

    /** 追加 class（已存在则不重复） */
    public function addClass(string $className): void
    {
        $className = trim($className);
        if ($className === '' || $this->tagName === null) {
            return;
        }
        $current = $this->getAttribute('class');
        $tokens = is_string($current) ? preg_split('/\s+/', trim($current)) : [];
        $tokens = array_values(array_filter((array) $tokens, static fn($t) => $t !== ''));
        if (in_array($className, $tokens, true)) {
            return;
        }
        $tokens[] = $className;
        $this->setAttribute('class', implode(' ', $tokens));
    }

    /** 应用全部改写并返回结果；不改变内部状态，可重复调用 */
    public function getUpdatedHtml(): string
    {
        if ($this->edits === []) {
            return $this->html;
        }
        // 从后往前套用；同一插入点按「先写的后套用」，与既有实现的属性排布一致
        $edits = $this->edits;
        foreach (array_keys($edits) as $i) {
            $edits[$i][3] = $i;
        }
        usort($edits, static fn(array $a, array $b) => ($b[0] <=> $a[0]) ?: ($a[3] <=> $b[3]));

        $out = $this->html;
        foreach ($edits as [$start, $end, $text]) {
            $out = substr($out, 0, $start) . $text . substr($out, $end);
        }
        return $out;
    }

    /**
     * 解析起始标签。
     *
     * @return array{0:string,1:array<string,array{ns:int,ne:int,vs:int,ve:int,quoted:bool,hasValue:bool}>,2:int,3:int}|null
     *         [标签名(大写), 属性表, 标签之后的位置, 标签名结束位置]
     */
    private function parseStartTag(int $lt): ?array
    {
        $len = strlen($this->html);
        $i = $lt + 1;
        $nameStart = $i;
        while ($i < $len && !ctype_space($this->html[$i]) && $this->html[$i] !== '>' && $this->html[$i] !== '/') {
            $i++;
        }
        $tag = strtoupper(substr($this->html, $nameStart, $i - $nameStart));
        if ($tag === '') {
            return null;
        }
        $nameEnd = $i;

        $attrs = [];
        while ($i < $len) {
            while ($i < $len && ctype_space($this->html[$i])) {
                $i++;
            }
            if ($i >= $len) {
                return null; // 标签未闭合，整体放弃
            }
            $ch = $this->html[$i];
            if ($ch === '>') {
                return [$tag, $attrs, $i + 1, $nameEnd];
            }
            if ($ch === '/' && ($this->html[$i + 1] ?? '') === '>') {
                return [$tag, $attrs, $i + 2, $nameEnd];
            }
            // 属性名
            $ns = $i;
            while (
                $i < $len
                && !ctype_space($this->html[$i])
                && $this->html[$i] !== '='
                && $this->html[$i] !== '>'
                && !($this->html[$i] === '/' && ($this->html[$i + 1] ?? '') === '>')
            ) {
                $i++;
            }
            $ne = $i;
            if ($ne === $ns) {           // 无法推进（如孤立的 '/'），跳过一个字符防死循环
                $i++;
                continue;
            }
            $attrName = strtolower(substr($this->html, $ns, $ne - $ns));

            $save = $i;
            while ($i < $len && ctype_space($this->html[$i])) {
                $i++;
            }
            if (($this->html[$i] ?? '') !== '=') {
                // 无值属性
                $i = $save;
                if (!isset($attrs[$attrName])) {
                    $attrs[$attrName] = ['ns' => $ns, 'ne' => $ne, 'vs' => $ne, 've' => $ne, 'quoted' => false, 'hasValue' => false];
                }
                continue;
            }
            $i++; // 跳过 '='
            while ($i < $len && ctype_space($this->html[$i])) {
                $i++;
            }
            $q = $this->html[$i] ?? '';
            if ($q === '"' || $q === "'") {
                $vs = $i + 1;
                $ve = strpos($this->html, $q, $vs);
                if ($ve === false) {
                    return null;
                }
                $i = $ve + 1;
                $quoted = true;
            } else {
                $vs = $i;
                while ($i < $len && !ctype_space($this->html[$i]) && $this->html[$i] !== '>') {
                    $i++;
                }
                $ve = $i;
                $quoted = false;
            }
            if (!isset($attrs[$attrName])) {
                $attrs[$attrName] = ['ns' => $ns, 'ne' => $ne, 'vs' => $vs, 've' => $ve, 'quoted' => $quoted, 'hasValue' => true];
            }
        }

        return null;
    }
}
