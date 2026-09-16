<?php
/**
 * YikaiCMS - 单页管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/admin/includes/list_ui.php';   // 列表共享组件：行内操作 / 批量下拉 / 封面占位

checkLogin();
requirePermission('edit_page');
$canEditBlox = hasPermission('blox_edit');

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'create') {
        $name = post('name');
        if (empty($name)) {
            error(__('pg_name_required'));
        }
        $slug = resolveSlug('', $name, 'channels', 0);
        $parentId = postInt('parent_id');
        $id = channelModel()->create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'type' => 'page',
            'status' => 1,
            'is_nav' => 1,
            'sort_order' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        adminLog('page', 'create', '创建单页：' . $name);
        success(['id' => $id]);
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $channel = channelModel()->find($id);
        $newStatus = $channel['status'] ? 0 : 1;
        channelModel()->updateById($id, ['status' => $newStatus, 'updated_at' => time()]);
        adminLog('page', 'toggle', "切换单页状态ID: $id");
        success(['status' => $newStatus]);
    }

    if ($action === 'delete') {
        requirePermission('delete_page');
        $id = postInt('id');
        $channel = channelModel()->find($id);
        if (!$channel) {
            error(__('pg_channel_missing'));
        }
        if (!empty($channel['is_system'])) {
            error(__('pg_system_undeletable'));
        }
        // 检查是否有子栏目
        $childCount = channelModel()->count(['parent_id' => $id]);
        if ($childCount > 0) {
            error(__('pg_has_children'));
        }
        // 删除关联内容
        contentModel()->query("DELETE FROM " . contentModel()->tableName() . " WHERE channel_id = ?", [$id]);
        // 删除栏目
        channelModel()->deleteById($id);
        adminLog('page', 'delete', '删除单页：' . $channel['name']);
        success();
    }

    exit;
}

// ============== 多语言视图 ==============
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/admin/includes/website_pages.php';
$allPages = array_map('websitePagePresentation', channelModel()->websitePages($_viewLang));
$pageView = in_array(get('view'), ['cards', 'list'], true) ? get('view') : 'cards';
$pageQuery = mb_substr((string) get('q'), 0, 200);
$pageFilter = in_array(get('filter'), ['all','active','disabled','changed','draft'], true) ? get('filter') : 'all';
$filteredPages = websitePagesFilter($allPages, $pageQuery, $pageFilter);
$pages = array_values(array_filter($filteredPages, static fn(array $page): bool => !empty($page['status'])));
$hiddenPages = array_values(array_filter($filteredPages, static fn(array $page): bool => empty($page['status'])));
// 结构页面（栏目首页 + 详情页模板）只在未搜索、未按状态筛选时展示：它们不属于单页集合，
// 混进搜索结果会让"共 N 个页面"的计数对不上。
$structuralPages = ($pageQuery === '' && $pageFilter === 'all') ? websiteStructuralPages($_viewLang) : [];
$homeTitle = websiteHomeTitle($_viewLang);
$homeUrl = langUrl('/', $_viewLang);
$showHome = in_array($pageFilter, ['all','active'], true) && ($pageQuery === '' || mb_strpos(mb_strtolower($homeTitle), mb_strtolower($pageQuery)) !== false);
$pageBrowseUrl = static fn(string $view): string => '/admin/page.php?' . http_build_query(['lang'=>$_viewLang, 'view'=>$view, 'q'=>$pageQuery, 'filter'=>$pageFilter]);

// 获取页脚导航URL列表
$footerNavUrls = [];
$footerNavData = json_decode(config('footer_nav') ?: '[]', true) ?: [];
foreach ($footerNavData as $group) {
    foreach (($group['links'] ?? []) as $link) {
        $footerNavUrls[] = $link['url'] ?? '';
    }
}

$pageTitle = __('website_pages_title');
$currentMenu = 'page';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('channels');

require_once ROOT_PATH . '/admin/includes/header.php';

echo renderAdminLangSwitcher($_viewLang, str_replace(':lang', $_defaultLang, __('pg_lang_tip')));
?>

<div class="mb-6 flex items-center justify-between">
    <p class="text-gray-500"><?php echo e(__('website_pages_intro')); ?></p>
    <?php if ($_viewLang === $_defaultLang): ?>
    <button onclick="showCreateModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded transition inline-flex items-center gap-1 whitespace-nowrap cursor-pointer">
        <i class="ti ti-plus text-base"></i>
        <?php echo __('admin_add'); ?>
    </button>
    <?php else: ?>
    <span class="text-xs text-gray-400"><?php echo e(__('pg_source_only')); ?></span>
    <?php endif; ?>
</div>

<form method="get" class="website-pages-toolbar" role="search">
    <input type="hidden" name="lang" value="<?php echo e($_viewLang); ?>">
    <input type="hidden" name="view" value="<?php echo e($pageView); ?>">
    <label class="website-pages-search"><span class="sr-only"><?php echo e(__('website_pages_search')); ?></span><i class="ti ti-search" aria-hidden="true"></i><input name="q" value="<?php echo e($pageQuery); ?>" placeholder="<?php echo e(__('website_pages_search')); ?>" maxlength="200"></label>
    <label><span class="sr-only"><?php echo e(__('website_pages_filter')); ?></span><select name="filter" aria-label="<?php echo e(__('website_pages_filter')); ?>">
    <?php foreach (['all','active','disabled','changed','draft'] as $filter): ?><option value="<?php echo e($filter); ?>" <?php echo $pageFilter === $filter ? 'selected' : ''; ?>><?php echo e(__('website_pages_filter_' . $filter)); ?></option><?php endforeach; ?>
    </select></label>
    <button class="website-pages-filter-button" type="submit"><?php echo e(__('website_pages_apply')); ?></button>
    <span class="website-page-note" role="status"><?php echo e(__('website_pages_count', ['count'=>count($filteredPages)])); ?></span>
    <nav class="website-pages-view" aria-label="<?php echo e(__('website_pages_view')); ?>">
        <a href="<?php echo e($pageBrowseUrl('cards')); ?>" <?php echo $pageView === 'cards' ? 'aria-current="page"' : ''; ?>><i class="ti ti-layout-grid" aria-hidden="true"></i><?php echo e(__('website_pages_cards')); ?></a>
        <a href="<?php echo e($pageBrowseUrl('list')); ?>" <?php echo $pageView === 'list' ? 'aria-current="page"' : ''; ?>><i class="ti ti-list" aria-hidden="true"></i><?php echo e(__('website_pages_list')); ?></a>
    </nav>
</form>
<?php if ($pageView === 'cards'): ?>
<div class="website-pages-grid">
    <?php if ($showHome) { echo renderWebsiteHomeCard($_viewLang); } ?>
    <?php // 已停用永远排在启用页之后，各自保持原有顺序 ?>
    <?php foreach ($pages as $page) { echo renderWebsitePageCard($page); } ?>
</div>
<?php // 栏目首页与详情页模板：同样需要排版，给一个直达编辑器的入口（不做启停/删除） ?>
<?php if ($structuralPages): ?>
<h2 class="website-pages-divider is-structural" data-testid="website-structural-divider">
    <i class="ti ti-layout-board" aria-hidden="true"></i>
    <span><?php echo e(__('website_structural_section')); ?></span>
</h2>
<div class="website-pages-grid">
    <?php foreach ($structuralPages as $item) { echo renderWebsiteStructuralCard($item); } ?>
</div>
<?php endif; ?>
<?php if ($hiddenPages): ?>
<h2 class="website-pages-divider" data-testid="website-pages-disabled-divider">
    <i class="ti ti-eye-off" aria-hidden="true"></i>
    <span><?php echo e(__('website_pages_disabled_section', ['count' => count($hiddenPages)])); ?></span>
</h2>
<div class="website-pages-grid">
    <?php foreach ($hiddenPages as $page) { echo renderWebsitePageCard($page); } ?>
</div>
<?php endif; ?>
<?php if (!$filteredPages): ?><p class="website-pages-empty"><?php echo e(__('website_pages_empty')); ?> <a href="/admin/page.php?lang=<?php echo e(rawurlencode($_viewLang)); ?>"><?php echo e(__('website_pages_reset')); ?></a></p><?php endif; ?>
<?php else: ?>
<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('page_name'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('page_parent'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('page_url'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('page_menu_position'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_sort_order'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_translate')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if ($showHome): ?><tr class="bg-blue-50/60 hover:bg-blue-50" data-testid="page-home-row">
                    <td class="px-4 py-3 text-gray-400">-</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <i class="ti ti-home text-primary text-lg"></i>
                            <div>
                                <div class="font-medium flex items-center gap-2">
                                    <?php echo e($homeTitle); ?>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-600 whitespace-nowrap"><?php echo e(__('admin_label_fixed')); ?></span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs text-gray-400"><?php echo __('admin_none'); ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded"><?php echo e($homeUrl); ?></code>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-600"><?php echo __('page_main_nav'); ?></span>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-400">-</td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs px-2 py-1 rounded bg-green-100 text-green-600"><?php echo __('admin_show'); ?></span>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-300">-</td>
                    <td class="px-4 py-3 text-left">
                        <?php if (bloxPageEditorEnabled() && hasPermission('blox_home')): ?>
                        <a href="/admin/blox_editor.php?home=1&amp;lang=<?php echo e(rawurlencode($_viewLang)); ?>"
                           data-testid="page-home-edit"
                           class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1"
                           title="<?php echo e(__('page_design_web')); ?>">
                            <i class="ti ti-stack-2 text-sm"></i>
                            <?php echo e(__('page_design_web')); ?>
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo e($homeUrl); ?>" target="_blank"
                           class="text-gray-500 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-external-link text-sm"></i>
                            <?php echo __('admin_preview'); ?>
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($pages as $item): ?>
                <?php
                $itemUrl = channelUrl($item);
                $itemEditTarget = pagePrimaryEditTarget($item);
                $itemEditUrl = pagePrimaryEditUrl($item);
                $itemEditRedirected = (int) ($itemEditTarget['id'] ?? 0) !== (int) $item['id'];
                $isTimelinePage = isTimelinePageChannel($itemEditTarget);
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo $item['id']; ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div>
                                <div class="font-medium flex items-center gap-2">
                                    <?php echo e($item['name']); ?>
                                    <span class="website-publication-label"><?php echo e(__('website_pages_state_' . $item['publication'])); ?></span>
                                    <?php if (($item['type'] ?? '') === 'album'): ?>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-purple-100 text-purple-600 whitespace-nowrap"><?php echo __('admin_album'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($itemEditRedirected): ?>
                                    <span data-testid="page-redirect-target-<?php echo (int) $item['id']; ?>"
                                          class="text-[10px] px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 whitespace-nowrap"
                                          title="<?php echo e(__('page_redirect_target_tip', ['source' => $item['name'], 'target' => $itemEditTarget['name'] ?? ''])); ?>">
                                        <i class="ti ti-corner-down-right mr-0.5"></i><?php echo e(__('page_redirect_target_badge', ['name' => $itemEditTarget['name'] ?? ''])); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($item['description']): ?>
                                <div class="text-xs text-gray-400"><?php echo e(cutStr($item['description'], 30)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($item['parent_name']): ?>
                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                            <?php echo e($item['parent_name']); ?>
                        </span>
                        <?php else: ?>
                        <span class="text-xs text-gray-400"><?php echo __('admin_none'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded"><?php echo e($itemUrl); ?></code>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $inMain = !empty($item['is_nav']);
                        $inFooter = in_array($itemUrl, $footerNavUrls);
                        ?>
                        <?php if ($inMain): ?>
                        <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-600"><?php echo __('page_main_nav'); ?></span>
                        <?php endif; ?>
                        <?php if ($inFooter): ?>
                        <span class="text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-600"><?php echo __('page_footer_nav'); ?></span>
                        <?php endif; ?>
                        <?php if (!$inMain && !$inFooter): ?>
                        <span class="text-xs text-gray-400"><?php echo __('page_none'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-500">
                        <?php echo $item['sort_order']; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? __('admin_show') : __('admin_hide'); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if (($item['type'] ?? '') === 'album'): ?>
                        <span class="text-xs text-gray-300">—</span>
                        <?php else: ?>
                        <?php if ($canEditBlox): ?>
                        <?php echo renderTransPills((int)$item['id'], $transStatus, '/admin/blox_editor.php'); ?>
                        <?php else: ?>
                        <span class="text-xs text-gray-300">-</span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-left">
                        <?php if (($item['type'] ?? '') === 'album'): ?>
                        <?php $albumId = (int)($item['album_id'] ?? 0); ?>
                        <a href="<?php echo $albumId ? '/admin/album_photos.php?id=' . $albumId : '/admin/channel.php?id=' . $item['id']; ?>"
                           class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1"
                           title="<?php echo e(__('admin_album')); ?>">
                            <i class="ti ti-photo text-sm"></i>
                            <?php echo __('admin_content_edit'); ?>
                        </a>
                        <?php else: ?>
                        <?php $__isBlox = in_array($item['publication'], ['published','changed','draft'], true); ?>
                        <?php if ($isTimelinePage || $canEditBlox): ?>
                        <a href="<?php echo e($itemEditUrl); ?>"
                           data-testid="page-primary-edit-<?php echo (int) $item['id']; ?>"
                            class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti <?php echo $isTimelinePage ? 'ti-timeline' : 'ti-stack-2'; ?> text-sm"></i>
                            <?php echo e(__($isTimelinePage ? 'admin_content_edit' : 'page_design_web')); ?>
                        </a>
                        <?php else: ?>
                        <span class="text-xs text-gray-400"><i class="ti ti-lock mr-1"></i><?php echo e(__('site_design_advanced_locked')); ?></span>
                        <?php endif; ?>
                        <?php if ($__isBlox && !$isTimelinePage && !$itemEditRedirected): ?>
                        <span class="text-xs px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-600 mr-2"
                              title="<?php echo e(__('page_mode_blocks_tip')); ?>"><?php echo __('page_mode_blocks'); ?></span>
                        <?php endif; ?>
                        <?php endif; ?>
                        <a href="<?php echo e($itemUrl); ?>" target="_blank"
                           class="text-gray-500 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-external-link text-sm"></i>
                            <?php echo __('admin_preview'); ?>
                        </a>
                        <?php if (($item['type'] ?? '') !== 'album'): ?>
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="ml-2 text-sm inline-flex items-center gap-1 <?php echo $item['status'] ? 'text-amber-600 hover:text-amber-700' : 'text-green-600 hover:text-green-700'; ?>"
                                title="<?php echo $item['status'] ? e(__('pg_disable_hint')) : ''; ?>">
                            <i class="ti <?php echo $item['status'] ? 'ti-eye-off' : 'ti-eye'; ?> text-sm"></i>
                            <?php echo $item['status'] ? e(__('pg_disable')) : e(__('admin_enabled')); ?>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($pages)): ?>
                <tr>
                    <td colspan="9" class="px-4 py-8 text-center text-gray-500"><?php echo __('admin_no_data'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($hiddenPages)): ?>
<!-- 已停用单页：不占主列表，可恢复；非系统页可删除 -->
<div class="bg-white rounded-lg shadow mt-6">
    <div class="px-4 py-3 border-b flex items-center gap-2">
        <i class="ti ti-eye-off text-gray-400"></i>
        <h3 class="font-medium text-gray-600"><?php echo __('admin_channel_hidden_tab'); ?>
            <span class="text-xs text-gray-400">(<?php echo count($hiddenPages); ?>)</span></h3>
        <span class="text-xs text-gray-400 ml-2"><?php echo __('admin_channel_hidden_tip'); ?></span>
    </div>
    <div class="p-4 space-y-2">
        <?php foreach ($hiddenPages as $item): ?>
        <?php
        $itemUrl = channelUrl($item);
        $itemEditTarget = pagePrimaryEditTarget($item);
        $itemEditUrl = pagePrimaryEditUrl($item);
        $itemEditRedirected = (int) ($itemEditTarget['id'] ?? 0) !== (int) $item['id'];
        $isTimelinePage = isTimelinePageChannel($itemEditTarget);
        ?>
        <div class="flex items-center gap-3 px-4 py-2.5 bg-gray-50/70 rounded-lg border border-dashed hover:shadow-sm">
            <span class="text-gray-300"><i class="ti ti-eye-off text-base"></i></span>
            <span class="text-gray-400 font-medium"><?php echo e($item['name']); ?></span>
            <?php if (($item['type'] ?? '') === 'album'): ?>
            <span class="text-[10px] px-1.5 py-0.5 rounded bg-purple-100 text-purple-600 whitespace-nowrap"><?php echo __('admin_album'); ?></span>
            <?php endif; ?>
            <?php if ($item['parent_name']): ?>
            <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-400"><?php echo __('page_parent'); ?>：<?php echo e($item['parent_name']); ?></span>
            <?php endif; ?>
            <?php if ($itemEditRedirected): ?>
            <span data-testid="page-redirect-target-<?php echo (int) $item['id']; ?>"
                  class="text-[10px] px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 whitespace-nowrap"
                  title="<?php echo e(__('page_redirect_target_tip', ['source' => $item['name'], 'target' => $itemEditTarget['name'] ?? ''])); ?>">
                <i class="ti ti-corner-down-right mr-0.5"></i><?php echo e(__('page_redirect_target_badge', ['name' => $itemEditTarget['name'] ?? ''])); ?>
            </span>
            <?php endif; ?>
            <code class="text-xs bg-gray-100 px-2 py-0.5 rounded text-gray-400"><?php echo e($itemUrl); ?></code>
            <span class="flex-1"></span>
            <?php if (($item['type'] ?? '') !== 'album'): ?>
            <?php $__isBlox = in_array($item['publication'], ['published','changed','draft'], true); ?>
            <?php if ($isTimelinePage || $canEditBlox): ?>
            <a href="<?php echo e($itemEditUrl); ?>"
               data-testid="page-primary-edit-<?php echo (int) $item['id']; ?>"
               class="text-primary hover:underline text-sm inline-flex items-center gap-1 whitespace-nowrap">
                <i class="ti <?php echo $isTimelinePage ? 'ti-timeline' : 'ti-stack-2'; ?> text-sm"></i><?php echo e(__($isTimelinePage ? 'admin_content_edit' : 'page_design_web')); ?>
            </a>
            <?php else: ?>
            <span class="text-xs text-gray-400"><i class="ti ti-lock mr-1"></i><?php echo e(__('site_design_advanced_locked')); ?></span>
            <?php endif; ?>
            <?php endif; ?>
            <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                    class="text-sm px-3 py-1 rounded border border-green-500 text-green-600 hover:bg-green-500 hover:text-white transition cursor-pointer inline-flex items-center gap-1 whitespace-nowrap">
                <i class="ti ti-eye text-base"></i><?php echo __('admin_channel_restore'); ?>
            </button>
            <?php if (empty($item['is_system']) && ($item['type'] ?? '') !== 'album'): ?>
            <button onclick="deletePage(<?php echo $item['id']; ?>, '<?php echo e($item['name']); ?>')"
                    class="text-red-500 hover:text-red-700 text-sm inline-flex items-center gap-1 whitespace-nowrap">
                <i class="ti ti-trash text-base"></i><?php echo __('admin_delete'); ?>
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- 添加单页弹窗 -->
<div id="createModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800"><?php echo __('admin_add'); ?></h3>
            <button type="button" onclick="closeCreateModal()" class="text-gray-400 hover:text-gray-600">
                <i class="ti ti-x text-xl"></i>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('page_name'); ?> <span class="text-red-500">*</span></label>
                <input type="text" id="createName" class="w-full border rounded px-4 py-2">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('page_parent'); ?></label>
                <select id="createParent" class="w-full border rounded px-4 py-2">
                    <option value="0"><?php echo __('admin_top_level'); ?></option>
                    <?php foreach ($allPages as $p): ?>
                    <?php if (!$p['parent_id']): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo e($p['name']); ?></option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" onclick="createPage()" class="w-full bg-primary hover:bg-secondary text-white py-2 rounded transition">
                <?php echo __('page_create_edit'); ?>
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function(event) {
    const button = event.target.closest('[data-page-delete]');
    if (button) deletePage(Number(button.dataset.pageDelete), button.dataset.pageName);
});

function showCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
    document.getElementById('createModal').classList.add('flex');
    document.getElementById('createName').value = '';
    document.getElementById('createName').focus();
}

function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
    document.getElementById('createModal').classList.remove('flex');
}

async function createPage() {
    var name = document.getElementById('createName').value.trim();
    if (!name) { showMessage('<?php echo __('page_name_required'); ?>', 'error'); return; }
    var formData = new FormData();
    formData.append('action', 'create');
    formData.append('name', name);
    formData.append('parent_id', document.getElementById('createParent').value);
    var response = await fetch('', { method: 'POST', body: formData });
    var data = await safeJson(response);
    if (data.code === 0) {
        showMessage(<?php echo json_encode(__('pg_created'), JSON_UNESCAPED_UNICODE); ?>);
        setTimeout(function() { location.href = <?php echo json_encode($canEditBlox ? '/admin/blox_editor.php?id=' : '/admin/page.php'); ?> + <?php echo $canEditBlox ? 'data.data.id' : "''"; ?>; }, 500);
    } else {
        showMessage(data.msg, 'error');
    }
}

async function deletePage(id, name) {
    if (!confirm(<?php echo json_encode(__('pg_del_confirm'), JSON_UNESCAPED_UNICODE); ?>.replace(':name', name))) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(function() { location.reload(); }, 800);
    } else {
        showMessage(data.msg, 'error');
    }
}

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        // 刷新让行在「主列表 ↔ 已停用」间移动
        showMessage(<?php echo json_encode(__('album_status_updated'), JSON_UNESCAPED_UNICODE); ?>);
        setTimeout(function() { location.reload(); }, 400);
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
