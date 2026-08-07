<?php
/**
 * 冒烟测试装机：准备一个全新的 SQLite yikaicms 站，供 admin CRUD 冒烟测试用。
 * 生成 config/config.php（sqlite）→ 导入 install/sql/sqlite.sql → 建管理员 → installed.lock。
 * 仅用于 CI / 本地冒烟，可反复运行（每次重建）。
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);

// 0) 保护本地开发配置：本脚本会把 config.php 整个换成 SQLite 冒烟配置。
//    CI 上没有 config.php，直接写；本地已有非冒烟配置时先备份，跑完可 --restore 还原。
//    （踩过：直接覆写把本地开发站的 MySQL 配置冲掉了）
$cfgPath = $root . '/config/config.php';
$bakPath = $root . '/config/config.php.smoke-backup';

if (in_array('--restore', $argv, true)) {
    if (is_file($bakPath)) {
        copy($bakPath, $cfgPath);
        unlink($bakPath);
        echo "已还原 config.php（来自 config.php.smoke-backup）\n";
    } else {
        echo "无备份可还原\n";
    }
    exit(0);
}

if (is_file($cfgPath) && !str_contains((string) file_get_contents($cfgPath), "'sqlite'")) {
    copy($cfgPath, $bakPath);
    echo "已备份原 config.php → config.php.smoke-backup（跑完用 php tests/smoke/setup.php --restore 还原）\n";
}

// 1) 生成 sqlite 版 config.php（基于 example，改数据库驱动）
$example = file_get_contents($root . '/config/config.php.example');
$cfg = preg_replace(
    ["/define\('DB_DRIVER',\s*'[^']*'\)/", "/define\('DB_PREFIX',\s*'[^']*'\)/"],
    ["define('DB_DRIVER', 'sqlite')", "define('DB_PREFIX', 'yikai_')"],
    $example
);
// SITE_URL 指向本地冒烟服务器
$cfg = preg_replace("/define\('SITE_URL',\s*'[^']*'\)/", "define('SITE_URL', 'http://127.0.0.1:8080')", $cfg);
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

// 浏览器回归必须显式通过设置闸；DEBUG 只作为无授权 CI 的本地旁路。
$enabled = $pdo->prepare('UPDATE yikai_settings SET value = ? WHERE "key" = ?');
$enabled->execute(['1', 'blox_editor_enabled']);
if ($enabled->rowCount() === 0) {
    $pdo->prepare('INSERT INTO yikai_settings ("group", "key", value, type, name, tip, options, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['page', 'blox_editor_enabled', '1', 'switch', 'Blox 编辑器（实验）', '', null, 3]);
}

// 3) 建管理员（bcrypt）
$t = time();
$hash = password_hash('smoke@Test123', PASSWORD_BCRYPT);
$pdo->exec("DELETE FROM yikai_users");
$pdo->prepare("INSERT INTO yikai_users (username,password,nickname,email,role_id,status,created_at,updated_at) VALUES ('admin',?,'管理员','a@a.com',1,1,?,?)")
    ->execute([$hash, $t, $t]);

// 4) installed.lock 必须先于 init.php；全新 CI 不存在可沿用的锁文件。
file_put_contents($root . '/installed.lock', date('Y-m-d H:i:s'));

// 5) 通过正式导入器准备并发布一份本地模板，供浏览器模板链路使用。
define('IK_CLI', true);
require_once $root . '/includes/init.php';
$templateJson = (string) file_get_contents(ROOT_PATH . '/tests/e2e/fixtures/section-template.json');
$template = BloxTemplateImporter::importJson($templateJson, 1, 'import', 'e2e-local-section');
bloxTemplateModel()->publishDraft($template['id']);

// 5b) 头模板草稿（不发布、无条件）：供编辑器模板模式（?template=N）浏览器用例使用。
// 种子内容与 e2e 复位共用同一 fixture 文件（单源），保证测试幂等复位有据可依。
$headerTemplateJson = (string) file_get_contents(ROOT_PATH . '/tests/e2e/fixtures/header-template.json');
$headerTemplate = BloxTemplateImporter::importJson($headerTemplateJson, 1, 'import', 'e2e-header-draft');

// 6) 报告可用的 parent id（供冒烟客户端引用）
$out = [
    'channel_list' => (int) $pdo->query("SELECT id FROM yikai_channels WHERE type='list' LIMIT 1")->fetchColumn(),
    'channel_any'  => (int) $pdo->query("SELECT id FROM yikai_channels LIMIT 1")->fetchColumn(),
    'product_cat'  => (int) $pdo->query("SELECT id FROM yikai_product_categories LIMIT 1")->fetchColumn(),
    'download_cat' => (int) ($pdo->query("SELECT id FROM yikai_download_categories LIMIT 1")->fetchColumn() ?: 0),
    'tables'       => (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn(),
    'blox_template' => (int) $template['id'],
    'blox_header_template' => (int) $headerTemplate['id'],
];
file_put_contents(__DIR__ . '/fixtures.json', json_encode($out));
echo "SMOKE SETUP OK: " . json_encode($out) . "\n";
