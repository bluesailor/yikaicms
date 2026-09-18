<?php
/** Blox 站点资料面板内编辑端点：版权文字与备案号（站点设置，保存即全站生效）。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
// 与站点设置页同级：版权文字、备案号是全站资料，不随模板草稿/发布走
requirePermission('*');

if (!bloxPageEditorEnabled()) {
    error(__('blox_feature_disabled'));
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    error(__('blox_bad_request'));
}

verifyCsrf();
require_once ROOT_PATH . '/includes/builder/SiteCopyrightSettings.php';

$action = (string) post('action');
if ($action !== 'save_copyright') {
    error(__('blox_bad_request'));
}

$language = trim((string) post('lang'));
if (!isset(availableLanguages()[$language])) {
    error(__('blox_bad_request'));
}

$read = static fn (string $key): string => (string) config($key, '');
$input = ['copyright' => post('copyright', '')];
foreach (['icp', 'police'] as $field) {
    if (isset($_POST[$field])) {
        $input[$field] = post($field, '');
    }
}

$settings = SiteCopyrightSettings::normalizeInput(
    $input,
    $language,
    (string) config('site_lang', 'zh-CN'),
    $read
);
settingModel()->saveBatch($settings);
adminLog('setting', 'update', 'update copyright/filing from Blox (' . $language . '): ' . implode(',', array_keys($settings)));

success(['state' => SiteCopyrightSettings::editorState($language, $read)], __('admin_saved'));
