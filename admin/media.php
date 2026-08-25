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
require_once ROOT_PATH . '/includes/MediaOptimization.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('media');

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        $id = postInt('id');
        $media = mediaModel()->find($id);
        $deletedFiles = 0;

        if ($media) {
            $deletedFiles = MediaOptimization::deleteArtifacts($media);
        }

        mediaModel()->deleteById($id);
        adminLog('media', 'delete', "Deleted media ID: $id; artifacts: $deletedFiles");
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $normalizedIds = MediaOptimization::normalizeIds($ids);
            $rows = mediaModel()->getByIds($normalizedIds);
            $deletedFiles = 0;
            foreach ($rows as $media) {
                $deletedFiles += MediaOptimization::deleteArtifacts($media);
            }

            mediaModel()->deleteByIds($normalizedIds);
            adminLog('media', 'batch_delete', 'Batch deleted media IDs: ' . implode(',', $normalizedIds)
                . '; artifacts: ' . $deletedFiles);
        }
        success();
    }

    if ($action === 'optimize') {
        $ids = MediaOptimization::normalizeIds($_POST['ids'] ?? []);
        if ($ids === []) {
            error(__('media_opt_none_selected'));
        }
        if (count($ids) > MediaOptimization::MAX_BATCH) {
            error(__('media_opt_batch_limit', ['n' => MediaOptimization::MAX_BATCH]));
        }

        $summary = MediaOptimization::repairMany(mediaModel()->getByIds($ids));
        adminLog('media', 'optimize', 'Optimized media IDs: ' . implode(',', $ids));
        success($summary, __('media_opt_done', [
            'repaired' => $summary['repaired'],
            'failed' => $summary['failed'],
        ]));
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
$healthAttention = !$selectMode && get('health', '') === 'attention';
$keyword = get('keyword', '');
$page = max(1, getInt('page', 1));
$perPage = 24;

$offset = ($page - 1) * $perPage;
$filters = array_filter(['type' => $type, 'keyword' => $keyword]);
$storedHealth = json_decode((string) config('site_health_media_summary', ''), true);
if (!is_array($storedHealth)) {
    $storedHealth = [];
}
if ($healthAttention) {
    $sampleIds = array_slice(
        MediaOptimization::normalizeIds($storedHealth['sample_ids'] ?? []),
        0,
        MediaOptimization::MAX_BATCH
    );
    $sampleRows = mediaModel()->getByIds($sampleIds);
    $rowsById = [];
    foreach ($sampleRows as $row) {
        $rowsById[(int) ($row['id'] ?? 0)] = $row;
    }
    $mediaList = [];
    foreach ($sampleIds as $sampleId) {
        if (isset($rowsById[$sampleId])) {
            $mediaList[] = $rowsById[$sampleId];
        }
    }
    $total = count($mediaList);
} else {
    $result = mediaModel()->getList($filters, $perPage, $offset);
    $total = $result['total'];
    $mediaList = $result['items'];
}
$mediaHealth = $selectMode ? [] : MediaOptimization::inspectMany($mediaList);
$mediaHealthSummary = ['healthy' => 0, 'pending' => 0, 'missing' => 0];
$mediaPendingIds = [];
foreach ($mediaHealth as $mediaId => $health) {
    $status = (string) ($health['status'] ?? 'unsupported');
    if (isset($mediaHealthSummary[$status])) {
        $mediaHealthSummary[$status]++;
    }
    if ($status === 'pending' && !empty($health['repairable'])) {
        $mediaPendingIds[] = (int) $mediaId;
    }
}

$pageTitle = __('admin_media');
$currentMenu = 'media';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="type" class="border rounded px-3 py-2">
                <option value=""><?php echo e(__('media_all_types')); ?></option>
                <option value="image" <?php echo $type === 'image' ? 'selected' : ''; ?>><?php echo e(__('media_type_image')); ?></option>
                <option value="file" <?php echo $type === 'file' ? 'selected' : ''; ?>><?php echo e(__('media_type_file')); ?></option>
                <option value="video" <?php echo $type === 'video' ? 'selected' : ''; ?>><?php echo e(__('media_type_video')); ?></option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="<?php echo __('admin_search'); ?>...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_search'); ?>
            </button>
        </form>

        <div class="grid grid-cols-2 sm:flex gap-2 w-full md:w-auto">
            <?php // 扫描入库：把 uploads/ 下未登记的历史文件（演示图、FTP 手传等）补进媒体表 ?>
            <button onclick="scanMedia(this)" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center justify-center gap-1" title="<?php echo e(__('media_scan_tip')); ?>">
                <i class="ti ti-refresh text-base"></i>
                <?php echo e(__('media_scan')); ?>
            </button>
            <?php if (!$selectMode): ?>
            <button type="button" id="optimizeSelectedBtn" data-testid="media-opt-selected" data-optimize-button onclick="optimizeSelectedMedia(this)" disabled
                    class="border px-4 py-2 rounded hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center justify-center gap-1">
                <i class="ti ti-photo-cog text-base"></i>
                <?php echo e(__('media_opt_selected')); ?>
            </button>
            <?php endif; ?>
            <button onclick="batchDelete()" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center justify-center gap-1">
                <i class="ti ti-trash text-base"></i>
                <?php echo __('admin_batch_delete'); ?>
            </button>
            <button onclick="uploadFiles()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center justify-center gap-1">
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

<?php if ($healthAttention): ?>
<div class="border border-amber-200 bg-amber-50 rounded-lg px-5 py-4 mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" data-testid="media-health-samples">
    <div class="flex items-start gap-3 min-w-0">
        <i class="ti ti-report-medical mt-0.5 text-xl text-amber-700" aria-hidden="true"></i>
        <div class="min-w-0">
            <h2 class="text-sm font-semibold text-amber-900"><?php echo e(__('media_health_samples_title')); ?></h2>
            <p class="mt-1 text-sm leading-6 text-amber-800">
                <?php echo e(__('media_health_samples_desc', [
                    'shown' => count($mediaList),
                    'pending' => max(0, (int) ($storedHealth['pending'] ?? 0)),
                    'missing' => max(0, (int) ($storedHealth['missing'] ?? 0)),
                ])); ?>
            </p>
        </div>
    </div>
    <a href="/admin/media.php" class="inline-flex min-h-10 shrink-0 items-center justify-center gap-1 rounded border border-amber-300 bg-white px-3 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100">
        <i class="ti ti-photo" aria-hidden="true"></i>
        <?php echo e(__('media_health_all')); ?>
    </a>
</div>
<?php endif; ?>

<?php if (!$selectMode && array_sum($mediaHealthSummary) > 0): ?>
<div class="bg-white border border-gray-200 rounded-lg px-4 py-3 mb-4 flex flex-col md:flex-row md:items-center justify-between gap-3" id="mediaOptimizationSummary" data-testid="media-opt-summary" aria-live="polite">
    <div class="flex items-center gap-3 min-w-0">
        <span class="w-9 h-9 shrink-0 rounded bg-gray-100 text-gray-600 inline-flex items-center justify-center">
            <i class="ti ti-photo-cog text-xl"></i>
        </span>
        <div class="min-w-0">
            <h2 class="text-sm font-semibold text-gray-800"><?php echo e(__('media_opt_title')); ?></h2>
            <p class="text-sm text-gray-500">
                <?php echo e(__('media_opt_summary', [
                    'healthy' => $mediaHealthSummary['healthy'],
                    'pending' => $mediaHealthSummary['pending'],
                    'missing' => $mediaHealthSummary['missing'],
                ])); ?>
            </p>
        </div>
    </div>
    <?php if ($mediaPendingIds !== []): ?>
    <div class="flex flex-wrap gap-2 md:justify-end">
        <button type="button" onclick="selectPendingMedia()" class="border border-gray-300 px-3 py-2 rounded text-sm text-gray-700 hover:bg-gray-50 inline-flex items-center gap-1">
            <i class="ti ti-checkbox text-base"></i>
            <?php echo e(__('media_opt_select_pending')); ?>
        </button>
        <button type="button" data-testid="media-opt-current" data-optimize-button onclick="optimizeCurrentPage(this)" class="bg-primary hover:bg-secondary text-white px-3 py-2 rounded text-sm inline-flex items-center gap-1">
            <i class="ti ti-sparkles text-base"></i>
            <?php echo e(__('media_opt_current_page')); ?>
        </button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- 文件列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="p-6">
        <?php if (!empty($mediaList)): ?>
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4" id="mediaGrid">
            <?php foreach ($mediaList as $item): ?>
            <?php
            $itemId = (int) $item['id'];
            $health = $mediaHealth[$itemId] ?? ['status' => 'unsupported', 'repairable' => false];
            $healthStatus = (string) ($health['status'] ?? 'unsupported');
            $healthMeta = match ($healthStatus) {
                'healthy' => ['class' => 'bg-green-600 text-white', 'icon' => 'ti-check', 'label' => __('media_opt_status_healthy')],
                'pending' => ['class' => 'bg-amber-500 text-white', 'icon' => 'ti-alert-triangle', 'label' => __('media_opt_status_pending')],
                'missing' => ['class' => 'bg-red-600 text-white', 'icon' => 'ti-file-alert', 'label' => __('media_opt_status_missing')],
                default => null,
            };
            ?>
            <div class="relative group border rounded-lg overflow-hidden" data-id="<?php echo $itemId; ?>" data-health="<?php echo e($healthStatus); ?>">
                <?php if (!$selectMode): ?>
                <div class="absolute top-2 left-2 z-10">
                    <input type="checkbox" name="ids[]" value="<?php echo $itemId; ?>" data-media-check
                           class="w-4 h-4 rounded border-gray-300" aria-label="<?php echo e(__('media_opt_select_item')); ?>">
                </div>
                <?php endif; ?>
                <?php if ($healthMeta !== null): ?>
                <span class="absolute top-2 right-2 z-10 w-7 h-7 rounded inline-flex items-center justify-center shadow-sm <?php echo e($healthMeta['class']); ?>" data-testid="media-health-status"
                      title="<?php echo e($healthMeta['label']); ?>" aria-label="<?php echo e($healthMeta['label']); ?>">
                    <i class="ti <?php echo e($healthMeta['icon']); ?> text-base"></i>
                </span>
                <?php endif; ?>

                <div class="aspect-square bg-gray-100 flex items-center justify-center">
                    <?php if ($item['type'] === 'image' && $healthStatus !== 'missing'): ?>
                    <img <?php echo responsiveImageAttributes((string) $item['url'], 'thumb', '(min-width: 1024px) 16vw, (min-width: 768px) 25vw, 50vw'); ?>
                         alt="<?php echo e($item['name']); ?>" loading="lazy" decoding="async"
                         class="w-full h-full object-cover cursor-pointer"
                         onclick="<?php echo $selectMode ? "pickMedia('" . e($item['url']) . "')" : "previewImage('" . e($item['url']) . "')"; ?>">
                    <?php elseif ($item['type'] === 'image'): ?>
                    <div class="text-center p-4 text-gray-400" role="img" aria-label="<?php echo e(__('media_opt_status_missing')); ?>">
                        <i class="ti ti-photo-off text-4xl" aria-hidden="true"></i>
                    </div>
                    <?php else: ?>
                    <div class="text-center p-4">
                        <div class="text-4xl text-gray-400 mb-2">
                            <?php
                            $fileIcon = match($item['type']) {
                                'video' => 'ti-movie',
                                'file' => 'ti-file-text',
                                default => 'ti-paperclip',
                            };
                            ?>
                            <i class="ti <?php echo e($fileIcon); ?>"></i>
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
                            class="bg-white text-gray-700 w-9 h-9 rounded inline-flex items-center justify-center hover:bg-gray-100"
                            title="<?php echo e(__('admin_copy')); ?>" aria-label="<?php echo e(__('admin_copy')); ?>">
                        <i class="ti ti-copy text-base"></i>
                    </button>
                    <button onclick="deleteMedia(<?php echo $itemId; ?>)"
                            class="bg-red-500 text-white w-9 h-9 rounded inline-flex items-center justify-center hover:bg-red-600"
                            title="<?php echo e(__('admin_delete')); ?>" aria-label="<?php echo e(__('admin_delete')); ?>">
                        <i class="ti ti-trash text-base"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center text-gray-500 py-12">
            <?php echo e(__('media_empty')); ?>
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
            <span class="text-sm"><?php echo str_replace([':p', ':t'], [(string) $page, (string) $totalPages], e(__('admin_page_of'))); ?></span>
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
            <button onclick="closeUploadModal()" class="text-gray-400 hover:text-gray-600 w-9 h-9 inline-flex items-center justify-center" title="<?php echo e(__('admin_close')); ?>" aria-label="<?php echo e(__('admin_close')); ?>">
                <i class="ti ti-x text-xl"></i>
            </button>
        </div>
        <div class="p-6">
            <div id="dropZone" class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-primary transition cursor-pointer">
                <div class="text-4xl text-gray-400 mb-4"><i class="ti ti-cloud-upload"></i></div>
                <p class="text-gray-600 mb-2"><?php echo e(__('media_drop_hint')); ?></p>
                <p class="text-sm text-gray-400"><?php echo e(__('media_format_hint')); ?></p>
            </div>
            <input type="file" id="fileInput" multiple class="hidden">
            <div id="uploadProgress" class="mt-4 space-y-2"></div>
        </div>
    </div>
</div>

<!-- 图片预览弹窗 -->
<div id="previewModal" class="fixed inset-0 z-50 hidden bg-black/90 flex items-center justify-center" onclick="closePreview()"
     role="dialog" aria-modal="true" aria-label="<?php echo e(__('admin_preview')); ?>">
    <div id="previewFrame" class="max-w-full max-h-full"></div>
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
const mediaPendingIds = <?php echo json_encode($mediaPendingIds, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function selectedMediaIds() {
    return Array.from(document.querySelectorAll('#mediaGrid input[name="ids[]"]:checked'))
        .map((input) => Number(input.value))
        .filter((id) => Number.isInteger(id) && id > 0);
}

function updateMediaSelection() {
    const button = document.getElementById('optimizeSelectedBtn');
    if (button) button.disabled = selectedMediaIds().length === 0;
}

document.querySelectorAll('[data-media-check]').forEach((input) => {
    input.addEventListener('change', updateMediaSelection);
});

function selectPendingMedia() {
    const pending = new Set(mediaPendingIds);
    document.querySelectorAll('[data-media-check]').forEach((input) => {
        input.checked = pending.has(Number(input.value));
    });
    updateMediaSelection();
}

function optimizeCurrentPage(button) {
    optimizeMedia(mediaPendingIds, button);
}

function optimizeSelectedMedia(button) {
    optimizeMedia(selectedMediaIds(), button);
}

async function optimizeMedia(ids, button) {
    ids = Array.from(new Set(ids)).slice(0, <?php echo MediaOptimization::MAX_BATCH; ?>);
    if (ids.length === 0) {
        showMessage(<?php echo json_encode(__('media_opt_none_selected'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        return;
    }
    if (!confirm(<?php echo json_encode(__('media_opt_confirm'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', ids.length))) return;

    const originalHtml = button ? button.innerHTML : '';
    document.querySelectorAll('[data-optimize-button]').forEach((item) => { item.disabled = true; });
    if (button) {
        button.innerHTML = '<i class="ti ti-loader-2 animate-spin text-base"></i>'
            + <?php echo json_encode(__('media_opt_working'), JSON_UNESCAPED_UNICODE); ?>;
    }
    const formData = new FormData();
    formData.append('action', 'optimize');
    ids.forEach((id) => formData.append('ids[]', String(id)));
    let reloading = false;
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage(data.msg || <?php echo json_encode(__('media_opt_success'), JSON_UNESCAPED_UNICODE); ?>);
            reloading = true;
            setTimeout(() => location.reload(), 700);
        } else {
            showMessage(data.msg || <?php echo json_encode(__('media_opt_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    } catch (error) {
        showMessage(<?php echo json_encode(__('media_opt_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    } finally {
        if (!reloading) {
            document.querySelectorAll('[data-optimize-button]').forEach((item) => { item.disabled = false; });
            updateMediaSelection();
            if (button) button.innerHTML = originalHtml;
        }
    }
}

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
            <span class="text-xs text-gray-400"><?php echo e(__('media_uploading')); ?></span>
        `;
        progress.appendChild(item);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', file.type.startsWith('image/') ? 'images' : 'files');

        try {
            const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
            const data = await safeJson(response);

            if (data.code === 0) {
                item.querySelector('span:last-child').textContent = <?php echo json_encode(__('admin_done'), JSON_UNESCAPED_UNICODE); ?>;
                item.querySelector('span:last-child').className = 'text-xs text-green-600';
            } else {
                item.querySelector('span:last-child').textContent = data.msg;
                item.querySelector('span:last-child').className = 'text-xs text-red-600';
            }
        } catch (err) {
            item.querySelector('span:last-child').textContent = <?php echo json_encode(__('admin_failed'), JSON_UNESCAPED_UNICODE); ?>;
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
    const image = document.createElement('img');
    image.src = url;
    image.alt = '';
    image.className = 'max-w-full max-h-full';
    document.getElementById('previewFrame').replaceChildren(image);
    document.getElementById('previewModal').classList.remove('hidden');
}

function closePreview() {
    document.getElementById('previewModal').classList.add('hidden');
    document.getElementById('previewFrame').replaceChildren();
}

function copyUrl(url) {
    navigator.clipboard.writeText(url).then(() => {
        showMessage(<?php echo json_encode(__('admin_copied'), JSON_UNESCAPED_UNICODE); ?>);
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
            alert(<?php echo json_encode(__('media_scan_done'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', data.data.added));
            if (data.data.added > 0) location.reload();
        } else {
            alert(data.msg || <?php echo json_encode(__('media_scan_failed'), JSON_UNESCAPED_UNICODE); ?>);
        }
    } catch (e) {
        alert(<?php echo json_encode(__('media_scan_req_failed'), JSON_UNESCAPED_UNICODE); ?>);
    } finally {
        btn.disabled = false;
        icon.classList.remove('animate-spin');
    }
}

async function batchDelete() {
    const checked = document.querySelectorAll('#mediaGrid input[name="ids[]"]:checked');
    if (checked.length === 0) {
        showMessage(<?php echo json_encode(__('media_pick_to_delete'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        return;
    }

    if (!confirm(<?php echo json_encode(__('media_del_confirm'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', checked.length))) return;

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
