<?php
/**
 * 扫所有 __('xxx') 调用，对比每个 lang/{code}.php，找出在任一启用语言中缺失的 key。
 *
 * 用法：
 *   php tools/check_lang_keys.php           # 检查 zh-CN / en / ja
 *   php tools/check_lang_keys.php --strict  # 同上，并列出"用了但未在源文件 zh-CN 里"的孤儿 key
 *   php tools/check_lang_keys.php zh-CN ja  # 仅检查指定语言
 *
 * 退出码：
 *   0 — 全部通过
 *   1 — 至少一个 key 在某语言下缺失
 *   2 — 用法错误
 *
 * 适合 CI/release-precheck 引用。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$ROOT = dirname(__DIR__);

// ── 参数 ──
$args = array_slice($argv, 1);
$strict = in_array('--strict', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--strict'));

$LANGS = $args !== [] ? $args : ['zh-CN', 'en', 'ja'];

// 仅保留实际存在 lang 文件的语言
$LANGS = array_values(array_filter($LANGS, fn($c) => is_file("$ROOT/lang/$c.php")));
if ($LANGS === []) {
    fwrite(STDERR, "No lang files found under $ROOT/lang/\n");
    exit(2);
}

// ── 1. 扫描代码里出现过的 key ──
$dirsToScan = [
    'themes', 'plugins', 'admin', 'includes', 'controllers', 'views',
    'list.php', 'detail.php', 'job_detail.php', 'product.php',
    'article.php', 'news.php', 'search.php', 'index.php',
    'page.php', 'sitemap.php', 'rss.php',
];

$usedKeys = [];          // key => first file:line
// __('xxx', ...) 和 __('xxx') 都算
$keyPattern = "/\b__\\(\\s*['\"]([a-z][a-zA-Z0-9_]*)['\"]\\s*[,)]/";
// configLang('settingsKey', 'langKey') —— 只取第二参数（langKey 才是 lang 文件里的 key）
$configLangPattern = "/\\bconfigLang\\(\\s*['\"][^'\"]*['\"]\\s*,\\s*['\"]([a-z][a-zA-Z0-9_]*)['\"]\\s*\\)/";

foreach ($dirsToScan as $rel) {
    $abs = "$ROOT/$rel";
    if (!file_exists($abs)) continue;

    if (is_file($abs)) {
        scanFile($abs, $usedKeys, $keyPattern, $configLangPattern, $ROOT);
        continue;
    }

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($rii as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            scanFile($f->getPathname(), $usedKeys, $keyPattern, $configLangPattern, $ROOT);
        }
    }
}

function scanFile(string $path, array &$used, string $keyPattern, string $configLangPattern, string $root): void {
    $src = (string) file_get_contents($path);
    if ($src === '') return;

    $rel = ltrim(str_replace($root, '', $path), '/');
    $lines = explode("\n", $src);

    foreach ($lines as $i => $line) {
        // 跳过注释行（粗略）
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '#')) {
            // 注释里也可能命中正则，但这是少数情况；保留扫，避免漏报
        }
        if (preg_match_all($keyPattern, $line, $m1)) {
            foreach ($m1[1] as $k) {
                $used[$k] ??= $rel . ':' . ($i + 1);
            }
        }
        if (preg_match_all($configLangPattern, $line, $m2)) {
            foreach ($m2[1] as $k) {
                $used[$k] ??= $rel . ':' . ($i + 1);
            }
        }
    }
}

// ── 2. 加载每个语言包 ──
$langData = [];
foreach ($LANGS as $code) {
    $langData[$code] = require "$ROOT/lang/$code.php";
    if (!is_array($langData[$code])) {
        fwrite(STDERR, "lang/$code.php did not return an array\n");
        exit(2);
    }
}

// ── 2b. 同文件重复 key 检测 ──
// require 加载后「后者赢」，重复对运行不可见，但 Psalm DuplicateArrayKey 会红 CI，
// 且重复本身通常意味着两处维护同一文案会漂移。token 级扫源码抓出来。
$dupTotal = 0;
foreach ($LANGS as $code) {
    $seen = [];
    foreach (token_get_all((string) file_get_contents("$ROOT/lang/$code.php")) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        // 只统计作为数组 key 出现的字符串：粗略以「行首缩进 + 'key' =>」判定成本高，
        // 这里用简化策略：lang 文件里每行一个 'key' => 'value'，key 是该行第一个字符串。
        [$id, $text, $line] = $token;
        $bare = trim($text, "'\"");
        if (!isset($seen['line:' . $line])) {
            $seen['line:' . $line] = true; // 每行只取第一个字符串 = key
            if (isset($seen[$bare])) {
                echo "✗ lang/$code.php:$line 重复 key '$bare'（首见于 {$seen[$bare]} 行）\n";
                $dupTotal++;
            } else {
                $seen[$bare] = $line;
            }
        }
    }
}
if ($dupTotal > 0) {
    echo "✗ 共 $dupTotal 处同文件重复 key——后者覆盖前者，且会触发 Psalm DuplicateArrayKey。\n";
    exit(1);
}

// ── 3. 找出每个语言里缺的 key ──
$missing = [];   // lang => [key => first-usage]
foreach ($LANGS as $code) {
    foreach ($usedKeys as $key => $location) {
        if (!array_key_exists($key, $langData[$code])) {
            $missing[$code][$key] = $location;
        }
    }
}

// ── 4. 报告 ──
$totalMissing = array_sum(array_map('count', $missing));

echo "扫描了 " . count($usedKeys) . " 个独立 key，跨 " . count($LANGS) . " 个语言文件\n";

if ($totalMissing === 0) {
    echo "✓ 所有 lang key 在所有启用语言中均有定义。\n";
} else {
    foreach ($missing as $code => $keys) {
        if ($keys === []) continue;
        echo "\n✗ lang/$code.php 缺失 " . count($keys) . " 个 key：\n";
        ksort($keys);
        foreach ($keys as $k => $loc) {
            echo "    $k    \033[2m($loc)\033[0m\n";
        }
    }
}

// ── 5. 严格模式：源 zh-CN 里有但代码里没用过的孤儿 key ──
if ($strict && in_array('zh-CN', $LANGS, true)) {
    $orphans = array_diff(array_keys($langData['zh-CN']), array_keys($usedKeys));
    if ($orphans !== []) {
        sort($orphans);
        echo "\n⚠ zh-CN 中定义但代码里未引用（候选清理）：" . count($orphans) . " 个\n";
        foreach (array_slice($orphans, 0, 30) as $k) {
            echo "    $k\n";
        }
        if (count($orphans) > 30) echo "    ... 另 " . (count($orphans) - 30) . " 个未列\n";
    }
}

exit($totalMissing === 0 ? 0 : 1);
