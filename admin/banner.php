<?php
/**
 * Yikai CMS - 轮播图管理
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
            error('分组名称和标识不能为空');
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            error('标识只能包含小写字母、数字和连字符，且以字母或数字开头');
        }

        if (!bannerGroupModel()->isSlugUnique($slug, $id)) {
            error('标识已存在');
        }

        $data = [
            'name'           => $name,
            'slug'           => $slug,
            'height_pc'      => max(200, min(2000, postInt('height_pc', 500))),
            'height_mobile'  => max(100, min(1000, postInt('height_mobile', 250))),
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
        if (!$group) error('分组不存在');

        $count = bannerGroupModel()->getBannerCount($group['slug']);
        if ($count > 0) {
            error("该分组下还有 {$count} 张轮播图，请先移除或移到其他分组");
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
    $positions = ['home' => '首页', 'about' => '关于我们', 'product' => '产品中心', 'case' => '案例展示'];
}

// 轮播图列表数据（list Tab 用）
$position = get('position', '');
$page = max(1, getInt('page', 1));
$perPage = 20;

$conditions = [];
if ($position) {
    $conditions['position'] = $position;
}

$result = bannerModel()->paginate($page, $perPage, $conditions, 'sort_order ASC, id DESC');
$total = $result['total'];
$banners = $result['items'];

// 全局设置
$bannerHeightPc = (int)config('banner_height_pc', 650);
$bannerHeightMobile = (int)config('banner_height_mobile', 300);

$pageTitle = '轮播图管理';
$currentMenu = 'banner';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/banner.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'list' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            轮播图列表
        </a>
        <a href="/admin/banner.php?tab=groups" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'groups' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            分组管理
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
                <option value="">全部分组</option>
                <?php foreach ($positions as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $position === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                筛选
            </button>
        </form>

        <div class="flex gap-2">
            <button onclick="openSettingsModal()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                轮播图设置
            </button>
            <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                添加轮播图
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
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">排序</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">图片</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">标题</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">分组</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
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
                        <div class="font-medium"><?php echo e($item['title'] ?: '无标题'); ?></div>
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
                            <?php echo $item['status'] ? '显示' : '隐藏'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            编辑</button>
                        <button onclick="deleteBanner(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($banners)): ?>
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-500">暂无数据</td>
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
            <h3 class="font-bold text-gray-800" id="modalTitle">添加轮播图</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1">标题</label>
                <input type="text" name="title" id="editTitle" class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">副标题</label>
                <input type="text" name="subtitle" id="editSubtitle" class="w-full border rounded px-4 py-2">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">按钮1文字</label>
                    <input type="text" name="btn1_text" id="editBtn1Text" class="w-full border rounded px-4 py-2" placeholder="如：了解更多">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">按钮1链接</label>
                    <input type="text" name="btn1_url" id="editBtn1Url" class="w-full border rounded px-4 py-2" placeholder="/about.html">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">按钮2文字</label>
                    <input type="text" name="btn2_text" id="editBtn2Text" class="w-full border rounded px-4 py-2" placeholder="如：联系我们">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">按钮2链接</label>
                    <input type="text" name="btn2_url" id="editBtn2Url" class="w-full border rounded px-4 py-2" placeholder="/contact.html">
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">图片</label>
                <div class="flex gap-2">
                    <input type="text" name="image" id="editImage" class="flex-1 border rounded px-4 py-2">
                    <button type="button" onclick="uploadImage()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded"><?php echo __('admin_choose_file'); ?></button>
                    <button type="button" onclick="pickImageFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">媒体库</button>
                </div>
                <div id="imagePreview" class="mt-2"></div>
                <p class="text-xs text-gray-400 mt-1">建议尺寸：1920 x <?php echo $bannerHeightPc; ?>px</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">链接地址</label>
                    <input type="text" name="link_url" id="editLinkUrl" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">打开方式</label>
                    <select name="link_target" id="editLinkTarget" class="w-full border rounded px-4 py-2">
                        <option value="_self">当前窗口</option>
                        <option value="_blank">新窗口</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">分组</label>
                    <select name="position" id="editPosition" class="w-full border rounded px-4 py-2">
                        <?php foreach ($positions as $k => $v): ?>
                        <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">排序</label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">状态</label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1">显示</option>
                        <option value="0">隐藏</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100">取消</button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存</button>
            </div>
        </form>
    </div>
</div>

<!-- 设置弹窗 -->
<div id="settingsModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeSettingsModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800">轮播图设置</h3>
            <button onclick="closeSettingsModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="settingsForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save_settings">

            <div>
                <label class="block text-gray-700 mb-1">PC端高度 (px)</label>
                <input type="number" name="banner_height_pc" id="settingHeightPc" value="<?php echo $bannerHeightPc; ?>" min="200" max="1000" class="w-full border rounded px-4 py-2">
                <p class="text-xs text-gray-400 mt-1">建议范围：400-800px，当前推荐：650px</p>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">移动端高度 (px)</label>
                <input type="number" name="banner_height_mobile" id="settingHeightMobile" value="<?php echo $bannerHeightMobile; ?>" min="150" max="600" class="w-full border rounded px-4 py-2">
                <p class="text-xs text-gray-400 mt-1">建议范围：200-400px，当前推荐：300px</p>
            </div>

            <div class="bg-blue-50 text-blue-700 p-3 rounded text-sm">
                <strong>提示：</strong>此设置为首页轮播图全局高度，其他分组可在分组管理中单独设置。
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeSettingsModal()" class="border px-4 py-2 rounded hover:bg-gray-100">取消</button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存设置
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
    document.getElementById('modalTitle').textContent = item ? '编辑轮播图' : '添加轮播图';
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
            showMessage('保存成功');
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg || '保存失败', 'error');
        }
    } catch (err) {
        showMessage('请求失败: ' + err.message, 'error');
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
            btn.textContent = '显示';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '隐藏';
        }
    }
}

async function deleteBanner(id) {
    if (!confirm('确定要删除吗？')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('删除成功');
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
        showMessage('设置保存成功');
        closeSettingsModal();
    } else {
        showMessage(data.msg || '保存失败', 'error');
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
            showMessage('上传成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败', 'error');
    }

    this.value = '';
});
</script>
<?php endif; ?>

<?php if ($tab === 'groups'): ?>
<!-- ========== 分组管理 ========== -->

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-between items-center">
        <p class="text-sm text-gray-500">管理轮播图分组，每个分组可生成短码嵌入到页面内容中。</p>
        <button onclick="openGroupModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            添加分组
        </button>
    </div>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">名称</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">标识</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">短码</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">尺寸 (PC/移动)</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">自动播放</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">轮播图</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($groups as $g):
                    $bannerCount = bannerGroupModel()->getBannerCount($g['slug']);
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium"><?php echo e($g['name']); ?></td>
                    <td class="px-4 py-3">
                        <code class="text-sm bg-gray-100 px-1.5 py-0.5 rounded"><?php echo e($g['slug']); ?></code>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <code class="text-sm bg-blue-50 text-blue-700 px-2 py-1 rounded">[banner-<?php echo e($g['slug']); ?>]</code>
                            <button onclick="copyShortcode('<?php echo e($g['slug']); ?>')" class="text-gray-400 hover:text-primary" title="复制短码">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                            </button>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600">
                        <?php echo $g['height_pc']; ?> / <?php echo $g['height_mobile']; ?>px
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600">
                        <?php echo $g['autoplay_delay'] > 0 ? ($g['autoplay_delay'] / 1000) . 's' : '关闭'; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="/admin/banner.php?position=<?php echo e($g['slug']); ?>" class="text-primary hover:underline text-sm"><?php echo $bannerCount; ?> 张</a>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleGroupStatus(<?php echo $g['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $g['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $g['status'] ? '启用' : '禁用'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openGroupModal(<?php echo json_encode($g, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2">编辑</button>
                        <button onclick="deleteGroup(<?php echo $g['id']; ?>)"
                                class="text-red-600 hover:underline text-sm">删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($groups)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">暂无分组</td>
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
            <h3 class="font-bold text-gray-800" id="groupModalTitle">添加分组</h3>
            <button onclick="closeGroupModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="groupForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="group_save">
            <input type="hidden" name="id" id="groupId" value="0">

            <div>
                <label class="block text-gray-700 mb-1">分组名称 <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="groupName" class="w-full border rounded px-4 py-2" required placeholder="如：产品页轮播">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">标识 (slug) <span class="text-red-500">*</span></label>
                <input type="text" name="slug" id="groupSlug" class="w-full border rounded px-4 py-2" required placeholder="如：product-page" pattern="[a-z0-9][a-z0-9-]*">
                <p class="text-xs text-gray-400 mt-1">小写字母、数字和连字符，用于生成短码 [banner-slug]</p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">PC端高度 (px)</label>
                    <input type="number" name="height_pc" id="groupHeightPc" value="500" min="200" max="2000" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">移动端高度 (px)</label>
                    <input type="number" name="height_mobile" id="groupHeightMobile" value="250" min="100" max="1000" class="w-full border rounded px-4 py-2">
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">自动播放间隔 (ms)</label>
                <input type="number" name="autoplay_delay" id="groupAutoplay" value="5000" min="0" max="30000" step="500" class="w-full border rounded px-4 py-2">
                <p class="text-xs text-gray-400 mt-1">设为 0 则关闭自动播放，推荐 3000-5000ms</p>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeGroupModal()" class="border px-4 py-2 rounded hover:bg-gray-100">取消</button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openGroupModal(item = null) {
    document.getElementById('groupModalTitle').textContent = item ? '编辑分组' : '添加分组';
    document.getElementById('groupId').value = item?.id || 0;
    document.getElementById('groupName').value = item?.name || '';
    document.getElementById('groupSlug').value = item?.slug || '';
    document.getElementById('groupHeightPc').value = item?.height_pc ?? 500;
    document.getElementById('groupHeightMobile').value = item?.height_mobile ?? 250;
    document.getElementById('groupAutoplay').value = item?.autoplay_delay ?? 5000;
    document.getElementById('groupModal').classList.remove('hidden');
}

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
            showMessage('保存成功');
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg || '保存失败', 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
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
            btn.textContent = '启用';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '禁用';
        }
    }
}

async function deleteGroup(id) {
    if (!confirm('确定要删除此分组吗？')) return;
    const formData = new FormData();
    formData.append('action', 'group_delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg || '删除失败', 'error');
    }
}

function copyShortcode(slug) {
    var text = '[banner-' + slug + ']';
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            showMessage('短码已复制: ' + text);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showMessage('短码已复制: ' + text);
    }
}
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
