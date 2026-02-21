<?php
/**
 * Yikai CMS - 图库相册管理（简化版）
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

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // 快速上传图片
    if ($action === 'quick_upload') {
        $albumId = postInt('album_id');

        if ($albumId <= 0) {
            error('请选择相册');
        }

        $album = albumModel()->find($albumId);
        if (!$album) {
            error('相册不存在');
        }

        if (empty($_FILES['files'])) {
            error('请选择图片');
        }

        $uploadDir = '/uploads/albums/' . date('Ym') . '/';
        $fullDir = ROOT_PATH . $uploadDir;
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0755, true);
        }

        $uploaded = 0;
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

                albumPhotoModel()->create([
                    'album_id' => $albumId,
                    'title' => pathinfo($files['name'][$i], PATHINFO_FILENAME),
                    'image' => $url,
                    'sort_order' => $maxSort + 1,
                    'status' => 1,
                    'created_at' => time(),
                ]);

                $uploaded++;
            }
        }

        // 更新相册图片数量
        albumModel()->updatePhotoCount($albumId);

        adminLog('album', 'quick_upload', '上传 ' . $uploaded . ' 张图片到相册ID：' . $albumId);
        success(['count' => $uploaded]);
    }

    if ($action === 'delete') {
        $id = postInt('id');

        $photos = albumPhotoModel()->getByAlbum($id);
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

        albumPhotoModel()->deleteByAlbum($id);
        albumModel()->deleteById($id);
        adminLog('album', 'delete', '删除相册ID：' . $id);
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = albumModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'update_sort') {
        $id = postInt('id');
        $sort = postInt('sort_order');
        albumModel()->updateById($id, ['sort_order' => $sort, 'updated_at' => time()]);
        success();
    }

    exit;
}

// 获取相册列表
$albums = albumModel()->all();

$pageTitle = '图库相册';
$currentMenu = 'album';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <div class="text-gray-600">
            共 <?php echo count($albums); ?> 个相册
        </div>
        <div class="flex gap-2">
            <button onclick="openUploadModal()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                上传图片
            </button>
            <a href="/admin/album_edit.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                新建相册
            </a>
        </div>
    </div>
</div>

<!-- 相册列表 -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
    <?php foreach ($albums as $item): ?>
    <div class="bg-white rounded-lg shadow overflow-hidden group">
        <!-- 封面 -->
        <a href="/admin/album_photos.php?id=<?php echo $item['id']; ?>" class="block aspect-[4/3] bg-gray-100 relative overflow-hidden">
            <?php if ($item['cover']): ?>
            <img src="<?php echo e($item['cover']); ?>" alt="<?php echo e($item['name']); ?>"
                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
            <?php else: ?>
            <div class="w-full h-full flex items-center justify-center text-gray-300">
                <svg class="w-16 h-16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
            </div>
            <?php endif; ?>

            <!-- 图片数量 -->
            <div class="absolute bottom-2 right-2 bg-black/60 text-white text-xs px-2 py-1 rounded">
                <?php echo (int)$item['photo_count']; ?> 张
            </div>

            <?php if (!$item['status']): ?>
            <div class="absolute top-2 left-2 bg-gray-500 text-white text-xs px-2 py-1 rounded">已隐藏</div>
            <?php endif; ?>
        </a>

        <!-- 信息 -->
        <div class="p-4">
            <h3 class="font-medium text-gray-900 mb-2"><?php echo e($item['name']); ?></h3>

            <!-- 操作 -->
            <div class="flex items-center justify-between pt-3 border-t">
                <a href="/admin/album_photos.php?id=<?php echo $item['id']; ?>"
                   class="text-primary hover:underline text-sm inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    管理图片
                </a>
                <div class="flex items-center gap-2">
                    <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                            class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                        <?php echo $item['status'] ? '显示' : '隐藏'; ?>
                    </button>
                    <a href="/admin/album_edit.php?id=<?php echo $item['id']; ?>" class="text-gray-500 hover:text-primary" title="编辑">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                    </a>
                    <button onclick="deleteAlbum(<?php echo $item['id']; ?>, '<?php echo e($item['name']); ?>')" class="text-gray-500 hover:text-red-600" title="删除">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($albums)): ?>
    <div class="col-span-full">
        <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
            </svg>
            <p class="mb-4">暂无相册</p>
            <a href="/admin/album_edit.php" class="inline-flex items-center gap-1 text-primary hover:underline">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                创建第一个相册
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 上传弹窗 -->
<div id="uploadModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="text-lg font-medium">批量上传图片</h3>
            <button onclick="closeUploadModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="p-6">
            <!-- 选择相册 -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">选择相册 <span class="text-red-500">*</span></label>
                <select id="uploadAlbumId" class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    <option value="">-- 请选择 --</option>
                    <?php foreach ($albums as $a): ?>
                    <option value="<?php echo $a['id']; ?>"><?php echo e($a['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 上传区域 -->
            <div id="quickUploadZone" class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center cursor-pointer hover:border-primary hover:bg-gray-50 transition">
                <input type="file" id="quickFileInput" multiple accept="image/*" class="hidden">
                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <p class="text-gray-600 mb-1">拖拽图片到此处，或 <span class="text-primary font-medium">点击选择</span></p>
                <p class="text-xs text-gray-400">支持 JPG、PNG、GIF、WEBP，可多选</p>
            </div>

            <!-- 上传进度 -->
            <div id="quickUploadProgress" class="hidden mt-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-600">正在上传...</span>
                    <span id="quickProgressText" class="text-sm text-gray-600">0%</span>
                </div>
                <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                    <div id="quickProgressBar" class="h-full bg-green-500 transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end rounded-b-lg">
            <button type="button" onclick="closeUploadModal()" class="px-4 py-2 border rounded hover:bg-gray-100">关闭</button>
        </div>
    </div>
</div>

<script>
const uploadModal = document.getElementById('uploadModal');
const quickUploadZone = document.getElementById('quickUploadZone');
const quickFileInput = document.getElementById('quickFileInput');

function openUploadModal() {
    uploadModal.classList.remove('hidden');
    uploadModal.classList.add('flex');
}

function closeUploadModal() {
    uploadModal.classList.add('hidden');
    uploadModal.classList.remove('flex');
}

quickUploadZone.addEventListener('click', () => quickFileInput.click());

quickUploadZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    quickUploadZone.classList.add('border-primary', 'bg-blue-50');
});
quickUploadZone.addEventListener('dragleave', () => {
    quickUploadZone.classList.remove('border-primary', 'bg-blue-50');
});
quickUploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    quickUploadZone.classList.remove('border-primary', 'bg-blue-50');
    if (e.dataTransfer.files.length) {
        quickUploadFiles(e.dataTransfer.files);
    }
});

quickFileInput.addEventListener('change', function() {
    if (this.files.length) {
        quickUploadFiles(this.files);
    }
});

async function quickUploadFiles(files) {
    const albumId = document.getElementById('uploadAlbumId').value;
    if (!albumId) {
        showMessage('请先选择相册', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'quick_upload');
    formData.append('album_id', albumId);
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }

    const progress = document.getElementById('quickUploadProgress');
    const progressBar = document.getElementById('quickProgressBar');
    const progressText = document.getElementById('quickProgressText');
    progress.classList.remove('hidden');

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
                setTimeout(() => location.reload(), 1500);
            } else {
                showMessage(result.msg || '上传失败', 'error');
            }
        }
    };

    xhr.open('POST', '');
    xhr.send(formData);
    quickFileInput.value = '';
}

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        btn.className = data.data.status
            ? 'text-xs px-2 py-1 rounded bg-green-100 text-green-600'
            : 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
        btn.textContent = data.data.status ? '显示' : '隐藏';
        showMessage('状态已更新');
    }
}

async function deleteAlbum(id, name) {
    if (!confirm(`确定要删除相册"${name}"吗？\n相册内的所有图片也将被删除！`)) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    }
}

uploadModal.addEventListener('click', function(e) {
    if (e.target === this) closeUploadModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeUploadModal();
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
