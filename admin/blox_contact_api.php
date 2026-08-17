<?php
/** Blox 联系页动态数据编辑端点。 */

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
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    error(__('blox_bad_request'));
}

verifyCsrf();
$pageId = getInt('id');
$page = channelModel()->findWhere(['id' => $pageId, 'type' => 'page']);
if (!$page) {
    error(__('blox_bad_request'));
}

$isContactPage = (string) ($page['slug'] ?? '') === 'contact';
if (!$isContactPage && !empty($page['translation_group_id'])) {
    $sourcePage = channelModel()->find((int) $page['translation_group_id']);
    $isContactPage = (string) ($sourcePage['slug'] ?? '') === 'contact';
}
if (!$isContactPage) {
    error(__('blox_bad_request'));
}

$pageLang = trim((string) ($page['lang'] ?? ''));
$pageLang = $pageLang !== '' ? $pageLang : null;
$action = (string) post('action', 'save_cards');

require_once ROOT_PATH . '/includes/contact_parts.php';

if ($action === 'save_cards') {
    $raw = (string) post('cards', '[]');
    if (strlen($raw) > 20000) {
        error(__('blox_bad_request'));
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error(__('blox_bad_request'));
    }

    $cards = normalizeContactCards($decoded);
    $settingKey = contactCardsSettingKey($pageLang);
    settingModel()->saveBatch([
        $settingKey => json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
    ]);
    adminLog('setting', 'update', 'update contact cards from Blox page #' . $pageId);

    success(['cards' => $cards, 'count' => count($cards)], __('admin_saved'));
}

if ($action === 'save_form') {
    requirePermission('form');

    $template = formTemplateModel()->findBySlug('contact');
    if (!$template) {
        error(__('blox_contact_form_missing'));
    }

    $localizedLang = $pageLang !== null && in_array($pageLang, ['en', 'ja'], true)
        ? $pageLang
        : '';
    $fieldsColumn = $localizedLang !== '' && array_key_exists('fields_' . $localizedLang, $template)
        ? 'fields_' . $localizedLang
        : 'fields';
    $successColumn = $localizedLang !== '' && array_key_exists('success_message_' . $localizedLang, $template)
        ? 'success_message_' . $localizedLang
        : 'success_message';
    $currentFields = trim((string) ($template[$fieldsColumn] ?? ''));
    if ($currentFields === '' && $fieldsColumn !== 'fields') {
        $currentFields = trim((string) ($template['fields'] ?? ''));
    }
    if (!isJsonFields($currentFields)) {
        error(__('blox_contact_form_advanced_locked'));
    }

    $raw = (string) post('fields', '[]');
    if (strlen($raw) > 50000) {
        error(__('blox_bad_request'));
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === [] || count($decoded) > 12) {
        error(__('blox_contact_form_invalid'));
    }
    $fields = normalizeContactFormFields($decoded);
    if (count($fields) !== count($decoded)) {
        error(__('blox_contact_form_invalid'));
    }
    if (!array_filter($fields, static fn (array $field): bool => $field['enabled'])) {
        error(__('blox_contact_form_need_enabled'));
    }

    $cut = static function (string $value, int $limit): string {
        $value = trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    };
    $title = $cut((string) post('title'), 100);
    $description = $cut((string) post('description'), 1000);
    $successMessage = $cut((string) post('success_message'), 255);
    if ($title === '' || $successMessage === '') {
        error(__('blox_contact_form_invalid'));
    }

    db()->beginTransaction();
    try {
        settingModel()->saveBatch([
            contactSettingKey('contact_form_title', $pageLang) => $title,
            contactSettingKey('contact_form_desc', $pageLang) => $description,
        ]);
        formTemplateModel()->updateById((int) $template['id'], [
            $fieldsColumn => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            $successColumn => $successMessage,
            'captcha' => (string) post('captcha') === '1' ? 1 : 0,
        ]);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollback();
        error_log('[blox_contact_api] save_form failed: ' . $exception->getMessage());
        error(__('blox_save_failed'));
    }
    adminLog('form_template', 'update', 'update contact form from Blox page #' . $pageId);

    success([
        'form' => [
            'title' => $title,
            'description' => $description,
            'success_message' => $successMessage,
            'captcha' => (string) post('captcha') === '1',
            'fields' => $fields,
        ],
    ], __('admin_saved'));
}

error(__('blox_bad_request'));
