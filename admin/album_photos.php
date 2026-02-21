<?php
/**
 * Yikai CMS - 相册图片管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('media');

$albumId = getInt('id');
if ($albumId <= 0) {
    redirect('/admin/album.php');
}

$album = albumModel()->find($albumId);
if (!$album) {
    redirect('/admin/album.php');
}

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // 批量上传图片
    if ($action === 'upload') {
        if (empty($_FILES['files'])) {
            error('请选择图片');
        }

        $uploadDir = '/uploads/albums/' . date('Ym') . '/';
        $fullDir = ROOT_PATH . $uploadDir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $uploaded = [];
        $files = $_FILES['files'];

        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) continue;

            $newName = uniqid() . '.' . $ext;
            $filePath = $fullDir . $newName;

            if (move_uploaded_file($files['tmp_name'][$i], $filePath)) {
                $url = $uploadDir . $newName;

                $maxSort = albumPhotoModel()->getMaxSort($albumId);

                $photoId = albumPhotoModel()->create([
                    'album_id' => $albumId,
                    'title' => pathinfo($files['name'][$i], PATHINFO_FILENAME),
                    'image' => $url,
                    'sort_order' => $maxSort + 1,
                    'status' => 1,
                    'created_at' => time(),
                ]);

                $uploaded[] = [
                    'id' => $photoId,
                    'url' => $url,
                    'title' => pathinfo($files['name'][$i], PATHINFO_FILENAME),
                ];
            }
        }

        // 更新相册图片数量
        albumModel()->updatePhotoCount($albumId);

        success(['uploaded' => $uploaded, 'count' => count($uploaded)]);
    }

    // 更新图片信息
    if ($action === 'update') {
        $photoId = postInt('photo_id');
        $data = [
            'title' => post('title'),
            'description' => post('description'),
        ];
        albumPhotoModel()->updateWhere($data, 'id = ? AND album_id = ?', [$photoId, $albumId]);
        success();
    }

    // 删除图片
    if ($action === 'delete') {
        $photoId = postInt('photo_id');
        $photo = albumPhotoModel()->findWhere(['id' => $photoId, 'album_id' => $albumId]);

        if ($photo) {
            $uploadsReal = realpath(UPLOADS_PATH);
            if ($photo['image']) {
                $path = realpath(ROOT_PATH . $photo['image']);
                if ($path && str_starts_with($path, $uploadsReal) && file_exists($path)) @unlink($path);
            }
            if ($photo['thumb']) {
                $path = realpath(ROOT_PATH . $photo['thumb']);
                if ($path && str_starts_with($path, $uploadsReal) && file_exists($path)) @unlink($path);
            }

            albumPhotoModel()->deleteById($photoId);
            albumModel()->updatePhotoCount($albumId);
        }

        success();
    }

    // 批量删除
    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $photos = albumPhotoModel()->getByIdsAndAlbum($ids, $albumId);

            $uploadsReal = realpath(UPLOADS_PATH);
            foreach ($photos as $photo) {
                if ($photo['image']) {
                    $path = realpath(ROOT_PATH . $photo['image']);
                    if ($path && str_starts_with($path, $uploadsReal) && file_exists($path)) @unlink($path);
                }
                if ($photo['thumb']) {
                    $path = realpath(ROOT_PATH . $photo['thumb']);
                    if ($path && str_starts_with($path, $uploadsReal) && file_exists($path)) @unlink($path);
                }
            }

            albumPhotoModel()->deleteByIds($ids, $albumId);
            albumModel()->updatePhotoCount($albumId);
        }
        success();
    }

    // 更新排序
    if ($action === 'update_sort') {
        $orders = $_POST['orders'] ?? [];
        foreach ($orders as $photoId => $sortOrder) {
            albumPhotoModel()->updateSort((int)$photoId, $albumId, (int)$sortOrder);
        }
        success();
    }

    // 切换状态
    if ($action === 'toggle_status') {
        $photoId = postInt('photo_id');
        $photo = albumPhotoModel()->findWhere(['id' => $photoId, 'album_id' => $albumId]);
        $newStatus = $photo['status'] ? 0 : 1;
        albumPhotoModel()->updateById($photoId, ['status' => $newStatus]);
        success(['status' => $newStatus]);
    }

    // 设为封面
    if ($action === 'set_cover') {
        $photoId = postInt('photo_id');
        $photo = albumPhotoModel()->findWhere(['id' => $photoId, 'album_id' => $albumId]);
        if ($photo) {
            albumModel()->setCover($albumId, $photo['image']);
        }
        success();
    }

    exit;
}

// 获取图片列表
$photos = albumPhotoModel()->getByAlbum($albumId);

$pageTitle = '管理图片 - ' . $album['name'];
$currentMenu = 'album';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 面包屑 -->
<div class="mb-6">
    <div class="flex items-center gap-2 text-sm text-gray-500">
        <a href="/admin/album.php" class="hover:text-primary">相册管理</a>
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
        <span class="text-gray-900"><?php echo e($album['name']); ?></span>
    </div>
</div>

<!-- 上传区域 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-6">
        <div id="uploadZone" class="upload-zone">
            <input type="file" id="fileInput" multiple accept="image/*" class="hidden">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            <p class="text-gray-600 mb-1">拖拽图片到此处，或 <span class="text-primary">点击上传</span></p>
            <p class="text-xs text-gray-400">支持 JPG、PNG、GIF、WEBP 格式，可多选</p>
        </div>

        <!-- 上传进度 -->
        <div id="uploadProgress" class="hidden mt-4">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm text-gray-600">正在上传...</span>
                <span id="progressText" class="text-sm text-gray-600">0%</span>
            </div>
            <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                <div id="progressBar" class="h-full bg-primary transition-all duration-300" style="width: 0%"></div>
            </div>
        </div>
    </div>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="checkAll">
                <span class="text-sm text-gray-600">全选</span>
            </label>
            <button onclick="batchDelete()" class="text-sm text-red-600 hover:underline hidden" id="batchDeleteBtn">
                删除选中
            </button>
        </div>
        <div class="text-sm text-gray-500">
            共 <span id="photoCount"><?php echo count($photos); ?></span> 张图片
        </div>
    </div>
</div>

<!-- 图片列表 -->
<div class="bg-white rounded-lg shadow p-6">
    <div id="photoGrid" class="photo-grid">
        <?php foreach ($photos as $photo): ?>
        <div class="photo-item" data-id="<?php echo $photo['id']; ?>">
            <input type="checkbox" class="checkbox photo-checkbox" value="<?php echo $photo['id']; ?>">
            <img src="<?php echo e($photo['image']); ?>" alt="<?php echo e($photo['title']); ?>" loading="lazy">
            <div class="overlay"></div>
            <div class="actions">
                <button onclick="setCover(<?php echo $photo['id']; ?>)" class="w-8 h-8 bg-white/90 rounded-full flex items-center justify-center hover:bg-white" title="设为封面">
                    <svg class="w-4 h-4 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </button>
                <button onclick="editPhoto(<?php echo $photo['id']; ?>, '<?php echo e(addslashes($photo['title'])); ?>', '<?php echo e(addslashes($photo['description'] ?? '')); ?>')" class="w-8 h-8 bg-white/90 rounded-full flex items-center justify-center hover:bg-white" title="编辑">
                    <svg class="w-4 h-4 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                </button>
                <button onclick="deletePhoto(<?php echo $photo['id']; ?>)" class="w-8 h-8 bg-white/90 rounded-full flex items-center justify-center hover:bg-red-500 hover:text-white" title="删除">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                </button>
            </div>
            <?php if (!$photo['status']): ?>
            <div class="absolute top-2 right-2 bg-gray-500 text-white text-xs px-2 py-0.5 rounded">隐藏</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($photos)): ?>
    <div class="text-center py-12 text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <p>暂无图片，请上传</p>
    </div>
    <?php endif; ?>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="text-lg font-medium">编辑图片信息</h3>
            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <form id="editForm" onsubmit="savePhoto(event)">
            <input type="hidden" name="photo_id" id="editPhotoId">
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">图片标题</label>
                    <input type="text" name="title" id="editTitle"
                           class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">图片描述</label>
                    <textarea name="description" id="editDescription" rows="3"
                              class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary"></textarea>
                </div>
            </div>
            <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-2 rounded-b-lg">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 border rounded hover:bg-gray-100">取消</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded hover:bg-secondary">保存</button>
            </div>
        </form>
    </div>
</div>

<!-- Sortable.js -->
<script src="/assets/sortable/Sortable.min.js"></script>
<script>
const albumId = <?php echo $albumId; ?>;
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('fileInput');
const photoGrid = document.getElementById('photoGrid');

// 拖拽排序
if (photoGrid.children.length > 0) {
    new Sortable(photoGrid, {
        animation: 150,
        ghostClass: 'sortable-ghost',
        onEnd: function() {
            const orders = {};
            photoGrid.querySelectorAll('.photo-item').forEach((item, index) => {
                orders[item.dataset.id] = photoGrid.children.length - index;
            });

            const formData = new FormData();
            formData.append('action', 'update_sort');
            Object.entries(orders).forEach(([id, order]) => {
                formData.append(`orders[${id}]`, order);
            });
            fetch('', { method: 'POST', body: formData });
        }
    });
}

// 上传区域点击
uploadZone.addEventListener('click', () => fileInput.click());

// 拖拽上传
uploadZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadZone.classList.add('dragover');
});
uploadZone.addEventListener('dragleave', () => {
    uploadZone.classList.remove('dragover');
});
uploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadZone.classList.remove('dragover');
    if (e.dataTransfer.files.length) {
        uploadFiles(e.dataTransfer.files);
    }
});

// 文件选择
fileInput.addEventListener('change', function() {
    if (this.files.length) {
        uploadFiles(this.files);
    }
});

// 上传文件
async function uploadFiles(files) {
    const formData = new FormData();
    formData.append('action', 'upload');
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }

    const progress = document.getElementById('uploadProgress');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    progress.classList.remove('hidden');

    try {
        const xhr = new XMLHttpRequest();
        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                progressBar.style.width = pct + '%';
                progressText.textContent = pct + '%';
            }
        };

        xhr.onload = function() {
            progress.classList.add('hidden');
            progressBar.style.width = '0%';

            if (xhr.status === 200) {
                const result = JSON.parse(xhr.responseText);
                if (result.code === 0) {
                    showMessage(`成功上传 ${result.data.count} 张图片`);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage(result.msg || '上传失败', 'error');
                }
            }
        };

        xhr.open('POST', '');
        xhr.send(formData);
    } catch (e) {
        progress.classList.add('hidden');
        showMessage('上传失败', 'error');
    }

    fileInput.value = '';
}

// 全选
document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('.photo-checkbox').forEach(cb => cb.checked = this.checked);
    updateBatchBtn();
});

// 单选变化
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('photo-checkbox')) {
        updateBatchBtn();
    }
});

function updateBatchBtn() {
    const checked = document.querySelectorAll('.photo-checkbox:checked').length;
    document.getElementById('batchDeleteBtn').classList.toggle('hidden', checked === 0);
}

// 设为封面
async function setCover(photoId) {
    const formData = new FormData();
    formData.append('action', 'set_cover');
    formData.append('photo_id', photoId);
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);
    if (result.code === 0) {
        showMessage('已设为封面');
    }
}

// 编辑图片
function editPhoto(id, title, description) {
    document.getElementById('editPhotoId').value = id;
    document.getElementById('editTitle').value = title;
    document.getElementById('editDescription').value = description;
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editModal').classList.add('flex');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
    document.getElementById('editModal').classList.remove('flex');
}

async function savePhoto(e) {
    e.preventDefault();
    const formData = new FormData(document.getElementById('editForm'));
    formData.append('action', 'update');
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);
    if (result.code === 0) {
        showMessage('保存成功');
        closeEditModal();
    }
}

// 删除图片
async function deletePhoto(photoId) {
    if (!confirm('确定要删除这张图片吗？')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('photo_id', photoId);
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);
    if (result.code === 0) {
        document.querySelector(`.photo-item[data-id="${photoId}"]`).remove();
        document.getElementById('photoCount').textContent = document.querySelectorAll('.photo-item').length;
        showMessage('删除成功');
    }
}

// 批量删除
async function batchDelete() {
    const checked = document.querySelectorAll('.photo-checkbox:checked');
    if (checked.length === 0) return;
    if (!confirm(`确定要删除选中的 ${checked.length} 张图片吗？`)) return;

    const formData = new FormData();
    formData.append('action', 'batch_delete');
    checked.forEach(cb => formData.append('ids[]', cb.value));

    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);
    if (result.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    }
}

// ESC关闭弹窗
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeEditModal();
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
