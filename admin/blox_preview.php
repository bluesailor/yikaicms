<?php
/** Standalone Blox canvas preview endpoint. */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
// 主题默认 Header 可能包含会员入口，预览接口也必须提供前台会员认证函数。
require_once ROOT_PATH . '/includes/member_auth.php';

checkLogin();
requirePermission('edit_page');

$isHomeLayout = (string) ($_GET['home'] ?? '') === '1';
$pageId = getInt('id');
if ($isHomeLayout) {
    if (!bloxPageEditorEnabled()) {
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
// 默认主题页脚包含客服挂件，回退预览必须和前台具备同一渲染函数。
require_once ROOT_PATH . '/includes/customer_service.php';
require_once ROOT_PATH . '/includes/builder/BloxCanvasPreview.php';
header('Cache-Control: no-store, max-age=0');
outputBloxCanvasPreview($isHomeLayout, $pageId);
