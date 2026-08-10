<?php
/**
 * YikaiCMS - 轮播图管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('banner');

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'title' => post('title'),
            'subtitle' => post('subtitle'),
            'image' => post('image'),
            'btn1_text' => post('btn1_text'),
            'btn1_url' => post('btn1_url'),
            'btn2_text' => post('btn2_text'),
            'btn2_url' => post('btn2_url'),
            'link_url' => post('link_url'),
            'link_target' => post('link_target', '_self'),
            'position' => post('position', 'home'),
            'sort_order' => postInt('sort_order', 0),
            'status' => postInt('status', 1),
        ];

        if ($id > 0) {
            bannerModel()->updateById($id, $data);
            adminLog('banner', 'update', "更新轮播图ID: $id");
        } else {
            $data['created_at'] = time();
            $id = bannerModel()->create($data);
            adminLog('banner', 'create', "创建轮播图ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        bannerModel()->deleteById($id);
        adminLog('banner', 'delete', "删除轮播图ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = bannerModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'sort') {
        $ids = $_POST['ids'] ?? [];
        bannerModel()->updateSort($ids);
        success();
    }

    if ($action === 'save_settings') {
        $heightPc = postInt('banner_height_pc', 650);
        $heightMobile = postInt('banner_height_mobile', 300);

        $heightPc = max(200, min(1000, $heightPc));
        $heightMobile = max(150, min(600, $heightMobile));

        settingModel()->set('banner_height_pc', (string)$heightPc);
        settingModel()->set('banner_height_mobile', (string)$heightMobile);

        adminLog('banner', 'settings', "更新轮播图设置: PC={$heightPc}px, 移动端={$heightMobile}px");
        success();
    }

    // ── 分组管理 ──

    if ($action === 'group_save') {
        $id = postInt('id');
        $name = post('name');
        $slug = post('slug');

        if (!$name || !$slug) {
            error(__('bn_group_required'));
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            error(__('bn_slug_format'));
        }

        if (!bannerGroupModel()->isSlugUnique($slug, $id)) {
            error(__('bn_slug_exists'));
        }

        $data = [
            'name'           => $name,
            'slug'           => $slug,
            'height_pc'      => max(200, min(2000, postInt('height_pc', 500))),
            'height_mobile'  => max(100, min(1000, postInt('height_mobile', 250))),
            'fullscreen'     => (isset($_POST['fullscreen']) && $_POST['fullscreen'] === '1') ? 1 : 0,
            'autoplay_delay' => max(0, min(30000, postInt('autoplay_delay', 5000))),
        ];

        if ($id > 0) {
            // 更新分组时同步更新 banners.position
            $old = bannerGroupModel()->find($id);
            bannerGroupModel()->updateById($id, $data);
            if ($old && $old['slug'] !== $slug) {
                db()->execute(
                    "UPDATE " . DB_PREFIX . "banners SET position = ? WHERE position = ?",
                    [$slug, $old['slug']]
                );
            }
            adminLog('banner', 'group_update', "更新轮播图分组: {$name}");
        } else {
            $data['status'] = 1;
            $data['created_at'] = time();
            $id = bannerGroupModel()->create($data);
            adminLog('banner', 'group_create', "创建轮播图分组: {$name}");
        }

        success(['id' => $id]);
    }

    if ($action === 'group_delete') {
        $id = postInt('id');
        $group = bannerGroupModel()->find($id);
        if (!$group) error(__('bn_group_missing'));

        $count = bannerGroupModel()->getBannerCount($group['slug']);
        if ($count > 0) {
            error(str_replace(':n', (string) $count, __('bn_group_has_items')));
        }

        bannerGroupModel()->deleteById($id);
        adminLog('banner', 'group_delete', "删除轮播图分组: {$group['name']}");
        success();
    }

    if ($action === 'group_toggle') {
        $id = postInt('id');
        $newStatus = bannerGroupModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    exit;
}

// 当前 Tab
$tab = get('tab', 'list');

// 动态分组
$groups = [];
try {
    $groups = bannerGroupModel()->all();
} catch (\Throwable $e) {
    // 表不存在时降级
}
$positions = [];
foreach ($groups as $g) {
    $positions[$g['slug']] = $g['name'];
}
if (empty($positions)) {
    $positions = ['home' => __('nav_home'), 'about' => __('shome_blk_about'), 'product' => __('admin_product'), 'case' => __('bn_pos_case')];
}

// 轮播图列表数据（list Tab 用）
$position = get('position', '');
$page = max(1, getInt('page', 1));
$perPage = 20;

$conditions = [];
if ($position) {
    $conditions['position'] = $position;
}

// 视图语言（?lang=en/ja 切换：banners 表有 lang 列）
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_langLabels  = availableLanguages();
$conditions['lang'] = $_viewLang;

$result = bannerModel()->paginate($page, $perPage, $conditions, 'sort_order ASC, id DESC');
$total = $result['total'];
$banners = $result['items'];

// 全局设置
$bannerHeightPc = (int)config('banner_height_pc', 650);
$bannerHeightMobile = (int)config('banner_height_mobile', 300);

$pageTitle = __('admin_banner');
$currentMenu = 'banner';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('banners');
require_once ROOT_PATH . '/admin/includes/header.php';

echo renderAdminLangSwitcher($_viewLang, __('bn_lang_tip'));
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/banner.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'list' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            <?php echo e(__('bn_tab_list')); ?>
        </a>
        <a href="/admin/banner.php?tab=groups<?php echo $_lang['qsAmp'] ?? ''; ?>" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'groups' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            <?php echo e(__('bn_tab_groups')); ?>
        </a>
    </div>
</div>

<?php if ($tab === 'list'): ?>
<!-- ========== 轮播图列表 ========== -->

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="position" class="border rounded px-3 py-2">
                <option value=""><?php echo __('filter_all_groups'); ?></option>
                <?php foreach ($positions as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $position === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_filter'); ?>
            </button>
        </form>

        <div class="flex gap-2">
            <button onclick="openSettingsModal()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-settings text-base"></i>
                <?php echo e(__('bn_tab_settings')); ?>
            </button>
            <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-plus text-base"></i>
                <?php echo __('admin_add'); ?>
            </button>
        </div>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_sort_order'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_image'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_title_label'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('label_group'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_translate')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y" id="sortableList">
                <?php foreach ($banners as $item): ?>
                <tr class="hover:bg-gray-50" data-id="<?php echo $item['id']; ?>">
                    <td class="px-4 py-3">
                        <span class="cursor-move text-gray-400">&#9776;</span>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($item['image']): ?>
                        <img src="<?php echo e($item['image']); ?>" class="h-12 w-20 object-cover rounded">
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium"><?php echo e($item['title'] ?: __('bn_untitled')); ?></div>
                        <?php if ($item['subtitle']): ?>
                        <div class="text-sm text-gray-500"><?php echo e($item['subtitle']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                            <?php echo $positions[$item['position']] ?? $item['position']; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? __('admin_show') : __('admin_hide'); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $_pillSrcId = (int) ($item['translation_group_id'] ?? $item['id']);
                        echo renderTransPills($_pillSrcId, $transStatus, '/admin/banner.php');
                        ?>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-pencil text-sm"></i>
                            <?php echo __('admin_edit'); ?></button>
                        <button onclick="deleteBanner(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-trash text-sm"></i>
                            <?php echo __('admin_delete'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($banners)): ?>
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-500"><?php echo __('admin_no_data'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo __('banner_add'); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('label_title'); ?></label>
                <input type="text" name="title" id="editTitle" class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('label_subtitle'); ?></label>
                <input type="text" name="subtitle" id="editSubtitle" class="w-full border rounded px-4 py-2">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_btn1_text'); ?></label>
                    <input type="text" name="btn1_text" id="editBtn1Text" class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('bn_ph_btn1')); ?>">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_btn1_url'); ?></label>
                    <input type="text" name="btn1_url" id="editBtn1Url" class="w-full border rounded px-4 py-2" placeholder="/about.html">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_btn2_text'); ?></label>
                    <input type="text" name="btn2_text" id="editBtn2Text" class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('bn_ph_btn2')); ?>">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_btn2_url'); ?></label>
                    <input type="text" name="btn2_url" id="editBtn2Url" class="w-full border rounded px-4 py-2" placeholder="/contact.html">
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('label_image'); ?></label>
                <div class="flex gap-2">
                    <input type="text" name="image" id="editImage" class="flex-1 border rounded px-4 py-2">
                    <button type="button" onclick="uploadImage()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded"><?php echo __('admin_choose_file'); ?></button>
                    <button type="button" onclick="pickImageFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded"><?php echo __('admin_media_library'); ?></button>
                </div>
                <div id="imagePreview" class="mt-2"></div>
                <p class="text-xs text-gray-400 mt-1"><?php echo str_replace(':size', '1920 x ' . $bannerHeightPc . 'px', e(__('bn_suggest_size'))); ?></p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_link_url'); ?></label>
                    <input type="text" name="link_url" id="editLinkUrl" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_link_target'); ?></label>
                    <select name="link_target" id="editLinkTarget" class="w-full border rounded px-4 py-2">
                        <option value="_self"><?php echo __('label_target_self'); ?></option>
                        <option value="_blank"><?php echo __('label_target_blank'); ?></option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_group'); ?></label>
                    <select name="position" id="editPosition" class="w-full border rounded px-4 py-2">
                        <?php foreach ($positions as $k => $v): ?>
                        <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo __('admin_show'); ?></option>
                        <option value="0"><?php echo __('admin_hide'); ?></option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __('admin_save'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- 设置弹窗 -->
<div id="settingsModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeSettingsModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800"><?php echo __('banner_settings'); ?></h3>
            <button onclick="closeSettingsModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="settingsForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save_settings">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_pc_height')); ?> (px)</label>
                <input type="number" name="banner_height_pc" id="settingHeightPc" value="<?php echo $bannerHeightPc; ?>" min="200" max="1000" class="w-full border rounded px-4 py-2">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_pc_height_tip')); ?></p>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_mobile_height')); ?> (px)</label>
                <input type="number" name="banner_height_mobile" id="settingHeightMobile" value="<?php echo $bannerHeightMobile; ?>" min="150" max="600" class="w-full border rounded px-4 py-2">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_mobile_height_tip')); ?></p>
            </div>

            <div class="bg-blue-50 text-blue-700 p-3 rounded text-sm">
                <strong><?php echo e(__('admin_tip_label')); ?></strong><?php echo e(__('bn_global_height_tip')); ?>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeSettingsModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __('admin_save'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// 拖拽排序
new Sortable(document.getElementById('sortableList'), {
    animation: 150,
    handle: '.cursor-move',
    onEnd: async function() {
        const ids = [...document.querySelectorAll('#sortableList tr[data-id]')].map(el => el.dataset.id);
        const formData = new FormData();
        formData.append('action', 'sort');
        ids.forEach(id => formData.append('ids[]', id));
        await fetch('', { method: 'POST', body: formData });
    }
});

function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? '<?php echo __("banner_edit"); ?>' : '<?php echo __("banner_add"); ?>';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editTitle').value = item?.title || '';
    document.getElementById('editSubtitle').value = item?.subtitle || '';
    document.getElementById('editBtn1Text').value = item?.btn1_text || '';
    document.getElementById('editBtn1Url').value = item?.btn1_url || '';
    document.getElementById('editBtn2Text').value = item?.btn2_text || '';
    document.getElementById('editBtn2Url').value = item?.btn2_url || '';
    document.getElementById('editImage').value = item?.image || '';
    document.getElementById('editLinkUrl').value = item?.link_url || '';
    document.getElementById('editLinkTarget').value = item?.link_target || '_self';
    document.getElementById('editPosition').value = item?.position || 'home';
    document.getElementById('editSortOrder').value = item?.sort_order || 0;
    document.getElementById('editStatus').value = item?.status ?? 1;

    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';
    if (item?.image) {
        const previewImg = document.createElement('img');
        previewImg.src = item.image;
        previewImg.className = 'h-20 rounded';
        preview.appendChild(previewImg);
    }

    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage('<?php echo __('admin_saved'); ?>');
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?> + ': ' + err.message, 'error');
    }
});

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = <?php echo json_encode(__('admin_show'), JSON_UNESCAPED_UNICODE); ?>;
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = <?php echo json_encode(__('admin_hide'), JSON_UNESCAPED_UNICODE); ?>;
        }
    }
}

async function deleteBanner(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}

function uploadImage() {
    document.getElementById('imageFileInput').click();
}

function pickImageFromMedia() {
    openMediaPicker(function(url) {
        document.getElementById('editImage').value = url;
        var preview = document.getElementById('imagePreview');
        if (preview) {
            preview.innerHTML = '<img src="' + url + '" class="h-20 rounded">';
        }
    });
}

function openSettingsModal() {
    document.getElementById('settingsModal').classList.remove('hidden');
}

function closeSettingsModal() {
    document.getElementById('settingsModal').classList.add('hidden');
}

document.getElementById('settingsForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage(<?php echo json_encode(__('save_success'), JSON_UNESCAPED_UNICODE); ?>);
        closeSettingsModal();
    } else {
        showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
});

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');

    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            document.getElementById('editImage').value = data.data.url;
            document.getElementById('imagePreview').innerHTML = '';
            const uploadedImg = document.createElement('img');
            uploadedImg.src = data.data.url;
            uploadedImg.className = 'h-20 rounded';
            document.getElementById('imagePreview').appendChild(uploadedImg);
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
    }

    this.value = '';
});
</script>
<?php endif; ?>

<?php if ($tab === 'groups'): ?>
<!-- ========== 分组管理 ========== -->

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-between items-center">
        <p class="text-sm text-gray-500"><?php echo e(__('bn_groups_intro')); ?></p>
        <button onclick="openGroupModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i>
            <?php echo e(__('bn_add_group')); ?>
        </button>
    </div>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_name'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('bn_slug')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('label_shortcode'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('bn_size_col')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('bn_autoplay')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_banner')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($groups as $g):
                    $bannerCount = bannerGroupModel()->getBannerCount($g['slug'], $_viewLang);
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium"><?php echo e($g['name']); ?></td>
                    <td class="px-4 py-3">
                        <code class="text-sm bg-gray-100 px-1.5 py-0.5 rounded"><?php echo e($g['slug']); ?></code>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <code class="text-sm bg-blue-50 text-blue-700 px-2 py-1 rounded">[banner-<?php echo e($g['slug']); ?>]</code>
                            <button onclick="copyShortcode('<?php echo e($g['slug']); ?>')" class="text-gray-400 hover:text-primary" title="<?php echo e(__('bn_copy_shortcode')); ?>">
                                <i class="ti ti-copy text-base"></i>
                            </button>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600">
                        <?php echo $g['height_pc']; ?> / <?php echo $g['height_mobile']; ?>px
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600">
                        <?php echo $g['autoplay_delay'] > 0 ? ($g['autoplay_delay'] / 1000) . 's' : __('bn_off'); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="/admin/banner.php?position=<?php echo e($g['slug']); ?>" class="text-primary hover:underline text-sm"><?php echo str_replace(':n', (string) $bannerCount, e(__('shome_n_images'))); ?></a>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleGroupStatus(<?php echo $g['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $g['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $g['status'] ? e(__('admin_enabled')) : e(__('admin_disabled')); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openGroupModal(<?php echo json_encode($g, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-3 inline-flex items-center gap-1 align-middle">
                            <i class="ti ti-pencil text-sm"></i>
                            <?php echo __('admin_edit'); ?></button>
                        <button onclick="deleteGroup(<?php echo $g['id']; ?>)" title="<?php echo __('admin_delete'); ?>"
                                class="text-red-600 hover:text-red-700 inline-flex items-center align-middle">
                            <i class="ti ti-trash text-base"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($groups)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-500"><?php echo __('empty_no_groups'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 分组编辑弹窗 -->
<div id="groupModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeGroupModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" id="groupModalTitle"><?php echo __('banner_add_group'); ?></h3>
            <button onclick="closeGroupModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="groupForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="group_save">
            <input type="hidden" name="id" id="groupId" value="0">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_group_name')); ?> <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="groupName" class="w-full border rounded px-4 py-2" required placeholder="<?php echo e(__('bn_ph_group_name')); ?>">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_slug')); ?> (slug) <span class="text-red-500">*</span></label>
                <input type="text" name="slug" id="groupSlug" class="w-full border rounded px-4 py-2" required placeholder="<?php echo e(__('bn_ph_slug')); ?>" data-x="ge" pattern="[a-z0-9][a-z0-9-]*">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_slug_tip')); ?></p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('bn_pc_height')); ?> (px)</label>
                    <input type="number" name="height_pc" id="groupHeightPc" value="500" min="200" max="2000" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('bn_mobile_height')); ?> (px)</label>
                    <input type="number" name="height_mobile" id="groupHeightMobile" value="250" min="100" max="1000" class="w-full border rounded px-4 py-2">
                </div>
            </div>

            <div class="border rounded-lg p-3 bg-gray-50">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="fullscreen" value="1" id="groupFullscreen" class="w-4 h-4">
                    <span class="font-medium text-gray-700"><?php echo e(__('bn_fullscreen')); ?></span>
                </label>
                <p class="text-xs text-gray-400 mt-1 ml-7"><?php echo e(__('bn_fullscreen_tip')); ?></p>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_autoplay_delay')); ?> (ms)</label>
                <input type="number" name="autoplay_delay" id="groupAutoplay" value="5000" min="0" max="30000" step="500" class="w-full border rounded px-4 py-2">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_autoplay_tip')); ?></p>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeGroupModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __("btn_save"); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openGroupModal(item = null) {
    document.getElementById('groupModalTitle').textContent = item ? '<?php echo __("banner_edit_group"); ?>' : '<?php echo __("banner_add_group"); ?>';
    document.getElementById('groupId').value = item?.id || 0;
    document.getElementById('groupName').value = item?.name || '';
    document.getElementById('groupSlug').value = item?.slug || '';
    document.getElementById('groupHeightPc').value = item?.height_pc ?? 500;
    document.getElementById('groupHeightMobile').value = item?.height_mobile ?? 250;
    document.getElementById('groupAutoplay').value = item?.autoplay_delay ?? 5000;
    document.getElementById('groupFullscreen').checked = !!(item && Number(item.fullscreen));
    groupFsSync();
    document.getElementById('groupModal').classList.remove('hidden');
}

// 全屏开启时禁用「PC端高度」（PC 走满屏，此值不生效）
function groupFsSync() {
    var fs = document.getElementById('groupFullscreen');
    var pc = document.getElementById('groupHeightPc');
    if (!fs || !pc) return;
    pc.disabled = fs.checked;
    var wrap = pc.closest('div');
    if (wrap) wrap.style.opacity = fs.checked ? '0.45' : '1';
}
document.getElementById('groupFullscreen')?.addEventListener('change', groupFsSync);

function closeGroupModal() {
    document.getElementById('groupModal').classList.add('hidden');
}

document.getElementById('groupForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    try {
        const response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage('<?php echo __('admin_saved'); ?>');
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
});

async function toggleGroupStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'group_toggle');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = '<?php echo __('admin_enabled'); ?>';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '<?php echo __('admin_disabled'); ?>';
        }
    }
}

async function deleteGroup(id) {
    if (!confirm(<?php echo json_encode(__('bn_del_group_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    const formData = new FormData();
    formData.append('action', 'group_delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg || <?php echo json_encode(__('admin_delete_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

function copyShortcode(slug) {
    var text = '[banner-' + slug + ']';
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            showMessage(<?php echo json_encode(__('bn_shortcode_copied'), JSON_UNESCAPED_UNICODE); ?> + ': ' + text);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showMessage(<?php echo json_encode(__('bn_shortcode_copied'), JSON_UNESCAPED_UNICODE); ?> + ': ' + text);
    }
}
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
