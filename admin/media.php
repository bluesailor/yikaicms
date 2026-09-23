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
require_once ROOT_PATH . '/includes/MediaUsageAudit.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('media');

$auditMediaUsage = static function (array $rows): array {
    try {
        return MediaUsageAudit::audit($rows);
    } catch (Throwable $e) {
        adminLog('media', 'usage_audit_failed', 'Media usage audit failed: ' . get_class($e));
        error(__('media_usage_audit_failed'));
    }
};

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'usage') {
        $ids = MediaOptimization::normalizeIds($_POST['ids'] ?? []);
        $id = postInt('id');
        if ($id > 0) {
            $ids[] = $id;
            $ids = array_values(array_unique($ids));
        }
        if (count($ids) > MediaOptimization::MAX_BATCH) {
            error(__('media_delete_batch_limit', ['count' => MediaOptimization::MAX_BATCH]));
        }
        $usage = $auditMediaUsage(mediaModel()->getByIds($ids));
        $blocked = array_filter($usage, static fn(array $item): bool => $item['count'] > 0);
        $references = [];
        foreach ($blocked as $mediaId => $summary) {
            foreach ($summary['items'] as $item) {
                $item['media_id'] = (int) $mediaId;
                $references[] = $item;
            }
        }
        success([
            'blocked' => $blocked !== [],
            'files' => count($blocked),
            'count' => array_sum(array_column($blocked, 'count')),
            'message' => $blocked === [] ? '' : MediaUsageAudit::blockedMessage($usage, count($ids) > 1),
            'references' => array_slice($references, 0, 50),
            'references_truncated' => count($references) > 50,
        ], '');
    }

    if ($action === 'delete') {
        $id = postInt('id');
        $media = mediaModel()->find($id);
        $deletedFiles = 0;

        if ($media) {
            $usage = $auditMediaUsage([$media]);
            if (($usage[$id]['count'] ?? 0) > 0) {
                adminLog('media', 'delete_blocked', "Blocked deletion of media ID: $id; references: " . $usage[$id]['count']);
                error(MediaUsageAudit::blockedMessage($usage));
            }
            $deletedFiles = MediaOptimization::deleteArtifacts($media);
        }

        $deletedRows = mediaModel()->deleteById($id);
        adminLog('media', 'delete', "Deleted media ID: $id; rows: $deletedRows; artifacts: $deletedFiles");
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $normalizedIds = MediaOptimization::normalizeIds($ids);
            if (count($normalizedIds) > MediaOptimization::MAX_BATCH) {
                error(__('media_delete_batch_limit', ['count' => MediaOptimization::MAX_BATCH]));
            }
            $rows = mediaModel()->getByIds($normalizedIds);
            $usage = $auditMediaUsage($rows);
            if (array_filter($usage, static fn(array $item): bool => $item['count'] > 0) !== []) {
                $blockedIds = array_keys(array_filter($usage, static fn(array $item): bool => $item['count'] > 0));
                adminLog('media', 'batch_delete_blocked', 'Blocked batch deletion of media IDs: ' . implode(',', $blockedIds));
                error(MediaUsageAudit::blockedMessage($usage, true));
            }
            $deletedFiles = 0;
            foreach ($rows as $media) {
                $deletedFiles += MediaOptimization::deleteArtifacts($media);
            }

            $deletedRows = mediaModel()->deleteByIds($normalizedIds);
            adminLog('media', 'batch_delete', 'Batch deleted media IDs: ' . implode(',', $normalizedIds)
                . '; rows: ' . $deletedRows . '; artifacts: ' . $deletedFiles);
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

    // 安全替换：新文件落原路径，URL 与全站引用不变（备份旧文件 + 重建衍生）
    if ($action === 'replace') {
        $id = postInt('id');
        $media = mediaModel()->find($id);
        if ($media === null) {
            error(__('media_replace_source_missing'));
        }
        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            error(__('media_replace_upload_missing'));
        }
        require_once ROOT_PATH . '/includes/MediaReplacement.php';
        $sourceExt = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
        $result = MediaReplacement::replace($media, (string) $upload['tmp_name'], $sourceExt);
        if (!$result['ok']) {
            error(__((string) $result['msg']));
        }
        mediaModel()->updateById($id, $result['meta']);
        adminLog('media', 'edit', 'Replaced media #' . $id . ' in place (backup: ' . (string) $result['backup'] . ')');
        success([
            'id' => $id,
            'backup' => (string) $result['backup'],
            'meta' => $result['meta'],
        ], __('media_replace_done'));
    }

    // 孤立媒体报告：批量核对引用，count=0 即孤立（删前仍有二次校验兜底）
    if ($action === 'orphans') {
        $reportType = (string) ($_REQUEST['type'] ?? '');
        if (!in_array($reportType, ['', 'image', 'video', 'file'], true)) {
            $reportType = '';
        }
        $limit = 500;
        $result = mediaModel()->getList(array_filter(['type' => $reportType]), $limit, 0);
        $usage = $auditMediaUsage($result['items']);
        $orphans = [];
        foreach ($result['items'] as $row) {
            $mediaId = (int) ($row['id'] ?? 0);
            if ($mediaId > 0 && (int) ($usage[$mediaId]['count'] ?? 0) === 0) {
                $orphans[] = [
                    'id' => $mediaId,
                    'name' => (string) ($row['name'] ?? ''),
                    'url' => (string) ($row['url'] ?? ''),
                    'type' => (string) ($row['type'] ?? ''),
                    'size' => (int) ($row['size'] ?? 0),
                ];
            }
        }
        adminLog('media', 'usage', 'Orphan report: ' . count($orphans) . '/' . count($result['items']));
        success([
            'scanned' => count($result['items']),
            'truncated' => count($result['items']) >= $limit,
            'orphans' => $orphans,
        ], '');
    }

    exit;
}

// 查询参数
$type = (string) get('type', '');
if (!in_array($type, ['', 'image', 'video', 'file'], true)) {
    $type = '';
}
// 选择模式（用户反馈补齐）：其它页面 window.open 本页 mode=select&target=<inputId>，
// 点击图片即回填 opener 对应输入框并关窗——此前 mode 参数从未被实现，弹窗只是
// 普通媒体库（勾选框是批量删除的），选中后没有任何确认/回填动作。
$selectMode = get('mode', '') === 'select';
$selectTarget = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) get('target', ''));
$healthAttention = !$selectMode && get('health', '') === 'attention';
$keyword = get('keyword', '');
$sort = MediaModel::normalizeSort((string) get('sort', MediaModel::SORT_DEFAULT));
$page = max(1, getInt('page', 1));
$perPage = 24;

$offset = ($page - 1) * $perPage;
$filters = array_filter([
    'type' => $type,
    'keyword' => $keyword,
    'sort' => $sort,
], static fn(mixed $value): bool => $value !== '');
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

$mediaUrl = static function (array $overrides = []) use ($type, $keyword, $sort, $selectMode, $selectTarget): string {
    $query = array_merge([
        'type' => $type,
        'keyword' => $keyword,
        'sort' => $sort !== MediaModel::SORT_DEFAULT ? $sort : '',
        'mode' => $selectMode ? 'select' : '',
        'target' => $selectMode ? $selectTarget : '',
    ], $overrides);
    $query = array_filter($query, static fn(mixed $value): bool => $value !== '');
    return '/admin/media.php' . ($query === [] ? '' : '?' . http_build_query($query));
};
?>

<?php /* 工具栏 */ ?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 space-y-4">
        <nav class="flex flex-wrap gap-1" aria-label="<?php echo e(__('media_all_types')); ?>" data-testid="media-type-tabs">
            <?php
            $typeTabs = [
                '' => ['icon' => 'ti-layout-grid', 'label' => __('media_all_types')],
                'image' => ['icon' => 'ti-photo', 'label' => __('media_type_image')],
                'video' => ['icon' => 'ti-video', 'label' => __('media_type_video')],
                'file' => ['icon' => 'ti-file-text', 'label' => __('media_type_file')],
            ];
            ?>
            <?php foreach ($typeTabs as $tabType => $tab): ?>
            <a href="<?php echo e($mediaUrl(['type' => $tabType, 'page' => ''])); ?>"
               data-media-type="<?php echo e($tabType === '' ? 'all' : $tabType); ?>"
               aria-current="<?php echo $type === $tabType ? 'page' : 'false'; ?>"
               class="inline-flex min-h-9 items-center gap-1.5 rounded px-3 py-2 text-sm font-medium transition <?php echo $type === $tabType ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                <i class="ti <?php echo e($tab['icon']); ?>" aria-hidden="true"></i>
                <?php echo e($tab['label']); ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="flex flex-wrap gap-4 items-center justify-between">
        <form class="flex min-w-0 flex-1 flex-wrap gap-3 items-center">
            <input type="hidden" name="type" value="<?php echo e($type); ?>">
            <?php if ($selectMode): ?>
            <input type="hidden" name="mode" value="select">
            <input type="hidden" name="target" value="<?php echo e($selectTarget); ?>">
            <?php endif; ?>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="min-w-48 flex-1 border rounded px-3 py-2" placeholder="<?php echo e(__('admin_search')); ?>...">

            <label class="relative min-w-40">
                <span class="sr-only"><?php echo e(__('blox_media_sort_label')); ?></span>
                <select name="sort" data-testid="media-sort" class="h-10 w-full border rounded bg-white pl-3 pr-8 text-sm text-gray-700">
                    <option value="default" <?php echo $sort === 'default' ? 'selected' : ''; ?>><?php echo e(__('blox_media_sort_default')); ?></option>
                    <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>><?php echo e(__('blox_media_sort_newest')); ?></option>
                    <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>><?php echo e(__('blox_media_sort_oldest')); ?></option>
                    <option value="largest" <?php echo $sort === 'largest' ? 'selected' : ''; ?>><?php echo e(__('blox_media_sort_largest')); ?></option>
                    <option value="smallest" <?php echo $sort === 'smallest' ? 'selected' : ''; ?>><?php echo e(__('blox_media_sort_smallest')); ?></option>
                    <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>><?php echo e(__('blox_media_sort_name')); ?></option>
                </select>
            </label>

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
            <button type="button" onclick="orphansReport(this)" data-testid="media-orphans-report"
                    class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center justify-center gap-1"
                    title="<?php echo e(__('media_orphans_tip')); ?>">
                <i class="ti ti-file-off text-base"></i>
                <?php echo e(__('media_orphans')); ?>
            </button>
            <?php endif; ?>
            <?php if (!$selectMode): ?>
            <button onclick="batchDelete()" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center justify-center gap-1">
                <i class="ti ti-trash text-base"></i>
                <?php echo __('admin_batch_delete'); ?>
            </button>
            <?php endif; ?>
            <button onclick="uploadFiles()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center justify-center gap-1">
                <i class="ti ti-upload text-base"></i>
                <?php echo __('admin_upload_file'); ?>
            </button>
        </div>
        </div>

        <?php if (!$selectMode): ?>
        <?php // 服务器图像编码能力（决定衍生文件能生成什么）：只做检测展示，不改变行为 ?>
        <div class="text-xs text-gray-400 flex flex-wrap items-center gap-x-3" data-testid="media-gd-capabilities">
            <span><i class="ti ti-cpu mr-1"></i><?php echo e(__('media_gd_capabilities', [
                'webp' => function_exists('imagewebp') ? __('media_gd_yes') : __('media_gd_no'),
                'avif' => function_exists('imageavif') ? __('media_gd_yes') : __('media_gd_no'),
            ])); ?></span>
        </div>
        <?php endif; ?>
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
            <?php if ($mediaHealthSummary['missing'] > 0): ?>
            <p class="mt-1 text-xs text-amber-700">
                <?php echo e(__('media_opt_missing_hint')); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($mediaPendingIds !== []): ?>
    <div class="flex flex-wrap gap-2 md:justify-end">
        <button type="button" data-testid="media-opt-select-pending" onclick="selectPendingMedia()" class="border border-gray-300 px-3 py-2 rounded text-sm text-gray-700 hover:bg-gray-50 inline-flex items-center gap-1">
            <i class="ti ti-checkbox text-base"></i>
            <?php echo e(__('media_opt_select_pending', ['n' => count($mediaPendingIds)])); ?>
        </button>
        <button type="button" data-testid="media-opt-current" data-optimize-button onclick="optimizeCurrentPage(this)" class="bg-primary hover:bg-secondary text-white px-3 py-2 rounded text-sm inline-flex items-center gap-1">
            <i class="ti ti-sparkles text-base"></i>
            <?php echo e(__('media_opt_current_page', ['n' => count($mediaPendingIds)])); ?>
        </button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php /* 文件列表 */ ?>
<div class="bg-white rounded-lg shadow">
    <div class="p-6">
        <?php if (!empty($mediaList)): ?>
        <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-4" id="mediaGrid">
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
            <div class="relative group border rounded-lg overflow-hidden bg-white" data-media-card data-id="<?php echo $itemId; ?>" data-health="<?php echo e($healthStatus); ?>">
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

                <div class="aspect-[4/3] bg-gray-100 flex items-center justify-center overflow-hidden">
                    <?php if ($item['type'] === 'image' && $healthStatus !== 'missing'): ?>
                    <img <?php echo responsiveImageAttributes((string) $item['url'], 'thumb', '(min-width: 1024px) 16vw, (min-width: 768px) 25vw, 50vw'); ?>
                         alt="<?php echo e($item['name']); ?>" loading="lazy" decoding="async"
                         class="w-full h-full object-contain p-2 cursor-pointer"
                         onclick="<?php echo $selectMode ? "pickMedia('" . e($item['url']) . "')" : "previewImage('" . e($item['url']) . "')"; ?>">
                    <?php elseif ($item['type'] === 'image'): ?>
                    <div class="text-center p-4 text-gray-400" role="img" aria-label="<?php echo e(__('media_opt_status_missing')); ?>">
                        <i class="ti ti-photo-off text-4xl" aria-hidden="true"></i>
                    </div>
                    <?php elseif ($item['type'] === 'video'): ?>
                    <button type="button" data-media-preview-button
                            class="relative flex h-full w-full items-center justify-center overflow-hidden bg-gray-950 text-gray-300"
                            onclick="<?php echo $selectMode ? "pickMedia('" . e($item['url']) . "')" : "previewVideo('" . e($item['url']) . "')"; ?>"
                            aria-label="<?php echo e(__('official_media_preview') . ': ' . $item['name']); ?>">
                        <video data-media-video-preview data-src="<?php echo e($item['url']); ?>"
                               class="h-full w-full object-contain transition-opacity duration-200" style="opacity:0"
                               preload="none" muted playsinline aria-hidden="true"></video>
                        <span data-media-video-status data-status="idle"
                              class="absolute inset-0 flex flex-col items-center justify-center gap-1 px-2 text-center text-[10px] leading-4 text-gray-300">
                            <i data-media-video-status-icon class="ti ti-loader-2 animate-spin text-2xl" aria-hidden="true"></i>
                            <span data-media-video-status-text><?php echo e(__('blox_media_video_preview_loading')); ?></span>
                        </span>
                        <span data-media-video-duration hidden
                              class="absolute bottom-2 right-2 rounded bg-black/75 px-1.5 py-0.5 text-[10px] tabular-nums text-white"></span>
                    </button>
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
                        <?php if ($item['type'] === 'image' && $item['width']): ?>
                        <?php
                        // 尺寸与使用场景提示：按宽度粗分档（横幅 ≥1600 / 封面 ≥800 / 更小只够缩略图）
                        $sceneHint = (int) $item['width'] >= 1600
                            ? __('media_scene_banner')
                            : ((int) $item['width'] >= 800 ? __('media_scene_cover') : __('media_scene_small'));
                        ?>
                        <span class="ml-1 inline-block text-[10px] leading-4 text-gray-500 bg-gray-100 rounded px-1"
                              title="<?php echo e(__('media_scene_tip')); ?>"><?php echo e($sceneHint); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($item['type'] === 'video'): ?>
                    <div data-media-video-meta hidden class="mt-1 text-xs tabular-nums text-gray-400"></div>
                    <?php endif; ?>
                    <?php if ((int) ($item['created_at'] ?? 0) > 0): ?>
                    <div data-media-created-date class="mt-1 text-xs tabular-nums text-gray-400" title="<?php echo e(__('admin_created_at')); ?>">
                        <i class="ti ti-calendar-time mr-0.5" aria-hidden="true"></i><?php echo e(date('Y-m-d', (int) $item['created_at'])); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($selectMode): ?>
                <?php /* 选择模式：hover 遮罩就是确认动作（原遮罩的复制/删除会拦截图片点击） */ ?>
                <div class="pointer-events-none absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition flex items-center justify-center">
                    <button onclick="pickMedia('<?php echo e($item['url']); ?>')"
                            class="pointer-events-auto bg-primary text-white px-4 py-1.5 rounded text-sm hover:opacity-90">
                        <?php echo __('media_select_use'); ?>
                    </button>
                </div>
                <?php else: ?>
                <div class="pointer-events-none absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2">
                    <button onclick="copyUrl('<?php echo e($item['url']); ?>')"
                            class="pointer-events-auto bg-white text-gray-700 w-9 h-9 rounded inline-flex items-center justify-center hover:bg-gray-100"
                            title="<?php echo e(__('admin_copy')); ?>" aria-label="<?php echo e(__('admin_copy')); ?>">
                        <i class="ti ti-copy text-base"></i>
                    </button>
                    <?php // 安全替换：新文件落原路径，URL 与全站引用不变（同扩展名才保得住地址） ?>
                    <button onclick="replaceMedia(<?php echo $itemId; ?>, '<?php echo e((string) $item['ext']); ?>')"
                            class="pointer-events-auto bg-white text-gray-700 w-9 h-9 rounded inline-flex items-center justify-center hover:bg-gray-100"
                            title="<?php echo e(__('media_replace')); ?>" aria-label="<?php echo e(__('media_replace')); ?>">
                        <i class="ti ti-refresh text-base"></i>
                    </button>
                    <button onclick="deleteMedia(<?php echo $itemId; ?>)"
                            class="pointer-events-auto bg-red-500 text-white w-9 h-9 rounded inline-flex items-center justify-center hover:bg-red-600"
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

    <?php /* 分页 */ ?>
    <?php if ($total > $perPage): ?>
    <div class="px-6 py-4 border-t flex flex-wrap items-center justify-between gap-3">
        <span class="text-sm text-gray-500"><?php echo str_replace(':n', (string) $total, e(__('mp_total_files'))); ?></span>
        <div class="flex items-center gap-2">
            <?php
            $totalPages = (int)ceil($total / $perPage);
            $queryString = http_build_query(array_filter([
                'type' => $type,
                'keyword' => $keyword,
                'sort' => $sort !== MediaModel::SORT_DEFAULT ? $sort : '',
                'mode' => $selectMode ? 'select' : '',
                'target' => $selectMode ? $selectTarget : '',
            ], static fn(mixed $value): bool => $value !== ''));
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

<?php /* 上传弹窗 */ ?>
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

<?php /* 图片预览弹窗 */ ?>
<div id="previewModal" class="fixed inset-0 z-50 hidden bg-black/90 flex items-center justify-center" onclick="closePreview()"
     role="dialog" aria-modal="true" aria-label="<?php echo e(__('admin_preview')); ?>">
    <div id="previewFrame" class="max-w-full max-h-full" onclick="event.stopPropagation()"></div>
</div>

<script src="/assets/js/blox-media-client.js"></script>
<script src="/assets/js/media-library-page.js"></script>
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
        const filename = document.createElement('span');
        filename.className = 'flex-1 text-sm truncate';
        filename.textContent = file.name;
        const status = document.createElement('span');
        status.className = 'text-xs text-gray-400';
        status.textContent = <?php echo json_encode(__('media_uploading'), JSON_UNESCAPED_UNICODE); ?>;
        item.append(filename, status);
        progress.appendChild(item);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', window.MediaLibraryPage.uploadType(file));

        try {
            const response = await fetch((window.YK_BASE || '') + '/admin/media_api.php?action=upload', { method: 'POST', body: formData });
            const data = await safeJson(response);

            if (data.code === 0) {
                status.textContent = <?php echo json_encode(__('admin_done'), JSON_UNESCAPED_UNICODE); ?>;
                status.className = 'text-xs text-green-600';
            } else {
                status.textContent = data.msg;
                status.className = 'text-xs text-red-600';
            }
        } catch (err) {
            status.textContent = <?php echo json_encode(__('admin_failed'), JSON_UNESCAPED_UNICODE); ?>;
            status.className = 'text-xs text-red-600';
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

function previewVideo(url) {
    const video = document.createElement('video');
    video.src = url;
    video.controls = true;
    video.autoplay = true;
    video.playsInline = true;
    video.className = 'max-w-[90vw] max-h-[85vh]';
    document.getElementById('previewFrame').replaceChildren(video);
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
    const usage = await checkMediaUsage([id]);
    if (!usage) return;
    if (usage.blocked) {
        showMessage(usage.message, 'error');
        return;
    }
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
        var resp = await fetch((window.YK_BASE || '') + '/admin/media_api.php?action=scan', {
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

    const ids = Array.from(checked).map((el) => Number(el.value));
    const usage = await checkMediaUsage(ids);
    if (!usage) return;
    if (usage.blocked) {
        showMessage(usage.message, 'error');
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

// 安全替换：新文件落原路径，URL 与全站引用不变（服务端校验同扩展名并备份旧文件）
function replaceMedia(id, ext) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.' + ext;
    input.onchange = async () => {
        if (!input.files || !input.files[0]) return;
        if (!confirm(<?php echo json_encode(__('media_replace_confirm'), JSON_UNESCAPED_UNICODE); ?>.replace(':ext', ext))) return;
        const formData = new FormData();
        formData.append('action', 'replace');
        formData.append('id', String(id));
        formData.append('file', input.files[0]);
        try {
            const response = await fetch('', { method: 'POST', body: formData });
            const data = await safeJson(response);
            if (data.code === 0) {
                showMessage(data.msg);
                setTimeout(() => location.reload(), 800);
            } else {
                showMessage(data.msg || <?php echo json_encode(__('media_replace_write_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            }
        } catch (e) {
            showMessage(<?php echo json_encode(__('media_replace_write_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    };
    input.click();
}

// 孤立媒体报告：核对最近一批媒体的引用，count=0 即孤立（删除仍走 usage 二次校验）
async function orphansReport(button) {
    const icon = button.querySelector('i');
    if (icon) icon.classList.add('animate-spin');
    try {
        const formData = new FormData();
        formData.append('action', 'orphans');
        formData.append('type', <?php echo json_encode($type); ?>);
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code !== 0) {
            showMessage(data.msg || <?php echo json_encode(__('media_usage_audit_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            return;
        }
        renderOrphansReport(data.data || {});
    } catch (e) {
        showMessage(<?php echo json_encode(__('media_usage_audit_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    } finally {
        if (icon) icon.classList.remove('animate-spin');
    }
}

function renderOrphansReport(data) {
    document.getElementById('mediaOrphansOverlay')?.remove();
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[ch]);
    const humanSize = (bytes) => {
        if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = bytes;
        let unit = 0;
        while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
        return (unit === 0 ? value : value.toFixed(1)) + ' ' + units[unit];
    };
    const orphans = Array.isArray(data.orphans) ? data.orphans : [];
    const rows = orphans.map((item) => `
        <tr class="border-b border-gray-100">
            <td class="py-2 pr-3 text-xs text-gray-700 break-all">${esc(item.name || ('#' + item.id))}</td>
            <td class="py-2 pr-3 text-xs text-gray-400 break-all">${esc(item.url || '')}</td>
            <td class="py-2 pr-3 text-xs text-gray-500 whitespace-nowrap">${esc(humanSize(Number(item.size || 0)))}</td>
            <td class="py-2 text-right">
                <button type="button" data-orphan-delete="${item.id}"
                        class="text-xs text-red-600 hover:text-red-500 px-2 py-1">${esc(<?php echo json_encode(__('admin_delete'), JSON_UNESCAPED_UNICODE); ?>)}</button>
            </td>
        </tr>`).join('');
    const overlay = document.createElement('div');
    overlay.id = 'mediaOrphansOverlay';
    overlay.className = 'fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4';
    overlay.innerHTML = `
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[80vh] flex flex-col" data-testid="media-orphans-modal">
            <div class="px-5 py-4 border-b flex items-center justify-between">
                <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-file-off text-amber-500"></i> ${esc(<?php echo json_encode(__('media_orphans_report_title'), JSON_UNESCAPED_UNICODE); ?>)}
                </h2>
                <button type="button" id="mediaOrphansClose" class="text-gray-400 hover:text-gray-600 p-1" aria-label="close"><i class="ti ti-x"></i></button>
            </div>
            <div class="px-5 py-3 text-xs text-gray-500 border-b">
                ${esc(<?php echo json_encode(__('media_orphans_scanned'), JSON_UNESCAPED_UNICODE); ?>.replace(':scanned', String(data.scanned || 0)).replace(':count', String(orphans.length)))}
                ${data.truncated ? esc(<?php echo json_encode(__('media_orphans_truncated'), JSON_UNESCAPED_UNICODE); ?>) : ''}
            </div>
            <div class="overflow-auto px-5 py-3 flex-1">
                ${orphans.length === 0
                    ? `<p class="text-sm text-green-600 text-center py-8">${esc(<?php echo json_encode(__('media_orphans_none'), JSON_UNESCAPED_UNICODE); ?>)}</p>`
                    : `<table class="w-full text-sm"><thead><tr class="text-left text-xs text-gray-400 border-b">
                           <th class="py-2 pr-3">${esc(<?php echo json_encode(__('media_orphans_col_name'), JSON_UNESCAPED_UNICODE); ?>)}</th>
                           <th class="py-2 pr-3">URL</th><th class="py-2 pr-3">${esc(<?php echo json_encode(__('media_orphans_col_size'), JSON_UNESCAPED_UNICODE); ?>)}</th><th class="py-2 w-16"></th>
                       </tr></thead><tbody>${rows}</tbody></table>`}
            </div>
            <div class="px-5 py-3 border-t text-xs text-gray-400">
                ${esc(<?php echo json_encode(__('media_orphans_delete_hint'), JSON_UNESCAPED_UNICODE); ?>)}
            </div>
        </div>`;
    overlay.addEventListener('click', (event) => {
        if (event.target === overlay || event.target.closest('#mediaOrphansClose')) {
            overlay.remove();
        }
    });
    overlay.addEventListener('click', async (event) => {
        const del = event.target.closest('[data-orphan-delete]');
        if (!del) return;
        const id = Number(del.getAttribute('data-orphan-delete'));
        del.closest('tr')?.remove();
        await deleteMedia(id);
    });
    document.body.appendChild(overlay);
}

async function checkMediaUsage(ids) {
    const formData = new FormData();
    formData.append('action', 'usage');
    ids.forEach((id) => formData.append('ids[]', String(id)));
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code !== 0) {
            showMessage(data.msg || <?php echo json_encode(__('media_usage_audit_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            return null;
        }
        return data.data;
    } catch (error) {
        showMessage(<?php echo json_encode(__('media_usage_audit_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        return null;
    }
}

window.MediaLibraryPage.initVideoPreviews(document.getElementById('mediaGrid'), {
    loading: <?php echo json_encode(__('blox_media_video_preview_loading'), JSON_UNESCAPED_UNICODE); ?>,
    unavailable: <?php echo json_encode(__('blox_media_video_preview_unavailable'), JSON_UNESCAPED_UNICODE); ?>,
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
