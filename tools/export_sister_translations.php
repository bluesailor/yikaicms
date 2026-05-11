<?php
/**
 * 从兄弟项目的运行 DB 中导出英文 / 日文频道与内容翻译数据。
 *
 * 数据来源：
 *   - 英文：同库（ikaicms）中 enkai_* 前缀（enkaicms 项目）
 *   - 日文：另一个库 ikai_cms 的 yikai_* 前缀（ikaicms 项目）
 *
 * 输出位置：
 *   install/seed_data_en.json
 *   install/seed_data_ja.json
 *
 * 后续 install/seed_channel_translations.php 改读这两个 JSON 而不是硬编码翻译表。
 *
 * 用法：浏览器打开 /tools/export_sister_translations.php?go=1
 *      （加 ?go=1 才执行写入；不加只 dry-run 显示统计）。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');

$go = !empty($_GET['go']);

// 同库不同前缀：ikaicms.enkai_*
try {
    $pdoEn = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, 'ikaicms'),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    exit("✗ 连接 ikaicms 库失败：" . $e->getMessage() . "\n");
}

// 不同库：ikai_cms.yikai_*
try {
    $pdoJa = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, 'ikai_cms'),
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    exit("✗ 连接 ikai_cms 库失败（ikaicms 项目数据）：" . $e->getMessage() . "\n");
}

function dumpTable(PDO $pdo, string $table): array {
    try {
        return $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

$enChannels = dumpTable($pdoEn, 'enkai_channels');
$enContents = dumpTable($pdoEn, 'enkai_contents');
$jaChannels = dumpTable($pdoJa, 'yikai_channels');
$jaContents = dumpTable($pdoJa, 'yikai_contents');

echo "── 来源数据汇总 ──\n";
printf("  英文（enkai_）  channels=%-3d  contents=%-3d\n", count($enChannels), count($enContents));
printf("  日文（ikai_cms） channels=%-3d  contents=%-3d\n", count($jaChannels), count($jaContents));
echo "\n";

if (!$enChannels && !$jaChannels) {
    exit("✗ 两边都没数据，检查 DB 连接和表前缀。\n");
}

// 重要：把每行的 lang 字段强制覆盖（兄弟项目里 lang 可能是 'zh-CN'）
function normalizeLang(array $rows, string $lang): array {
    foreach ($rows as &$r) {
        if (array_key_exists('lang', $r)) $r['lang'] = $lang;
    }
    return $rows;
}

$payload = [
    'en' => [
        'channels' => normalizeLang($enChannels, 'en'),
        'contents' => normalizeLang($enContents, 'en'),
    ],
    'ja' => [
        'channels' => normalizeLang($jaChannels, 'ja'),
        'contents' => normalizeLang($jaContents, 'ja'),
    ],
];

if (!$go) {
    echo "── 频道按 slug 分布预览 ──\n";
    foreach (['en', 'ja'] as $lang) {
        $slugs = array_column($payload[$lang]['channels'], 'slug');
        echo "  $lang: " . count($slugs) . " 个频道，slug 前 10 个：" . implode(', ', array_slice($slugs, 0, 10)) . "\n";
    }
    echo "\nDry-run 完毕。访问 ?go=1 写入 install/seed_data_en.json + seed_data_ja.json\n";
    exit;
}

$outDir = dirname(__DIR__) . '/install';
$enFile = $outDir . '/seed_data_en.json';
$jaFile = $outDir . '/seed_data_ja.json';

file_put_contents($enFile, json_encode($payload['en'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($jaFile, json_encode($payload['ja'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

printf("✓ 已写入：\n  %s (%s bytes)\n  %s (%s bytes)\n",
    $enFile, number_format(filesize($enFile)),
    $jaFile, number_format(filesize($jaFile))
);
echo "\n下一步：将 install/seed_channel_translations.php 改为读取这两个 JSON。\n";
