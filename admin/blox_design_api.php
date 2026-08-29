<?php
/** Blox site-wide design token and named preset mutation API. */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('blox_global');

if (!bloxPageEditorEnabled()) {
    error(__('blox_feature_disabled'));
}

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
header('Cache-Control: no-store, max-age=0');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    error(__('blox_bad_request'));
}
verifyCsrf();

try {
    $action = trim((string) post('action', ''));
    if (in_array($action, ['page_hero_save_draft', 'page_hero_publish'], true)) {
        $backgroundInput = trim((string) post('background', ''));
        $background = $backgroundInput === '' ? '' : UrlPolicy::image($backgroundInput);
        if ($backgroundInput !== '' && $background === '') {
            error(__('blox_page_hero_invalid_image'));
        }
        $optionsInput = trim((string) post('options', ''));
        $options = $optionsInput === '' ? [] : json_decode($optionsInput, true);
        if (!is_array($options)) {
            error(__('blox_page_hero_invalid_options'));
        }
        $revision = (int) post('revision', '0');
        $state = ['background' => $background, 'options' => $options];
        $snapshot = $action === 'page_hero_publish'
            ? PageHeroDesignDraft::publish($state, $revision)
            : PageHeroDesignDraft::saveDraft($state, $revision);
        adminLog('blox_design', $action, 'Blox global page hero ' . $action);
        success($snapshot);
    }
    if ($action === 'usage') {
        success(BloxDesignDependencies::usageSnapshot());
    }
    if ($action === 'snapshot') {
        success(BloxDesignSystem::snapshot());
    }
    $input = [
        'revision' => (int) post('revision', '0'),
        'id' => (string) post('id', ''),
        'name' => (string) post('name', ''),
        'category' => (string) post('category', ''),
        'value' => (string) post('value', ''),
        'color' => (string) post('color', ''),
        'background' => (string) post('background', ''),
        'border_color' => (string) post('border_color', ''),
        'radius' => (string) post('radius', 'none'),
        'locked' => (string) post('locked', '') === '1',
    ];
    $state = BloxDesignSystem::mutate($action, $input, bloxAdvancedFeaturesEnabled());
    adminLog('blox_design', $action, 'Blox design system ' . $action . ' ' . mb_substr($input['id'], 0, 48));
    success($state);
} catch (RuntimeException $e) {
    if ($e->getMessage() === __('blox_save_conflict')) {
        error($e->getMessage(), 409);
    }
    error($e->getMessage());
} catch (Throwable $e) {
    error($e->getMessage());
}
