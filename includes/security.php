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
    // 危险元素整段删除（含内容）
    $svg = preg_replace('#<\s*(script|foreignObject|iframe|embed|object|animate|set|handler)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $svg) ?? $svg;
    // 危险元素的自闭合 / 落单开标签
    $svg = preg_replace('#<\s*(script|foreignObject|iframe|embed|object|animate|set|handler)\b[^>]*/?>#is', '', $svg) ?? $svg;
    // on* 事件处理属性（onload / onclick …）
    $svg = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/is', '', $svg) ?? $svg;
    // href / xlink:href / src 里的 javascript: 或 data:（保留普通 http/相对引用）
    $svg = preg_replace('/(href|xlink:href|src)\s*=\s*("|\')\s*(javascript|data)\s*:[^"\']*\2/is', '$1=$2#$2', $svg) ?? $svg;
    return $svg;
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
    if ($html === null || $html === '') return '';

    // 允许的标签白名单
    $allowedTags = '<p><br><b><i><u><s><em><strong><small><sub><sup>'
        . '<h1><h2><h3><h4><h5><h6>'
        . '<ul><ol><li><dl><dt><dd>'
        . '<table><thead><tbody><tfoot><tr><th><td><caption><colgroup><col>'
        . '<a><img><figure><figcaption>'
        . '<blockquote><pre><code><hr><div><span>'
        . '<video><source><audio><iframe>';

    // 第一步：移除 script/style 标签及其内容
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;

    // 第二步：strip_tags 保留白名单
    $html = strip_tags($html, $allowedTags);

    // 第三步：移除事件属性（on*）和 javascript: 协议
    $html = preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html) ?? $html;
    $html = preg_replace('/(?:href|src|action)\s*=\s*["\']?\s*javascript\s*:/i', 'data-removed="1"', $html) ?? $html;

    // 第四步：iframe src 限定可信视频平台（Host 精确比对）
    $html = preg_replace_callback(
        '/<iframe\b([^>]*)>/i',
        function ($matches) {
            $attrs = $matches[1];
            if (preg_match('/src\s*=\s*["\']([^"\']+)["\']/i', $attrs, $srcMatch)) {
                if (!trustedIframeHost(html_entity_decode($srcMatch[1]))) {
                    return '<!-- iframe removed -->';
                }
            }
            return $matches[0];
        },
        $html
    ) ?? $html;

    return $html;
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
