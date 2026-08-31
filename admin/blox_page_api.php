<?php
/** Blox single-page preview, draft and publication endpoint. */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('blox_edit');
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
    $targetChannel = channelModel()->find($pageId);
    $isContentList = is_array($targetChannel) && (string) ($targetChannel['type'] ?? '') === 'list';
    $documentClass = $isContentList ? ChannelBloxDocument::class : PageBloxDocument::class;
    $documentClass::load($pageId);

    if ($action === 'catalog_items') {
        $catalogType = (string) ($targetChannel['type'] ?? '');
        if (!in_array($catalogType, ['product', 'list'], true)) {
            error(__('blox_bad_request'));
        }
        requirePermission($catalogType === 'product' ? 'edit_product' : 'edit_article');
        require_once ROOT_PATH . '/includes/builder/BloxCatalogItems.php';
        success(BloxCatalogItems::read($targetChannel, (string) post('keyword', ''), (int) post('page', '1')));
    }

    if ($action === 'save_page_hero') {
        $heroBgInput = trim((string) post('hero_bg', ''));
        $heroBg = $heroBgInput === '' ? '' : UrlPolicy::image($heroBgInput);
        if ($heroBgInput !== '' && $heroBg === '') {
            error(__('blox_page_hero_invalid_image'));
        }
        $showHero = (string) post('show_hero', '0') === '1' ? 1 : 0;
        $styleSourceInput = trim((string) post('hero_style_source', PageHeroStyleResolver::MODE_SELF));
        $styleSource = PageHeroStyleResolver::normalizeMode($styleSourceInput);
        if ($styleSource !== $styleSourceInput) {
            error(__('blox_page_hero_invalid_source'));
        }
        if ($styleSource === PageHeroStyleResolver::MODE_PARENT && (int) ($targetChannel['parent_id'] ?? 0) <= 0) {
            error(__('blox_page_hero_parent_unavailable'));
        }
        $styleOptionsInput = trim((string) post('hero_style_options', ''));
        $styleOptionsRaw = $styleOptionsInput === '' ? [] : json_decode($styleOptionsInput, true);
        if (!is_array($styleOptionsRaw)) {
            error(__('blox_page_hero_invalid_options'));
        }
        $styleOptions = PageHeroStyleResolver::encodeOptions($styleOptionsRaw);
        channelModel()->updateById($pageId, [
            'hero_bg' => $heroBg,
            'show_hero' => $showHero,
            'hero_style_source' => $styleSource,
            'hero_style_options' => $styleOptions,
            'updated_at' => time(),
        ]);
        $resolved = PageHeroStyleResolver::resolve(array_merge($targetChannel, [
            'hero_bg' => $heroBg,
            'show_hero' => $showHero,
            'hero_style_source' => $styleSource,
            'hero_style_options' => $styleOptions,
        ]));
        adminLog($isContentList ? 'channel' : 'page', 'save_page_hero', 'save page hero #' . $pageId);
        success([
            'hero_bg' => $heroBg,
            'show_hero' => $showHero === 1,
            'style_source' => $styleSource,
            'style_options' => PageHeroStyleResolver::normalizeOptions($styleOptions),
            'resolved_options' => $resolved['options'],
            'resolved_bg' => $resolved['background'],
            'source' => $resolved['source'],
            'source_channel_name' => $resolved['source_channel_name'],
        ]);
    }

    if ($action === 'preview') {
        if (!defined('SITE_LANG') && is_array($targetChannel)) {
            define('SITE_LANG', (string) ($targetChannel['lang'] ?? siteLang()));
        }
        require_once ROOT_PATH . '/includes/builder/BloxCanvasPreview.php';
        outputBloxCanvasPreview(false, $pageId);
    }

    $blocksJson = (string) post('blocks_data', '[]');
    $baseRevision = trim((string) post('base_revision', ''));
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);

    if ($action === 'save_draft') {
        $result = $documentClass::saveDraft($pageId, $blocksJson, $baseRevision, $adminId);
        adminLog($isContentList ? 'channel' : 'page', 'save_draft', 'save Blox document draft #' . $pageId);
        $result['return_receipt'] = BloxAreaEditorTarget::issueReturnReceipt('draft');
        success($result);
    }

    if ($action === 'publish') {
        $result = $documentClass::saveAndPublish($pageId, $blocksJson, $baseRevision, $adminId);
        adminLog($isContentList ? 'channel' : 'page', 'publish', 'save and publish Blox document #' . $pageId);
        $result['return_receipt'] = BloxAreaEditorTarget::issueReturnReceipt('published');
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
