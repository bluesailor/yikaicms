<?php
/**
 * Yikai CMS - 演示模式开关（隐藏页）
 *
 * 不在后台侧栏挂链接，直接通过 /admin/setting_demo.php 访问。
 * 开关写入 yikai_settings.demo_mode，Compatibility::bootstrap() 在每次请求时读取并定义 DEMO_MODE 常量。
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$currentOn = (string)config('demo_mode', '0') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'toggle') {
        $newValue = post('demo_mode', '0') === '1' ? '1' : '0';
        settingModel()->set('demo_mode', $newValue);
        adminLog('setting', 'demo_mode', '演示模式 ' . ($newValue === '1' ? '开启' : '关闭'));
        success([], __('admin_saved'));
    }
}

$pageTitle = __('dm_title');
$currentMenu = '';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-2xl mx-auto">
    <form id="demoForm" class="space-y-6">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="toggle">

        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800"><?php echo e(__('dm_heading')); ?></h2>
                <p class="text-sm text-gray-500 mt-1">
                    <?php echo str_replace(':file', '<code class="bg-gray-100 px-1 rounded">upgrade.php</code>', e(__('dm_desc'))); ?>
                </p>
            </div>
            <div class="p-6 space-y-4">
                <label class="flex items-start gap-3 p-4 rounded-lg border hover:bg-gray-50 cursor-pointer">
                    <input type="checkbox" name="demo_mode" value="1" <?php echo $currentOn ? 'checked' : ''; ?> class="w-5 h-5 rounded mt-0.5">
                    <div>
                        <span class="font-medium text-gray-700"><?php echo e(__('dm_enable')); ?></span>
                        <p class="text-xs text-gray-400 mt-1">
                            <?php echo e(__('dm_current_state')); ?>
                            <?php if ($currentOn): ?>
                                <span class="text-orange-600 font-medium"><?php echo e(__('dm_state_on')); ?></span>
                            <?php else: ?>
                                <span class="text-green-600 font-medium"><?php echo e(__('dm_state_off')); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </label>

                <div class="bg-blue-50 border border-blue-200 rounded p-3 text-xs text-blue-700 space-y-1">
                    <div><strong><?php echo e(__('dm_entry')); ?></strong>/admin/setting_demo.php<?php echo e(__('dm_entry_note')); ?></div>
                    <div><strong><?php echo e(__('dm_mechanism')); ?></strong><?php echo str_replace([':row', ':const'], ['<code>' . DB_PREFIX . 'settings.demo_mode</code>', '<code>config/config.php</code>'], e(__('dm_mechanism_note'))); ?></div>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-2">
                <i class="ti ti-check text-base"></i>
                <?php echo e(__('admin_save')); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.getElementById('demoForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    if (!fd.has('demo_mode')) fd.set('demo_mode', '0');
    var resp = await fetch('', { method: 'POST', body: fd });
    var data = await safeJson(resp);
    if (data.code === 0) {
        showMessage(data.msg || <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?>);
        setTimeout(() => location.reload(), 800);
    } else {
        showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
