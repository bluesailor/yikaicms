<?php
/**
 * 内容版本历史端点：列表 / 预览 / 恢复。
 * 供文章、单页编辑页的「历史版本」面板调用。POST(恢复) 由 auth.php 自动校验 CSRF。
 *
 * GET  ?action=list&type=article|page&id=<targetId>          → {items:[{id,summary,admin_name,time_text}]}
 * GET  ?action=preview&type=..&id=..&rev_id=<revId>          → {html, summary, time_text}
 * POST  action=restore&type=..&id=..&rev_id=<revId>          → {restored:<n>}
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

$action   = (string) input('action', 'list');
$type     = (string) input('type', '');
$targetId = (int) input('id', 0);

if (!in_array($type, ['article', 'page'], true) || $targetId <= 0) {
    error('参数错误');
}

$model = contentRevisionModel();

if ($action === 'list') {
    $items = [];
    foreach ($model->listFor($type, $targetId) as $r) {
        $items[] = [
            'id'         => (int) $r['id'],
            'summary'    => (string) $r['summary'],
            'admin_name' => (string) $r['admin_name'],
            'time_text'  => date('Y-m-d H:i', (int) $r['created_at']),
        ];
    }
    success(['items' => $items]);
}

// 取版本并校验归属（防越权读别的对象的历史）
$loadOwned = static function (int $revId) use ($model, $type, $targetId): array {
    $rev = $model->getOne($revId);
    if (!$rev || (string) $rev['target_type'] !== $type || (int) $rev['target_id'] !== $targetId) {
        error('版本不存在');
    }
    return $rev;
};

if ($action === 'preview') {
    $rev = $loadOwned((int) input('rev_id', 0));
    $snap = json_decode((string) $rev['snapshot'], true);
    $html = '';
    foreach ((is_array($snap) ? ($snap['targets'] ?? []) : []) as $t) {
        if (!empty($t['fields']['content'])) {
            $html = (string) $t['fields']['content'];
            break;
        }
    }
    success([
        'html'      => $html,
        'summary'   => (string) $rev['summary'],
        'time_text' => date('Y-m-d H:i', (int) $rev['created_at']),
    ]);
}

if ($action === 'restore') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        error('非法请求');
    }
    $rev = $loadOwned((int) input('rev_id', 0));
    try {
        $n = $model->restoreRevision(
            (int) $rev['id'],
            (int) ($_SESSION['admin_id'] ?? 0),
            (string) ($_SESSION['admin_username'] ?? '')
        );
        adminLog($type, 'restore', "恢复版本 #{$rev['id']} → {$type} #{$targetId}");
        cacheClear();
        success(['restored' => $n]);
    } catch (\Throwable $e) {
        error('恢复失败：' . $e->getMessage());
    }
}

error('未知操作');
