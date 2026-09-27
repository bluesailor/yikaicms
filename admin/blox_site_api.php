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
if (!in_array($action, ['save_copyright', 'save_theme_content'], true)) {
    error(__('blox_bad_request'));
}

$language = trim((string) post('lang'));
if (!isset(availableLanguages()[$language])) {
    error(__('blox_bad_request'));
}

if ($action === 'save_theme_content') {
    // 主题在 content-fields.json 里声明给页头/页尾的文案：只改提交的这几项（主题内容页的其他字段不动），
    // 与主题内容页同一套校验、锁与防覆盖指纹。
    $theme = currentTheme();
    $input = $_POST['fields'] ?? [];
    try {
        if (!is_array($input)) {
            throw new RuntimeException('tc_value');
        }
        $state = ThemeContent::editorState($theme, $language, getLang());
        $allowed = array_column($state['fields'], 'key');
        if ($allowed === [] || array_diff(array_keys($input), $allowed) !== []) {
            throw new RuntimeException('tc_schema');
        }
        ThemeContent::save($theme, $language, $input, (string) post('fingerprint'), true);
    } catch (Throwable $error) {
        $code = $error->getMessage();
        error(__(preg_match('/^tc_[a-z_]+$/D', $code) === 1 ? $code : 'tc_schema'));
    }
    adminLog('setting', 'update', 'update theme content from Blox (' . $theme . ', ' . $language . '): ' . implode(',', array_keys($input)));
    success(['state' => ThemeContent::editorState($theme, $language, getLang())], __('tc_saved'));
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
