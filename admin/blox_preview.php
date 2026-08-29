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
$legacyHeaderPresetSlug = trim((string) ($_GET['header_preset'] ?? ''));
$areaPresetSlug = trim((string) ($_GET['area_preset'] ?? $legacyHeaderPresetSlug));
$areaPresetType = $legacyHeaderPresetSlug !== '' ? 'header' : trim((string) ($_GET['template_area'] ?? ''));
$isAreaPresetPreview = $isHomeLayout && $areaPresetSlug !== '';
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

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

if ($isAreaPresetPreview) {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET'
        || !in_array($areaPresetType, ['header', 'footer'], true)
        || !preg_match('/^[a-z0-9-]{1,80}$/', $areaPresetSlug)) {
        error(__('blox_bad_request'));
    }
    $preset = null;
    foreach (BloxAreaTemplatePresets::editorCatalog($areaPresetType) as $candidate) {
        if ((string) ($candidate['slug'] ?? '') === $areaPresetSlug) {
            $preset = $candidate;
            break;
        }
    }
    if ($preset === null) {
        error(__('blox_area_preset_not_found'));
    }
    $_GET['template_area'] = $areaPresetType;
    $_GET['area_only'] = '1';
    if ($areaPresetType === 'header') {
        $headerState = trim((string) ($_GET['header_state'] ?? 'normal'));
        $_POST['header_state'] = in_array($headerState, BloxHeaderStates::NAMES, true) ? $headerState : 'normal';
        $_POST['drawer_open'] = (string) ($_GET['drawer_open'] ?? '') === '1' ? '1' : '0';
    }
    $_POST['blocks_data'] = json_encode([
        'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
        'settings' => $preset['settings'],
        'sections' => $preset['sections'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} else {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        || (string) ($_POST['action'] ?? '') !== 'preview') {
        error(__('blox_bad_request'));
    }
    verifyCsrf();
}

// 默认主题页脚包含客服挂件，回退预览必须和前台具备同一渲染函数。
require_once ROOT_PATH . '/includes/customer_service.php';
require_once ROOT_PATH . '/includes/builder/BloxCanvasPreview.php';
header('Cache-Control: no-store, max-age=0');
outputBloxCanvasPreview($isHomeLayout, $pageId);
