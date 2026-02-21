<?php
/**
 * Yikai CMS - 相册编辑（简化版）
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

$id = getInt('id');
$album = null;
$photos = [];

if ($id > 0) {
    $album = albumModel()->find($id);
    if (!$album) {
        redirect('/admin/album.php');
    }
    // 获取相册图片供选择封面
    $photos = albumPhotoModel()->getByAlbum($id);
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name' => post('name'),
        'slug' => post('slug'),
        'cover' => post('cover'),
        'description' => post('description'),
        'sort_order' => postInt('sort_order'),
        'status' => postInt('status', 1),
        'updated_at' => time(),
    ];

    if (empty($data['name'])) {
        error('相册名称不能为空');
    }

    $data['slug'] = resolveSlug($data['slug'] ?? '', $data['name'], 'albums', $id);

    if ($id > 0) {
        albumModel()->updateById($id, $data);
        adminLog('album', 'update', '更新相册：' . $data['name']);
    } else {
        $data['created_at'] = time();
        $data['photo_count'] = 0;
        $id = albumModel()->create($data);
        adminLog('album', 'create', '创建相册：' . $data['name']);
    }

    success(['id' => $id]);
}

$pageTitle = $album ? '编辑相册' : '新建相册';
$currentMenu = 'album';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<form id="editForm" class="max-w-2xl">
    <div class="bg-white rounded-lg shadow">
        <div class="p-6 border-b">
            <h2 class="text-lg font-medium"><?php echo $pageTitle; ?></h2>
        </div>

        <div class="p-6 space-y-6">
            <!-- 相册名称 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    相册名称 <span class="text-red-500">*</span>
                </label>
                <input type="text" name="name" value="<?php echo e($album['name'] ?? ''); ?>" required
                       class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary"
                       placeholder="如：荣誉资质、企业环境">
            </div>

            <!-- URL别名 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">URL别名</label>
                <input type="text" name="slug" value="<?php echo e($album['slug'] ?? ''); ?>"
                       class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary"
                       placeholder="留空自动生成，如：honor">
                <p class="text-xs text-gray-400 mt-1">用于前台URL，仅支持字母、数字、横杠</p>
            </div>

            <!-- 封面图 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">封面图片</label>
                <div class="flex items-start gap-4">
                    <div id="coverPreview" class="w-40 h-30 bg-gray-100 rounded-lg overflow-hidden flex items-center justify-center border-2 border-dashed border-gray-300">
                        <?php if (!empty($album['cover'])): ?>
                        <img src="<?php echo e($album['cover']); ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                        <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1">
                        <input type="hidden" name="cover" id="coverInput" value="<?php echo e($album['cover'] ?? ''); ?>">
                        <input type="file" id="coverFile" accept="image/*" class="hidden">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" onclick="document.getElementById('coverFile').click()"
                                    class="border px-4 py-2 rounded hover:bg-gray-50 inline-flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                                上传封面
                            </button>
                            <?php if ($album && count($photos) > 0): ?>
                            <button type="button" onclick="openPhotoSelector()"
                                    class="border px-4 py-2 rounded hover:bg-gray-50 inline-flex items-center gap-2 text-primary border-primary">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                从相册选择
                            </button>
                            <?php endif; ?>
                            <button type="button" onclick="clearCover()" class="text-gray-500 hover:text-red-500 px-2">清除</button>
                        </div>
                        <p class="text-xs text-gray-400 mt-2">不设置则自动使用第一张图片</p>
                    </div>
                </div>
            </div>

            <!-- 相册描述 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">相册描述</label>
                <textarea name="description" rows="3"
                          class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary"
                          placeholder="简单描述相册内容..."><?php echo e($album['description'] ?? ''); ?></textarea>
            </div>

            <!-- 排序和状态 -->
            <div class="grid grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">排序</label>
                    <input type="number" name="sort_order" value="<?php echo $album['sort_order'] ?? 0; ?>"
                           class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    <p class="text-xs text-gray-400 mt-1">数字越大越靠前</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">状态</label>
                    <select name="status" class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        <option value="1" <?php echo ($album['status'] ?? 1) == 1 ? 'selected' : ''; ?>>显示</option>
                        <option value="0" <?php echo ($album['status'] ?? 1) == 0 ? 'selected' : ''; ?>>隐藏</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 操作按钮 -->
        <div class="px-6 py-4 border-t bg-gray-50 flex items-center justify-between rounded-b-lg">
            <a href="/admin/album.php" class="text-gray-500 hover:text-gray-700">返回列表</a>
            <div class="flex gap-3">
                <?php if ($album): ?>
                <a href="/admin/album_photos.php?id=<?php echo $id; ?>" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    管理图片
                </a>
                <?php endif; ?>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    保存
                </button>
            </div>
        </div>
    </div>
</form>

<?php if ($album && count($photos) > 0): ?>
<!-- 图片选择弹窗 -->
<div id="photoSelectorModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4 max-h-[80vh] flex flex-col">
        <div class="px-6 py-4 border-b flex items-center justify-between flex-shrink-0">
            <h3 class="text-lg font-medium">选择封面图片</h3>
            <button onclick="closePhotoSelector()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="p-6 overflow-y-auto flex-1">
            <div class="grid grid-cols-4 gap-3">
                <?php foreach ($photos as $photo): ?>
                <div class="aspect-square bg-gray-100 rounded-lg overflow-hidden cursor-pointer hover:ring-2 hover:ring-primary transition-all"
                     onclick="selectCover('<?php echo e($photo['image']); ?>')">
                    <img src="<?php echo e($photo['image']); ?>" alt="<?php echo e($photo['title']); ?>" class="w-full h-full object-cover">
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (empty($photos)): ?>
            <div class="text-center py-8 text-gray-500">暂无图片</div>
            <?php endif; ?>
        </div>
        <div class="px-6 py-4 border-t bg-gray-50 flex justify-end rounded-b-lg flex-shrink-0">
            <button type="button" onclick="closePhotoSelector()" class="px-4 py-2 border rounded hover:bg-gray-100">关闭</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.getElementById('coverFile').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const formData = new FormData();
    formData.append('file', this.files[0]);

    try {
        const response = await fetch('/admin/upload.php?type=image', {
            method: 'POST',
            body: formData
        });
        const result = await safeJson(response);

        if (result.code === 0) {
            document.getElementById('coverInput').value = result.data.url;
            document.getElementById('coverPreview').innerHTML = '';
            const uploadedImg = document.createElement('img');
            uploadedImg.src = result.data.url;
            uploadedImg.className = 'w-full h-full object-cover';
            document.getElementById('coverPreview').appendChild(uploadedImg);
            showMessage('封面上传成功');
        } else {
            showMessage(result.msg || '上传失败', 'error');
        }
    } catch (e) {
        showMessage('上传失败', 'error');
    }

    this.value = '';
});

function clearCover() {
    document.getElementById('coverInput').value = '';
    document.getElementById('coverPreview').innerHTML = `
        <svg class="w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
    `;
}

<?php if ($album && count($photos) > 0): ?>
const photoModal = document.getElementById('photoSelectorModal');

function openPhotoSelector() {
    photoModal.classList.remove('hidden');
    photoModal.classList.add('flex');
}

function closePhotoSelector() {
    photoModal.classList.add('hidden');
    photoModal.classList.remove('flex');
}

function selectCover(url) {
    document.getElementById('coverInput').value = url;
    document.getElementById('coverPreview').innerHTML = '';
    const selectedImg = document.createElement('img');
    selectedImg.src = url;
    selectedImg.className = 'w-full h-full object-cover';
    document.getElementById('coverPreview').appendChild(selectedImg);
    closePhotoSelector();
    showMessage('已选择封面');
}

photoModal.addEventListener('click', function(e) {
    if (e.target === this) closePhotoSelector();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePhotoSelector();
});
<?php endif; ?>

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await safeJson(response);

        if (result.code === 0) {
            showMessage('保存成功');
            setTimeout(() => {
                <?php if ($album): ?>
                location.reload();
                <?php else: ?>
                location.href = '/admin/album_photos.php?id=' + result.data.id;
                <?php endif; ?>
            }, 1000);
        } else {
            showMessage(result.msg || '保存失败', 'error');
        }
    } catch (e) {
        showMessage('保存失败', 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
