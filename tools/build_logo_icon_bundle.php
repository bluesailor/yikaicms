<?php
/**
 * 把 logo-maker 的 7618 个散 SVG 打成「索引 + 数据块」两个文件。
 *
 * 由来（2026-08-22）：插件从核心包移出、改走插件市场后装不上——市场安装走
 * zipResourceViolation() 的 zip 炸弹防护，默认上限 5000 条目，而它有 7651 个。
 * 提高上限只能救新版 CMS，存量站（v1.18.5 及更早）用的仍是 5000，等于永远装不上。
 * 所以打包不是优化项而是必需项：条目数降到 ~35，任何版本都能装。
 *
 * 格式选型：不用「一个大数组的 PHP/JSON」——那要为取一个图标解析 4MB。
 * 改为 icons.bin（全部 SVG 顺序拼接）+ icons-index.php（名字 → [偏移, 长度]），
 * 取用时 fseek/fread 只读需要的那一段。
 *
 * 用法：php tools/build_logo_icon_bundle.php
 * 图标素材有变更时重跑；产物随插件包分发，散 SVG 留在仓库做源。
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$libDir = $root . '/plugins/logo-maker/assets/icon-library';
if (!is_dir($libDir)) {
    fwrite(STDERR, "找不到图标库目录：{$libDir}\n");
    exit(1);
}

$index = [];
$binPath = $libDir . '/icons.bin';
$out = fopen($binPath, 'wb');
if ($out === false) {
    fwrite(STDERR, "无法写入 {$binPath}\n");
    exit(1);
}

$offset = 0;
$count = 0;
foreach (glob($libDir . '/*/*/*.svg') ?: [] as $file) {
    $rel = substr($file, strlen($libDir) + 1);
    $key = str_replace('\\', '/', substr($rel, 0, -4));   // tabler/outline/home
    $svg = file_get_contents($file);
    if ($svg === false || !str_contains($svg, '<svg')) {
        continue;
    }
    $len = strlen($svg);
    fwrite($out, $svg);
    $index[$key] = [$offset, $len];
    $offset += $len;
    $count++;
}
fclose($out);

ksort($index);
$php = "<?php\n"
    . "/**\n"
    . " * logo-maker 图标索引：名字 → [icons.bin 中的偏移, 长度]。\n"
    . " * 由 tools/build_logo_icon_bundle.php 生成，请勿手改。\n"
    . " * 共 {$count} 个图标。\n"
    . " */\n\n"
    . "declare(strict_types=1);\n\n"
    . "return " . var_export($index, true) . ";\n";
file_put_contents($libDir . '/icons-index.php', $php);

printf(
    "已生成：\n  icons.bin        %7.2f MB（%d 个图标）\n  icons-index.php  %7.2f MB\n",
    $offset / 1048576,
    $count,
    filesize($libDir . '/icons-index.php') / 1048576
);
