<?php
/** Site-wide headers, footers, popups and their display rules. */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requireAnyBloxPermission();
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$canEditPages = hasPermission('blox_edit') && hasPermission('edit_page');
$canManageGlobalBlox = hasPermission('blox_global');
$basicBloxEnabled = bloxPageEditorEnabled();
$advancedBloxEnabled = bloxAdvancedFeaturesEnabled();
$currentTheme = (string) config('current_theme', 'default');
$errorMessage = '';
$defaultAreaSeeds = [
    'header' => 'clean-site-header',
    'footer' => 'clean-site-footer',
];
$areaPresetOptions = ['header' => [], 'footer' => []];
foreach (BloxAreaTemplatePresets::catalog() as $preset) {
    $presetType = (string) ($preset['type'] ?? '');
    if (isset($areaPresetOptions[$presetType])) {
        $areaPresetOptions[$presetType][] = $preset;
    }
}
$areaTemplateDraftId = ['header' => 0, 'footer' => 0];

$templateCounts = array_fill_keys(BloxTemplateModel::TYPES, ['total' => 0, 'published' => 0]);
if (db()->tableExists('blox_templates')) {
    foreach (bloxTemplateModel()->catalog() as $template) {
        $type = (string) ($template['type'] ?? '');
        if (!isset($templateCounts[$type])) {
            continue;
        }
        $published = (int) ($template['status'] ?? 0) === 1;
        $templateCounts[$type]['total']++;
        $templateCounts[$type]['published'] += $published ? 1 : 0;

        if (!isset($areaTemplateDraftId[$type])) {
            continue;
        }
        if ($areaTemplateDraftId[$type] === 0) {
            $areaTemplateDraftId[$type] = (int) ($template['id'] ?? 0);
        }
    }
}

$areaRows = [
    'header' => ['label' => __('site_design_area_header'), 'icon' => 'ti-layout-navbar'],
    'footer' => ['label' => __('site_design_area_footer'), 'icon' => 'ti-layout-bottombar'],
    'popup' => ['label' => __('site_design_area_popup'), 'icon' => 'ti-window'],
    'archive' => ['label' => __('site_design_area_archive'), 'icon' => 'ti-list-details'],
    'search' => ['label' => __('site_design_area_search'), 'icon' => 'ti-list-search'],
    'error404' => ['label' => __('site_design_area_error404'), 'icon' => 'ti-error-404'],
];

$designContexts = [];
$designLanguages = enabledLanguages();
foreach ([siteLang() => ($designLanguages[siteLang()] ?? siteLang())] + $designLanguages as $code => $label) {
    $key = $code === siteLang() ? 'home' : 'home:' . $code;
    $designContexts[$key] = [
        'label' => __('blox_current_context_home') . ' · ' . $label,
        'context' => ['home' => true, 'channel_id' => 0, 'page_id' => 0, 'lang' => $code],
        'url' => langUrl('/', $code),
    ];
}
if ($advancedBloxEnabled && $canManageGlobalBlox) {
    $designEntities = BloxAreaConditions::entityOptions();
    foreach (['channel', 'page'] as $scope) {
        foreach ($designEntities[$scope] as $entity) {
            $key = $scope . ':' . $entity['id'];
            $target = BloxAreaAssignmentManager::contextFromKey($key, $designEntities, siteLang());
            $designContexts[$key] = $target;
        }
    }
}
$designContextInput = $_GET['context'] ?? 'home';
$designContextKey = is_string($designContextInput) ? $designContextInput : 'home';
if (!isset($designContexts[$designContextKey])) {
    $designContextKey = 'home';
}
$designContext = $designContexts[$designContextKey];
$designPreviewUrl = (string) ($designContext['url'] ?? '');
if ($designPreviewUrl === '') {
    $previewChannel = channelModel()->find((int) explode(':', $designContextKey, 2)[1]);
    $designPreviewUrl = $previewChannel ? channelUrl($previewChannel) : langUrl('/', siteLang());
}
$designAreaState = [];
if ($advancedBloxEnabled && $canManageGlobalBlox) {
    $publishedFrame = [];
    if (str_starts_with($designContextKey, 'page:')) {
        $pageState = PageBloxDocument::load((int) $designContext['context']['page_id']);
        if ($pageState['has_published']) {
            $publishedFrame = BloxDocumentPipeline::decode($pageState['published_document_json'])['settings'] ?? [];
        }
    }
    foreach (['header', 'footer'] as $area) {
        $publication = BloxAreaEditorTarget::publicationState($area, $designContext['context']);
        $resolved = in_array($publication['status'], ['ready', 'conditional'], true) ? $publication['template'] : null;
        $editUrl = BloxAreaEditorTarget::url($area, $designContext['context']);
        if (str_starts_with($editUrl, '/admin/blox_editor.php?')) {
            $editUrl .= '&area_lang=' . rawurlencode($designContext['context']['lang'])
                . '&preview_context=' . rawurlencode($designContextKey);
            $editUrl = BloxAreaEditorTarget::withReturnTo($editUrl, $designPreviewUrl);
        } else {
            $editUrl = '';
        }
        $stored = $resolved ? bloxTemplateModel()->find((int) $resolved['id']) : null;
        $designAreaState[$area] = [
            'publication' => $publication,
            'hidden' => !empty($publishedFrame['page_' . $area . '_hidden']),
            'resolved' => $resolved,
            'edit_url' => $editUrl,
            'changed' => $stored && (string) ($stored['draft_data'] ?? '') !== (string) ($stored['published_data'] ?? ''),
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) post('action', '');
    try {
        if ($action === 'seed_default_area') {
            if (!$advancedBloxEnabled || !$canManageGlobalBlox) {
                throw new RuntimeException(__('blox_feature_disabled'));
            }
            if (!db()->tableExists('blox_templates')) {
                throw new RuntimeException(__('blox_tpl_table_missing'));
            }
            $area = strtolower(trim((string) post('area', '')));
            if (!isset($defaultAreaSeeds[$area])) {
                throw new RuntimeException(__('blox_area_preset_not_found'));
            }
            $presetSlug = trim((string) post('preset', $defaultAreaSeeds[$area]));
            $allowedPresetSlugs = array_column($areaPresetOptions[$area], 'slug');
            if (!in_array($presetSlug, $allowedPresetSlugs, true)) {
                throw new RuntimeException(__('blox_area_preset_not_found'));
            }
            $result = BloxAreaTemplatePresets::install($presetSlug, (int) ($_SESSION['admin_id'] ?? 0));
            settingModel()->saveBatch([
                $area === 'header' ? 'blox_custom_header_enabled' : 'blox_custom_footer_enabled' => '1',
            ]);
            redirect('/admin/blox_editor.php?template=' . (int) $result['id']);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$GLOBALS['pageTitle'] = __('website_layout_title');
$GLOBALS['currentMenu'] = 'site_design';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-7" data-testid="site-design-dashboard">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="mt-1 max-w-3xl text-sm text-gray-500"><?php echo e(__('website_layout_intro')); ?></p>
        </div>
        <?php if ($errorMessage !== ''): ?>
        <div class="w-full rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            <?php echo e($errorMessage); ?>
        </div>
        <?php endif; ?>
        <?php if (hasPermission('edit_page')): ?>
        <a href="/admin/page.php" class="inline-flex h-10 items-center gap-2 border border-gray-300 rounded px-4 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <i class="ti ti-files" aria-hidden="true"></i><?php echo e(__('website_pages_title')); ?>
        </a>
        <?php endif; ?>
    </div>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-gray-900"><?php echo e(__('website_layout_choose')); ?></h2>
        <p class="mb-4 text-sm text-gray-600"><?php echo e(__('website_layout_steps')); ?></p>
        <?php if ($advancedBloxEnabled && $canManageGlobalBlox): ?>
        <form method="get" class="mb-3 flex flex-wrap items-center gap-3">
            <label for="site-design-context" class="text-sm font-medium text-gray-700"><?php echo e(__('blox_current_context')); ?></label>
            <select name="context" id="site-design-context" data-testid="site-design-context" class="h-10 max-w-full border border-gray-300 bg-white px-2 text-sm">
                <?php foreach ($designContexts as $key => $option): ?>
                <option value="<?php echo e($key); ?>" <?php echo $key === $designContextKey ? 'selected' : ''; ?>><?php echo e($option['label']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="inline-flex h-10 items-center gap-2 border border-gray-300 bg-white px-3 text-sm"><i class="ti ti-eye"></i><?php echo e(__('site_design_inspect')); ?></button>
            <a data-testid="site-design-context-preview" href="<?php echo e($designPreviewUrl); ?>" target="_blank" rel="noopener" class="text-sm text-primary"><?php echo e(__('blox_current_preview')); ?></a>
        </form>
        <?php endif; ?>
        <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
            <?php foreach ($areaRows as $type => $area):
                $currentDraftTemplateId = $areaTemplateDraftId[$type] ?? 0;
                $counts = $templateCounts[$type];
                $statusLabel = $counts['published'] > 0
                    ? __('site_design_status_published', ['count' => $counts['published']])
                    : ($counts['total'] > 0 ? __('site_design_status_draft', ['count' => $counts['total']]) : __($type === 'popup' ? 'website_layout_no_popup' : 'site_design_status_none'));
            ?>
            <div class="flex flex-wrap items-center gap-4 px-5 py-4" data-testid="site-design-area-<?php echo e($type); ?>" id="site-design-area-<?php echo e($type); ?>">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-gray-100 text-gray-600"><i class="ti <?php echo e($area['icon']); ?> text-xl"></i></span>
                <div class="site-design-area-copy">
                    <div class="font-medium text-gray-900"><?php echo e($area['label']); ?></div>
                    <div class="mt-0.5 text-xs text-gray-500"><?php echo e($statusLabel); ?></div>
                    <?php if (isset($designAreaState[$type])): $state = $designAreaState[$type]; ?>
                    <p class="mt-1 text-sm text-gray-700" data-testid="site-design-area-source">
                        <?php echo e(__(!$state['hidden'] && $state['publication']['status'] === 'conditional' ? 'site_design_rule_selected' : 'blox_current_use_label')); ?>
                        <?php if ($state['hidden']): ?>
                        <strong><?php echo e(__('site_design_area_hidden')); ?></strong>
                        <?php else: ?>
                        <strong><?php echo e($state['resolved'] ? BloxAreaTemplatePresets::displayName($state['resolved']) : __('blox_current_theme_fallback', ['theme' => $currentTheme])); ?></strong>
                        <?php if ($state['publication']['status'] !== 'conditional'): ?><span><?php echo e(__($state['resolved'] ? 'blox_current_use_badge' : 'blox_current_theme_badge')); ?></span><?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($state['resolved'] && !$state['hidden']): ?>
                    <p class="mt-1 text-xs text-gray-500"><?php echo e(BloxAreaConditions::summary($state['resolved']['conditions'] ?? null, $designEntities)); ?></p>
                    <?php endif; ?>
                    <?php if ($state['changed']): ?><p class="mt-1 text-xs text-amber-700"><?php echo e(__('blox_page_unpublished_changes')); ?></p><?php endif; ?>
                    <?php if (!$state['hidden'] && $state['publication']['status'] !== 'ready'): ?>
                    <p class="mt-1 text-xs text-gray-600" data-testid="site-design-area-reason"><?php echo e(__('site_design_reason_' . $state['publication']['status'], ['name' => $state['publication']['template'] ? BloxAreaTemplatePresets::displayName($state['publication']['template']) : ''])); ?></p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php if ($advancedBloxEnabled && $canManageGlobalBlox): ?>
                <div class="flex flex-wrap items-center gap-2">
                    <?php if (isset($designAreaState[$type]) && !$designAreaState[$type]['hidden'] && in_array($designAreaState[$type]['publication']['status'], ['empty', 'invalid', 'hidden'], true)):
                        $repairUrl = '/admin/blox_editor.php?template=' . (int) $designAreaState[$type]['publication']['template']['id']
                            . '&area_lang=' . rawurlencode($designContext['context']['lang']) . '&preview_context=' . rawurlencode($designContextKey);
                    ?>
                    <a data-testid="site-design-area-repair" href="<?php echo e(BloxAreaEditorTarget::withReturnTo($repairUrl, $designPreviewUrl)); ?>" class="text-sm text-primary"><?php echo e(__('site_design_repair_template')); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($designAreaState[$type]['hidden']) && $canEditPages && str_starts_with($designContextKey, 'page:')): ?>
                    <a data-testid="site-design-hidden-page-edit" href="<?php echo e(BloxAreaEditorTarget::withReturnTo('/admin/blox_editor.php?id=' . (int) $designContext['context']['page_id'], $designPreviewUrl)); ?>" class="text-sm text-primary"><?php echo e(__('site_design_edit_page')); ?></a>
                    <?php endif; ?>
                    <?php if (!empty($designAreaState[$type]['edit_url']) && !$designAreaState[$type]['hidden']): ?>
                    <a data-testid="site-design-area-edit" href="<?php echo e($designAreaState[$type]['edit_url']); ?>" class="inline-flex items-center gap-1 rounded border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-medium text-indigo-700 hover:bg-indigo-100">
                        <i class="ti ti-edit"></i><?php echo e(__('website_layout_edit_' . $type)); ?>
                    </a>
                    <?php endif; ?>
                    <?php if (in_array($type, ['header', 'footer'], true) && $currentDraftTemplateId <= 0): ?>
                    <form method="post" class="flex flex-wrap items-center justify-end gap-2">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="seed_default_area">
                        <input type="hidden" name="area" value="<?php echo e($type); ?>">
                        <label class="sr-only" for="site-design-<?php echo e($type); ?>-preset"><?php echo e(__('site_design_choose_starter')); ?></label>
                        <select id="site-design-<?php echo e($type); ?>-preset" name="preset" class="h-9 max-w-56 border border-gray-300 bg-white px-2 text-xs text-gray-700" data-testid="site-design-<?php echo e($type); ?>-preset">
                            <?php foreach ($areaPresetOptions[$type] as $preset): ?>
                            <option value="<?php echo e($preset['slug']); ?>"><?php echo e($preset['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="inline-flex items-center gap-1 rounded border border-primary/30 bg-primary/5 px-3 py-2 text-xs font-medium text-primary hover:bg-primary/10">
                            <i class="ti ti-layout-grid-add"></i><?php echo e(__('site_design_use_starter')); ?>
                        </button>
                    </form>
                    <?php endif; ?>
                    <a href="/admin/blox_templates.php?type=<?php echo e($type); ?>&amp;context=<?php echo e(rawurlencode($designContextKey)); ?>" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75">
                        <?php echo e(__('website_layout_rules')); ?><i class="ti ti-arrow-right"></i>
                    </a>
                </div>
                <?php else: ?>
                <span class="text-xs text-gray-400"><i class="ti ti-lock mr-1"></i><?php echo e(__('site_design_advanced_locked')); ?></span>
                <?php endif; ?>
                <?php if (isset($designAreaState[$type]) && !$designAreaState[$type]['hidden']): ?>
                <details class="w-full" data-site-area-preview="<?php echo e($type); ?>" data-url="<?php echo e($designPreviewUrl); ?>" data-loading="<?php echo e(__('site_design_preview_loading')); ?>" data-error="<?php echo e(__('site_design_preview_error')); ?>">
                    <summary class="cursor-pointer text-sm text-gray-600"><?php echo e(__('site_design_preview_published')); ?></summary>
                    <div class="site-area-preview mt-3" data-preview-surface></div>
                    <p class="mt-2 text-xs text-gray-500" role="status" data-preview-status></p>
                </details>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm">
        <p class="font-medium text-gray-800"><?php echo e(__('website_layout_scope')); ?></p>
        <p class="mt-1 text-gray-600"><?php echo e(__('website_layout_scope_hint')); ?></p>
        <?php if ($basicBloxEnabled && $canManageGlobalBlox): ?>
        <div class="mt-3 flex flex-wrap gap-4">
            <a href="/admin/product_design.php" data-testid="site-design-products" class="text-primary"><?php echo e(__('blox_tpl_type_product-detail')); ?></a>
            <a href="/admin/article_design.php" data-testid="site-design-articles" class="text-primary"><?php echo e(__('blox_tpl_type_article-detail')); ?></a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$advancedBloxEnabled || !$canManageGlobalBlox): ?>
    <div class="border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <strong><?php echo e(__('site_design_advanced_locked')); ?></strong>
        <span class="ml-1"><?php echo e(__('site_design_advanced_locked_hint')); ?></span>
    </div>
    <?php endif; ?>
</div>

<?php if ($advancedBloxEnabled && $canManageGlobalBlox): ?>
<script src="/assets/js/site-design-preview.js?v=<?php echo (int) filemtime(ROOT_PATH . '/assets/js/site-design-preview.js'); ?>" defer></script>
<?php endif; ?>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
