<?php
/**
 * YikaiCMS - Site health and security diagnostics.
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/MediaOptimization.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    @ini_set('display_errors', '0');
    $action = (string) post('action');

    if ($action === 'start_scan') {
        $previousScan = $_SESSION['site_health_scan'] ?? null;
        if (is_array($previousScan)) {
            SiteHealth::cleanupBrowserProbe((string) ($previousScan['storage_file'] ?? ''), STORAGE_PATH);
            SiteHealth::cleanupUploadProbe((string) ($previousScan['upload_file'] ?? ''), UPLOADS_PATH);
            unset($_SESSION['site_health_scan']);
        }
        $probe = SiteHealth::createBrowserProbes(STORAGE_PATH);
        $checks = array_merge(SiteHealth::runDirect(), $probe['checks']);
        $mediaTotal = 0;
        $mediaFailed = false;
        try {
            $mediaTotal = mediaModel()->countImages();
        } catch (Throwable) {
            $mediaFailed = true;
        }
        $_SESSION['site_health_scan'] = [
            'nonce' => $probe['nonce'],
            'created_at' => time(),
            'checks' => SiteHealth::normalizeResults($checks),
            'storage_file' => $probe['storage_file'],
            'storage_token' => $probe['storage_token'],
            'upload_file' => $probe['upload_file'],
            'upload_token' => $probe['upload_token'],
            'media' => [
                'cursor' => 0,
                'total' => $mediaTotal,
                'scanned' => 0,
                'healthy' => 0,
                'pending' => 0,
                'missing' => 0,
                'unsupported' => 0,
                'repairable' => 0,
                'sample_ids' => [],
                'failed' => $mediaFailed,
                'done' => $mediaFailed || $mediaTotal === 0,
            ],
        ];
        success([
            'nonce' => $probe['nonce'],
            'checks' => SiteHealth::normalizeResults($checks),
            'probes' => $probe['probes'],
            'media' => $_SESSION['site_health_scan']['media'],
        ], '');
    }

    if ($action === 'scan_media') {
        $scan = $_SESSION['site_health_scan'] ?? null;
        $nonce = (string) post('nonce');
        if (!is_array($scan)
            || !isset($scan['nonce'], $scan['created_at'])
            || !hash_equals((string) $scan['nonce'], $nonce)
            || (int) $scan['created_at'] < time() - 600) {
            if (is_array($scan)) {
                SiteHealth::cleanupBrowserProbe((string) ($scan['storage_file'] ?? ''), STORAGE_PATH);
                SiteHealth::cleanupUploadProbe((string) ($scan['upload_file'] ?? ''), UPLOADS_PATH);
            }
            unset($_SESSION['site_health_scan']);
            error(__('health_scan_expired'));
        }

        $media = is_array($scan['media'] ?? null) ? $scan['media'] : [];
        if (!empty($media['done'])) {
            success($media, '');
        }

        $rows = mediaModel()->getImageBatchAfterId(
            max(0, (int) ($media['cursor'] ?? 0)),
            MediaOptimization::MAX_BATCH
        );
        $batch = MediaOptimization::summarizeMany($rows);
        foreach (['scanned', 'healthy', 'pending', 'missing', 'unsupported', 'repairable'] as $key) {
            $media[$key] = max(0, (int) ($media[$key] ?? 0)) + $batch[$key];
        }
        $samples = array_merge(
            MediaOptimization::normalizeIds($media['sample_ids'] ?? []),
            $batch['sample_ids']
        );
        $media['sample_ids'] = array_slice(array_values(array_unique($samples)), 0, MediaOptimization::MAX_BATCH);
        if ($rows !== []) {
            $media['cursor'] = max(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows));
        }
        $media['done'] = count($rows) < MediaOptimization::MAX_BATCH;
        if ($media['done']) {
            // 扫描期间若有媒体增删，以游标实际完成的快照为准，避免总数变化造成假“未完成”。
            $media['total'] = (int) $media['scanned'];
        } else {
            $media['total'] = max((int) ($media['total'] ?? 0), (int) $media['scanned']);
        }
        $_SESSION['site_health_scan']['media'] = $media;
        success($media, '');
    }

    if ($action === 'finish_scan') {
        $scan = $_SESSION['site_health_scan'] ?? null;
        $nonce = (string) post('nonce');
        if (!is_array($scan)
            || !isset($scan['nonce'], $scan['created_at'])
            || !hash_equals((string) $scan['nonce'], $nonce)
            || (int) $scan['created_at'] < time() - 600) {
            if (is_array($scan)) {
                SiteHealth::cleanupBrowserProbe((string) ($scan['storage_file'] ?? ''), STORAGE_PATH);
                SiteHealth::cleanupUploadProbe((string) ($scan['upload_file'] ?? ''), UPLOADS_PATH);
            }
            unset($_SESSION['site_health_scan']);
            error(__('health_scan_expired'));
        }

        $observations = json_decode((string) post('observations'), true);
        if (!is_array($observations)) {
            $observations = [];
        }
        $media = is_array($scan['media'] ?? null) ? $scan['media'] : [];
        if (empty($media['done'])) {
            error(__('health_media_scan_incomplete'));
        }
        try {
            $checks = is_array($scan['checks'] ?? null) ? $scan['checks'] : [];
            $checks = array_merge(
                $checks,
                SiteHealth::evaluateBrowserProbes(
                    $observations,
                    (string) ($scan['storage_token'] ?? ''),
                    (string) ($scan['upload_token'] ?? '')
                ),
                [SiteHealth::mediaOptimizationResult($media)],
                [SiteHealth::checkUpdateService()]
            );
            $checks = SiteHealth::normalizeResults($checks);
            $summary = SiteHealth::summary($checks);
            $scannedAt = time();
            // 除计数外还要记下**哪几项**红了：只有计数的话，update 控制台上看到一片红
            // 也无从下手——「谁的 storage 在暴露」才是可执行的信息。只存 id，
            // 不存路径/正文，随心跳上报时也就不会带出站点内部信息。
            $badIds = [];
            foreach ($checks as $check) {
                if (in_array($check['status'], [SiteHealth::CRITICAL, SiteHealth::RECOMMENDED], true)) {
                    $badIds[] = ($check['status'] === SiteHealth::CRITICAL ? '!' : '~') . $check['id'];
                }
            }
            settingModel()->saveBatch([
                'site_health_last_summary' => (string) json_encode($summary, JSON_UNESCAPED_UNICODE),
                'site_health_last_at' => (string) $scannedAt,
                'site_health_last_bad' => mb_substr(implode(',', $badIds), 0, 500),
                'site_health_media_summary' => (string) json_encode([
                    'total' => max(0, (int) ($media['total'] ?? 0)),
                    'scanned' => max(0, (int) ($media['scanned'] ?? 0)),
                    'healthy' => max(0, (int) ($media['healthy'] ?? 0)),
                    'pending' => max(0, (int) ($media['pending'] ?? 0)),
                    'missing' => max(0, (int) ($media['missing'] ?? 0)),
                    'unsupported' => max(0, (int) ($media['unsupported'] ?? 0)),
                    'repairable' => max(0, (int) ($media['repairable'] ?? 0)),
                    'sample_ids' => MediaOptimization::normalizeIds($media['sample_ids'] ?? []),
                    'failed' => !empty($media['failed']),
                    'scanned_at' => $scannedAt,
                ], JSON_UNESCAPED_UNICODE),
            ]);
            adminLog(
                'site_health',
                'scan',
                sprintf(
                    'Site health scan: critical=%d recommended=%d good=%d unknown=%d',
                    $summary['critical'],
                    $summary['recommended'],
                    $summary['good'],
                    $summary['unknown']
                )
            );
        } finally {
            SiteHealth::cleanupBrowserProbe((string) ($scan['storage_file'] ?? ''), STORAGE_PATH);
                SiteHealth::cleanupUploadProbe((string) ($scan['upload_file'] ?? ''), UPLOADS_PATH);
            unset($_SESSION['site_health_scan']);
        }
        success(['checks' => $checks, 'summary' => $summary, 'scanned_at' => $scannedAt], '');
    }

    error(__('operation_failed'));
}

$tab = get('tab', 'status') === 'info' ? 'info' : 'status';
$lastSummary = json_decode((string) config('site_health_last_summary', ''), true);
if (!is_array($lastSummary)) {
    $lastSummary = ['critical' => 0, 'recommended' => 0, 'good' => 0, 'unknown' => 0, 'total' => 0];
}
$lastAt = (int) config('site_health_last_at', '0');
$diagnosticInfo = $tab === 'info' ? SiteHealth::diagnosticInfo() : [];
$pageTitle = __('health_title');
$currentMenu = 'site_health';

require_once ROOT_PATH . '/admin/includes/header.php';
unset($pageTitle);
?>

<div data-admin-page="<?php echo e($currentMenu); ?>">

<div class="mb-6 border-b border-gray-200">
    <nav class="flex gap-6" aria-label="<?php echo e(__('health_tabs_label')); ?>">
        <a href="/admin/site_health.php" class="py-3 text-sm font-medium border-b-2 <?php echo $tab === 'status' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-800'; ?>">
            <?php echo e(__('health_status_tab')); ?>
        </a>
        <a href="/admin/site_health.php?tab=info" class="py-3 text-sm font-medium border-b-2 <?php echo $tab === 'info' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-800'; ?>">
            <?php echo e(__('health_info_tab')); ?>
        </a>
    </nav>
</div>

<?php if ($tab === 'status'): ?>
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900"><?php echo e(__('health_title')); ?></h1>
        <p id="healthLastAt" class="mt-1 text-sm text-gray-500">
            <?php echo $lastAt > 0 ? e(__('health_last_scan') . ': ' . date('Y-m-d H:i', $lastAt)) : e(__('health_never_scanned')); ?>
        </p>
    </div>
    <button id="healthRun" type="button" class="inline-flex min-h-10 items-center justify-center gap-2 rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60">
        <i class="ti ti-shield-check text-lg" aria-hidden="true"></i>
        <span><?php echo e(__('health_run')); ?></span>
    </button>
</div>

<div id="healthSummary" class="grid grid-cols-2 lg:grid-cols-4 border border-gray-200 bg-white rounded-lg overflow-hidden mb-6">
    <?php foreach (['critical', 'recommended', 'good', 'unknown'] as $index => $status): ?>
    <div class="min-h-24 px-5 py-4 <?php echo $index % 2 === 0 ? 'border-r' : ''; ?> <?php echo $index < 2 ? 'border-b lg:border-b-0' : ''; ?> <?php echo $index > 0 ? 'lg:border-l' : ''; ?> border-gray-200">
        <p class="text-sm text-gray-500"><?php echo e(__('health_summary_' . $status)); ?></p>
        <p class="mt-2 text-2xl font-semibold text-gray-900" data-summary="<?php echo e($status); ?>"><?php echo (int) ($lastSummary[$status] ?? 0); ?></p>
    </div>
    <?php endforeach; ?>
</div>

<div id="healthNotice" class="hidden mb-6 rounded border px-4 py-3 text-sm" role="status" aria-live="polite"></div>

<div id="healthResults">
    <div id="healthEmpty" class="border border-dashed border-gray-300 rounded-lg bg-white px-6 py-12 text-center">
        <i class="ti ti-shield-search text-4xl text-gray-300" aria-hidden="true"></i>
        <h2 class="mt-3 text-base font-semibold text-gray-800"><?php echo e(__('health_empty_title')); ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?php echo e(__('health_empty_desc')); ?></p>
    </div>
</div>

<script>
(function () {
    'use strict';
    var runButton = document.getElementById('healthRun');
    var resultRoot = document.getElementById('healthResults');
    var notice = document.getElementById('healthNotice');
    var lastAt = document.getElementById('healthLastAt');
    var labels = <?php echo json_encode([
        'run' => __('health_run'),
        'running' => __('health_running'),
        'mediaRunning' => __('health_media_running'),
        'failed' => __('health_scan_failed'),
        'lastScan' => __('health_last_scan'),
        'action' => __('health_action'),
        'categories' => [
            'security' => __('health_category_security'),
            'updates' => __('health_category_updates'),
            'environment' => __('health_category_environment'),
            'operations' => __('health_category_operations'),
        ],
        'statuses' => [
            'critical' => __('health_status_critical'),
            'recommended' => __('health_status_recommended'),
            'good' => __('health_status_good'),
            'unknown' => __('health_status_unknown'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var statusStyles = {
        critical: ['bg-red-50', 'text-red-700', 'border-red-200', 'ti-circle-x'],
        recommended: ['bg-amber-50', 'text-amber-700', 'border-amber-200', 'ti-alert-triangle'],
        good: ['bg-green-50', 'text-green-700', 'border-green-200', 'ti-circle-check'],
        unknown: ['bg-gray-100', 'text-gray-600', 'border-gray-200', 'ti-help-circle']
    };

    function postAction(values) {
        var body = new URLSearchParams(values);
        body.set(<?php echo json_encode(CSRF_TOKEN_NAME); ?>, <?php echo json_encode(csrfToken()); ?>);
        return fetch('/admin/site_health.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload || payload.code !== 0) throw new Error(payload && payload.msg ? payload.msg : labels.failed);
                return payload.data;
            });
    }

    function setNotice(message, type) {
        notice.textContent = message;
        notice.className = 'mb-6 rounded border px-4 py-3 text-sm ' + (type === 'error'
            ? 'border-red-200 bg-red-50 text-red-700'
            : 'border-blue-200 bg-blue-50 text-blue-700');
    }

    function updateSummary(summary) {
        ['critical', 'recommended', 'good', 'unknown'].forEach(function (status) {
            var node = document.querySelector('[data-summary="' + status + '"]');
            if (node) node.textContent = String(summary && summary[status] ? summary[status] : 0);
        });
    }

    function createResultRow(check) {
        var style = statusStyles[check.status] || statusStyles.unknown;
        var row = document.createElement('div');
        row.className = 'flex flex-col sm:flex-row sm:items-start gap-3 px-5 py-4 border-t border-gray-100 first:border-t-0';
        row.dataset.healthId = check.id || '';

        var icon = document.createElement('i');
        icon.className = 'ti ' + style[3] + ' mt-0.5 text-xl ' + style[1];
        icon.setAttribute('aria-hidden', 'true');
        row.appendChild(icon);

        var content = document.createElement('div');
        content.className = 'min-w-0 flex-1';
        var titleLine = document.createElement('div');
        titleLine.className = 'flex flex-wrap items-center gap-2';
        var title = document.createElement('h3');
        title.className = 'text-sm font-semibold text-gray-900';
        title.textContent = check.title || check.id;
        var badge = document.createElement('span');
        badge.className = 'inline-flex rounded px-2 py-0.5 text-xs font-medium ' + style[0] + ' ' + style[1];
        badge.textContent = labels.statuses[check.status] || labels.statuses.unknown;
        titleLine.appendChild(title);
        titleLine.appendChild(badge);
        content.appendChild(titleLine);
        var description = document.createElement('p');
        description.className = 'mt-1 text-sm leading-6 text-gray-600';
        description.textContent = check.description || '';
        content.appendChild(description);
        row.appendChild(content);

        if (check.action_url) {
            var action = document.createElement('a');
            action.href = check.action_url;
            action.className = 'inline-flex self-start shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline';
            action.textContent = labels.action;
            var arrow = document.createElement('i');
            arrow.className = 'ti ti-chevron-right';
            arrow.setAttribute('aria-hidden', 'true');
            action.appendChild(arrow);
            row.appendChild(action);
        }
        return row;
    }

    function renderResults(checks) {
        resultRoot.replaceChildren();
        var grouped = {};
        (checks || []).forEach(function (check) {
            var category = labels.categories[check.category] ? check.category : 'environment';
            if (!grouped[category]) grouped[category] = [];
            grouped[category].push(check);
        });
        ['security', 'updates', 'environment', 'operations'].forEach(function (category) {
            if (!grouped[category] || grouped[category].length === 0) return;
            var section = document.createElement('section');
            section.className = 'mb-6 overflow-hidden rounded-lg border border-gray-200 bg-white';
            var heading = document.createElement('h2');
            heading.className = 'border-b border-gray-200 bg-gray-50 px-5 py-3 text-sm font-semibold text-gray-800';
            heading.textContent = labels.categories[category];
            section.appendChild(heading);
            grouped[category].forEach(function (check) { section.appendChild(createResultRow(check)); });
            resultRoot.appendChild(section);
        });
    }

    function observeProbe(probe) {
        return fetch(probe.url, { method: probe.method, cache: 'no-store', credentials: 'same-origin', redirect: 'manual' })
            .then(function (response) {
                return response.text().then(function (body) {
                    return { id: probe.id, status: response.status, body: body.slice(0, 1024), error: false };
                });
            })
            .catch(function () { return { id: probe.id, status: 0, body: '', error: true }; });
    }

    function scanMediaBatches(nonce, state) {
        var current = state || { scanned: 0, total: 0, done: false };
        setNotice(labels.mediaRunning
            .replace(':scanned', String(current.scanned || 0))
            .replace(':total', String(current.total || 0)), 'info');
        if (current.done) return Promise.resolve(current);

        return postAction({ action: 'scan_media', nonce: nonce }).then(function (next) {
            return next.done ? next : scanMediaBatches(nonce, next);
        });
    }

    runButton.addEventListener('click', function () {
        runButton.disabled = true;
        runButton.querySelector('span').textContent = labels.running;
        runButton.querySelector('i').className = 'ti ti-loader-2 animate-spin text-lg';
        setNotice(labels.running, 'info');
        postAction({ action: 'start_scan' })
            .then(function (data) {
                renderResults(data.checks);
                return Promise.all([
                    Promise.all((data.probes || []).map(observeProbe)),
                    scanMediaBatches(data.nonce, data.media)
                ]).then(function (results) {
                    return postAction({ action: 'finish_scan', nonce: data.nonce, observations: JSON.stringify(results[0]) });
                });
            })
            .then(function (data) {
                renderResults(data.checks);
                updateSummary(data.summary);
                var date = new Date(data.scanned_at * 1000);
                lastAt.textContent = labels.lastScan + ': ' + date.toLocaleString();
                notice.className = 'hidden';
            })
            .catch(function (error) { setNotice(error.message || labels.failed, 'error'); })
            .finally(function () {
                runButton.disabled = false;
                runButton.querySelector('span').textContent = labels.run;
                runButton.querySelector('i').className = 'ti ti-shield-check text-lg';
            });
    });
}());
</script>
<?php else: ?>
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900"><?php echo e(__('health_info_title')); ?></h1>
        <p class="mt-1 text-sm text-gray-500"><?php echo e(__('health_info_desc')); ?></p>
    </div>
    <button id="healthCopy" type="button" class="inline-flex min-h-10 items-center justify-center gap-2 rounded border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
        <i class="ti ti-copy text-lg" aria-hidden="true"></i>
        <span><?php echo e(__('health_info_copy')); ?></span>
    </button>
</div>

<div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
    <dl class="divide-y divide-gray-100">
        <?php foreach ($diagnosticInfo as $key => $value): ?>
        <div class="grid gap-1 px-5 py-4 sm:grid-cols-[14rem_minmax(0,1fr)] sm:gap-6">
            <dt class="text-sm font-medium text-gray-600"><?php echo e(__('health_info_' . $key)); ?></dt>
            <dd class="break-words font-mono text-sm text-gray-900"><?php echo e(SiteHealth::formatDiagnosticValue((string) $key, $value)); ?></dd>
        </div>
        <?php endforeach; ?>
    </dl>
</div>

<script>
(function () {
    var button = document.getElementById('healthCopy');
    // 复制的是「标签: 人能读的值」，与页面上看到的逐行一致。
    // 之前复制出去的是 JSON，扩展那两行会变成 {"pdo":true,...}，技术支持还得自己解析。
    var info = <?php
        $__copy = [];
        foreach ($diagnosticInfo as $__k => $__v) {
            $__copy[] = __('health_info_' . $__k) . ': ' . SiteHealth::formatDiagnosticValue((string) $__k, $__v);
        }
        echo json_encode(implode("\n", $__copy), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    ?>;
    button.addEventListener('click', function () {
        navigator.clipboard.writeText(info).then(function () {
            button.querySelector('span').textContent = <?php echo json_encode(__('health_info_copied'), JSON_UNESCAPED_UNICODE); ?>;
            window.setTimeout(function () { button.querySelector('span').textContent = <?php echo json_encode(__('health_info_copy'), JSON_UNESCAPED_UNICODE); ?>; }, 1600);
        });
    });
}());
</script>
<?php endif; ?>

</div>
<?php unset($currentMenu); ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
