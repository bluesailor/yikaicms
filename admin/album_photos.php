<?php
/**
 * YikaiCMS - 相册图片管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/admin/includes/album_upload.php';

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
            error(__('ap_pick_images'));
        }

        ['uploaded' => $uploaded, 'rejected' => $rejected] = albumStoreUploadedPhotos($albumId, $_FILES['files']);
        if ($uploaded === [] && $rejected !== []) {
            error($rejected[0]['name'] . '：' . $rejected[0]['error']);
        }

        // 更新相册图片数量
        albumModel()->updatePhotoCount($albumId);

        // 如果相册没有封面，自动设第一张为封面
        $currentAlbum = albumModel()->find($albumId);
        if ($currentAlbum && empty($currentAlbum['cover']) && !empty($uploaded)) {
            albumModel()->updateById($albumId, ['cover' => $uploaded[0]['url']]);
        }

        success(['uploaded' => $uploaded, 'count' => count($uploaded), 'rejected' => $rejected]);
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

    // 替换图片：保留标题、描述、排序与显示状态，只换图片文件
    if ($action === 'replace') {
        $photoId = postInt('photo_id');
        $photo = albumPhotoModel()->findWhere(['id' => $photoId, 'album_id' => $albumId]);
        if (!$photo) {
            error(__('ap_photo_missing'));
        }
        // 与 WordPress 一致：从媒体库选图（弹窗内可上传新图），不接受任意地址
        $media = albumMediaLibraryImage((string) post('image_url'));
        if ($media === null) {
            error(__('ap_pick_from_library'));
        }
        $newImage = (string) $media['url'];
        $oldImage = (string) ($photo['image'] ?? '');
        $oldThumb = (string) ($photo['thumb'] ?? '');
        albumPhotoModel()->updateById($photoId, ['image' => $newImage, 'thumb' => '']);
        if ($oldImage !== '' && (string) ($album['cover'] ?? '') === $oldImage) {
            albumModel()->setCover($albumId, $newImage);
        }
        albumRemoveUnusedPhotoFiles([$oldImage, $oldThumb]);
        success(['url' => $newImage]);
    }

    // 删除图片
    if ($action === 'delete') {
        $photoId = postInt('photo_id');
        $photo = albumPhotoModel()->findWhere(['id' => $photoId, 'album_id' => $albumId]);

        if ($photo) {
            albumPhotoModel()->deleteById($photoId);
            albumModel()->updatePhotoCount($albumId);
            // 先删记录再清文件：媒体库或其它相册仍在用的文件保留
            albumRemoveUnusedPhotoFiles([(string) $photo['image'], (string) $photo['thumb']]);
        }

        success();
    }

    // 批量删除
    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $photos = albumPhotoModel()->getByIdsAndAlbum($ids, $albumId);
            albumPhotoModel()->deleteByIds($ids, $albumId);
            albumModel()->updatePhotoCount($albumId);
            albumRemoveUnusedPhotoFiles(array_merge(array_column($photos, 'image'), array_column($photos, 'thumb')));
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

$pageTitle = __('ap_title') . ' - ' . $album['name'];
$currentMenu = 'album';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php /* 面包屑 */ ?>
<div class="mb-6">
    <div class="flex items-center gap-2 text-sm text-gray-500">
        <a href="/admin/album.php" class="hover:text-primary"><?php echo e(__('admin_album')); ?></a>
        <i class="ti ti-chevron-right text-base"></i>
        <span class="text-gray-900"><?php echo e($album['name']); ?></span>
    </div>
</div>

<?php /* 页面调用短码 + 展示模式 */ ?>
<div class="bg-blue-50 border border-blue-100 rounded-lg p-4 mb-6 flex flex-wrap items-center gap-x-6 gap-y-2">
    <div class="flex items-center gap-2">
        <span class="text-sm text-gray-600"><?php echo e(__('ap_shortcode')); ?></span>
        <code class="text-sm bg-white border text-primary px-2 py-1 rounded select-all font-mono">[album-<?php echo (int)$album['id']; ?>]</code>
        <button type="button" onclick="ykCopyShortcode(this,'[album-<?php echo (int)$album['id']; ?>]')"
                class="text-gray-400 hover:text-primary p-1" title="<?php echo e(__('ap_copy_shortcode_tip')); ?>">
            <i class="ti ti-copy text-base"></i>
        </button>
    </div>
    <div class="flex items-center gap-2 text-sm text-gray-600">
        <span><?php echo e(__('ap_layout')); ?></span>
        <span class="font-medium text-gray-800"><?php echo ($album['layout'] ?? 'grid') === 'masonry' ? __('ap_layout_masonry') : __('ap_layout_grid'); ?></span>
        <a href="/admin/album_edit.php?id=<?php echo (int)$album['id']; ?>" class="text-primary hover:underline"><?php echo e(__('admin_edit')); ?></a>
    </div>
    <p class="text-xs text-gray-400 w-full"><?php echo e(__('ap_shortcode_tip')); ?></p>
</div>
<script>
function ykCopyShortcode(btn, code) {
    const done = () => { if (window.showMessage) showMessage(<?php echo json_encode(__('ap_shortcode_copied'), JSON_UNESCAPED_UNICODE); ?>.replace(':code', code)); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(done).catch(() => ykFallbackCopy(code, done));
    } else { ykFallbackCopy(code, done); }
}
function ykFallbackCopy(text, cb) {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); cb(); } catch (e) {}
    document.body.removeChild(ta);
}
</script>

<?php /* 上传区域 */ ?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-6">
        <div id="uploadZone" class="upload-zone">
            <input type="file" id="fileInput" multiple accept="image/*" class="hidden">
            <i class="ti ti-photo text-base mx-auto mb-3 text-gray-300"></i>
            <p class="text-gray-600 mb-1"><?php echo str_replace(':click', '<span class="text-primary">' . e(__('ap_click_upload')) . '</span>', e(__('ap_drop_hint'))); ?></p>
            <p class="text-xs text-gray-400"><?php echo e(__('ap_format_hint')); ?></p>
        </div>

        <?php /* 上传进度 */ ?>
        <div id="uploadProgress" class="hidden mt-4">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm text-gray-600"><?php echo e(__('ap_uploading')); ?></span>
                <span id="progressText" class="text-sm text-gray-600">0%</span>
            </div>
            <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                <div id="progressBar" class="h-full bg-primary transition-all duration-300" style="width: 0%"></div>
            </div>
        </div>
    </div>
</div>

<?php /* 工具栏 */ ?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="checkAll">
                <span class="text-sm text-gray-600"><?php echo e(__('admin_select_all')); ?></span>
            </label>
            <button onclick="batchDelete()" class="text-sm text-red-600 hover:underline hidden" id="batchDeleteBtn">
                <?php echo e(__('admin_batch_delete')); ?>
            </button>
        </div>
        <div class="text-sm text-gray-500">
            <?php echo str_replace(':n', '<span id="photoCount">' . count($photos) . '</span>', e(__('ap_n_photos'))); ?>
        </div>
    </div>
</div>

<?php /* 图片列表 */ ?>
<div class="bg-white rounded-lg shadow p-6">
    <div id="photoGrid" class="photo-grid">
        <?php foreach ($photos as $photo): ?>
        <?php $isCover = (string) ($album['cover'] ?? '') !== '' && (string) $album['cover'] === (string) $photo['image']; ?>
        <div class="photo-item" data-id="<?php echo $photo['id']; ?>"<?php echo $isCover ? ' data-cover="1"' : ''; ?>>
            <input type="checkbox" class="checkbox photo-checkbox" value="<?php echo $photo['id']; ?>">
            <img src="<?php echo e($photo['image']); ?>" alt="<?php echo e($photo['title']); ?>" loading="lazy">
            <?php // 右上角状态角标：封面（星标＝封面/特色图）与已隐藏，避开底部操作按钮 ?>
            <div class="photo-badges">
                <span class="photo-badge photo-badge-cover"<?php echo $isCover ? '' : ' hidden'; ?>>
                    <i class="ti ti-star" aria-hidden="true"></i><?php echo e(__('ap_cover_badge')); ?>
                </span>
                <?php if (!$photo['status']): ?>
                <span class="photo-badge"><?php echo e(__('admin_hide')); ?></span>
                <?php endif; ?>
            </div>
            <div class="overlay"></div>
            <div class="actions">
                <button onclick="setCover(<?php echo $photo['id']; ?>)" data-testid="album-photo-set-cover" class="cover-action w-8 h-8 bg-white/90 text-amber-500 rounded-full flex items-center justify-center hover:bg-white" title="<?php echo e(__('ap_set_cover')); ?>" aria-label="<?php echo e(__('ap_set_cover')); ?>">
                    <i class="ti ti-star text-base"></i>
                </button>
                <button onclick="editPhoto(<?php echo $photo['id']; ?>, '<?php echo e(addslashes($photo['title'])); ?>', '<?php echo e(addslashes($photo['description'] ?? '')); ?>')" class="w-8 h-8 bg-white/90 rounded-full flex items-center justify-center hover:bg-white" title="<?php echo __('admin_edit'); ?>">
                    <i class="ti ti-pencil text-base text-gray-700"></i>
                </button>
                <button onclick="deletePhoto(<?php echo $photo['id']; ?>)" class="w-8 h-8 bg-white/90 rounded-full flex items-center justify-center hover:bg-red-500 hover:text-white" title="<?php echo __('admin_delete'); ?>">
                    <i class="ti ti-trash text-base"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($photos)): ?>
    <div class="text-center py-12 text-gray-500">
        <i class="ti ti-photo text-base mx-auto mb-4 text-gray-300"></i>
        <p><?php echo e(__('ap_empty')); ?></p>
    </div>
    <?php endif; ?>
</div>

<?php /* 编辑弹窗 */ ?>
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="text-lg font-medium"><?php echo e(__('ap_edit_photo')); ?></h3>
            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                <i class="ti ti-x text-lg"></i>
            </button>
        </div>
        <form id="editForm" onsubmit="savePhoto(event)">
            <input type="hidden" name="photo_id" id="editPhotoId">
            <div class="p-6 space-y-4">
                <div>
                    <div class="relative rounded-lg overflow-hidden bg-gray-100 border" style="aspect-ratio:16/10">
                        <img id="editPreview" src="" alt="" class="w-full h-full object-contain">
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <p id="editReplaceHint" class="text-xs text-gray-500"><?php echo e(__('ap_replace_hint')); ?></p>
                        <button type="button" onclick="pickReplacementImage()"
                                data-testid="album-photo-replace"
                                class="shrink-0 px-3 py-1.5 text-sm border border-primary text-primary rounded hover:bg-primary hover:text-white inline-flex items-center gap-1">
                            <i class="ti ti-replace text-base"></i><?php echo e(__('ap_replace_image')); ?>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(__('ap_photo_title')); ?></label>
                    <input type="text" name="title" id="editTitle"
                           class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(__('ap_photo_desc')); ?></label>
                    <textarea name="description" id="editDescription" rows="3"
                              class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary"></textarea>
                </div>
            </div>
            <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-2 rounded-b-lg">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded hover:bg-secondary"><?php echo e(__('admin_save')); ?></button>
            </div>
        </form>
    </div>
</div>

<?php /* Sortable.js */ ?>
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
                    const rejected = result.data.rejected || [];
                    if (rejected.length) {
                        showMessage(<?php echo json_encode(__('ap_uploaded_partial'), JSON_UNESCAPED_UNICODE); ?>
                            .replace(':n', result.data.count)
                            .replace(':m', rejected.length)
                            .replace(':names', rejected.map(item => item.name + '（' + item.error + '）').join('、')), 'error');
                        setTimeout(() => location.reload(), 4000);
                        return;
                    }
                    showMessage(<?php echo json_encode(__('ap_uploaded_n'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', result.data.count));
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage(result.msg || <?php echo json_encode(__('admin_upload_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
                }
            }
        };

        xhr.open('POST', '');
        xhr.send(formData);
    } catch (e) {
        progress.classList.add('hidden');
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
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
        document.querySelectorAll('.photo-item').forEach(item => {
            const isCover = item.dataset.id === String(photoId);
            item.toggleAttribute('data-cover', isCover);
            item.querySelector('.photo-badge-cover').hidden = !isCover;
        });
        showMessage(<?php echo json_encode(__('ap_cover_set'), JSON_UNESCAPED_UNICODE); ?>);
    }
}

// 编辑图片：替换图从媒体库选（弹窗内可上传新图），保存时才生效
let pendingReplacementUrl = '';
function resetEditFile() {
    pendingReplacementUrl = '';
    document.getElementById('editReplaceHint').textContent = <?php echo json_encode(__('ap_replace_hint'), JSON_UNESCAPED_UNICODE); ?>;
}

function pickReplacementImage() {
    openMediaPicker(function (url) {
        if (!url) return;
        pendingReplacementUrl = url;
        document.getElementById('editPreview').src = url;
        document.getElementById('editReplaceHint').textContent = <?php echo json_encode(__('ap_replace_pending'), JSON_UNESCAPED_UNICODE); ?>
            .replace(':name', decodeURIComponent(url.split('/').pop() || url));
    }, { type: 'image' });
}

function editPhoto(id, title, description) {
    resetEditFile();
    document.getElementById('editPhotoId').value = id;
    document.getElementById('editTitle').value = title;
    document.getElementById('editDescription').value = description;
    const current = document.querySelector(`.photo-item[data-id="${id}"] img`);
    document.getElementById('editPreview').src = current ? current.getAttribute('src') : '';
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editModal').classList.add('flex');
}

function closeEditModal() {
    resetEditFile();
    document.getElementById('editModal').classList.add('hidden');
    document.getElementById('editModal').classList.remove('flex');
}

async function savePhoto(e) {
    e.preventDefault();
    const photoId = document.getElementById('editPhotoId').value;
    const replacement = pendingReplacementUrl;
    if (replacement) {
        const replaceData = new FormData();
        replaceData.append('action', 'replace');
        replaceData.append('photo_id', photoId);
        replaceData.append('image_url', replacement);
        const replaced = await safeJson(await fetch('', { method: 'POST', body: replaceData }));
        if (replaced.code !== 0) {
            showMessage(replaced.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            return;
        }
        const img = document.querySelector(`.photo-item[data-id="${photoId}"] img`);
        if (img) img.src = replaced.data.url;
    }
    const formData = new FormData(document.getElementById('editForm'));
    formData.append('action', 'update');
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);
    if (result.code === 0) {
        showMessage(replacement ? <?php echo json_encode(__('ap_replaced'), JSON_UNESCAPED_UNICODE); ?> : '<?php echo __('admin_saved'); ?>');
        closeEditModal();
    } else {
        showMessage(result.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

// 删除图片
async function deletePhoto(photoId) {
    if (!confirm(<?php echo json_encode(__('ap_del_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('photo_id', photoId);
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);
    if (result.code === 0) {
        document.querySelector(`.photo-item[data-id="${photoId}"]`).remove();
        document.getElementById('photoCount').textContent = document.querySelectorAll('.photo-item').length;
        showMessage('<?php echo __('admin_deleted'); ?>');
    }
}

// 批量删除
async function batchDelete() {
    const checked = document.querySelectorAll('.photo-checkbox:checked');
    if (checked.length === 0) return;
    if (!confirm(<?php echo json_encode(__('ap_del_n_confirm'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', checked.length))) return;

    const formData = new FormData();
    formData.append('action', 'batch_delete');
    checked.forEach(cb => formData.append('ids[]', cb.value));

    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);
    if (result.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    }
}

// ESC关闭弹窗
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeEditModal();
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
