<?php
/**
 * Yikai CMS - 配方 / 站点模板管理
 *
 * 列出 /recipes/ 下所有 manifest，支持一键应用 + 导出当前配置为新配方。
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/RecipeService.php';

checkLogin();
requirePermission('*');

$svc = new RecipeService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'preview') {
        try {
            $slug = (string) post('slug', '');
            $updateExisting = post('update_existing', '0') === '1';
            $plan = $svc->preview($slug, $updateExisting);
            $plan['settings'] = array_map(static fn(string $key): string => settingLabel($key, $key), $plan['settings']);
            $token = bin2hex(random_bytes(24));
            $_SESSION['recipe_plan'] = ['token' => $token, 'slug' => $slug, 'update' => $updateExisting,
                'expires' => time() + 600, 'fingerprint' => $plan['fingerprint'], 'blocked' => $plan['blocked']];
            unset($plan['fingerprint']);
            $plan['token'] = $token;
            success($plan);
        } catch (Throwable $e) {
            error($e->getMessage());
        }
    }

    if ($action === 'apply') {
        $slug = (string)post('slug', '');
        $updateExisting = post('update_existing', '0') === '1';
        try {
            $plan = $_SESSION['recipe_plan'] ?? null;
            unset($_SESSION['recipe_plan']);
            if (!is_array($plan) || (int) $plan['expires'] < time() || $plan['blocked']
                || $plan['slug'] !== $slug || $plan['update'] !== $updateExisting
                || !hash_equals((string) $plan['token'], (string) post('plan_token', ''))) {
                throw new RuntimeException(__('setup_plan_stale'));
            }
            $report = $svc->apply($slug, ['update_existing' => $updateExisting, 'expected_fingerprint' => $plan['fingerprint']]);
            adminLog('recipe', 'apply', "apply recipe {$slug}: ch +{$report['channels_created']}/~{$report['channels_updated']}, ext +{$report['extfields_created']}/~{$report['extfields_updated']}, ct +{$report['contents_created']}");
            success($report, __('recipe_apply_success'));
        } catch (\Throwable $e) {
            error(__('recipe_apply_failed') . ': ' . $e->getMessage());
        }
    }

    if ($action === 'export') {
        try {
            $manifest = $svc->exportCurrent([
                'include_contents' => post('include_contents', '0') === '1',
                'name'             => post('export_name', '') ?: (__('recipe_export_default_name') . ' ' . date('Y-m-d H:i')),
                'slug'             => 'exported-' . date('Ymd-His'),
            ]);
            adminLog('recipe', 'export', 'export current config as recipe');
            $filename = 'yikai-recipe-' . date('Ymd-His') . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } catch (\Throwable $e) {
            error(__('recipe_export_failed') . ': ' . $e->getMessage());
        }
    }
}

$recipes = $svc->list();
$applied = $svc->appliedHistory();

// 应用过的方案排前面（按时间倒序），未应用的按 manifest 顺序保持
uksort($recipes, function ($a, $b) use ($applied) {
    $ta = $applied[$a] ?? 0;
    $tb = $applied[$b] ?? 0;
    if ($ta === $tb) return 0;
    return $tb <=> $ta;
});

$lang = getLang();
$langSuffix = $lang !== 'zh-CN' ? '_' . $lang : '';

// 工具函数：根据当前 admin 语言挑 manifest 的 name / description
$pick = function (array $r, string $key) use ($langSuffix) {
    if ($langSuffix && !empty($r[$key . $langSuffix])) return (string)$r[$key . $langSuffix];
    return (string)($r[$key] ?? '');
};

$pageTitle = __('admin_recipe');
$currentMenu = 'recipe';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-6">
    <!-- 说明卡 -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800">
        <div class="font-bold mb-1">💡 <?php echo __('recipe_intro_title'); ?></div>
        <p><?php echo e(__('setup_recipe_limit')); ?></p>
    </div>

    <!-- 可用配方列表 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('recipe_available'); ?></h2>
            <p class="text-sm text-gray-500 mt-1"><?php echo __('recipe_scan_hint', ['count' => count($recipes)]); ?></p>
        </div>
        <div class="p-6 space-y-3">
            <?php if (empty($recipes)): ?>
                <p class="text-gray-400 text-center py-4"><?php echo __('recipe_empty'); ?></p>
            <?php else: foreach ($recipes as $slug => $r): ?>
                <?php $appliedAt = $applied[$slug] ?? null; ?>
                <div class="border rounded-lg p-4 hover:border-primary transition <?php echo $appliedAt ? 'border-green-300 bg-green-50/40' : ''; ?>">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex-1 min-w-0" style="min-width:min(100%,220px)">
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <h3 class="font-bold text-gray-800"><?php echo e($pick($r, 'name')); ?></h3>
                                <span class="text-xs bg-gray-100 px-2 py-0.5 rounded font-mono"><?php echo e($slug); ?></span>
                                <span class="text-xs text-gray-400">v<?php echo e((string)($r['version'] ?? '1.0.0')); ?></span>
                                <?php if ($appliedAt): ?>
                                <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded inline-flex items-center gap-1">
                                    <i class="ti ti-circle-check text-sm"></i>
                                    <?php echo __('recipe_applied_at', ['date' => date('Y-m-d', (int)$appliedAt)]); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-600 mb-2"><?php echo e($pick($r, 'description')); ?></p>
                            <div class="flex flex-wrap gap-2 text-xs text-gray-500">
                                <span class="bg-blue-50 text-blue-700 px-2 py-0.5 rounded"><?php echo __('recipe_count_channels', ['n' => count($r['channels'])]); ?></span>
                                <span class="bg-green-50 text-green-700 px-2 py-0.5 rounded"><?php echo __('recipe_count_extfields', ['n' => count($r['extfields'])]); ?></span>
                                <span class="bg-yellow-50 text-yellow-700 px-2 py-0.5 rounded"><?php echo __('recipe_count_contents', ['n' => count($r['contents'])]); ?></span>
                                <span class="bg-purple-50 text-purple-700 px-2 py-0.5 rounded"><?php echo __('recipe_count_settings', ['n' => count($r['settings'])]); ?></span>
                                <span class="text-gray-400"><?php echo __('label_author'); ?>: <?php echo e((string)($r['author'] ?? '')); ?></span>
                            </div>
                        </div>
                        <div class="flex-shrink-0">
                            <button type="button" data-slug="<?php echo e($slug); ?>" data-name="<?= e($pick($r, 'name')) ?>" class="btn-apply bg-primary hover:bg-secondary text-white px-5 py-2 rounded transition font-medium">
                                <?php echo __('recipe_apply'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- 导出当前配置 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('recipe_export_title'); ?></h2>
            <p class="text-sm text-gray-500 mt-1"><?php echo __('recipe_export_desc'); ?></p>
        </div>
        <div class="p-6">
            <form id="exportForm" method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="export">
                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('recipe_export_name'); ?></label>
                        <input type="text" name="export_name" value="<?php echo e(__('recipe_export_default_name') . ' ' . date('Y-m-d H:i')); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="include_contents" value="1" class="w-4 h-4 rounded">
                        <div>
                            <span class="font-medium text-gray-700"><?php echo __('recipe_include_contents'); ?></span>
                            <p class="text-xs text-gray-400"><?php echo __('recipe_include_contents_hint'); ?></p>
                        </div>
                    </label>
                </div>
                <div class="flex justify-end mt-4">
                    <button type="submit" class="bg-gray-700 hover:bg-gray-800 text-white px-6 py-2 rounded inline-flex items-center gap-2">
                        <i class="ti ti-download text-base"></i>
                        <?php echo __('recipe_export_button'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 应用确认模态 -->
<dialog id="applyModal" aria-labelledby="applyTitle" style="margin:auto;padding:0;border:0;border-radius:8px;width:min(32rem,calc(100vw - 32px));max-height:90vh;overflow:auto;overflow-wrap:anywhere;">
    <div class="bg-white rounded-lg shadow-xl w-full p-6">
        <h3 id="applyTitle" class="font-bold text-lg text-gray-800 mb-2"><?php echo e(__('setup_plan_title')); ?></h3>
        <p class="text-sm text-gray-600 mb-4"><?php echo __('recipe_confirm_intro'); ?> <strong id="applyName"></strong>. <?php echo __('recipe_confirm_will'); ?></p>
        <p class="text-sm text-gray-700 mb-4"><?= e(__('setup_plan_preserve')) ?></p>
        <a href="/admin/database.php" target="_blank" rel="noopener" class="inline-block underline py-2 mb-2"><?= e(__('setup_backup')) ?></a>
        <label class="flex items-center gap-2 text-sm mb-4">
            <input type="checkbox" id="updateExisting" class="w-4 h-4 rounded">
            <span><?php echo e(__('setup_plan_overwrite')); ?></span>
        </label>
        <div id="recipePlan" class="text-sm text-gray-700 space-y-2 mb-4" role="status" aria-live="polite"></div>
        <div class="flex flex-wrap justify-end gap-3">
            <button type="button" id="cancelApply" class="px-4 py-2 border rounded text-gray-700 hover:bg-gray-50"><?php echo __('btn_cancel'); ?></button>
            <button type="button" id="previewApply" class="px-4 py-2 border rounded text-gray-700"><?= e(__('setup_plan_preview')) ?></button>
            <button type="button" id="confirmApply" class="px-4 py-2 bg-primary hover:bg-secondary text-white rounded font-medium disabled:opacity-50 disabled:cursor-not-allowed"><?php echo __('recipe_confirm_apply'); ?></button>
        </div>
    </div>
</dialog>

<script>
(function(){
    var modal = document.getElementById('applyModal');
    var applyName = document.getElementById('applyName');
    var pendingSlug = null;
    var planToken = '';
    var planBox = document.getElementById('recipePlan');
    var confirmButton = document.getElementById('confirmApply');
    var updateCheckbox = document.getElementById('updateExisting');
    var previewButton = document.getElementById('previewApply');
    var previewGeneration = 0;
    // JSON_HEX_TAG 防御脚本闭合标签破出（如果翻译里偶然出现）
    var i18n = {
        applying:       <?php echo json_encode(__('recipe_applying'), JSON_HEX_TAG); ?>,
        confirm_apply:  <?php echo json_encode(__('recipe_confirm_apply'), JSON_HEX_TAG); ?>,
        report_tpl:     <?php echo json_encode(__('recipe_report_tpl'), JSON_HEX_TAG); ?>,
        apply_failed:   <?php echo json_encode(__('recipe_apply_failed'), JSON_HEX_TAG); ?>,
        network_error:  <?php echo json_encode(__('error_network'), JSON_HEX_TAG); ?>,
        plan_hint: <?= json_encode(__('setup_plan_hint'), JSON_HEX_TAG) ?>,
        plan_counts: <?= json_encode(__('setup_plan_counts'), JSON_HEX_TAG) ?>,
        plan_settings: <?= json_encode(__('setup_plan_settings'), JSON_HEX_TAG) ?>,
        actions: <?= json_encode(array_combine(['new', 'update', 'keep', 'conflict'], array_map(static fn(string $action): string => __('setup_plan_' . $action), ['new', 'update', 'keep', 'conflict'])), JSON_HEX_TAG) ?>,
    };
    function resetPlan() {
        previewGeneration++;
        planToken = '';
        confirmButton.disabled = true;
        previewButton.disabled = false;
        planBox.textContent = i18n.plan_hint;
    }
    updateCheckbox.addEventListener('change', resetPlan);
    modal.addEventListener('close', function() { pendingSlug = null; resetPlan(); });
    previewButton.addEventListener('click', async function() {
        resetPlan();
        var generation = previewGeneration;
        this.disabled = true;
        var fd = new FormData();
        fd.set('_token', <?= json_encode(csrfToken(), JSON_HEX_TAG) ?>);
        fd.set('action', 'preview'); fd.set('slug', pendingSlug);
        fd.set('update_existing', updateCheckbox.checked ? '1' : '0');
        try {
            var result = await safeJson(await fetch('', { method: 'POST', body: fd }));
            if (generation !== previewGeneration) return;
            if (result.code !== 0) throw new Error(result.msg || i18n.apply_failed);
            var plan = result.data;
            planBox.textContent = '';
            plan.channels.forEach(function(row) {
                var item = document.createElement('p');
                item.textContent = i18n.actions[row.action] + ': ' + row.name;
                planBox.appendChild(item);
            });
            var counts = document.createElement('p');
            counts.textContent = i18n.plan_counts.replace(':contents', plan.contents).replace(':fields', plan.extfields);
            planBox.appendChild(counts);
            var settings = document.createElement('p');
            settings.textContent = i18n.plan_settings + ' ' + (plan.settings.join(', ') || '0');
            planBox.appendChild(settings);
            planToken = plan.token;
            confirmButton.disabled = plan.blocked;
        } catch (error) {
            if (generation === previewGeneration) planBox.textContent = error.message;
        } finally {
            if (generation === previewGeneration) previewButton.disabled = false;
        }
    });

    document.querySelectorAll('.btn-apply').forEach(function(btn){
        btn.addEventListener('click', function(){
            pendingSlug = this.dataset.slug;
            applyName.textContent = this.dataset.name || pendingSlug;
            updateCheckbox.checked = false;
            resetPlan();
            modal.showModal();
        });
    });

    document.getElementById('cancelApply').addEventListener('click', function(){
        modal.close();
        pendingSlug = null;
    });

    document.getElementById('confirmApply').addEventListener('click', async function(){
        if (!pendingSlug || !planToken) return;
        var fd = new FormData();
        fd.set('_token', '<?php echo csrfToken(); ?>');
        fd.set('action', 'apply');
        fd.set('slug', pendingSlug);
        fd.set('plan_token', planToken);
        fd.set('update_existing', document.getElementById('updateExisting').checked ? '1' : '0');
        this.disabled = true;
        this.textContent = i18n.applying;
        try {
            var resp = await fetch('', { method: 'POST', body: fd });
            var data = await safeJson(resp);
            if (data.code === 0) {
                var r = data.data || {};
                var msg = i18n.report_tpl
                    .replace('{cc}', r.channels_created || 0)
                    .replace('{cu}', r.channels_updated || 0)
                    .replace('{ec}', r.extfields_created || 0)
                    .replace('{eu}', r.extfields_updated || 0)
                    .replace('{nc}', r.contents_created || 0);
                showMessage(msg);
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                showMessage(data.msg || i18n.apply_failed, 'error');
                resetPlan();
                this.textContent = i18n.confirm_apply;
            }
        } catch (e) {
            showMessage(i18n.network_error + ': ' + e.message, 'error');
            resetPlan();
            this.textContent = i18n.confirm_apply;
        }
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
