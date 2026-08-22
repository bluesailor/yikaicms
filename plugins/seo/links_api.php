<?php
/**
 * SEO 助手 - 内链建议 / 基石内容 AJAX 端点（专业版）
 *
 * 供内容编辑页的 SEO 分析面板调用：
 *   action=suggest    传当前正文，返回可加内链的位置
 *   action=cornerstone 切换当前内容的基石内容标记
 *
 * 与 ai.php 同一套骨架（登录 + CSRF + Pro 闸 + 全程 JSON）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 全程 JSON 响应：任何 warning/异常都不能把 HTML 混进响应体，否则前端解析失败静默卡住
set_error_handler(function ($no, $str, $file, $line) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => "{$str} in {$file}:{$line}"]);
    exit;
});
set_exception_handler(function ($e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once __DIR__ . '/links.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => '请先登录']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

if (!seo_is_pro()) {
    echo json_encode(['success' => false, 'error' => '该功能需要 SEO 助手专业版']);
    exit;
}

// 角色校验：登录 + CSRF + Pro 闸之外，还必须持有内容编辑权限。
// 否则任何已登录的后台账号（哪怕只有查看权限）都能改基石标记 / 消耗 AI 配额。
// （codex 审计 P2-3）本端点服务于内容编辑页，持有任一内容编辑权限即可。
$seoCanEdit = false;
foreach (['edit_article', 'edit_page', 'edit_product', 'edit_case', 'edit_download'] as $seoPerm) {
    if (function_exists('hasPermission') && hasPermission($seoPerm)) {
        $seoCanEdit = true;
        break;
    }
}
if (!$seoCanEdit) {
    echo json_encode(['success' => false, 'error' => '没有内容编辑权限']);
    exit;
}

$action = (string) ($_POST['action'] ?? 'suggest');
$contentId = (int) ($_POST['content_id'] ?? 0);

if ($action === 'cornerstone') {
    if ($contentId <= 0) {
        echo json_encode(['success' => false, 'error' => '请先保存内容再标记基石']);
        exit;
    }
    // 只允许标记真实存在且已发布的内容
    $row = db()->fetchOne(
        'SELECT id FROM ' . DB_PREFIX . 'contents WHERE id = ? AND deleted_at IS NULL',
        [$contentId]
    );
    if (!$row) {
        echo json_encode(['success' => false, 'error' => '内容不存在']);
        exit;
    }
    $on = seo_cornerstone_toggle($contentId);
    adminLog('plugin', 'seo', 'cornerstone ' . ($on ? 'on' : 'off') . ': #' . $contentId);
    echo json_encode(['success' => true, 'cornerstone' => $on]);
    exit;
}

$html = (string) ($_POST['content'] ?? '');
echo json_encode([
    'success' => true,
    'cornerstone' => in_array($contentId, seo_cornerstone_ids(), true),
    'items' => seo_link_suggestions($html, $contentId),
], JSON_UNESCAPED_UNICODE);
