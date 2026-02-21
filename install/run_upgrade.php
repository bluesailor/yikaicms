<?php
/**
 * 一次性升级脚本 - 执行后请删除此文件
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family: monospace; padding: 20px;'>";
echo "=== Yikai CMS 升级脚本 ===\n\n";

try {
    $pdo = db()->getPdo();

    // 1. 添加 album_id 字段
    try {
        $pdo->exec('ALTER TABLE ' . DB_PREFIX . 'channels ADD COLUMN `album_id` INT UNSIGNED DEFAULT 0 COMMENT "关联相册ID" AFTER `type`');
        echo "✓ 1. album_id 字段添加成功\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "✓ 1. album_id 字段已存在，跳过\n";
        } else {
            echo "✗ 1. 错误: " . $e->getMessage() . "\n";
        }
    }

    // 2. 把原来的 honor 改掉
    $affected = $pdo->exec("UPDATE " . DB_PREFIX . "channels SET slug = 'honor-old' WHERE slug = 'honor'");
    echo "✓ 2. 原 honor slug 已处理 (影响 {$affected} 行)\n";

    // 3. 获取 about 和 honor 相册的 ID
    $aboutId = $pdo->query("SELECT id FROM " . DB_PREFIX . "channels WHERE slug = 'about' LIMIT 1")->fetchColumn();
    $albumId = $pdo->query("SELECT id FROM " . DB_PREFIX . "albums WHERE slug = 'honor' LIMIT 1")->fetchColumn();

    echo "  - about 栏目 ID: " . ($aboutId ?: '未找到') . "\n";
    echo "  - honor 相册 ID: " . ($albumId ?: '未找到') . "\n";

    if ($aboutId && $albumId) {
        // 检查是否已存在
        $exists = $pdo->query("SELECT id FROM " . DB_PREFIX . "channels WHERE slug = 'honor'")->fetchColumn();
        if (!$exists) {
            $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "channels (parent_id, name, slug, type, album_id, is_nav, is_home, status, sort_order, created_at, updated_at) VALUES (?, '荣誉资质', 'honor', 'album', ?, 1, 0, 1, 50, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())");
            $stmt->execute([$aboutId, $albumId]);
            echo "✓ 3. 荣誉资质栏目创建成功\n";
        } else {
            echo "✓ 3. honor 栏目已存在，跳过创建\n";
        }
    } else {
        echo "✗ 3. 错误: 找不到 about 栏目或 honor 相册，请先创建\n";
    }

    // 4. 删除旧的
    $affected = $pdo->exec("DELETE FROM " . DB_PREFIX . "channels WHERE slug = 'honor-old'");
    echo "✓ 4. 旧栏目已清理 (删除 {$affected} 行)\n";

    echo "\n=== 升级完成! ===\n";
    echo "\n<strong style='color:red;'>请删除此文件: install/run_upgrade.php</strong>\n";
    echo "\n访问测试: <a href='/about/honor.html'>/about/honor.html</a>\n";

} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

echo "</pre>";
