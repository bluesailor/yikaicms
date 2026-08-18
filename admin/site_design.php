<?php
/** 网站设计控制中心：聚合 Blox 编辑入口、全站区域和设计资产。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_page');
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$isAdministrator = hasPermission('*');
$advancedBloxEnabled = bloxAdvancedFeaturesEnabled();
$currentTheme = (string) config('current_theme', 'default');

$pageCount = (int) db()->fetchColumn(
    'SELECT COUNT(*) FROM ' . DB_PREFIX . 'channels WHERE type = ?',
    ['page']
);
$draftCount = 0;
if (db()->tableExists('blox_page_drafts')) {
    $bloxPageCount = (int) db()->fetchColumn(
        'SELECT COUNT(DISTINCT c.id) FROM ' . DB_PREFIX . 'channels c'
        . ' LEFT JOIN ' . DB_PREFIX . 'contents ct ON ct.channel_id = c.id AND ct.deleted_at IS NULL'
        . ' LEFT JOIN ' . DB_PREFIX . 'blox_page_drafts bd ON bd.page_id = c.id'
        . ' WHERE c.type = ? AND (ct.content_type = ? OR bd.id IS NOT NULL)',
        ['page', 'blocks']
    );
    $draftCount = (int) db()->fetchColumn(
        'SELECT COUNT(*) FROM ' . DB_PREFIX . 'blox_page_drafts WHERE updated_at > published_at'
    );
} else {
    $bloxPageCount = (int) db()->fetchColumn(
        'SELECT COUNT(DISTINCT c.id) FROM ' . DB_PREFIX . 'channels c'
        . ' INNER JOIN ' . DB_PREFIX . 'contents ct ON ct.channel_id = c.id AND ct.deleted_at IS NULL'
        . ' WHERE c.type = ? AND ct.content_type = ?',
        ['page', 'blocks']
    );
}

$templateCounts = array_fill_keys(BloxTemplateModel::TYPES, ['total' => 0, 'published' => 0]);
$templateTotal = 0;
$templatePublished = 0;
if (db()->tableExists('blox_templates')) {
    foreach (bloxTemplateModel()->catalog() as $template) {
        $type = (string) ($template['type'] ?? '');
        if (!isset($templateCounts[$type])) {
            continue;
        }
        $published = (int) ($template['status'] ?? 0) === 1;
        $templateCounts[$type]['total']++;
        $templateCounts[$type]['published'] += $published ? 1 : 0;
        $templateTotal++;
        $templatePublished += $published ? 1 : 0;
    }
}

$design = BloxDesignSystem::snapshot();
$activeTokenCount = count(array_filter(
    $design['tokens'],
    static fn (array $item): bool => (string) ($item['status'] ?? 'active') !== 'archived'
));
$activeStyleCount = count(array_filter(
    $design['styles'],
    static fn (array $item): bool => (string) ($item['status'] ?? 'active') !== 'archived'
));

$homeActive = HomeBloxDocument::isActive();
$homeHasPublished = HomeBloxDocument::hasPublished();
$homeStatus = $homeActive && $homeHasPublished
    ? __('site_design_home_active')
    : __('site_design_home_structured');
$areaRows = [
    'header' => ['label' => __('site_design_area_header'), 'icon' => 'ti-layout-navbar'],
    'footer' => ['label' => __('site_design_area_footer'), 'icon' => 'ti-layout-bottombar'],
    'popup' => ['label' => __('site_design_area_popup'), 'icon' => 'ti-window'],
];

$GLOBALS['pageTitle'] = __('site_design_title');
$GLOBALS['currentMenu'] = 'site_design';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-7" data-testid="site-design-dashboard">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-900"><?php echo e(__('site_design_title')); ?></h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500"><?php echo e(__('site_design_intro')); ?></p>
        </div>
        <?php if ($advancedBloxEnabled && $isAdministrator): ?>
        <a href="/admin/blox_editor.php?home=1" class="inline-flex h-10 items-center gap-2 bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-700">
            <i class="ti ti-home-edit"></i><?php echo e(__('site_design_open_home')); ?>
        </a>
        <?php else: ?>
        <a href="/admin/page.php" class="inline-flex h-10 items-center gap-2 bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-700">
            <i class="ti ti-files"></i><?php echo e(__('site_design_manage_pages')); ?>
        </a>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-2 gap-px overflow-hidden border border-gray-200 bg-gray-200 lg:grid-cols-4">
        <div class="bg-white px-5 py-4">
            <div class="text-xs text-gray-500"><?php echo e(__('site_design_current_theme')); ?></div>
            <div class="mt-1 truncate text-lg font-semibold text-gray-900"><?php echo e($currentTheme); ?></div>
        </div>
        <div class="bg-white px-5 py-4">
            <div class="text-xs text-gray-500"><?php echo e(__('site_design_blox_pages')); ?></div>
            <div class="mt-1 text-lg font-semibold text-gray-900"><?php echo $bloxPageCount; ?> / <?php echo $pageCount; ?></div>
        </div>
        <div class="bg-white px-5 py-4">
            <div class="text-xs text-gray-500"><?php echo e(__('site_design_drafts')); ?></div>
            <div class="mt-1 text-lg font-semibold text-gray-900"><?php echo $draftCount; ?></div>
        </div>
        <div class="bg-white px-5 py-4">
            <div class="text-xs text-gray-500"><?php echo e(__('site_design_published_templates')); ?></div>
            <div class="mt-1 text-lg font-semibold text-gray-900"><?php echo $templatePublished; ?> / <?php echo $templateTotal; ?></div>
        </div>
    </div>

    <section>
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-gray-900"><?php echo e(__('site_design_section_edit')); ?></h2>
        </div>
        <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
            <div class="flex flex-wrap items-center gap-4 px-5 py-4" data-testid="site-design-home">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-blue-50 text-blue-600"><i class="ti ti-home-edit text-xl"></i></span>
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-gray-900"><?php echo e(__('site_design_home')); ?></div>
                    <div class="mt-0.5 text-xs text-gray-500"><?php echo e($homeStatus); ?></div>
                </div>
                <?php if ($advancedBloxEnabled && $isAdministrator): ?>
                <a href="/admin/blox_editor.php?home=1" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75">
                    <?php echo e(__('site_design_open')); ?><i class="ti ti-arrow-right"></i>
                </a>
                <?php else: ?>
                <span class="text-xs text-gray-400"><i class="ti ti-lock mr-1"></i><?php echo e(__('site_design_advanced_locked')); ?></span>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap items-center gap-4 px-5 py-4" data-testid="site-design-pages">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-emerald-50 text-emerald-600"><i class="ti ti-files text-xl"></i></span>
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-gray-900"><?php echo e(__('site_design_pages')); ?></div>
                    <div class="mt-0.5 text-xs text-gray-500"><?php echo e(__('site_design_pages_hint', ['count' => $pageCount])); ?></div>
                </div>
                <a href="/admin/page.php" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75">
                    <?php echo e(__('site_design_manage')); ?><i class="ti ti-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-gray-900"><?php echo e(__('site_design_section_areas')); ?></h2>
        <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
            <?php foreach ($areaRows as $type => $area):
                $counts = $templateCounts[$type];
                $statusLabel = $counts['published'] > 0
                    ? __('site_design_status_published', ['count' => $counts['published']])
                    : ($counts['total'] > 0 ? __('site_design_status_draft', ['count' => $counts['total']]) : __('site_design_status_none'));
            ?>
            <div class="flex flex-wrap items-center gap-4 px-5 py-4" data-testid="site-design-area-<?php echo e($type); ?>">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-gray-100 text-gray-600"><i class="ti <?php echo e($area['icon']); ?> text-xl"></i></span>
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-gray-900"><?php echo e($area['label']); ?></div>
                    <div class="mt-0.5 text-xs text-gray-500"><?php echo e($statusLabel); ?></div>
                </div>
                <?php if ($advancedBloxEnabled && $isAdministrator): ?>
                <a href="/admin/blox_templates.php?type=<?php echo e($type); ?>" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75">
                    <?php echo e(__('site_design_manage')); ?><i class="ti ti-arrow-right"></i>
                </a>
                <?php else: ?>
                <span class="text-xs text-gray-400"><i class="ti ti-lock mr-1"></i><?php echo e(__('site_design_advanced_locked')); ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section>
        <h2 class="mb-3 text-sm font-semibold text-gray-900"><?php echo e(__('site_design_section_assets')); ?></h2>
        <div class="divide-y divide-gray-200 border-y border-gray-200 bg-white">
            <div class="flex flex-wrap items-center gap-4 px-5 py-4" data-testid="site-design-system">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-rose-50 text-rose-600"><i class="ti ti-palette text-xl"></i></span>
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-gray-900"><?php echo e(__('site_design_design_system')); ?></div>
                    <div class="mt-0.5 text-xs text-gray-500"><?php echo e(__('site_design_design_counts', ['tokens' => $activeTokenCount, 'styles' => $activeStyleCount])); ?></div>
                </div>
                <?php if ($isAdministrator): ?>
                <a href="/admin/blox_design.php" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75">
                    <?php echo e(__('site_design_manage')); ?><i class="ti ti-arrow-right"></i>
                </a>
                <?php else: ?>
                <span class="text-xs text-gray-400"><i class="ti ti-lock mr-1"></i><?php echo e(__('site_design_advanced_locked')); ?></span>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap items-center gap-4 px-5 py-4" data-testid="site-design-templates">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-violet-50 text-violet-600"><i class="ti ti-template text-xl"></i></span>
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-gray-900"><?php echo e(__('site_design_templates')); ?></div>
                    <div class="mt-0.5 text-xs text-gray-500"><?php echo e(__('site_design_template_counts', ['total' => $templateTotal, 'published' => $templatePublished])); ?></div>
                </div>
                <?php if ($advancedBloxEnabled && $isAdministrator): ?>
                <a href="/admin/blox_templates.php" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75">
                    <?php echo e(__('site_design_manage')); ?><i class="ti ti-arrow-right"></i>
                </a>
                <?php else: ?>
                <span class="text-xs text-gray-400"><i class="ti ti-lock mr-1"></i><?php echo e(__('site_design_advanced_locked')); ?></span>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap items-center gap-4 px-5 py-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-amber-50 text-amber-600"><i class="ti ti-brush text-xl"></i></span>
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-gray-900"><?php echo e(__('site_design_theme')); ?></div>
                    <div class="mt-0.5 text-xs text-gray-500"><?php echo e(__('site_design_theme_hint', ['theme' => $currentTheme])); ?></div>
                </div>
                <?php if ($isAdministrator): ?>
                <a href="/admin/theme.php" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75">
                    <?php echo e(__('site_design_manage')); ?><i class="ti ti-arrow-right"></i>
                </a>
                <?php else: ?>
                <span class="text-xs text-gray-400"><i class="ti ti-lock mr-1"></i><?php echo e(__('site_design_advanced_locked')); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if (!$advancedBloxEnabled || !$isAdministrator): ?>
    <div class="border-l-4 border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <strong><?php echo e(__('site_design_advanced_locked')); ?></strong>
        <span class="ml-1"><?php echo e(__('site_design_advanced_locked_hint')); ?></span>
    </div>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
