<?php
/**
 * 冒烟测试装机：准备一个全新的 SQLite yikaicms 站，供 admin CRUD 冒烟测试用。
 * 生成 config/config.php（sqlite）→ 导入 install/sql/sqlite.sql → 建管理员 → installed.lock。
 * 仅用于 CI / 本地冒烟，可反复运行（每次重建）。
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$i18nOnly = in_array('--admin-i18n', $argv, true);

// 0) 保护本地状态。setup 会覆盖配置、重建 SQLite 库并写 installed.lock/fixtures.json，
//    因此必须在任何写操作之前完整备份，且不得覆盖上一次未恢复的备份。
$stateFiles = [
    'config'   => $root . '/config/config.php',
    'database' => $root . '/storage/database.sqlite',
    'lock'     => $root . '/installed.lock',
    'fixtures' => __DIR__ . '/fixtures.json',
];
$backupDir = $root . '/storage/.smoke-state-backup';
$manifestPath = $backupDir . '/manifest.json';

/** @param array<string,string> $stateFiles */
function backupSmokeState(array $stateFiles, string $backupDir, string $manifestPath): void
{
    if (is_dir($backupDir)) {
        throw new RuntimeException('检测到未恢复的 smoke 备份，请先运行 php tests/smoke/setup.php --restore');
    }
    if (!mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
        throw new RuntimeException('无法创建 smoke 状态备份目录');
    }

    $manifest = [];
    try {
        foreach ($stateFiles as $key => $path) {
            $exists = is_file($path);
            $manifest[$key] = ['exists' => $exists];
            if ($exists && !copy($path, $backupDir . '/' . $key . '.bak')) {
                throw new RuntimeException("无法备份 {$path}");
            }
        }
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($manifestPath, $json) === false) {
            throw new RuntimeException('无法写入 smoke 状态备份清单');
        }
    } catch (Throwable $e) {
        removeSmokeBackupDir($backupDir);
        throw $e;
    }
}

/** @param array<string,string> $stateFiles */
function restoreSmokeState(array $stateFiles, string $backupDir, string $manifestPath): bool
{
    if (!is_file($manifestPath)) {
        return false;
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($stateFiles as $key => $path) {
        $existed = (bool) ($manifest[$key]['exists'] ?? false);
        $backup = $backupDir . '/' . $key . '.bak';
        if ($existed) {
            $parent = dirname($path);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                throw new RuntimeException("无法创建恢复目录 {$parent}");
            }
            if (!is_file($backup) || !copy($backup, $path)) {
                throw new RuntimeException("无法恢复 {$path}");
            }
        } elseif (is_file($path) && !unlink($path)) {
            throw new RuntimeException("无法删除 smoke 生成文件 {$path}");
        }
    }
    removeSmokeBackupDir($backupDir);
    return true;
}

function removeSmokeBackupDir(string $backupDir): void
{
    if (!is_dir($backupDir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($backupDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($backupDir);
}

if (in_array('--restore', $argv, true)) {
    try {
        echo restoreSmokeState($stateFiles, $backupDir, $manifestPath)
            ? "已还原 smoke 前的配置、数据库、安装锁与 fixture\n"
            : "无 smoke 状态备份可还原\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, '恢复 smoke 状态失败：' . $e->getMessage() . "\n");
        exit(1);
    }
}

try {
    backupSmokeState($stateFiles, $backupDir, $manifestPath);
} catch (Throwable $e) {
    fwrite(STDERR, '准备 smoke 状态备份失败：' . $e->getMessage() . "\n");
    exit(1);
}

$cfgPath = $stateFiles['config'];

// 1) 生成 sqlite 版 config.php（基于 example，改数据库驱动）
$example = file_get_contents($root . '/config/config.php.example');
$cfg = preg_replace(
    ["/define\('DB_DRIVER',\s*'[^']*'\)/", "/define\('DB_PREFIX',\s*'[^']*'\)/"],
    ["define('DB_DRIVER', 'sqlite')", "define('DB_PREFIX', 'yikai_')"],
    $example
);
// SITE_URL 指向本地冒烟服务器
$smokeSiteUrl = getenv('SMOKE_SITE_URL') ?: 'http://127.0.0.1:8080';
$cfg = preg_replace("/define\('SITE_URL',\s*'[^']*'\)/", "define('SITE_URL', '" . addslashes($smokeSiteUrl) . "')", $cfg);
$cfg = preg_replace("/define\('DEBUG',\s*(?:true|false)\)/", "define('DEBUG', true)", $cfg);
file_put_contents($root . '/config/config.php', $cfg);

// 2) 重建 sqlite 数据库文件 + 导入 schema
$dbFile = $root . '/storage/database.sqlite';
@mkdir($root . '/storage', 0777, true);
@mkdir($root . '/storage/cache', 0777, true);
@mkdir($root . '/uploads', 0777, true);
@unlink($dbFile);
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents($root . '/install/sql/sqlite.sql'));

// ── 站点语言（--lang=en 等；默认 zh-CN）────────────────────────────────
// 客户站大量是纯英文/日文，而测试一直只跑中文——「新建行 lang 落错桶、
// 列表查不到」这类 bug 在中文站上根本不成立（默认值恰好对），只有换语言才现形。
// 让 smoke 能按语言起站，CI 并行跑 zh-CN 与 en 两腿。
$smokeLang = 'zh-CN';
foreach ($argv as $a) {
    if (str_starts_with((string) $a, '--lang=')) {
        $v = substr((string) $a, 7);
        if (in_array($v, ['zh-CN', 'en', 'ja'], true)) {
            $smokeLang = $v;
        }
    }
}
if ($smokeLang !== 'zh-CN') {
    $setLang = $pdo->prepare('UPDATE yikai_settings SET value = ? WHERE "key" = ?');
    $setLang->execute([$smokeLang, 'site_lang']);
    $setLang->execute([$smokeLang, 'admin_lang']);
    $setLang->execute([json_encode([$smokeLang]), 'enabled_languages']);
}

if (!$i18nOnly) {
    // 浏览器回归必须显式通过设置闸；i18n 扫描不加载 Blox。
    $enabled = $pdo->prepare('UPDATE yikai_settings SET value = ? WHERE "key" = ?');
    $enabled->execute(['1', 'blox_editor_enabled']);
    if ($enabled->rowCount() === 0) {
        $pdo->prepare('INSERT INTO yikai_settings ("group", "key", value, type, name, tip, options, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['page', 'blox_editor_enabled', '1', 'switch', 'Blox 编辑器（实验）', '', null, 3]);
    }
}

// 3) 建管理员（bcrypt）
$t = time();
$hash = password_hash('smoke@Test123', PASSWORD_BCRYPT);
$pdo->exec("DELETE FROM yikai_users");
$pdo->prepare("INSERT INTO yikai_users (username,password,nickname,email,role_id,status,created_at,updated_at) VALUES ('admin',?,'管理员','a@a.com',1,1,?,?)")
    ->execute([$hash, $t, $t]);

// 4) installed.lock 必须先于 init.php；全新 CI 不存在可沿用的锁文件。
file_put_contents($root . '/installed.lock', date('Y-m-d H:i:s'));

$templateId = 0;
$headerTemplateId = 0;
if (!$i18nOnly) {
    // 5) 通过正式导入器准备 Blox 模板；后台 i18n 扫描不需要这组编辑器 fixture。
    define('IK_CLI', true);
    require_once $root . '/includes/init.php';
    $templateJson = (string) file_get_contents(ROOT_PATH . '/tests/e2e/fixtures/section-template.json');
    $template = BloxTemplateImporter::importJson($templateJson, 1, 'import', 'e2e-local-section');
    bloxTemplateModel()->publishDraft($template['id']);
    $templateId = (int) $template['id'];

    $headerTemplateJson = (string) file_get_contents(ROOT_PATH . '/tests/e2e/fixtures/header-template.json');
    $headerTemplate = BloxTemplateImporter::importJson($headerTemplateJson, 1, 'import', 'e2e-header-draft');
    $headerTemplateId = (int) $headerTemplate['id'];
}

// 6) 报告可用的 parent id（供冒烟客户端引用）
$out = [
    // 按站点语言挑 fixture：种子数据是三语的，随手取第一行会拿到中文栏目，
    // 而英文站建的内容 lang=en——父子语言不一致，列表页永远查不到（这不是产品
    // bug 而是测试自身的坑，--lang=en 首跑就撞上）。同语言取不到才回退任意行。
    'channel_list' => (int) ($pdo->query("SELECT id FROM yikai_channels WHERE type='list' AND lang='{$smokeLang}' LIMIT 1")->fetchColumn()
        ?: $pdo->query("SELECT id FROM yikai_channels WHERE type='list' LIMIT 1")->fetchColumn()),
    'channel_any'  => (int) ($pdo->query("SELECT id FROM yikai_channels WHERE lang='{$smokeLang}' LIMIT 1")->fetchColumn()
        ?: $pdo->query("SELECT id FROM yikai_channels LIMIT 1")->fetchColumn()),
    'product_cat'  => (int) ($pdo->query("SELECT id FROM yikai_product_categories WHERE lang='{$smokeLang}' LIMIT 1")->fetchColumn()
        ?: $pdo->query("SELECT id FROM yikai_product_categories LIMIT 1")->fetchColumn()),
    'download_cat' => (int) ($pdo->query("SELECT id FROM yikai_download_categories LIMIT 1")->fetchColumn() ?: 0),
    'tables'       => (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn(),
    'blox_template' => $templateId,
    'blox_header_template' => $headerTemplateId,
];
file_put_contents(__DIR__ . '/fixtures.json', json_encode($out));
echo "SMOKE SETUP OK: " . json_encode($out) . "\n";
