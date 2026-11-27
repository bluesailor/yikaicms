<?php
/**
 * Yikai CMS - 批量翻译任务（评审 T03，第一条切片：产品）
 *
 * 产品列表勾选 → 选语言 → 在这里逐项用 AI 翻成草稿。页面按批调用 step，关掉即停，
 * 回来点「继续」接着跑；生成结果先落库，中断后不重新调模型。「翻译缺失项」只新建缺的译文；
 * 「更新过期译文」（T04）只覆盖机器写入、没人改过的草稿，其余存为待审译文。
 * 状态机与恢复规则见 includes/TranslationBatches.php。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/TranslationBatches.php';
require_once ROOT_PATH . '/includes/Cron.php';

checkLogin();
requirePermission('edit_product');
if (!canUseAdminTranslation()) permissionDenied();

$currentMenu = 'translation_tasks';
$pageTitle = __('tbt_title');
$tablesReady = db()->tableExists('translation_batches') && db()->tableExists('translation_batch_items') && db()->tableExists('translation_batch_events');
$service = new TranslationBatches(db());
$languages = enabledLanguages();
$defaultLang = (string) config('site_lang', 'zh-CN');

/** 页面上一项的显示数据（不含生成结果全文） @param array<string,mixed> $item @return array<string,mixed> */
$itemView = static function (array $item) use ($languages): array {
    $error = (string) $item['error'];
    return [
        'id' => (int) $item['id'], 'source_id' => (int) $item['source_id'], 'source_title' => (string) ($item['source_title'] ?? ''),
        'lang' => (string) $item['lang'], 'lang_name' => (string) ($languages[$item['lang']] ?? $item['lang']),
        'status' => (string) $item['status'], 'attempts' => (int) $item['attempts'], 'target_id' => (int) $item['target_id'],
        'note' => in_array($error, ['source_missing', 'source_changed', 'target_missing'], true) ? __('tbt_err_' . $error) : $error,
    ];
};

/**
 * 批次的用量与暂停原因（2.3.1）：生成次数、模型请求数 / 上限；升级前的批次请求数补不出来，显示「未知」。
 * 因额度暂停时说明「开始 / 继续」会把上限提到多少。
 * @param array<string,mixed> $batch @param array<string,int> $counts @return array{usage:string,pause:string,pause_kind:string}
 */
$batchView = static function (array $batch, array $counts): array {
    $requests = (int) ($batch['requests'] ?? 0);
    $max = (int) $batch['max_calls'];
    $usage = __((int) ($batch['requests_known'] ?? 1) === 1 ? 'tbt_usage' : 'tbt_usage_unknown', [
        'attempts' => (string) (int) $batch['calls'], 'requests' => (string) $requests, 'max' => (string) $max,
    ]);
    $kind = '';
    $pause = '';
    if ((string) $batch['status'] === 'paused') {
        $open = 0;
        foreach (TranslationBatches::OPEN as $status) $open += (int) ($counts[$status] ?? 0);
        $kind = (string) $batch['note'] === TranslationBatches::NOTE_BUDGET ? 'budget' : 'manual';
        // 与 TranslationBatches::resume() 的算法一致：已用 + 剩余项 × 每项额度
        $next = ['next' => (string) ($requests + $open * TranslationBatches::REQUESTS_PER_ITEM), 'open' => (string) $open, 'per' => (string) TranslationBatches::REQUESTS_PER_ITEM];
        // 升级前因额度暂停的批次：当时的上限按生成次数算，说明这一点，不显示「请求已用到上限」
        $pause = match (true) {
            $kind === 'manual' => __('tbt_pause_manual'),
            (int) ($batch['requests_known'] ?? 1) === 1 => __('tbt_pause_budget', ['requests' => (string) $requests, 'max' => (string) $max] + $next),
            default => __('tbt_pause_budget_legacy', ['attempts' => (string) (int) $batch['calls'], 'max' => (string) $max] + $next),
        };
    }
    return ['usage' => $usage, 'pause' => $pause, 'pause_kind' => $kind];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tablesReady) error(__('tbt_need_upgrade'));
    $action = (string) ($_POST['action'] ?? '');
    $batchId = (int) ($_POST['batch_id'] ?? 0);
    $now = time();

    if ($action === 'create') {
        $langs = array_values(array_intersect(array_map('strval', (array) ($_POST['langs'] ?? [])), array_keys($languages)));
        $langs = array_values(array_filter($langs, static fn (string $lang): bool => $lang !== $defaultLang));
        if ($langs === []) error(__('tbt_err_langs'));
        require_once ROOT_PATH . '/includes/AiService.php';
        if (!aiService()->isConfigured()) error(__('tbt_err_ai'));
        $ids = array_slice(array_map('intval', (array) ($_POST['ids'] ?? [])), 0, 500);
        try {
            $created = $service->create(getAdminId(), $ids, $langs, $defaultLang, $now, ($_POST['mode'] ?? '') === 'update' ? 'update' : 'missing');
        } catch (InvalidArgumentException) {
            error(__('tbt_err_empty'));
        }
        if ($created['total'] === 0) error(__($created['not_outdated'] > 0 ? 'tss_nothing_outdated' : 'tbt_err_empty'));
        // 立即在后台开跑（不等结果）；没配站点地址或连不上时，本页与定时任务照常接着跑
        Cron::kick('translation_batches');
        adminLog('translate', 'batch_create', "批量翻译 #{$created['batch_id']}：{$created['total']} 项");
        success(['id' => $created['batch_id']], __('tbt_created', [
            'total' => (string) $created['total'], 'elsewhere' => (string) $created['queued_elsewhere'], 'skipped' => (string) ($created['not_source'] + $created['not_outdated']),
        ]));
    }

    if ($service->batch($batchId) === null) error(__('tbt_none'));
    $counts = null;
    $progress = true;
    if ($action === 'step') {
        // 一项可能要调好几次模型；先放开会话锁，别让同一账号的其它后台页面跟着卡住
        session_write_close();
        // 这一轮有没有动过任何一项：没动（都在失败后的等待期，或被后台执行器领着）时前端放慢轮询
        $mark = static fn (): string => json_encode(array_map(static fn (array $i): array => [$i['status'], $i['attempts']], $service->items($batchId)), JSON_THROW_ON_ERROR);
        $before = $mark();
        $counts = $service->step($batchId, 20);
        $progress = $before !== $mark();
    } elseif ($action === 'status') {
        // 只读：后台执行器在跑时页面定时刷新进度，不领项、不调模型
    } elseif ($action === 'retry') {
        if ($service->retry($batchId, $now) === 0) error(__('tbt_nothing_to_retry'));
    } elseif ($action === 'pause') {
        $service->pause($batchId, $now);
    } elseif ($action === 'resume') {
        $service->resume($batchId, $now);
    } elseif ($action === 'cancel') {
        $service->cancel($batchId, $now);
    } else {
        error(__('operation_failed'));
    }
    $current = $service->batch($batchId) ?? [];
    $allCounts = $service->counts($batchId);
    success([
        'status' => (string) ($current['status'] ?? ''),
        'progress' => $progress,
        'counts' => $counts ?? $allCounts,
        'items' => array_map($itemView, $service->items($batchId)),
    ] + ($current !== [] ? $batchView($current, $allCounts) : []), '');
}

$batchId = (int) ($_GET['id'] ?? 0);
$batch = $tablesReady && $batchId > 0 ? $service->batch($batchId) : null;
$batches = $tablesReady && $batch === null ? $service->recent(30) : [];
$statusLabels = [];
foreach (['pending', 'generating', 'generated', 'applied', 'proposed', 'exists', 'conflict', 'failed', 'cancelled', 'running', 'paused', 'done'] as $status) {
    $statusLabels[$status] = __('tbt_st_' . $status);
}
$langNames = static fn (string $json): string => implode(', ', array_map(static fn (string $lang): string => (string) ($languages[$lang] ?? $lang), (array) json_decode($json, true)));

// 后台执行（CORE-01）：计划任务 10 分钟内调用过本站（心跳），关掉本页也会继续。
// 看心跳而不看 translation_batches 的上次运行：建批后的立即触发也会跑这个任务，但它不代表定时任务配好了
$workerLast = (int) settingModel()->get('cron_heartbeat', '0');
$workerOn = $workerLast > 0 && $workerLast >= time() - 600;
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6 max-w-6xl">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><?php echo e(__('tbt_title')); ?></h1>
        <p class="text-sm text-gray-500 mt-1 max-w-3xl"><?php echo e(__('tbt_intro')); ?></p>
        <p class="text-sm mt-1 max-w-3xl <?php echo $workerOn ? 'text-green-700' : 'text-amber-700'; ?>" data-tbt-worker="<?php echo $workerOn ? 'on' : 'off'; ?>"><?php echo e($workerOn ? __('tbt_worker_on', ['time' => date('H:i', $workerLast)]) : __('tbt_worker_off')); ?></p>
        <p class="text-sm mt-1"><a class="text-primary hover:underline" href="<?php echo e(BasePath::url('/admin/translation_status.php')); ?>"><?php echo e(__('tss_title')); ?></a></p>
    </div>

    <?php if (!$tablesReady): ?>
    <div class="bg-amber-50 border-l-4 border-amber-400 text-amber-800 text-sm px-4 py-3" role="alert"><?php echo e(__('tbt_need_upgrade')); ?></div>
    <?php elseif ($batch === null): ?>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <?php if ($batches === []): ?>
        <p class="px-6 py-8 text-sm text-gray-500"><?php echo e(__('tbt_none')); ?></p>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2"><?php echo e(rtrim(__('tbt_batch', ['id' => '']), ' #')); ?></th>
                    <th class="px-4 py-2"><?php echo e(__('tbt_langs')); ?></th>
                    <th class="px-4 py-2"><?php echo e(__('tbt_col_status')); ?></th>
                    <th class="px-4 py-2"><?php echo e(__('admin_created_at')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batches as $row): ?>
                <tr class="border-t">
                    <td class="px-4 py-2"><a class="text-primary hover:underline" href="?id=<?php echo (int) $row['id']; ?>"><?php echo e(__('tbt_batch', ['id' => (string) $row['id']])); ?></a> · <?php echo (int) $row['total']; ?></td>
                    <td class="px-4 py-2"><?php echo e($langNames((string) $row['langs'])); ?></td>
                    <td class="px-4 py-2"><?php echo e($statusLabels[$row['status']] ?? (string) $row['status']); ?></td>
                    <td class="px-4 py-2 text-gray-500"><?php echo e(date('Y-m-d H:i', (int) $row['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <p class="mb-4 text-sm"><a class="text-primary hover:underline" href="<?php echo e(BasePath::url('/admin/translation_tasks.php')); ?>">← <?php echo e(__('tbt_back')); ?></a></p>
    <div class="bg-white rounded-lg shadow p-5 mb-4" id="tbtBatch" data-batch="<?php echo (int) $batch['id']; ?>">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-gray-800"><?php echo e(__('tbt_batch', ['id' => (string) $batch['id']])); ?></h2>
                <p class="text-sm text-gray-500"><?php echo e(__('tbt_langs')); ?>：<?php echo e($langNames((string) $batch['langs'])); ?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" id="tbtRun" onclick="tbtRun()" class="bg-primary text-white px-4 py-1.5 rounded text-sm"><?php echo e(__('tbt_run')); ?></button>
                <button type="button" id="tbtPause" onclick="tbtPause()" class="border px-4 py-1.5 rounded text-sm"><?php echo e(__('tbt_pause')); ?></button>
                <button type="button" onclick="tbtAction('retry')" class="border px-4 py-1.5 rounded text-sm"><?php echo e(__('tbt_retry')); ?></button>
                <button type="button" data-confirm="<?php echo e(__('tbt_confirm_again')); ?>" onclick="tbtCancel(this)" class="border border-red-200 text-red-600 px-4 py-1.5 rounded text-sm"><?php echo e(__('tbt_cancel')); ?></button>
            </div>
        </div>
        <p id="tbtSummary" class="mt-3 text-sm text-gray-600" aria-live="polite"></p>
        <div class="mt-2 h-2 bg-gray-100 rounded" aria-hidden="true"><div id="tbtBar" class="h-2 bg-primary rounded" style="width:0"></div></div>
        <?php // 2.3.1：生成次数与模型请求数 / 上限，暂停原因与继续后的新上限；由脚本随每次刷新更新 ?>
        <p id="tbtUsage" class="mt-2 text-xs text-gray-500" data-tbt-usage></p>
        <p id="tbtPauseReason" class="mt-2 text-sm px-3 py-2 border-l-4" role="status" hidden></p>
    </div>
    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-500">
                <tr>
                    <th class="px-4 py-2"><?php echo e(__('tbt_col_product')); ?></th>
                    <th class="px-4 py-2"><?php echo e(__('tbt_col_lang')); ?></th>
                    <th class="px-4 py-2"><?php echo e(__('tbt_col_status')); ?></th>
                    <th class="px-4 py-2"><?php echo e(__('tbt_col_note')); ?></th>
                </tr>
            </thead>
            <tbody id="tbtRows"></tbody>
        </table>
    </div>
    <?php $events = $service->events((int) $batch['id']); if ($events !== []): ?>
    <details class="bg-white rounded-lg shadow mt-4">
        <summary class="px-4 py-3 text-sm font-medium text-gray-700 cursor-pointer"><?php echo e(__('tbt_log')); ?></summary>
        <ul class="px-4 pb-3 text-sm text-gray-600 space-y-1">
            <?php foreach ($events as $event): ?>
            <li><span class="text-gray-400"><?php echo e(date('m-d H:i:s', (int) $event['created_at'])); ?></span>
                <?php echo e(__('tbt_ev_' . $event['event'])); ?><?php if ((int) $event['item_id'] > 0): ?> · #<?php echo (int) $event['item_id']; ?><?php endif; ?>
                <?php if ((string) $event['detail'] !== ''): ?><span class="text-gray-500">— <?php echo e((string) $event['detail']); ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
    <script>
    const tbtLabels = <?php echo json_encode($statusLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const tbtText = <?php echo json_encode(['open' => __('tbt_open'), 'review' => __('tbt_review'), 'running' => __('tbt_running')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const tbtEditUrl = <?php echo json_encode(BasePath::url('/admin/product_edit.php?id='), JSON_UNESCAPED_SLASHES); ?>;
    const tbtOpen = ['pending', 'generating', 'generated'];
    let tbtState = <?php $initialCounts = $service->counts((int) $batch['id']); echo json_encode(['status' => (string) $batch['status'], 'counts' => (object) $initialCounts,
        'items' => array_map($itemView, $service->items((int) $batch['id']))] + $batchView($batch, $initialCounts), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    let tbtLoop = false;

    function tbtCell(text, link) {
        const td = document.createElement('td');
        td.className = 'px-4 py-2';
        if (link) {
            const a = document.createElement('a');
            a.href = link; a.className = 'text-primary hover:underline'; a.textContent = text;
            td.append(a);
        } else td.textContent = text;
        return td;
    }
    function tbtRender() {
        const rows = document.getElementById('tbtRows');
        rows.replaceChildren(...tbtState.items.map((item) => {
            const tr = document.createElement('tr');
            tr.className = 'border-t';
            tr.append(
                tbtCell(item.source_title || ('#' + item.source_id), tbtEditUrl + item.source_id),
                tbtCell(item.lang_name),
                tbtCell(tbtLabels[item.status] || item.status),
                item.target_id && !tbtOpen.includes(item.status) ? tbtCell(item.status === 'proposed' ? tbtText.review : tbtText.open, tbtEditUrl + item.target_id) : tbtCell(item.note || ''),
            );
            return tr;
        }));
        const total = tbtState.items.length;
        const open = tbtState.items.filter((item) => tbtOpen.includes(item.status)).length;
        const parts = Object.entries(tbtState.counts).map(([status, n]) => (tbtLabels[status] || status) + ' ' + n);
        document.getElementById('tbtSummary').textContent = (tbtLoop ? tbtText.running + ' · ' : (tbtLabels[tbtState.status] || tbtState.status) + ' · ') + parts.join(' · ');
        document.getElementById('tbtBar').style.width = (total ? Math.round((total - open) * 100 / total) : 100) + '%';
        document.getElementById('tbtUsage').textContent = tbtState.usage || '';
        const reason = document.getElementById('tbtPauseReason');
        const paused = !tbtLoop && tbtState.status === 'paused' && !!tbtState.pause;
        reason.hidden = !paused;
        reason.textContent = paused ? tbtState.pause : '';
        reason.dataset.kind = paused ? tbtState.pause_kind : '';
        reason.className = 'mt-2 text-sm px-3 py-2 border-l-4 ' + (tbtState.pause_kind === 'budget' ? 'bg-amber-50 border-amber-400 text-amber-800' : 'bg-gray-50 border-gray-300 text-gray-700');
        document.getElementById('tbtRun').disabled = tbtLoop || open === 0 || tbtState.status === 'cancelled';
        // 后台执行器在跑时页面没在循环，也要能暂停
        document.getElementById('tbtPause').disabled = !tbtLoop && tbtState.status !== 'running';
    }
    async function tbtPost(action) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('batch_id', document.getElementById('tbtBatch').dataset.batch);
        const response = await fetch(location.pathname, { method: 'POST', body: fd });
        const data = await safeJson(response);
        if (data.code !== 0) throw new Error(data.msg || 'error');
        tbtState = data.data;
        tbtRender();
        return tbtState;
    }
    async function tbtRun() {
        tbtLoop = true;
        tbtRender();
        try {
            if (tbtState.status === 'paused' || tbtState.status === 'done') await tbtPost('resume');
            while (tbtLoop) {
                const state = await tbtPost('step');
                if (state.status !== 'running' || !state.items.some((item) => tbtOpen.includes(item.status) && item.status !== 'generating')) break;
                // 这一轮什么都没动（失败后的等待期，或后台执行器正领着）：等 5 秒再问，不连着发请求
                if (!state.progress) await new Promise((resolve) => setTimeout(resolve, 5000));
            }
        } catch (e) {
            showMessage(e.message, 'error');
        }
        tbtLoop = false;
        tbtRender();
    }
    async function tbtPause() {
        tbtLoop = false;
        try { await tbtPost('pause'); } catch (e) { showMessage(e.message, 'error'); }
    }
    async function tbtAction(action) {
        try { await tbtPost(action); } catch (e) { showMessage(e.message, 'error'); }
    }
    // 取消用两步按钮：第一次点击只把文字换成确认语
    function tbtCancel(button) {
        if (button.dataset.armed !== '1') {
            button.dataset.armed = '1';
            button.dataset.label = button.textContent;
            button.textContent = button.dataset.confirm;
            setTimeout(() => { button.dataset.armed = ''; button.textContent = button.dataset.label; }, 5000);
            return;
        }
        tbtLoop = false;
        tbtAction('cancel');
    }
    tbtRender();
    // 后台执行（定时任务 / 建批后立即触发）在跑时，本页不点「开始」也每 5 秒刷新一次进度
    setInterval(() => {
        if (tbtLoop || document.hidden || tbtState.status !== 'running') return;
        tbtPost('status').catch(() => {});
    }, 5000);
    </script>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
