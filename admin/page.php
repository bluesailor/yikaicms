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

// 获取当前视图语言下的单页 + 图库相册类型栏目
// （album 型栏目本身是导航入口，内容在相册里维护，一并列出避免用户在「单页」找不到入口）
// content_type 与草稿只用于展示 Blox 状态；普通单页的主编辑入口统一进入 Blox。
$hasBloxDraftStorage = db()->tableExists('blox_page_drafts');
$draftSelect = $hasBloxDraftStorage ? ', bd.id AS blox_draft_id' : ', 0 AS blox_draft_id';
$draftJoin = $hasBloxDraftStorage
    ? ' LEFT JOIN ' . DB_PREFIX . 'blox_page_drafts bd ON bd.page_id = c.id'
    : '';
$pages = channelModel()->query(
    'SELECT c.*, p.name as parent_name, ct.content_type' . $draftSelect . ' FROM ' . channelModel()->tableName() . ' c
     LEFT JOIN ' . channelModel()->tableName() . ' p ON c.parent_id = p.id
     LEFT JOIN ' . contentModel()->tableName() . ' ct
            ON ct.channel_id = c.id AND ct.lang = c.lang AND ct.deleted_at IS NULL
     ' . $draftJoin . '
     WHERE c.type IN (\'page\', \'album\') AND c.lang = ? ORDER BY c.parent_id ASC, c.sort_order ASC, c.id ASC',
    [$_viewLang]
);

// 已停用（status=0）的单页收进下方独立区块，不占主列表（同栏目管理「已停用」页签）
$hiddenPages = array_values(array_filter($pages, fn($p) => empty($p['status'])));
$pages = array_values(array_filter($pages, fn($p) => !empty($p['status'])));

// 获取页脚导航URL列表
$footerNavUrls = [];
$footerNavData = json_decode(config('footer_nav') ?: '[]', true) ?: [];
foreach ($footerNavData as $group) {
    foreach (($group['links'] ?? []) as $link) {
        $footerNavUrls[] = $link['url'] ?? '';
    }
}

$pageTitle = __('admin_page_static');
$currentMenu = 'page';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('channels');

require_once ROOT_PATH . '/admin/includes/header.php';

echo renderAdminLangSwitcher($_viewLang, str_replace(':lang', $_defaultLang, __('pg_lang_tip')));
?>

<div class="mb-6 flex items-center justify-between">
    <p class="text-gray-500"><?php echo __('page_desc'); ?></p>
    <?php if ($_viewLang === $_defaultLang): ?>
    <button onclick="showCreateModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded transition inline-flex items-center gap-1 whitespace-nowrap cursor-pointer">
        <i class="ti ti-plus text-base"></i>
        <?php echo __('admin_add'); ?>
    </button>
    <?php else: ?>
    <span class="text-xs text-gray-400"><?php echo e(__('pg_source_only')); ?></span>
    <?php endif; ?>
</div>

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
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr class="bg-blue-50/60 hover:bg-blue-50" data-testid="page-home-row">
                    <td class="px-4 py-3 text-gray-400">-</td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <i class="ti ti-home text-primary text-lg"></i>
                            <div>
                                <div class="font-medium flex items-center gap-2">
                                    <?php echo e(__('admin_home')); ?>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-blue-100 text-blue-600 whitespace-nowrap"><?php echo e(__('admin_label_fixed')); ?></span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs text-gray-400"><?php echo __('admin_none'); ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded">/</code>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-600"><?php echo __('page_main_nav'); ?></span>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-400">-</td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs px-2 py-1 rounded bg-green-100 text-green-600"><?php echo __('admin_show'); ?></span>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-300">-</td>
                    <td class="px-4 py-3 text-center">
                        <?php if (bloxPageEditorEnabled()): ?>
                        <a href="/admin/blox_editor.php?home=1"
                           data-testid="page-home-edit"
                           class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1"
                           title="<?php echo e(__('site_design_open_home')); ?>">
                            <i class="ti ti-pencil text-sm"></i>
                            <?php echo e(__('site_design_open_home')); ?>
                        </a>
                        <?php endif; ?>
                        <a href="/" target="_blank"
                           class="text-gray-500 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-external-link text-sm"></i>
                            <?php echo __('admin_preview'); ?>
                        </a>
                    </td>
                </tr>
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
                            <?php if ($item['image']): ?>
                            <img src="<?php echo e($item['image']); ?>" class="w-12 h-8 object-cover rounded">
                            <?php endif; ?>
                            <div>
                                <div class="font-medium flex items-center gap-2">
                                    <?php echo e($item['name']); ?>
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
                        <?php echo renderTransPills((int)$item['id'], $transStatus, '/admin/blox_editor.php'); ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if (($item['type'] ?? '') === 'album'): ?>
                        <?php $albumId = (int)($item['album_id'] ?? 0); ?>
                        <a href="<?php echo $albumId ? '/admin/album_photos.php?id=' . $albumId : '/admin/channel.php?id=' . $item['id']; ?>"
                           class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1"
                           title="<?php echo e(__('admin_album')); ?>">
                            <i class="ti ti-photo text-sm"></i>
                            <?php echo __('admin_content_edit'); ?>
                        </a>
                        <?php else: ?>
                        <?php $__isBlox = ($item['content_type'] ?? 'html') === 'blocks' || (int) ($item['blox_draft_id'] ?? 0) > 0; ?>
                        <a href="<?php echo e($itemEditUrl); ?>"
                           data-testid="page-primary-edit-<?php echo (int) $item['id']; ?>"
                            class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti <?php echo $isTimelinePage ? 'ti-timeline' : 'ti-stack-2'; ?> text-sm"></i>
                            <?php echo $itemEditRedirected
                                ? e(__('page_edit_redirect_target', ['name' => $itemEditTarget['name'] ?? '']))
                                : ($isTimelinePage ? e(__('admin_timeline')) : e(__('page_mode_blox'))); ?>
                        </a>
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
            <?php $__isBlox = ($item['content_type'] ?? 'html') === 'blocks' || (int) ($item['blox_draft_id'] ?? 0) > 0; ?>
            <a href="<?php echo e($itemEditUrl); ?>"
               data-testid="page-primary-edit-<?php echo (int) $item['id']; ?>"
               class="text-primary hover:underline text-sm inline-flex items-center gap-1 whitespace-nowrap">
                <i class="ti <?php echo $isTimelinePage ? 'ti-timeline' : 'ti-stack-2'; ?> text-sm"></i><?php echo $itemEditRedirected
                    ? e(__('page_edit_redirect_target', ['name' => $itemEditTarget['name'] ?? '']))
                    : ($isTimelinePage ? e(__('admin_timeline')) : e(__('page_mode_blox'))); ?>
            </a>
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
                    <?php foreach ($pages as $p): ?>
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
        setTimeout(function() { location.href = '/admin/blox_editor.php?id=' + data.data.id; }, 500);
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
