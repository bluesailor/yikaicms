<?php
/** Blox single-page preview, draft and publication endpoint. */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_page');

if (!bloxPageEditorEnabled()) {
    error(__('blox_feature_disabled'));
}

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
header('Cache-Control: no-store, max-age=0');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    error(__('blox_bad_request'));
}
verifyCsrf();

$pageId = getInt('id');
if ($pageId <= 0) {
    $pageId = (int) post('id', '0');
}
$action = (string) post('action', 'save_draft');

try {
    // Validate page ownership before rendering submitted HTML into the editor iframe.
    PageBloxDocument::load($pageId);

    if ($action === 'preview') {
        require_once ROOT_PATH . '/includes/builder/BloxCanvasPreview.php';
        outputBloxCanvasPreview(false, $pageId);
    }

    $blocksJson = (string) post('blocks_data', '[]');
    $baseRevision = trim((string) post('base_revision', ''));
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);

    if ($action === 'save_draft') {
        $result = PageBloxDocument::saveDraft($pageId, $blocksJson, $baseRevision, $adminId);
        adminLog('page', 'save_draft', 'save Blox page draft #' . $pageId);
        success($result);
    }

    if ($action === 'publish') {
        $result = PageBloxDocument::saveAndPublish($pageId, $blocksJson, $baseRevision, $adminId);
        adminLog('page', 'publish', 'save and publish Blox page #' . $pageId);
        success($result);
    }

    error(__('blox_invalid_action'));
} catch (RuntimeException $e) {
    if ($e->getMessage() === __('blox_save_conflict')) {
        error($e->getMessage(), 409);
    }
    error($e->getMessage());
} catch (Throwable $e) {
    error($e->getMessage());
}
