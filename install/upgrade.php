<?php
/**
 * Yikai CMS - 数据库升级工具
 *
 * 浏览器访问此页面执行升级，升级完成后请删除本文件。
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';

// 升级项定义：每项包含 id、描述、检测方法、执行SQL
$upgrades = [

    [
        'id'    => '20260215_redirect',
        'title' => '栏目跳转设置',
        'desc'  => '为栏目表添加 redirect_type 和 redirect_url 字段，支持自动跳转/不跳转/指定地址跳转三种模式。',
        'check' => function () {
            try {
                db()->fetchOne('SELECT redirect_type FROM ' . DB_PREFIX . 'channels LIMIT 1');
                return true; // 已存在
            } catch (\Throwable $e) {
                return false;
            }
        },
        'sqls' => [
            "ALTER TABLE `" . DB_PREFIX . "channels` ADD COLUMN `redirect_type` varchar(10) NOT NULL DEFAULT 'auto' COMMENT '跳转方式：auto/none/url' AFTER `link_target`",
            "ALTER TABLE `" . DB_PREFIX . "channels` ADD COLUMN `redirect_url` varchar(255) NOT NULL DEFAULT '' COMMENT '跳转地址' AFTER `redirect_type`",
        ],
    ],

    [
        'id'    => '20260215_contact_page',
        'title' => '联系我们改为内置单页',
        'desc'  => '将联系我们栏目从外部链接(link)改为单页(page)类型，新增联系信息卡片设置（可自定义图标、标题、内容，最多4个）。',
        'check' => function () {
            $ch = db()->fetchOne('SELECT type FROM ' . DB_PREFIX . 'channels WHERE slug = ?', ['contact']);
            $cards = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', ['contact_cards']);
            return $ch && $ch['type'] === 'page' && $cards;
        },
        'sqls' => [
            "UPDATE `" . DB_PREFIX . "channels` SET `type` = 'page', `link_url` = '', `link_target` = '_self' WHERE `slug` = 'contact'",
            "INSERT IGNORE INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `sort_order`) VALUES ('contact', 'contact_cards', '[{\"icon\":\"phone\",\"label\":\"联系电话\",\"value\":\"400-888-8888\"},{\"icon\":\"email\",\"label\":\"电子邮箱\",\"value\":\"contact@example.com\"},{\"icon\":\"location\",\"label\":\"公司地址\",\"value\":\"上海市浦东新区XX路XX号\"}]', 'contact_cards', '联系信息卡片', '联系我们页面顶部展示的信息卡片，最多4个', 0)",
        ],
    ],

    [
        'id'    => '20260215_contact_form',
        'title' => '联系表单自定义',
        'desc'  => '新增表单标题、描述文字、提交成功提示、字段配置（可设置标签、必填、启用）等设置项。',
        'check' => function () {
            return (bool)db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', ['contact_form_fields']);
        },
        'sqls' => [
            "INSERT IGNORE INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `sort_order`) VALUES ('contact', 'contact_form_title', '在线留言', 'text', '表单标题', '', 10)",
            "INSERT IGNORE INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `sort_order`) VALUES ('contact', 'contact_form_desc', '', 'textarea', '表单描述', '显示在标题下方的说明文字', 11)",
            "INSERT IGNORE INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `sort_order`) VALUES ('contact', 'contact_form_fields', '[{\"key\":\"name\",\"label\":\"您的姓名\",\"type\":\"text\",\"required\":true,\"enabled\":true},{\"key\":\"phone\",\"label\":\"联系电话\",\"type\":\"tel\",\"required\":true,\"enabled\":true},{\"key\":\"email\",\"label\":\"电子邮箱\",\"type\":\"email\",\"required\":false,\"enabled\":true},{\"key\":\"company\",\"label\":\"公司名称\",\"type\":\"text\",\"required\":false,\"enabled\":true},{\"key\":\"content\",\"label\":\"留言内容\",\"type\":\"textarea\",\"required\":true,\"enabled\":true}]', 'contact_form_fields', '表单字段', '设置表单显示的字段、标签和是否必填', 12)",
            "INSERT IGNORE INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `sort_order`) VALUES ('contact', 'contact_form_success', '提交成功，我们会尽快与您联系！', 'text', '提交成功提示', '表单提交成功后显示的文字', 13)",
        ],
    ],

    // --- 未来升级项追加到这里 ---

];

// 处理执行
$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run'])) {
    $runIds = (array)$_POST['run'];
    foreach ($upgrades as &$up) {
        if (!in_array($up['id'], $runIds)) continue;
        if ($up['check']()) {
            $up['result'] = 'skipped';
            $up['message'] = '已是最新，无需升级';
            continue;
        }
        try {
            foreach ($up['sqls'] as $sql) {
                db()->execute($sql);
            }
            $up['result'] = 'success';
            $up['message'] = '升级成功';
        } catch (\Throwable $e) {
            $up['result'] = 'error';
            $up['message'] = $e->getMessage();
        }
    }
    unset($up);
}

// 检测各项状态
foreach ($upgrades as &$up) {
    if (!isset($up['result'])) {
        $up['status'] = $up['check']() ? 'done' : 'pending';
    }
}
unset($up);

$hasPending = false;
foreach ($upgrades as $up) {
    if (($up['status'] ?? '') === 'pending') {
        $hasPending = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Yikai CMS - 数据库升级</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f3f4f6; color: #374151; min-height: 100vh; display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; }
.container { max-width: 700px; width: 100%; }
h1 { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
.subtitle { color: #6b7280; margin-bottom: 24px; font-size: 14px; }
.card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.1); margin-bottom: 16px; overflow: hidden; }
.card-header { padding: 16px 20px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; gap: 12px; }
.card-body { padding: 16px 20px; font-size: 14px; color: #6b7280; }
.badge { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 500; }
.badge-pending { background: #fef3c7; color: #92400e; }
.badge-done { background: #d1fae5; color: #065f46; }
.badge-success { background: #d1fae5; color: #065f46; }
.badge-error { background: #fee2e2; color: #991b1b; }
.badge-skipped { background: #e5e7eb; color: #6b7280; }
.item-title { font-weight: 600; font-size: 15px; flex: 1; }
.item-id { color: #9ca3af; font-size: 12px; font-family: monospace; }
.btn { display: inline-block; padding: 10px 28px; border: none; border-radius: 6px; font-size: 15px; font-weight: 500; cursor: pointer; transition: background .2s; }
.btn-primary { background: #3b82f6; color: #fff; }
.btn-primary:hover { background: #2563eb; }
.btn-disabled { background: #d1d5db; color: #9ca3af; cursor: not-allowed; }
.actions { text-align: center; margin-top: 24px; }
.warn { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #92400e; margin-bottom: 20px; }
.msg { margin-top: 8px; font-size: 13px; }
.msg-error { color: #dc2626; }
.msg-success { color: #059669; }
.all-done { text-align: center; padding: 32px; color: #059669; font-size: 16px; }
</style>
</head>
<body>
<div class="container">
    <h1>Yikai CMS 数据库升级</h1>
    <p class="subtitle">检测数据库结构变更，一键执行升级。</p>

    <div class="warn">升级前请确保已备份数据库。升级完成后请删除本文件 (install/upgrade.php)。</div>

    <form method="post">
    <?php foreach ($upgrades as $up): ?>
    <div class="card">
        <div class="card-header">
            <?php if (($up['status'] ?? '') === 'pending' && !isset($up['result'])): ?>
            <input type="checkbox" name="run[]" value="<?php echo $up['id']; ?>" checked>
            <?php endif; ?>
            <span class="item-title"><?php echo htmlspecialchars($up['title']); ?></span>
            <span class="item-id"><?php echo $up['id']; ?></span>
            <?php if (isset($up['result'])): ?>
                <span class="badge badge-<?php echo $up['result']; ?>">
                    <?php echo match($up['result']) { 'success' => '升级成功', 'error' => '升级失败', 'skipped' => '已跳过' }; ?>
                </span>
            <?php elseif ($up['status'] === 'done'): ?>
                <span class="badge badge-done">已是最新</span>
            <?php else: ?>
                <span class="badge badge-pending">待升级</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php echo htmlspecialchars($up['desc']); ?>
            <?php if (isset($up['result']) && $up['result'] === 'error'): ?>
            <div class="msg msg-error"><?php echo htmlspecialchars($up['message']); ?></div>
            <?php elseif (isset($up['result']) && $up['result'] === 'success'): ?>
            <div class="msg msg-success"><?php echo $up['message']; ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="actions">
        <?php if ($hasPending && empty($_POST)): ?>
        <button type="submit" class="btn btn-primary">执行升级</button>
        <?php elseif (!empty($_POST)): ?>
        <a href="" class="btn btn-primary">刷新检测</a>
        <?php else: ?>
        <div class="all-done">所有升级项均已完成，数据库已是最新版本。</div>
        <?php endif; ?>
    </div>
    </form>
</div>
</body>
</html>
