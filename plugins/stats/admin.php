<?php
/**
 * 网站统计接入 - 管理页面
 * 由 /admin/plugin_page.php?plugin=stats 加载（已 checkLogin + CSRF）。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['stats_action'] ?? '') === 'save') {
    $provider = (string) ($_POST['stats_provider'] ?? '');
    if (!in_array($provider, ['', 'baidu', '51la', 'cnzz', 'ga4', 'custom'], true)) {
        $provider = '';
    }
    settingModel()->set('stats_provider', $provider, 'plugin');
    settingModel()->set('stats_id', trim((string) ($_POST['stats_id'] ?? '')), 'plugin');
    settingModel()->set('stats_custom_code', (string) ($_POST['stats_custom_code'] ?? ''), 'plugin');
    adminLog('plugin', 'update', __('stt_log_update'));
    success();
}

$provider   = (string) config('stats_provider', '');
$statsId     = (string) config('stats_id', '');
$customCode  = (string) config('stats_custom_code', '');
$hasPro      = function_exists('license_has_module') && license_has_module('stats');

$providers = [
    'baidu'  => [__('stt_p_baidu'), 'https://tongji.baidu.com', __('stt_h_baidu')],
    '51la'   => [__('stt_p_51la'), 'https://v6.51.la', __('stt_h_51la')],
    'cnzz'   => [__('stt_p_cnzz'), 'https://web.umeng.com', __('stt_h_cnzz')],
    'ga4'    => ['Google Analytics 4', 'https://analytics.google.com', __('stt_h_ga4')],
    'custom' => [__('stt_p_custom'), '', __('stt_h_custom')],
];

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl space-y-6">

    <!-- 接入设置 -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1"><?php echo e(__('stt_title')); ?></h2>
        <p class="text-sm text-gray-500 mb-5"><?php echo strtr(e(__('stt_desc')), [':head' => '<code class="bg-gray-100 px-1.5 py-0.5 rounded">&lt;head&gt;</code>']); ?></p>

        <form id="statsForm" class="space-y-5">
            <input type="hidden" name="stats_action" value="save">

            <div>
                <label class="block font-medium text-gray-700 mb-2"><?php echo e(__('stt_provider')); ?></label>
                <select name="stats_provider" id="statsProvider" class="w-full md:w-80 border rounded px-4 py-2">
                    <option value="" <?php echo $provider === '' ? 'selected' : ''; ?>><?php echo e(__('stt_disabled')); ?></option>
                    <?php foreach ($providers as $pk => $p): ?>
                        <option value="<?php echo $pk; ?>" <?php echo $provider === $pk ? 'selected' : ''; ?>><?php echo e($p[0]); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 预设服务商：站点 ID -->
            <div id="rowId" style="<?php echo ($provider !== '' && $provider !== 'custom') ? '' : 'display:none'; ?>">
                <label class="block font-medium text-gray-700 mb-2"><?php echo e(__('stt_site_id')); ?></label>
                <input type="text" name="stats_id" value="<?php echo e($statsId); ?>" class="w-full md:w-80 border rounded px-4 py-2 font-mono">
                <p class="text-xs text-gray-400 mt-2" id="idHint"></p>
            </div>

            <!-- 自定义代码 -->
            <div id="rowCustom" style="<?php echo $provider === 'custom' ? '' : 'display:none'; ?>">
                <label class="block font-medium text-gray-700 mb-2"><?php echo e(__('stt_custom_code')); ?></label>
                <textarea name="stats_custom_code" rows="5" class="w-full border rounded px-4 py-2 font-mono text-sm" placeholder="&lt;script&gt;...&lt;/script&gt;"><?php echo e($customCode); ?></textarea>
                <p class="text-xs text-amber-600 mt-2"><?php echo e(__('stt_custom_warn')); ?></p>
            </div>

            <div>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition"><?php echo e(__('stt_save')); ?></button>
            </div>
        </form>
    </div>

    <!-- Pro：数据看板（license 闸控） -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center gap-2 mb-3">
            <h2 class="font-bold text-gray-800"><?php echo e(__('stt_dashboard')); ?></h2>
            <span class="text-xs px-2 py-0.5 rounded-full <?php echo $hasPro ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>"><?php echo e(__('stt_pro_badge')); ?></span>
        </div>

        <?php if ($hasPro): ?>
            <p class="text-sm text-gray-600 mb-3"><?php echo e(__('stt_pro_unlocked')); ?></p>
            <?php if ($provider !== '' && $provider !== 'custom' && !empty($providers[$provider][1])): ?>
                <a href="<?php echo e($providers[$provider][1]); ?>" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-1 text-primary hover:underline text-sm">
                    <?php echo strtr(e(__('stt_open_console')), [':provider' => e($providers[$provider][0])]); ?>
                </a>
            <?php else: ?>
                <p class="text-sm text-gray-400"><?php echo e(__('stt_pick_first')); ?></p>
            <?php endif; ?>
        <?php else: ?>
            <div class="bg-gray-50 border border-dashed rounded-lg p-5 text-center">
                <p class="text-sm text-gray-600 mb-3"><?php echo strtr(e(__('stt_pro_pitch')), [':b' => '<b>', ':_b' => '</b>']); ?></p>
                <a href="/admin/license.php" class="inline-flex items-center gap-1 bg-primary hover:bg-secondary text-white px-5 py-2 rounded text-sm transition"><?php echo e(__('stt_go_license')); ?></a>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
(function () {
    var hints = <?php echo json_encode(array_map(fn($p) => $p[2], $providers), JSON_UNESCAPED_UNICODE); ?>;
    var sel = document.getElementById('statsProvider');
    var rowId = document.getElementById('rowId'), rowCustom = document.getElementById('rowCustom'), idHint = document.getElementById('idHint');
    function sync() {
        var v = sel.value;
        rowCustom.style.display = (v === 'custom') ? '' : 'none';
        rowId.style.display = (v !== '' && v !== 'custom') ? '' : 'none';
        idHint.textContent = hints[v] || '';
    }
    sel.addEventListener('change', sync);
    sync();
    document.getElementById('statsForm').addEventListener('submit', function (e) {
        e.preventDefault();
        adminSave(this, { reload: true });
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
