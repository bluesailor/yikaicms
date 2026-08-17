<?php
/** Standalone Blox canvas preview endpoint. */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_page');

$isHomeLayout = (string) ($_GET['home'] ?? '') === '1';
$pageId = getInt('id');
if ($isHomeLayout) {
    if (!bloxAdvancedFeaturesEnabled()) {
        error(__('blox_feature_disabled'));
    }
    requirePermission('*');
} elseif (!bloxPageEditorEnabled()) {
    error(__('blox_feature_disabled'));
} elseif (!$pageId || !channelModel()->findWhere(['id' => $pageId, 'type' => 'page'])) {
    error(__('blox_page_not_found'));
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
    || (string) ($_POST['action'] ?? '') !== 'preview') {
    error(__('blox_bad_request'));
}
verifyCsrf();

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/builder/BloxCanvasPreview.php';
header('Cache-Control: no-store, max-age=0');
outputBloxCanvasPreview($isHomeLayout, $pageId);
