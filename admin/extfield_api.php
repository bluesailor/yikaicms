<?php
/**
 * YikaiCMS - 扩展字段 JSON API（2.0.4 高级字段）
 *
 * GET ?action=search&target=product|content|<模型键>&q=关键词
 *     关联字段的候选条目（标题模糊匹配，最多 20 条）。权限跟着目标走：产品要产品编辑权限，内容要任一内容权限。
 * GET ?action=render&owner=channel|product_category&id=条目 id
 *     栏目 / 产品分类编辑弹窗里的字段区（服务端渲染好的 HTML，前端 YkExtFields.mount 挂进弹窗）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
header('Content-Type: application/json; charset=utf-8');

$action = (string) get('action');
if ($action === 'render') {
    $owner = (string) get('owner');
    $permission = ['channel' => '*', 'product_category' => 'edit_product'][$owner] ?? null;
    if ($permission === null) {
        error(__('ef_bad_request'));
    }
    if (!hasPermission($permission)) {
        permissionDenied();
    }
    require_once ROOT_PATH . '/admin/includes/extfield_helpers.php';
    ob_start();
    efRenderFields($owner, max(0, getInt('id')), ['bare' => true, 'no_scripts' => true]);
    success(['html' => (string) ob_get_clean()]);
}
if ($action !== 'search') {
    error(__('ef_bad_request'));
}

$target = (string) get('target', 'product');
if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $target)) {
    error(__('ef_bad_request'));
}
$q = mb_substr(trim((string) get('q')), 0, 100);

if ($target === 'product') {
    if (!hasPermission('edit_product')) {
        permissionDenied();
    }
    $sql = 'SELECT id, title FROM ' . DB_PREFIX . 'products WHERE (deleted_at IS NULL OR deleted_at = 0)';
    $params = [];
} else {
    if (!hasAnyContentPerm()) {
        permissionDenied();
    }
    $sql = 'SELECT id, title FROM ' . DB_PREFIX . 'contents WHERE (deleted_at IS NULL OR deleted_at = 0)';
    $params = [];
    if ($target !== 'content') {
        $sql .= ' AND type = ?';
        $params[] = $target;
    }
}
if ($q !== '') {
    if (ctype_digit($q)) {
        $sql .= ' AND (title LIKE ? OR id = ?)';
        array_push($params, '%' . $q . '%', (int) $q);
    } else {
        $sql .= ' AND title LIKE ?';
        $params[] = '%' . $q . '%';
    }
}
$sql .= ' ORDER BY id DESC LIMIT 20';

try {
    $rows = db()->fetchAll($sql, $params);
} catch (Throwable) {
    $rows = [];
}
success(['items' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title']], $rows)]);
