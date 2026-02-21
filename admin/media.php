<?php
/**
 * Yikai CMS - 媒体库管理
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

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        $id = postInt('id');
        $media = mediaModel()->find($id);

        if ($media && !empty($media['path'])) {
            $realPath = realpath($media['path']);
            if ($realPath && str_starts_with($realPath, realpath(UPLOADS_PATH)) && file_exists($realPath)) {
                @unlink($realPath);
            }
        }

        mediaModel()->deleteById($id);
        adminLog('media', 'delete', "删除媒体ID: $id");
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $pathRows = mediaModel()->getPathsByIds($ids);

            foreach ($pathRows as $media) {
                if (!empty($media['path'])) {
                    $realPath = realpath($media['path']);
                    if ($realPath && str_starts_with($realPath, realpath(UPLOADS_PATH)) && file_exists($realPath)) {
                        @unlink($realPath);
                    }
                }
            }

            mediaModel()->deleteByIds($ids);
            adminLog('media', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    exit;
}

// 查询参数
$type = get('type', '');
$keyword = get('keyword', '');
$page = max(1, getInt('page', 1));
$perPage = 24;

$offset = ($page - 1) * $perPage;
$filters = array_filter(['type' => $type, 'keyword' => $keyword]);
$result = mediaModel()->getList($filters, $perPage, $offset);
$total = $result['total'];
$mediaList = $result['items'];

$pageTitle = '媒体库';
$currentMenu = 'media';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="type" class="border rounded px-3 py-2">
                <option value="">全部类型</option>
                <option value="image" <?php echo $type === 'image' ? 'selected' : ''; ?>>图片</option>
                <option value="file" <?php echo $type === 'file' ? 'selected' : ''; ?>>文件</option>
                <option value="video" <?php echo $type === 'video' ? 'selected' : ''; ?>>视频</option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="搜索文件名...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                搜索
            </button>
        </form>

        <div class="flex gap-2">
            <button onclick="batchDelete()" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                批量删除
            </button>
            <button onclick="uploadFiles()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                上传文件
            </button>
        </div>
    </div>
</div>

<!-- 文件列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="p-6">
        <?php if (!empty($mediaList)): ?>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4" id="mediaGrid">
            <?php foreach ($mediaList as $item): ?>
            <div class="relative group border rounded-lg overflow-hidden" data-id="<?php echo $item['id']; ?>">
                <div class="absolute top-2 left-2 z-10">
                    <input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>"
                           class="w-4 h-4 rounded border-gray-300">
                </div>

                <div class="aspect-square bg-gray-100 flex items-center justify-center">
                    <?php if ($item['type'] === 'image'): ?>
                    <img src="<?php echo e($item['url']); ?>" alt="<?php echo e($item['name']); ?>"
                         class="w-full h-full object-cover cursor-pointer" onclick="previewImage('<?php echo e($item['url']); ?>')">
                    <?php else: ?>
                    <div class="text-center p-4">
                        <div class="text-4xl text-gray-400 mb-2">
                            <?php
                            echo match($item['type']) {
                                'video' => '🎬',
                                'file' => '📄',
                                default => '📎'
                            };
                            ?>
                        </div>
                        <div class="text-xs text-gray-500 uppercase"><?php echo e($item['ext']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="p-2">
                    <div class="text-xs text-gray-700 truncate" title="<?php echo e($item['name']); ?>">
                        <?php echo e($item['name']); ?>
                    </div>
                    <div class="text-xs text-gray-400 mt-1">
                        <?php echo formatFileSize((int)$item['size']); ?>
                        <?php if ($item['width'] && $item['height']): ?>
                        · <?php echo $item['width']; ?>x<?php echo $item['height']; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2">
                    <button onclick="copyUrl('<?php echo e($item['url']); ?>')"
                            class="bg-white text-gray-700 px-3 py-1 rounded text-sm hover:bg-gray-100">
                        复制
                    </button>
                    <button onclick="deleteMedia(<?php echo $item['id']; ?>)"
                            class="bg-red-500 text-white px-3 py-1 rounded text-sm hover:bg-red-600">
                        删除
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center text-gray-500 py-12">
            暂无媒体文件
        </div>
        <?php endif; ?>
    </div>

    <!-- 分页 -->
    <?php if ($total > $perPage): ?>
    <div class="px-6 py-4 border-t flex items-center justify-between">
        <span class="text-sm text-gray-500">共 <?php echo $total; ?> 个文件</span>
        <div class="flex items-center gap-2">
            <?php
            $totalPages = (int)ceil($total / $perPage);
            $queryString = http_build_query(array_filter(['type' => $type, 'keyword' => $keyword]));
            $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
            ?>
            <?php if ($page > 1): ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                上一页</a>
            <?php endif; ?>
            <span class="text-sm">第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
            <?php if ($page < $totalPages): ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                下一页
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 上传弹窗 -->
<div id="uploadModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeUploadModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800">上传文件</h3>
            <button onclick="closeUploadModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <div class="p-6">
            <div id="dropZone" class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-primary transition cursor-pointer">
                <div class="text-4xl text-gray-400 mb-4">📁</div>
                <p class="text-gray-600 mb-2">拖拽文件到此处或点击上传</p>
                <p class="text-sm text-gray-400">支持图片、文档等常见格式</p>
            </div>
            <input type="file" id="fileInput" multiple class="hidden">
            <div id="uploadProgress" class="mt-4 space-y-2"></div>
        </div>
    </div>
</div>

<!-- 图片预览弹窗 -->
<div id="previewModal" class="fixed inset-0 z-50 hidden bg-black/90 flex items-center justify-center" onclick="closePreview()">
    <img id="previewImage" src="" class="max-w-full max-h-full">
</div>

<script>
function uploadFiles() {
    document.getElementById('uploadModal').classList.remove('hidden');
}

function closeUploadModal() {
    document.getElementById('uploadModal').classList.add('hidden');
}

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');

dropZone.addEventListener('click', () => fileInput.click());

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('border-primary', 'bg-blue-50');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('border-primary', 'bg-blue-50');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('border-primary', 'bg-blue-50');
    handleFiles(e.dataTransfer.files);
});

fileInput.addEventListener('change', () => {
    handleFiles(fileInput.files);
});

async function handleFiles(files) {
    const progress = document.getElementById('uploadProgress');
    progress.innerHTML = '';

    for (const file of files) {
        const item = document.createElement('div');
        item.className = 'flex items-center gap-3 p-2 bg-gray-50 rounded';
        item.innerHTML = `
            <span class="flex-1 text-sm truncate">${file.name}</span>
            <span class="text-xs text-gray-400">上传中...</span>
        `;
        progress.appendChild(item);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', file.type.startsWith('image/') ? 'images' : 'files');

        try {
            const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
            const data = await safeJson(response);

            if (data.code === 0) {
                item.querySelector('span:last-child').textContent = '完成';
                item.querySelector('span:last-child').className = 'text-xs text-green-600';
            } else {
                item.querySelector('span:last-child').textContent = data.msg;
                item.querySelector('span:last-child').className = 'text-xs text-red-600';
            }
        } catch (err) {
            item.querySelector('span:last-child').textContent = '失败';
            item.querySelector('span:last-child').className = 'text-xs text-red-600';
        }
    }

    setTimeout(() => {
        closeUploadModal();
        location.reload();
    }, 1500);
}

function previewImage(url) {
    document.getElementById('previewImage').src = url;
    document.getElementById('previewModal').classList.remove('hidden');
}

function closePreview() {
    document.getElementById('previewModal').classList.add('hidden');
}

function copyUrl(url) {
    navigator.clipboard.writeText(url).then(() => {
        showMessage('已复制到剪贴板');
    });
}

async function deleteMedia(id) {
    if (!confirm('确定要删除吗？')) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        showMessage('删除成功');
        document.querySelector(`[data-id="${id}"]`)?.remove();
    } else {
        showMessage(data.msg, 'error');
    }
}

async function batchDelete() {
    const checked = document.querySelectorAll('#mediaGrid input[name="ids[]"]:checked');
    if (checked.length === 0) {
        showMessage('请选择要删除的文件', 'error');
        return;
    }

    if (!confirm(`确定要删除选中的 ${checked.length} 个文件吗？`)) return;

    const formData = new FormData();
    formData.append('action', 'batch_delete');
    checked.forEach(el => formData.append('ids[]', el.value));

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
