<?php
/**
 * 把 install/seed_data_{en,ja}.json 里的翻译补到当前已装的 yikaicms 数据库。
 *
 * 跟 install 时跑的是同一个 seedChannelTranslations() 函数；这里只是
 * 给"已经装完"的站点提供一次性补数据的入口。
 *
 * 是幂等的：translation_group_id+lang 已存在的行不会重复插（保留你
 * 之前在后台手动录入或用 setting_lang.php AI 翻译写入的版本）。
 *
 * 用法：
 *   /tools/apply_translations.php           — 只统计要做什么（dry-run）
 *   /tools/apply_translations.php?apply=1   — 实际写入
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/install/seed_channel_translations.php';

header('Content-Type: text/plain; charset=utf-8');

$dry = !isset($_GET['apply']);
$pdo = Database::getInstance()->getPdo();

// ── dry-run: 列出会插入哪些行 ──
function previewMissing(PDO $pdo, string $prefix): array
{
    $result = [
        'channels' => ['en' => [], 'ja' => []],
        'contents' => ['en' => [], 'ja' => []],
    ];

    foreach (['channels', 'contents'] as $kind) {
        $srcRows = $pdo->query("SELECT id, slug, " . ($kind === 'channels' ? 'name' : 'title') . " AS label
                                FROM {$prefix}{$kind} WHERE lang = 'zh-CN' ORDER BY id ASC")
                       ->fetchAll(PDO::FETCH_ASSOC);

        $existing = [];
        foreach ($pdo->query("SELECT translation_group_id, lang FROM {$prefix}{$kind} WHERE lang IN ('en','ja')")
                      ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $existing[(int) $r['translation_group_id']][$r['lang']] = true;
        }

        foreach ($srcRows as $src) {
            foreach (['en', 'ja'] as $lang) {
                if (empty($existing[(int) $src['id']][$lang])) {
                    $result[$kind][$lang][] = ['slug' => $src['slug'], 'label' => $src['label']];
                }
            }
        }
    }
    return $result;
}

$preview = previewMissing($pdo, DB_PREFIX);

echo "── 当前缺口（dry-run） ──\n";
foreach (['channels', 'contents'] as $kind) {
    foreach (['en', 'ja'] as $lang) {
        $list = $preview[$kind][$lang];
        printf("  %s/%s 缺 %d 条\n", $kind, $lang, count($list));
        foreach (array_slice($list, 0, 5) as $r) {
            echo "      • {$r['slug']}    {$r['label']}\n";
        }
        if (count($list) > 5) echo "      ... 另 " . (count($list) - 5) . " 条\n";
    }
}

if (!$dry) {
    echo "\n── 执行 ──\n";
    $before = [
        'channels' => (int) $pdo->query("SELECT COUNT(*) FROM " . DB_PREFIX . "channels WHERE lang IN ('en','ja')")->fetchColumn(),
        'contents' => (int) $pdo->query("SELECT COUNT(*) FROM " . DB_PREFIX . "contents WHERE lang IN ('en','ja')")->fetchColumn(),
    ];

    seedChannelTranslations($pdo, DB_PREFIX);

    $after = [
        'channels' => (int) $pdo->query("SELECT COUNT(*) FROM " . DB_PREFIX . "channels WHERE lang IN ('en','ja')")->fetchColumn(),
        'contents' => (int) $pdo->query("SELECT COUNT(*) FROM " . DB_PREFIX . "contents WHERE lang IN ('en','ja')")->fetchColumn(),
    ];

    printf("  channels en+ja: %d → %d  （+%d）\n", $before['channels'], $after['channels'], $after['channels'] - $before['channels']);
    printf("  contents en+ja: %d → %d  （+%d）\n", $before['contents'], $after['contents'], $after['contents'] - $before['contents']);

    // 确保 enabled_languages 包含三语
    $cur = $pdo->query("SELECT value FROM " . DB_PREFIX . "settings WHERE `key` = 'enabled_languages'")->fetchColumn();
    $list = $cur ? (json_decode((string) $cur, true) ?: []) : [];
    if (!is_array($list)) $list = [];
    $needed = ['zh-CN', 'en', 'ja'];
    if (array_diff($needed, $list) !== []) {
        $merged = array_values(array_unique(array_merge($list, $needed)));
        $stmt = $pdo->prepare("UPDATE " . DB_PREFIX . "settings SET value = ? WHERE `key` = 'enabled_languages'");
        if (!$cur) {
            // 行不存在，插入
            $pdo->prepare("INSERT INTO " . DB_PREFIX . "settings (`group`, `key`, `value`, `name`, `type`, `sort_order`)
                           VALUES ('site', 'enabled_languages', ?, '', '', 0)")
                ->execute([json_encode($merged)]);
        } else {
            $stmt->execute([json_encode($merged)]);
        }
        echo "  enabled_languages 更新为：" . json_encode($merged, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "  enabled_languages 已包含三语，跳过\n";
    }

    // 清前台 HTML 缓存
    $cacheDir = ROOT_PATH . '/storage/cache/html';
    if (is_dir($cacheDir)) {
        $cleared = 0;
        foreach (glob($cacheDir . '/*') ?: [] as $f) {
            if (is_file($f)) { unlink($f); $cleared++; }
        }
        echo "  清理前台 HTML 缓存 {$cleared} 个文件\n";
    }

    echo "\n✓ 完成。前台访问 /en/about.html 或 /ja/contact.html 检查。\n";
} else {
    echo "\nDry-run 完毕。确认要补入访问 /tools/apply_translations.php?apply=1\n";
}
