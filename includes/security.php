<?php
/**
 * YikaiCMS — 安全工具（纯函数，从 functions.php 抽出以便单测，无外部依赖）。
 *
 * - zipUnsafeEntry(): zip-slip 条目检测（解压前调用）
 * - sanitizeSvg():    SVG XSS 净化（上传 SVG 落盘后调用）
 */

declare(strict_types=1);

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
