<?php
/**
 * Yikai CMS - 发展历程管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('content');

// 图标选项
$iconOptions = [
    '' => '无图标',
    'flag' => '旗帜',
    'rocket' => '火箭',
    'award' => '奖杯',
    'users' => '团队',
    'box' => '产品',
    'trending-up' => '增长',
    'map' => '地图',
    'handshake' => '合作',
    'building' => '大楼',
    'star' => '星星',
    'heart' => '爱心',
    'zap' => '闪电',
    'target' => '目标',
    'globe' => '全球',
];

// 颜色选项
$colorOptions = [
    'primary' => '主色',
    'blue' => '蓝色',
    'green' => '绿色',
    'yellow' => '黄色',
    'red' => '红色',
    'purple' => '紫色',
    'cyan' => '青色',
    'indigo' => '靛蓝',
    'pink' => '粉色',
    'gray' => '灰色',
];

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'year' => postInt('year'),
            'month' => postInt('month'),
            'day' => postInt('day'),
            'title' => post('title'),
            'content' => post('content'),
            'image' => post('image'),
            'icon' => post('icon'),
            'color' => post('color', 'primary'),
            'sort_order' => postInt('sort_order'),
            'status' => postInt('status', 1),
            'updated_at' => time(),
        ];

        if (empty($data['title'])) {
            error('请输入标题');
        }

        if ($data['year'] < 1900 || $data['year'] > 2100) {
            error('请输入有效年份');
        }

        if ($id > 0) {
            timelineModel()->updateById($id, $data);
            adminLog('timeline', 'update', "更新时间线ID: $id");
        } else {
            $data['created_at'] = time();
            $id = timelineModel()->create($data);
            adminLog('timeline', 'create', "创建时间线ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        timelineModel()->deleteById($id);
        adminLog('timeline', 'delete', "删除时间线ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = timelineModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'sort') {
        $ids = $_POST['ids'] ?? [];
        timelineModel()->updateSort($ids);
        success();
    }

    exit;
}

// 获取列表
$timelines = timelineModel()->all();

$pageTitle = '发展历程';
$currentMenu = 'timeline';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-between items-center">
        <div class="text-gray-500 text-sm">
            拖拽排序 · 共 <?php echo count($timelines); ?> 条记录
        </div>
        <div class="flex gap-2">
            <a href="/history.php" target="_blank" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                预览
            </a>
            <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                添加事件
            </button>
        </div>
    </div>
</div>

<!-- 时间线列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-10">排序</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">时间</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">标题</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">内容摘要</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">颜色</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y" id="sortableList">
                <?php foreach ($timelines as $item): ?>
                <tr class="hover:bg-gray-50" data-id="<?php echo $item['id']; ?>">
                    <td class="px-4 py-3">
                        <span class="cursor-move text-gray-400 hover:text-gray-600">&#9776;</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="font-medium text-primary"><?php echo $item['year']; ?></span>
                        <?php if ($item['month'] > 0): ?>
                        <span class="text-gray-500">年<?php echo $item['month']; ?>月</span>
                        <?php else: ?>
                        <span class="text-gray-500">年</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <?php if ($item['image']): ?>
                            <img src="<?php echo e($item['image']); ?>" class="w-10 h-10 object-cover rounded">
                            <?php endif; ?>
                            <span class="font-medium"><?php echo e($item['title']); ?></span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-sm max-w-xs truncate">
                        <?php echo e(cutStr($item['content'] ?? '', 50)); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $colorClass = match($item['color']) {
                            'blue' => 'bg-blue-500',
                            'green' => 'bg-green-500',
                            'yellow' => 'bg-yellow-500',
                            'red' => 'bg-red-500',
                            'purple' => 'bg-purple-500',
                            'cyan' => 'bg-cyan-500',
                            'indigo' => 'bg-indigo-500',
                            'pink' => 'bg-pink-500',
                            'gray' => 'bg-gray-500',
                            default => 'bg-primary',
                        };
                        ?>
                        <span class="inline-block w-4 h-4 rounded-full <?php echo $colorClass; ?>"></span>
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
                        <button onclick="deleteItem(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($timelines)): ?>
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-gray-500">暂无数据，点击"添加事件"开始创建</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-bold text-gray-800" id="modalTitle">添加事件</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <!-- 时间 -->
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">年份 <span class="text-red-500">*</span></label>
                    <input type="number" name="year" id="editYear" required min="1900" max="2100"
                           value="<?php echo date('Y'); ?>" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">月份</label>
                    <select name="month" id="editMonth" class="w-full border rounded px-4 py-2">
                        <option value="0">不显示</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?>月</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">日期</label>
                    <select name="day" id="editDay" class="w-full border rounded px-4 py-2">
                        <option value="0">不显示</option>
                        <?php for ($i = 1; $i <= 31; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?>日</option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- 标题 -->
            <div>
                <label class="block text-gray-700 mb-1">标题 <span class="text-red-500">*</span></label>
                <input type="text" name="title" id="editTitle" required class="w-full border rounded px-4 py-2"
                       placeholder="例如：公司成立、获得融资、产品发布">
            </div>

            <!-- 内容 -->
            <div>
                <label class="block text-gray-700 mb-1">内容描述</label>
                <textarea name="content" id="editContent" rows="3" class="w-full border rounded px-4 py-2"
                          placeholder="详细描述这个里程碑事件..."></textarea>
            </div>

            <!-- 图片 -->
            <div>
                <label class="block text-gray-700 mb-1">配图</label>
                <div class="flex gap-2">
                    <input type="text" name="image" id="editImage" class="flex-1 border rounded px-4 py-2" placeholder="图片URL">
                    <button type="button" onclick="uploadImage()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                        选择
                    </button>
                    <button type="button" onclick="pickImageFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">媒体库</button>
                </div>
                <div id="imagePreview" class="mt-2"></div>
            </div>

            <!-- 样式 -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">图标</label>
                    <select name="icon" id="editIcon" class="w-full border rounded px-4 py-2">
                        <?php foreach ($iconOptions as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">颜色</label>
                    <select name="color" id="editColor" class="w-full border rounded px-4 py-2">
                        <?php foreach ($colorOptions as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- 排序和状态 -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">排序</label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                    <p class="text-xs text-gray-400 mt-1">数字越大越靠前</p>
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
                    保存
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
        showMessage('排序已保存');
    }
});

function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? '编辑事件' : '添加事件';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editYear').value = item?.year || new Date().getFullYear();
    document.getElementById('editMonth').value = item?.month || 0;
    document.getElementById('editDay').value = item?.day || 0;
    document.getElementById('editTitle').value = item?.title || '';
    document.getElementById('editContent').value = item?.content || '';
    document.getElementById('editImage').value = item?.image || '';
    document.getElementById('editIcon').value = item?.icon || '';
    document.getElementById('editColor').value = item?.color || 'primary';
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
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('保存成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
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

async function deleteItem(id) {
    if (!confirm('确定要删除这条记录吗？')) return;
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

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
