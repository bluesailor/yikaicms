<?php
/**
 * YikaiCMS - 媒体库管理
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
// 选择模式（用户反馈补齐）：其它页面 window.open 本页 mode=select&target=<inputId>，
// 点击图片即回填 opener 对应输入框并关窗——此前 mode 参数从未被实现，弹窗只是
// 普通媒体库（勾选框是批量删除的），选中后没有任何确认/回填动作。
$selectMode = get('mode', '') === 'select';
$selectTarget = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) get('target', ''));
$keyword = get('keyword', '');
$page = max(1, getInt('page', 1));
$perPage = 24;

$offset = ($page - 1) * $perPage;
$filters = array_filter(['type' => $type, 'keyword' => $keyword]);
$result = mediaModel()->getList($filters, $perPage, $offset);
$total = $result['total'];
$mediaList = $result['items'];

$pageTitle = __('admin_media');
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
                   class="border rounded px-3 py-2" placeholder="<?php echo __('admin_search'); ?>...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_search'); ?>
            </button>
        </form>

        <div class="flex gap-2">
            <?php // 扫描入库：把 uploads/ 下未登记的历史文件（演示图、FTP 手传等）补进媒体表 ?>
            <button onclick="scanMedia(this)" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center gap-1" title="把 uploads 目录里未登记的文件补进媒体库">
                <i class="ti ti-refresh text-base"></i>
                扫描入库
            </button>
            <button onclick="batchDelete()" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <i class="ti ti-trash text-base"></i>
                <?php echo __('admin_batch_delete'); ?>
            </button>
            <button onclick="uploadFiles()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-upload text-base"></i>
                <?php echo __('admin_upload_file'); ?>
            </button>
        </div>
    </div>
</div>

<?php if ($selectMode): ?>
<div class="bg-blue-50 border border-blue-100 rounded-lg px-5 py-3 mb-4 text-sm text-blue-700">
    <i class="ti ti-hand-click"></i> <?php echo __('media_select_hint'); ?>
</div>
<?php endif; ?>

<!-- 文件列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="p-6">
        <?php if (!empty($mediaList)): ?>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4" id="mediaGrid">
            <?php foreach ($mediaList as $item): ?>
            <div class="relative group border rounded-lg overflow-hidden" data-id="<?php echo $item['id']; ?>">
                <?php if (!$selectMode): ?>
                <div class="absolute top-2 left-2 z-10">
                    <input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>"
                           class="w-4 h-4 rounded border-gray-300">
                </div>
                <?php endif; ?>

                <div class="aspect-square bg-gray-100 flex items-center justify-center">
                    <?php if ($item['type'] === 'image'): ?>
                    <img src="<?php echo e($item['url']); ?>" alt="<?php echo e($item['name']); ?>"
                         class="w-full h-full object-cover cursor-pointer"
                         onclick="<?php echo $selectMode ? 'pickMedia(this.src)' : "previewImage('" . e($item['url']) . "')"; ?>">
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

                <?php if ($selectMode): ?>
                <!-- 选择模式：hover 遮罩就是确认动作（原遮罩的复制/删除会拦截图片点击） -->
                <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition flex items-center justify-center">
                    <button onclick="pickMedia('<?php echo e($item['url']); ?>')"
                            class="bg-primary text-white px-4 py-1.5 rounded text-sm hover:opacity-90">
                        <?php echo __('media_select_use'); ?>
                    </button>
                </div>
                <?php else: ?>
                <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2">
                    <button onclick="copyUrl('<?php echo e($item['url']); ?>')"
                            class="bg-white text-gray-700 px-3 py-1 rounded text-sm hover:bg-gray-100">
                        <?php echo __('admin_copy'); ?>
                    </button>
                    <button onclick="deleteMedia(<?php echo $item['id']; ?>)"
                            class="bg-red-500 text-white px-3 py-1 rounded text-sm hover:bg-red-600">
                        <?php echo __('admin_delete'); ?>
                    </button>
                </div>
                <?php endif; ?>
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
        <span class="text-sm text-gray-500"><?php echo str_replace(':n', (string) $total, e(__('mp_total_files'))); ?></span>
        <div class="flex items-center gap-2">
            <?php
            $totalPages = (int)ceil($total / $perPage);
            $queryString = http_build_query(array_filter(['type' => $type, 'keyword' => $keyword]));
            $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
            ?>
            <?php if ($page > 1): ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <i class="ti ti-chevron-left text-base"></i>
                <?php echo __('list_prev_page'); ?></a>
            <?php endif; ?>
            <span class="text-sm">第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
            <?php if ($page < $totalPages): ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <?php echo __('list_next_page'); ?>
                <i class="ti ti-chevron-right text-base"></i>
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
            <h3 class="font-bold text-gray-800"><?php echo __('btn_upload_file'); ?></h3>
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

var YK_SELECT_TARGET = <?php echo json_encode($selectMode ? $selectTarget : '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function pickMedia(url) {
    if (!YK_SELECT_TARGET) return;
    try {
        var input = window.opener && window.opener.document.getElementById(YK_SELECT_TARGET);
        if (input) {
            input.value = url;
            input.dispatchEvent(new window.opener.Event('input', { bubbles: true }));
            input.dispatchEvent(new window.opener.Event('change', { bubbles: true }));
            window.close();
            return;
        }
    } catch (e) { /* opener 跨页/已关：走降级 */ }
    prompt(<?php echo json_encode(__('media_select_copy_fallback'), JSON_UNESCAPED_UNICODE); ?>, url);
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
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        document.querySelector(`[data-id="${id}"]`)?.remove();
    } else {
        showMessage(data.msg, 'error');
    }
}

async function scanMedia(btn) {
    btn.disabled = true;
    var icon = btn.querySelector('i');
    icon.classList.add('animate-spin');
    try {
        // 全局 POST CSRF（RBAC 加固后 auth.php 对所有 POST 强制校验）——header 携带 token
        var resp = await fetch('/admin/media_api.php?action=scan', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        });
        var data = await resp.json();
        if (data.code === 0) {
            alert('扫描完成：新增 ' + data.data.added + ' 个文件');
            if (data.data.added > 0) location.reload();
        } else {
            alert(data.msg || '扫描失败');
        }
    } catch (e) {
        alert('扫描请求失败');
    } finally {
        btn.disabled = false;
        icon.classList.remove('animate-spin');
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
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
