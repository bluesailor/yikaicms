<?php
/** 首页 Blox 草稿保存端点。P0 只保存草稿，不切换线上首页。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 首页文档属于免费基础编辑能力；超级管理员权限仍由上方独立校验。
if (!bloxPageEditorEnabled()) {
    error(__('blox_feature_disabled'));
}
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error(__('blox_bad_request'));
}
verifyCsrf();

if (($_POST['action'] ?? '') === 'preview') {
    require_once ROOT_PATH . '/includes/builder/BloxCanvasPreview.php';
    outputBloxCanvasPreview(true, 0);
}
$action = (string) ($_POST['action'] ?? '');
$currentDocumentJson = static function (): string {
    $current = HomeBloxDocument::load();
    return json_encode([
        'schema' => $current['schema'],
        'settings' => $current['settings'],
        'sections' => $current['sections'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
};
if ($action === 'publish') {
    try {
        // 兼容旧客户端；新编辑器始终携带当前文档，服务端原子保存并发布。
        if (array_key_exists('blocks_data', $_POST)) {
            $baseRevision = trim((string) ($_POST['base_revision'] ?? ''));
            if ($baseRevision !== '' && !BloxDocumentPipeline::revisionMatches($currentDocumentJson(), $baseRevision)) {
                error(__('blox_save_conflict'), 409);
            }
            $result = HomeBloxDocument::saveAndPublish((string) $_POST['blocks_data']);
        } else {
            $result = HomeBloxDocument::publishDraft();
        }
        adminLog('home', 'publish', '保存并发布 Blox 首页');
        $result['return_receipt'] = BloxAreaEditorTarget::issueReturnReceipt('published');
        success($result);
    } catch (Throwable $e) {
        error($e->getMessage());
    }
}
if ($action === 'rollback') {
    try {
        $result = HomeBloxDocument::rollbackToLegacy();
        adminLog('home', 'rollback', 'rollback to legacy homepage');
        success($result);
    } catch (Throwable $e) {
        error($e->getMessage());
    }
}

try {
    $baseRevision = trim((string) ($_POST['base_revision'] ?? ''));
    if ($baseRevision !== '' && !BloxDocumentPipeline::revisionMatches($currentDocumentJson(), $baseRevision)) {
        error(__('blox_save_conflict'), 409);
    }
    $document = HomeBloxDocument::saveDraft((string) ($_POST['blocks_data'] ?? '[]'));
    adminLog('home', 'edit', '保存首页 Blox 草稿');
    $savedJson = json_encode([
        'schema' => $document['schema'],
        'settings' => $document['settings'],
        'sections' => $document['sections'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    success([
        'sections' => count($document['sections']),
        'active'   => $document['active'],
        'source'   => $document['source'],
        'base_revision' => BloxDocumentPipeline::fingerprint($savedJson),
        'return_receipt' => BloxAreaEditorTarget::issueReturnReceipt('draft'),
    ]);
} catch (Throwable $e) {
    error($e->getMessage());
}
