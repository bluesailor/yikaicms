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
/** @psalm-suppress MissingFile CI 环境无 config.php（部署时生成） */
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

$action   = (string) input('action', 'list');
$type     = (string) input('type', '');
$targetId = (int) input('id', 0);

if (!in_array($type, ['article', 'page'], true) || $targetId <= 0) {
    error(__('admin_bad_params'));
}

// 按类型要求对应的编辑权限。此前本端点只有 checkLogin()：虽然有版本归属校验
// （版本必须属于该对象），但没有能力校验——只持有 edit_article 的投稿者可以
// ?type=page&action=restore，把任意单页回滚到旧版本，是实打实的写操作。
requirePermission('edit_' . $type);

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

if ($action === 'blocks') {
    // 「载入到画布」的数据源：只读，不写库。载入本身是结构编辑的入口，
    // 与页面编辑接口一致要求 blox_edit（列表/预览保持只需 edit_*）。
    if ($type !== 'page') {
        error(__('admin_bad_params'));
    }
    requirePermission('blox_edit');
    $rev = $loadOwned((int) input('rev_id', 0));
    require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    try {
        $state = PageBloxDocument::load($targetId);
        $json = PageBloxDocument::revisionEditableDocument($rev, $state);
    } catch (\Throwable $e) {
        error(__('blox_revision_load_failed') . '：' . $e->getMessage());
    }
    success([
        'blocks'    => $json,
        'summary'   => (string) $rev['summary'],
        'time_text' => date('Y-m-d H:i', (int) $rev['created_at']),
    ]);
}

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
        error(__('admin_illegal_request'));
    }
    $rev = $loadOwned((int) input('rev_id', 0));
    try {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $adminName = (string) ($_SESSION['admin_username'] ?? '');
        if ($type === 'page' && db()->tableExists('blox_page_drafts')) {
            require_once ROOT_PATH . '/includes/builder/bootstrap.php';
            // 恢复 Blox 结构属于结构编辑：与页面编辑接口同样要求 blox_edit。
            $pageState = PageBloxDocument::load($targetId);
            if ($pageState['has_draft'] || $pageState['has_published'] || PageBloxDocument::revisionBlocks($rev) !== '') {
                requirePermission('blox_edit');
            }
            $n = PageBloxDocument::restoreRevision($targetId, $rev, $adminId, $adminName);
        } else {
            $n = $model->restoreRevision((int) $rev['id'], $adminId, $adminName);
        }
        adminLog($type, 'restore', "恢复版本 #{$rev['id']} → {$type} #{$targetId}");
        cacheClear();
        success(['restored' => $n]);
    } catch (\Throwable $e) {
        error('恢复失败：' . $e->getMessage());
    }
}

error('未知操作');
