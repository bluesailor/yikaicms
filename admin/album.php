<?php
/**
 * YikaiCMS - 图库相册管理（简化版）
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

// ============== 多语言视图 ==============
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];

// 检测 albums 表是否有 lang 列（向后兼容未跑 20260512_album_i18n migration 的库）
$_albumsHasLang = (function (): bool {
    if (db()->isSqlite()) {
        foreach (db()->fetchAll("PRAGMA table_info('" . DB_PREFIX . "albums')") as $c) {
            if ($c['name'] === 'lang') return true;
        }
        return false;
    }
    return !empty(db()->fetchAll("SHOW COLUMNS FROM `" . DB_PREFIX . "albums` LIKE 'lang'"));
})();

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // 快速上传图片
    if ($action === 'quick_upload') {
        $albumId = postInt('album_id');

        if ($albumId <= 0) {
            error(__('album_pick'));
        }

        $album = albumModel()->find($albumId);
        if (!$album) {
            error(__('album_missing'));
        }

        if (empty($_FILES['files'])) {
            error(__('album_pick_images'));
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

// 获取相册列表（按 view-lang 过滤；albums 表没 lang 列时列出全部）
if ($_albumsHasLang) {
    $albums = db()->fetchAll(
        "SELECT * FROM " . DB_PREFIX . "albums WHERE lang = ? ORDER BY sort_order ASC, id ASC",
        [$_viewLang]
    );
} else {
    $albums = albumModel()->all();
}

// 翻译徽标索引（仅当 albums 有 lang 列时才查；下方 in_array 已加 albums）
$transStatus = $_albumsHasLang ? loadTransStatus('albums') : [];

$pageTitle = __('admin_album');
$currentMenu = 'album';

require_once ROOT_PATH . '/admin/includes/header.php';

echo renderAdminLangSwitcher($_viewLang, str_replace(':lang', $_defaultLang, __('album_lang_tip')));
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <div class="text-gray-600">
            <?php echo str_replace([':n', ':lang'], [(string) count($albums), e($_viewLang)], e(__('album_total'))); ?>
        </div>
        <div class="flex gap-2">
            <button onclick="openUploadModal()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-upload text-base"></i>
                <?php echo __('admin_upload_image'); ?>
            </button>
            <?php if ($_lang['isSource']): ?>
            <a href="/admin/album_edit.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-plus text-base"></i>
                <?php echo __('admin_add'); ?>
            </a>
            <?php else: ?>
            <span class="text-xs text-gray-400"><?php echo e(__('album_source_only')); ?></span>
            <?php endif; ?>
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
                <i class="ti ti-photo text-base"></i>
            </div>
            <?php endif; ?>

            <!-- 图片数量 -->
            <div class="absolute bottom-2 right-2 bg-black/60 text-white text-xs px-2 py-1 rounded">
                <?php echo str_replace(':n', (string) (int) $item['photo_count'], e(__('shome_n_images'))); ?>
            </div>

            <?php if (!$item['status']): ?>
            <div class="absolute top-2 left-2 bg-gray-500 text-white text-xs px-2 py-1 rounded"><?php echo e(__('album_hidden')); ?></div>
            <?php endif; ?>
        </a>

        <!-- 信息 -->
        <div class="p-4">
            <h3 class="font-medium text-gray-900 mb-2"><?php echo e($item['name']); ?></h3>

            <!-- 页面调用短码 -->
            <div class="flex items-center gap-1 mb-2">
                <code class="text-xs bg-gray-100 text-gray-700 px-2 py-1 rounded select-all font-mono">[album-<?php echo (int)$item['id']; ?>]</code>
                <button type="button" onclick="ykCopyShortcode(this,'[album-<?php echo (int)$item['id']; ?>]')"
                        class="text-xs text-gray-400 hover:text-primary p-1" title="<?php echo e(__('album_copy_sc_tip')); ?>">
                    <i class="ti ti-copy text-base"></i>
                </button>
            </div>

            <?php if ($_lang['isSource'] && $_albumsHasLang): ?>
            <!-- 翻译徽标（仅源语言视图显示） -->
            <div class="mb-2"><?php echo renderTransPills((int)$item['id'], $transStatus, '/admin/album_edit.php'); ?></div>
            <?php endif; ?>

            <!-- 操作 -->
            <div class="flex items-center justify-between pt-3 border-t">
                <a href="/admin/album_photos.php?id=<?php echo $item['id']; ?>"
                   class="text-primary hover:underline text-sm inline-flex items-center gap-1">
                    <i class="ti ti-photo text-base"></i>
                    <?php echo __('admin_manage'); ?>
                </a>
                <div class="flex items-center gap-2">
                    <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                            class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                        <?php echo $item['status'] ? __('admin_show') : __('admin_hide'); ?>
                    </button>
                    <a href="/admin/album_edit.php?id=<?php echo $item['id']; ?>" class="text-gray-500 hover:text-primary" title="<?php echo __('admin_edit'); ?>">
                        <i class="ti ti-pencil text-base"></i>
                    </a>
                    <?php if ($_lang['isSource']): ?>
                    <button onclick="deleteAlbum(<?php echo $item['id']; ?>, '<?php echo e($item['name']); ?>')" class="text-gray-500 hover:text-red-600" title="<?php echo __('admin_delete'); ?>">
                        <i class="ti ti-trash text-base"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($albums)): ?>
    <div class="col-span-full">
        <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
            <i class="ti ti-photo text-base mx-auto mb-4 text-gray-300"></i>
            <p class="mb-4"><?php echo e(__('album_empty')); ?></p>
            <a href="/admin/album_edit.php" class="inline-flex items-center gap-1 text-primary hover:underline">
                <i class="ti ti-plus text-base"></i>
                <?php echo e(__('album_create_first')); ?>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 上传弹窗 -->
<div id="uploadModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="text-lg font-medium"><?php echo e(__('album_batch_upload')); ?></h3>
            <button onclick="closeUploadModal()" class="text-gray-400 hover:text-gray-600">
                <i class="ti ti-x text-lg"></i>
            </button>
        </div>
        <div class="p-6">
            <!-- 选择相册 -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('album_select')); ?> <span class="text-red-500">*</span></label>
                <select id="uploadAlbumId" class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    <option value="">-- <?php echo e(__('admin_please_select')); ?> --</option>
                    <?php foreach ($albums as $a): ?>
                    <option value="<?php echo $a['id']; ?>"><?php echo e($a['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 上传区域 -->
            <div id="quickUploadZone" class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center cursor-pointer hover:border-primary hover:bg-gray-50 transition">
                <input type="file" id="quickFileInput" multiple accept="image/*" class="hidden">
                <i class="ti ti-photo text-base mx-auto mb-3 text-gray-300"></i>
                <p class="text-gray-600 mb-1"><?php echo e(__('album_drop_hint')); ?> <span class="text-primary font-medium"><?php echo e(__('album_click_select')); ?></span></p>
                <p class="text-xs text-gray-400"><?php echo e(__('album_formats')); ?></p>
            </div>

            <!-- 上传进度 -->
            <div id="quickUploadProgress" class="hidden mt-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-600"><?php echo e(__('album_uploading')); ?></span>
                    <span id="quickProgressText" class="text-sm text-gray-600">0%</span>
                </div>
                <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                    <div id="quickProgressBar" class="h-full bg-green-500 transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end rounded-b-lg">
            <button type="button" onclick="closeUploadModal()" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo e(__('blox_template_close')); ?></button>
        </div>
    </div>
</div>

<script>
// 复制相册短码到剪贴板
function ykCopyShortcode(btn, code) {
    const done = () => { showMessage(<?php echo json_encode(__('album_sc_copied'), JSON_UNESCAPED_UNICODE); ?>.replace(':code', code)); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(done).catch(() => fallbackCopy(code, done));
    } else {
        fallbackCopy(code, done);
    }
}
function fallbackCopy(text, cb) {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); cb(); } catch (e) {}
    document.body.removeChild(ta);
}

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
        showMessage(<?php echo json_encode(__('album_pick'), JSON_UNESCAPED_UNICODE); ?>, 'error');
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
                showMessage(<?php echo json_encode(__('album_upload_done'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', result.data.count));
                setTimeout(() => location.reload(), 1500);
            } else {
                showMessage(result.msg || <?php echo json_encode(__('admin_upload_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
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
        btn.textContent = data.data.status ? <?php echo json_encode(__('admin_show'), JSON_UNESCAPED_UNICODE); ?> : <?php echo json_encode(__('admin_hide'), JSON_UNESCAPED_UNICODE); ?>;
        showMessage(<?php echo json_encode(__('album_status_updated'), JSON_UNESCAPED_UNICODE); ?>);
    }
}

async function deleteAlbum(id, name) {
    if (!confirm(<?php echo json_encode(__('album_del_confirm'), JSON_UNESCAPED_UNICODE); ?>.replace(':name', name))) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
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
