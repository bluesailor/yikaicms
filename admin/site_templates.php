<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/SiteTemplateService.php';
require_once ROOT_PATH . '/includes/SiteImportReport.php';
checkLogin();
requirePermission('*');
$service = new SiteTemplateService(ROOT_PATH);
$errorMessage = '';
$exportCheck = null;
$notice = (string) ($_SESSION['site_template_notice'] ?? '');
unset($_SESSION['site_template_notice']);
$brand = ['site_name' => (string) config('site_name'), 'contact_phone' => '', 'contact_email' => '', 'contact_address' => ''];
// 导入完成后实时生成报告（不落库）：随时可重跑，也不会出现过期结论
$report = null;
if (($notice === 'st_applied' || get('report') === '1') && $service->recovery() !== null) {
    $report = SiteImportReport::build(ROOT_PATH);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $action = post('action');
        if ($action === 'export') {
            $exportCheck = $service->exportCheck();
            if ($exportCheck['blocked'] !== '') throw new RuntimeException($exportCheck['blocked']);
            if (($exportCheck['issues'] !== [] || $exportCheck['limited']) && post('confirm_export') !== '1') throw new RuntimeException('st_export_review');
            $temporary = tempnam(sys_get_temp_dir(), 'yk-export-');
            if ($temporary === false) throw new RuntimeException('st_storage');
            try {
                $exportSummary = $service->export($temporary);
                adminLog('theme', 'export', 'Site template exported: ' . (string) $exportSummary['theme']);
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="yikai-site-template-' . date('Ymd-His') . '.zip"');
                header('Content-Length: ' . (string) filesize($temporary));
                header('Cache-Control: no-store');
                readfile($temporary);
            } finally { @unlink($temporary); }
            exit;
        }
        if ($action === 'stage') {
            // 分阶段提取（E03）：每次只处理一批，前端按返回的进度继续调用。
            // 服务端决定推进节奏，客户端不能指定处理哪些条目或跳到哪个阶段。
            success($service->stage(post('token'), getAdminId()));
        }
        if ($action === 'check_export') {
            $exportCheck = $service->exportCheck();
        } elseif ($action === 'prepare') {
            $upload = $_FILES['package'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) throw new RuntimeException('st_upload');
            $_SESSION['site_template_preview'] = $service->prepare((string) $upload['tmp_name'], getAdminId());
        } elseif ($action === 'apply') {
            foreach ($brand as $key => $_value) $brand[$key] = post($key);
            $service->apply(post('token'), getAdminId(), $brand, post('trusted') === '1' && post('confirm') === '1');
            unset($_SESSION['site_template_preview']);
            // 整站替换会清空并重写 28 张内容表：不留痕就无从追查谁在何时做的
            adminLog('theme', 'import', 'Site template applied');
            $_SESSION['site_template_notice'] = 'st_applied';
        } elseif ($action === 'restore' && post('confirm') === '1') {
            $service->restore();
            unset($_SESSION['site_template_preview']);
            adminLog('theme', 'import', 'Site template restored to pre-import snapshot');
            $_SESSION['site_template_notice'] = 'st_restored';
        } else { throw new RuntimeException('st_invalid'); }
        if ($action !== 'check_export') redirect('/admin/site_templates.php');
    } catch (Throwable $error) {
        $code = $error->getMessage();
        $errorMessage = __(preg_match('/^st_[a-z_]+$/D', $code) ? $code : 'st_invalid');
    }
}
$fresh = false;
$recovery = null;
try { $fresh = $service->canApply(); $recovery = $service->recovery(); }
catch (Throwable $error) { $errorMessage = __('st_storage'); }
$preview = $_SESSION['site_template_preview'] ?? null;
$pageTitle = __('st_title');
$currentMenu = 'site_setup';
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="max-w-4xl space-y-6">
    <?php require ROOT_PATH . '/admin/includes/setting_sources_notice.php'; ?>
    <header><a href="/admin/site_setup.php" class="text-primary underline"><?= e(__('setup_title')) ?></a>
        <h1 class="text-2xl font-bold text-gray-800 mt-2"><?= e($pageTitle) ?></h1>
        <p class="text-gray-600 mt-2"><?= e(__('st_intro')) ?></p>
        <?php // 整站包是覆盖整个站点的；只想复用一个页面或区块的人该去 Blox 模板库 ?>
        <p class="text-gray-600 mt-1 text-sm"><?= e(__('st_scope_note')) ?>
            <a href="/admin/blox_templates.php" class="text-primary hover:underline"><?= e(__('admin_blox_templates')) ?></a>
        </p></header>
    <?php if ($errorMessage !== ''): ?><p role="alert" class="bg-red-50 text-red-700 p-4 rounded"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if (in_array($notice, ['st_applied', 'st_restored'], true)): ?><p role="status" class="bg-green-50 text-green-700 p-4 rounded"><?= e(__($notice)) ?></p><?php endif; ?>

    <?php if ($report !== null): ?>
    <?php
    $reportTone = [
        SiteImportReport::FAILED => 'border-red-200 bg-red-50 text-red-700',
        SiteImportReport::WARNING => 'border-amber-200 bg-amber-50 text-amber-700',
        SiteImportReport::OK => 'border-green-200 bg-green-50 text-green-700',
    ][$report['status']] ?? 'border-gray-200 bg-gray-50 text-gray-700';
    ?>
    <section class="rounded border p-4 space-y-3 <?= e($reportTone) ?>" aria-labelledby="ir-title">
        <h2 id="ir-title" class="font-bold"><?= e(__('ir_title')) ?></h2>
        <p class="text-sm"><?= e(__('ir_status_' . $report['status'])) ?></p>
        <p class="text-xs"><?= e(__('ir_scanned', [
            'scanned' => (string) $report['scanned'],
            'failed' => (string) ($report['counts'][SiteImportReport::FAILED] ?? 0),
            'warning' => (string) ($report['counts'][SiteImportReport::WARNING] ?? 0),
        ])) ?><?= $report['limited'] ? ' ' . e(__('ir_limited')) : '' ?></p>
        <?php if ($report['items'] !== []): ?>
        <ul class="space-y-1 bg-white rounded p-3 text-sm text-gray-700">
            <?php foreach ($report['items'] as $item): ?>
            <li class="flex flex-wrap items-baseline gap-2 border-b last:border-0 py-1">
                <span class="text-xs px-1.5 py-0.5 rounded <?= $item['level'] === SiteImportReport::FAILED ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' ?>"><?= e(__('ir_level_' . $item['level'])) ?></span>
                <span><?= e(__($item['code'])) ?></span>
                <?php if ($item['label'] !== ''): ?><span class="text-gray-500"><?= e($item['label']) ?></span><?php endif; ?>
                <?php if ($item['detail'] !== ''): ?><code class="text-xs text-gray-500 break-all"><?= e($item['detail']) ?></code><?php endif; ?>
                <?php if ($item['url'] !== ''): ?><a class="text-primary hover:underline text-xs ml-auto" href="<?= e($item['url']) ?>"><?= e(__('ir_fix')) ?></a><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <a href="/admin/site_templates.php?report=1" class="inline-block text-xs text-primary hover:underline"><?= e(__('ir_recheck')) ?></a>
    </section>
    <?php endif; ?>
    <section class="bg-white rounded-lg shadow p-6" aria-labelledby="st-export">
        <h2 id="st-export" class="text-lg font-bold"><?= e(__('st_export_title')) ?></h2>
        <p class="text-gray-600 mt-2"><?= e(__('st_export_hint')) ?></p>
        <p class="text-sm text-gray-600 mt-2"><?= e(__('st_scope')) ?></p>
        <p class="text-sm text-gray-600 mt-2"><?= e(__('usability_export_scope')) ?></p>
        <form method="post" class="mt-4"><?= csrfField() ?><input type="hidden" name="action" value="check_export">
            <button type="submit" class="border rounded px-4 py-3"><?= e(__('usability_export_check')) ?></button></form>
        <?php if ($exportCheck !== null): ?>
        <div role="status" class="mt-4 space-y-3" data-testid="export-check-report">
            <?php if ($exportCheck['blocked'] !== ''): ?>
            <p class="bg-red-50 text-red-700 p-4 rounded"><?= e(__('usability_export_blocked')) ?> <?= e(__($exportCheck['blocked'])) ?></p>
            <?php else: ?>
            <p><?= e(__('usability_export_checked', ['count' => (string) $exportCheck['scanned']])) ?></p>
            <?php foreach ($exportCheck['issues'] as $exportIssue): ?>
            <p class="bg-amber-50 text-amber-900 p-4 rounded"><?= e(__($exportIssue['code'])) ?> — <?= e($exportIssue['label']) ?> <code class="break-all"><?= e($exportIssue['detail']) ?></code>
                <a href="<?= e($exportIssue['url']) ?>" class="text-primary underline"><?= e(__('ir_fix')) ?></a></p>
            <?php endforeach; ?>
            <?php if ($exportCheck['limited']): ?><p><?= e(__('ir_limited')) ?></p><?php endif; ?>
            <form method="post" class="space-y-3"><?= csrfField() ?><input type="hidden" name="action" value="export">
                <?php if ($exportCheck['issues'] !== [] || $exportCheck['limited']): ?><label class="block"><input type="checkbox" name="confirm_export" value="1" required> <?= e(__('usability_export_confirm')) ?></label><?php endif; ?>
                <button type="submit" class="border rounded px-4 py-3"><?= e(__('st_export')) ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <section class="bg-white rounded-lg shadow p-6 space-y-4" aria-labelledby="st-import">
        <h2 id="st-import" class="text-lg font-bold"><?= e(__('st_import_title')) ?></h2>
        <p class="text-gray-600"><?= e(__('st_import_hint')) ?></p>
        <?php if (!$fresh): ?><p class="bg-amber-50 text-amber-900 p-4 rounded"><?= e(__('st_not_fresh')) ?></p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="space-y-3">
            <?= csrfField() ?><input type="hidden" name="action" value="prepare">
            <label class="block font-medium" for="st-package"><?= e(__('st_package')) ?></label>
            <input id="st-package" type="file" name="package" accept=".zip" required class="block w-full max-w-full" aria-describedby="st-size">
            <p id="st-size" class="text-sm text-gray-600"><?= e(__('st_size')) ?></p>
            <button type="submit" class="border rounded px-4 py-3"><?= e(__('st_preview')) ?></button>
        </form>
        <?php if (is_array($preview)): ?>
        <div class="border rounded p-4 space-y-4">
            <h3 class="font-bold"><?= e(__('st_preview_title')) ?></h3>
            <?php
            // 语言缺口单独摆出来：它是一条判断，不是一个计数，混进计数表里会被略过
            $emptyLanguages = is_array($preview['summary']['languages_empty'] ?? null) ? $preview['summary']['languages_empty'] : [];
            $missingPlugins = is_array($preview['missing_plugins'] ?? null) ? $preview['missing_plugins'] : [];
            $counts = $preview['summary'];
            unset($counts['languages_empty']);
            ?>
            <dl class="grid grid-cols-2 gap-3">
                <?php foreach ($counts as $key => $value): ?>
                <div class="min-w-0"><dt class="text-sm text-gray-600"><?= e(__('st_count_' . $key)) ?></dt><dd class="font-medium break-words"><?= e((string) $value) ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <?php if ($emptyLanguages !== []): ?>
            <p role="status" class="bg-amber-50 text-amber-900 p-3 rounded text-sm">
                <?= e(__('st_lang_empty', ['list' => SiteTemplateLanguages::labels($emptyLanguages)])) ?>
            </p>
            <?php endif; ?>
            <?php if ($missingPlugins !== []): ?>
            <div role="alert" class="bg-amber-50 text-amber-900 p-3 rounded text-sm space-y-2" data-testid="st-missing-plugins">
                <p><?= e(__('st_plugin_missing_intro')) ?></p>
                <ul class="list-disc pl-5 space-y-1">
                    <?php foreach ($missingPlugins as $plugin): ?>
                    <li><code><?= e($plugin['slug'] . ' ' . $plugin['version']) ?></code>
                        <a class="text-primary underline ml-2" href="/admin/plugin.php?tab=market&amp;q=<?= e(rawurlencode($plugin['slug'])) ?>"><?= e(__('st_plugin_market')) ?></a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p><?= e(__('st_plugin_force_hint')) ?></p>
            </div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
                <?= csrfField() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="token" value="<?= e($preview['token']) ?>">
                <p id="st-stage-status" role="status" aria-live="polite" class="text-sm text-gray-600 hidden"></p>
                <p class="text-gray-600"><?= e(__('st_brand_hint')) ?></p>
                <?php foreach ($brand as $key => $value): ?>
                <div><label class="block font-medium mb-1" for="st-<?= e($key) ?>"><?= e(__('st_' . $key)) ?></label>
                    <input id="st-<?= e($key) ?>" name="<?= e($key) ?>" type="<?= $key === 'contact_email' ? 'email' : 'text' ?>" maxlength="500" value="<?= e($value) ?>" <?= $key === 'site_name' ? 'required' : '' ?> class="border rounded px-3 py-2 w-full"></div>
                <?php endforeach; ?>
                <label class="flex gap-2 items-start"><input type="checkbox" name="trusted" value="1" required class="mt-1"><span><?= e(__('st_trust_label')) ?></span></label>
                <label class="flex gap-2 items-start"><input type="checkbox" name="confirm" value="1" required class="mt-1"><span><?= e(__('st_confirm')) ?></span></label>
                <button type="submit" class="bg-primary text-white rounded px-4 py-3"><?= e(__('st_apply')) ?></button>
            </form>
            <script>
            // 提交前先分批把媒体落盘：大包的文件 IO 在这里按服务端预算推进，
            // 真正的提交请求只剩数据库替换与生效，不会撞上执行时限。
            (function () {
                var form = document.currentScript.previousElementSibling;
                var status = document.getElementById('st-stage-status');
                var token = form.querySelector('input[name="token"]').value;
                var texts = <?= json_encode([
                    'progress' => __('st_stage_progress'),
                    'failed' => __('st_stage_failed'),
                ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                var staged = false;
                form.addEventListener('submit', function (event) {
                    if (staged) return;
                    event.preventDefault();
                    var button = form.querySelector('button[type="submit"]');
                    button.disabled = true;
                    status.classList.remove('hidden');
                    var step = function () {
                        var body = new URLSearchParams();
                        body.set('action', 'stage');
                        body.set('token', token);
                        return fetch(window.location.href, { method: 'POST', body: body })
                            .then(function (response) { return response.json(); })
                            .then(function (result) {
                                if (!result || Number(result.code) !== 0) throw new Error((result && result.msg) || texts.failed);
                                var data = result.data || {};
                                status.textContent = texts.progress
                                    .replace(':done', String(data.done || 0))
                                    .replace(':total', String(data.total || 0));
                                if (!data.complete) return step();
                                staged = true;
                                form.submit();
                            });
                    };
                    step().catch(function (error) {
                        status.textContent = String((error && error.message) || texts.failed);
                        button.disabled = false;
                    });
                });
            })();
            </script>
        </div>
        <?php endif; endif; ?>
    </section>
    <?php if ($recovery !== null): ?>
    <section class="bg-white rounded-lg shadow p-6 space-y-3" aria-labelledby="st-recovery">
        <h2 id="st-recovery" class="text-lg font-bold"><?= e(__('st_recovery')) ?></h2>
        <p class="text-gray-600"><?= e(__('st_recovery_hint')) ?></p>
        <p><?= e(__('st_record_time', ['time' => date('Y-m-d H:i', $recovery['created_at'])])) ?></p>
        <?php if ($recovery['can_restore']): ?>
        <form method="post" class="space-y-3"><?= csrfField() ?><input type="hidden" name="action" value="restore">
            <label class="flex gap-2 items-start"><input type="checkbox" name="confirm" value="1" required class="mt-1"><span><?= e(__('st_restore_confirm')) ?></span></label>
            <button type="submit" class="border rounded px-4 py-3"><?= e(__('st_restore')) ?></button></form>
        <?php else: ?><p class="text-gray-600"><?= e(__($recovery['status'] === 'restored' ? 'st_restored' : 'st_restore_unavailable')) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>
    <a class="inline-block border rounded px-4 py-3" href="/" target="_blank" rel="noopener"><?= e(__('setup_preview')) ?></a>
</div>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
