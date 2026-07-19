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

    if ($action === 'apply') {
        $slug = (string)post('slug', '');
        $updateExisting = post('update_existing', '0') === '1';
        try {
            $report = $svc->apply($slug, ['update_existing' => $updateExisting]);
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
        <p><?php echo __('recipe_intro_body'); ?></p>
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
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex-1">
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
                            <button type="button" data-slug="<?php echo e($slug); ?>" class="btn-apply bg-primary hover:bg-secondary text-white px-5 py-2 rounded transition font-medium">
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
<div id="applyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 p-6">
        <h3 class="font-bold text-lg text-gray-800 mb-2"><?php echo __('recipe_confirm_title'); ?></h3>
        <p class="text-sm text-gray-600 mb-4"><?php echo __('recipe_confirm_intro'); ?> <strong id="applyName"></strong>. <?php echo __('recipe_confirm_will'); ?></p>
        <ul class="text-sm text-gray-700 space-y-1 mb-4 list-disc list-inside">
            <li><?php echo __('recipe_confirm_step1'); ?></li>
            <li><?php echo __('recipe_confirm_step2'); ?></li>
            <li><?php echo __('recipe_confirm_step3'); ?></li>
            <li><?php echo __('recipe_confirm_step4'); ?></li>
        </ul>
        <label class="flex items-center gap-2 text-sm mb-4">
            <input type="checkbox" id="updateExisting" class="w-4 h-4 rounded">
            <span><?php echo __('recipe_update_existing'); ?></span>
        </label>
        <div class="flex justify-end gap-3">
            <button type="button" id="cancelApply" class="px-4 py-2 border rounded text-gray-700 hover:bg-gray-50"><?php echo __('btn_cancel'); ?></button>
            <button type="button" id="confirmApply" class="px-4 py-2 bg-primary hover:bg-secondary text-white rounded font-medium"><?php echo __('recipe_confirm_apply'); ?></button>
        </div>
    </div>
</div>

<script>
(function(){
    var modal = document.getElementById('applyModal');
    var applyName = document.getElementById('applyName');
    var pendingSlug = null;
    // JSON_HEX_TAG 防御脚本闭合标签破出（如果翻译里偶然出现）
    var i18n = {
        applying:       <?php echo json_encode(__('recipe_applying'), JSON_HEX_TAG); ?>,
        confirm_apply:  <?php echo json_encode(__('recipe_confirm_apply'), JSON_HEX_TAG); ?>,
        report_tpl:     <?php echo json_encode(__('recipe_report_tpl'), JSON_HEX_TAG); ?>,
        apply_failed:   <?php echo json_encode(__('recipe_apply_failed'), JSON_HEX_TAG); ?>,
        network_error:  <?php echo json_encode(__('error_network'), JSON_HEX_TAG); ?>,
    };

    document.querySelectorAll('.btn-apply').forEach(function(btn){
        btn.addEventListener('click', function(){
            pendingSlug = this.dataset.slug;
            applyName.textContent = pendingSlug;
            modal.style.display = 'flex';
        });
    });

    document.getElementById('cancelApply').addEventListener('click', function(){
        modal.style.display = 'none';
        pendingSlug = null;
    });

    document.getElementById('confirmApply').addEventListener('click', async function(){
        if (!pendingSlug) return;
        var fd = new FormData();
        fd.set('_token', '<?php echo csrfToken(); ?>');
        fd.set('action', 'apply');
        fd.set('slug', pendingSlug);
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
                this.disabled = false;
                this.textContent = i18n.confirm_apply;
            }
        } catch (e) {
            showMessage(i18n.network_error + ': ' + e.message, 'error');
            this.disabled = false;
            this.textContent = i18n.confirm_apply;
        }
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
