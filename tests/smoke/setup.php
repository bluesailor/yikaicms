<?php
/**
 * 冒烟测试装机：准备一个全新的 SQLite yikaicms 站，供 admin CRUD 冒烟测试用。
 * 生成 config/config.php（sqlite）→ 导入 install/sql/sqlite.sql → 建管理员 → installed.lock。
 * 仅用于 CI / 本地冒烟，可反复运行（每次重建）。
 */
declare(strict_types=1);
$root = dirname(__DIR__, 2);

// 1) 生成 sqlite 版 config.php（基于 example，改数据库驱动）
$example = file_get_contents($root . '/config/config.php.example');
$cfg = preg_replace(
    ["/define\('DB_DRIVER',\s*'[^']*'\)/", "/define\('DB_PREFIX',\s*'[^']*'\)/"],
    ["define('DB_DRIVER', 'sqlite')", "define('DB_PREFIX', 'yikai_')"],
    $example
);
// SITE_URL 指向本地冒烟服务器
$cfg = preg_replace("/define\('SITE_URL',\s*'[^']*'\)/", "define('SITE_URL', 'http://127.0.0.1:8080')", $cfg);
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

// 3) 建管理员（bcrypt）
$t = time();
$hash = password_hash('smoke@Test123', PASSWORD_BCRYPT);
$pdo->exec("DELETE FROM yikai_users");
$pdo->prepare("INSERT INTO yikai_users (username,password,nickname,email,role_id,status,created_at,updated_at) VALUES ('admin',?,'管理员','a@a.com',1,1,?,?)")
    ->execute([$hash, $t, $t]);

// 4) installed.lock
file_put_contents($root . '/installed.lock', date('Y-m-d H:i:s'));

// 5) 报告可用的 parent id（供冒烟客户端引用）
$out = [
    'channel_list' => (int) $pdo->query("SELECT id FROM yikai_channels WHERE type='list' LIMIT 1")->fetchColumn(),
    'channel_any'  => (int) $pdo->query("SELECT id FROM yikai_channels LIMIT 1")->fetchColumn(),
    'product_cat'  => (int) $pdo->query("SELECT id FROM yikai_product_categories LIMIT 1")->fetchColumn(),
    'download_cat' => (int) ($pdo->query("SELECT id FROM yikai_download_categories LIMIT 1")->fetchColumn() ?: 0),
    'tables'       => (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn(),
];
file_put_contents(__DIR__ . '/fixtures.json', json_encode($out));
echo "SMOKE SETUP OK: " . json_encode($out) . "\n";
