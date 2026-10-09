<?php
/**
 * 语言包制品（build.sh 调用；设计见 includes/i18n/LanguagePacks.php 文件头）。
 *
 *   php tools/build-lang-packs.php list
 *       输出不随安装包分发的语言文件（lang/<code>.php，一行一个），build.sh 并入 EXCLUDES——
 *       进 EXCLUDES 同时意味着「从未随核心包分发」，增量包据此不会删掉存量站上的这些文件。
 *
 *   php tools/build-lang-packs.php build <源目录> <CMS版本> <输出目录>
 *       为每种可下载语言生成 yikaicms-lang-<code>-<版本>.zip（lang/<code>.php + pack.json，**未签名**）
 *       和汇总 lang-packs-v<版本>.json。签名在发版时另跑 tools/sign-lang-packs.php——
 *       CI 也跑 build.sh，但没有私钥。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/i18n/LanguagePacks.php';
require_once __DIR__ . '/LangPackZip.php';

$mode = $argv[1] ?? '';
if ($mode === 'list') {
    foreach (LanguagePacks::downloadable() as $code) {
        echo 'lang/' . $code . ".php\n";
    }
    exit(0);
}

if ($mode !== 'build' || count($argv) !== 5) {
    fwrite(STDERR, "用法: php tools/build-lang-packs.php list | build <源目录> <CMS版本> <输出目录>\n");
    exit(2);
}
[, , $source, $version, $outDir] = $argv;
if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
    fwrite(STDERR, "版本号无效: {$version}\n");
    exit(2);
}
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "无法创建输出目录: {$outDir}\n");
    exit(1);
}

$keys = static fn(string $file): array => array_keys((array) (static fn(string $f): mixed => require $f)($file));
$baseKeys = $keys($source . '/lang/zh-CN.php');
$summary = ['schema' => 1, 'cms_version' => $version, 'packs' => []];
foreach (LanguagePacks::downloadable() as $code) {
    $file = $source . '/lang/' . $code . '.php';
    if (!is_file($file)) {
        fwrite(STDERR, "缺少语言文件: lang/{$code}.php\n");
        exit(1);
    }
    $php = (string) file_get_contents($file);
    $sha256 = hash('sha256', $php);
    $translated = count(array_intersect($keys($file), $baseKeys));
    $name = LanguagePacks::packageName($code, $version);
    langPackZip($outDir . '/' . $name, $code, $php, [
        'schema' => 1,
        'code' => $code,
        'cms_version' => $version,
        'sha256' => $sha256,
        'keys' => count($baseKeys),
        'translated_keys' => $translated,
        'signature' => '',
    ]);
    $summary['packs'][$code] = [
        'file' => $name,
        'zip_sha256' => hash_file('sha256', $outDir . '/' . $name),
        'bytes' => filesize($outDir . '/' . $name),
        'php_sha256' => $sha256,
        'translated' => round($translated / max(1, count($baseKeys)) * 100, 1),
        'signed' => false,
    ];
}
file_put_contents(
    $outDir . '/lang-packs-v' . $version . '.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
echo '语言包 ' . count($summary['packs']) . " 个（未签名）→ {$outDir}\n";
