<?php
/**
 * YikaiCMS — 安全工具（纯函数，从 functions.php 抽出以便单测，无外部依赖）。
 *
 * - zipUnsafeEntry(): zip-slip 条目检测（解压前调用）
 * - sanitizeSvg():    SVG XSS 净化（上传 SVG 落盘后调用）
 * - sanitizeHtml():   富文本 HTML 净化（原在 functions.php，移此便于单测）
 */

declare(strict_types=1);

require_once __DIR__ . '/UrlPolicy.php';   // URL 安全策略唯一权威实现（v1.18.6 统一）
require_once __DIR__ . '/HtmlPolicy.php';  // HTML 输出安全策略唯一权威实现（v1.18.6 统一）

/**
 * zip-slip 防护：检查 ZIP 内是否有会逃出解压目录的条目名。
 * 命中绝对路径、盘符、`..` 段或以 / 开头即判定不安全，调用方应中止 extractTo。
 * 返回首个不安全条目名（安全则返回 null）。
 */
function zipUnsafeEntry(ZipArchive $zip): ?string
{
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $norm = str_replace('\\', '/', $name);
        if ($norm === ''
            || $norm[0] === '/'                             // 绝对路径 /etc/...
            || preg_match('#^[A-Za-z]:#', $norm)            // Windows 盘符 C:\
            || preg_match('#(^|/)\.\.(/|$)#', $norm)) {     // 任意 .. 目录穿越
            return $name;
        }
    }
    return null;
}

/**
 * SVG 净化：移除 <script>/<foreignObject>、on* 事件属性、javascript: 与 data: 协议引用等
 * XSS 载体。上传 SVG 时用，返回净化后的 SVG 文本。
 */
function sanitizeSvg(string $svg): string
{
    // 去掉 DOCTYPE / 实体声明（防 XXE / 实体炸弹）与 XML 处理指令
    $svg = preg_replace('/<!DOCTYPE.*?>/is', '', $svg) ?? $svg;
    $svg = preg_replace('/<\?xml.*?\?>/is', '', $svg) ?? $svg;

    if (!class_exists(DOMDocument::class)) {
        return sanitizeSvgFallback($svg);
    }

    $previousErrors = libxml_use_internal_errors(true);
    try {
        $document = new DOMDocument();
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $root = $document->documentElement;
        if (!$loaded || !$root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            return sanitizeSvgFallback($svg);
        }

        $elements = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        $dangerousElements = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'animate', 'set', 'handler', 'style'];
        foreach ($elements as $element) {
            if (in_array(strtolower($element->localName), $dangerousElements, true)) {
                $element->parentNode?->removeChild($element);
                continue;
            }

            for ($index = $element->attributes->length - 1; $index >= 0; $index--) {
                $attribute = $element->attributes->item($index);
                if (!$attribute instanceof DOMAttr) {
                    continue;
                }
                $name = strtolower($attribute->localName ?: $attribute->name);
                $qualifiedName = strtolower($attribute->name);
                $value = $attribute->value;
                if (str_starts_with($name, 'on')
                    || ((in_array($name, ['href', 'src'], true) || $qualifiedName === 'xlink:href')
                        && svgHasDangerousUrl($value))
                    || ($name === 'style' && svgHasDangerousCss($value))) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }

        $clean = $document->saveXML($root);
        return is_string($clean) ? $clean : sanitizeSvgFallback($svg);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
    }
}

function svgHasDangerousUrl(string $value): bool
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $normalized = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $decoded));
    return str_starts_with($normalized, 'javascript:') || str_starts_with($normalized, 'data:');
}

function svgHasDangerousCss(string $value): bool
{
    $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return preg_match('/(?:javascript\s*:|data\s*:|expression\s*\(|@import|-moz-binding)/i', $decoded) === 1;
}

function sanitizeSvgFallback(string $svg): string
{
    // 危险元素整段删除（含内容）
    $svg = preg_replace('#<\s*(script|foreignObject|iframe|embed|object|animate|set|handler|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $svg) ?? $svg;
    // 危险元素的自闭合 / 落单开标签
    $svg = preg_replace('#<\s*(script|foreignObject|iframe|embed|object|animate|set|handler|style)\b[^>]*/?>#is', '', $svg) ?? $svg;
    // on* 事件处理属性（onload / onclick …）
    $svg = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/is', '', $svg) ?? $svg;
    // 分支分别匹配单双引号，允许属性值内出现另一种引号。
    return preg_replace_callback(
        '/\b(href|xlink:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/is',
        static function (array $matches): string {
            $value = (string) ($matches[2] !== '' ? $matches[2] : ($matches[3] !== '' ? $matches[3] : $matches[4]));
            return svgHasDangerousUrl($value) ? $matches[1] . '="#"' : $matches[0];
        },
        $svg
    ) ?? $svg;
}

/**
 * 净化富文本HTML，移除危险标签和属性，保留安全的格式化标签。
 *
 * iframe 只放行可信视频平台，且按 parse_url 的 Host 精确比对（等于可信域
 * 或其子域）。不能用 str_contains——`youtube.com.evil.com` 字符串里同样
 * 包含 `youtube.com`，子串匹配挡不住伪装域名。
 */
function sanitizeHtml(?string $html): string
{
    // v1.18.6 起委托 HtmlPolicy::richText()——三层 HTML 策略见该类头注释
    return HtmlPolicy::richText($html);
}

/**
 * iframe src 是否指向可信视频平台：http(s) 或协议相对，且 Host 等于
 * 可信域或其子域。相对路径、其它协议、Host 解析失败一律拒绝。
 */
function trustedIframeHost(string $src): bool
{
    return UrlPolicy::isTrustedIframeHost($src);
}

/**
 * 判断上传文件的检测 MIME 是否与扩展名匹配。
 * WebM 在部分 fileinfo 数据库中会回落为 octet-stream，此时只接受 EBML 文件头，
 * 不能把通用二进制 MIME 整类加入白名单。
 *
 * @param list<string> $allowedMimes
 */
function uploadMimeMatches(string $extension, string $detectedMime, string $path, array $allowedMimes): bool
{
    if (in_array($detectedMime, $allowedMimes, true)) {
        return true;
    }
    if ($extension !== 'webm' || $detectedMime !== 'application/octet-stream') {
        return false;
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $signature = fread($handle, 4);
    fclose($handle);
    return $signature === "\x1A\x45\xDF\xA3";
}

/**
 * ZIP 解压资源限制（Zip Bomb 防护）：小 ZIP 解压出巨大体积会耗尽磁盘/inode/
 * PHP 超时。在 extractTo / 逐条流式写入之前调用，与 zipUnsafeEntry 同批。
 *
 * 压缩比检查带 1MB 起判阈值：小文本文件天然高压缩比，不该误伤。
 * 返回首条违规的英文描述（调用方包进本地化提示）；安全返回 null。
 * 默认限值按主题/插件包设定；升级全量包更大，调用方自行放宽。
 */
function zipResourceViolation(
    ZipArchive $zip,
    int $maxFiles = 5000,
    int $maxTotalBytes = 209_715_200,   // 200 MB
    int $maxFileBytes = 20_971_520,     // 20 MB
    int $maxRatio = 100
): ?string {
    if ($zip->numFiles > $maxFiles) {
        return 'too many entries (' . $zip->numFiles . ' > ' . $maxFiles . ')';
    }
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) {
            continue;
        }
        $size = (int) ($st['size'] ?? 0);
        $comp = (int) ($st['comp_size'] ?? 0);
        $name = (string) ($st['name'] ?? ('#' . $i));
        if ($size > $maxFileBytes) {
            return 'entry too large: ' . $name . ' (' . round($size / 1048576, 1) . ' MB > '
                . round($maxFileBytes / 1048576, 1) . ' MB)';
        }
        if ($size > 1_048_576 && $comp > 0 && intdiv($size, $comp) > $maxRatio) {
            return 'suspicious compression ratio: ' . $name . ' (' . intdiv($size, $comp) . ':1)';
        }
        $total += $size;
        if ($total > $maxTotalBytes) {
            return 'total uncompressed size exceeds ' . round($maxTotalBytes / 1048576) . ' MB';
        }
    }
    return null;
}
