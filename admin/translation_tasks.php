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

checkLogin();
requirePermission('edit_product');

$currentMenu = 'translation_tasks';
$pageTitle = __('tbt_title');
$tablesReady = db()->tableExists('translation_batches') && db()->tableExists('translation_batch_items');
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
        adminLog('translate', 'batch_create', "批量翻译 #{$created['batch_id']}：{$created['total']} 项");
        success(['id' => $created['batch_id']], __('tbt_created', [
            'total' => (string) $created['total'], 'elsewhere' => (string) $created['queued_elsewhere'], 'skipped' => (string) ($created['not_source'] + $created['not_outdated']),
        ]));
    }

    if ($service->batch($batchId) === null) error(__('tbt_none'));
    $counts = null;
    if ($action === 'step') {
        // 一项可能要调好几次模型；先放开会话锁，别让同一账号的其它后台页面跟着卡住
        session_write_close();
        $counts = $service->step($batchId, 20);
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
    success([
        'status' => (string) ($service->batch($batchId)['status'] ?? ''),
        'counts' => $counts ?? $service->counts($batchId),
        'items' => array_map($itemView, $service->items($batchId)),
    ], '');
}

$batchId = (int) ($_GET['id'] ?? 0);
$batch = $tablesReady && $batchId > 0 ? $service->batch($batchId) : null;
$batches = $tablesReady && $batch === null ? $service->recent(30) : [];
$statusLabels = [];
foreach (['pending', 'generating', 'generated', 'applied', 'proposed', 'exists', 'conflict', 'failed', 'cancelled', 'running', 'paused', 'done'] as $status) {
    $statusLabels[$status] = __('tbt_st_' . $status);
}
$langNames = static fn (string $json): string => implode(', ', array_map(static fn (string $lang): string => (string) ($languages[$lang] ?? $lang), (array) json_decode($json, true)));

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6 max-w-6xl">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><?php echo e(__('tbt_title')); ?></h1>
        <p class="text-sm text-gray-500 mt-1 max-w-3xl"><?php echo e(__('tbt_intro')); ?></p>
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
    <script>
    const tbtLabels = <?php echo json_encode($statusLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const tbtText = <?php echo json_encode(['open' => __('tbt_open'), 'review' => __('tbt_review'), 'running' => __('tbt_running')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const tbtEditUrl = <?php echo json_encode(BasePath::url('/admin/product_edit.php?id='), JSON_UNESCAPED_SLASHES); ?>;
    const tbtOpen = ['pending', 'generating', 'generated'];
    let tbtState = { status: <?php echo json_encode((string) $batch['status']); ?>, counts: <?php echo json_encode((object) $service->counts((int) $batch['id'])); ?>, items: <?php echo json_encode(array_map($itemView, $service->items((int) $batch['id'])), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?> };
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
        document.getElementById('tbtRun').disabled = tbtLoop || open === 0 || tbtState.status === 'cancelled';
        document.getElementById('tbtPause').disabled = !tbtLoop;
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
    </script>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
