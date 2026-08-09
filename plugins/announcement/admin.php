<?php
/**
 * 网站公告 - 配置页
 * 由 /admin/plugin_page.php?plugin=announcement 加载（已 checkLogin + CSRF）。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ann_action'] ?? '') === 'save') {
    settingModel()->set('ann_enabled',   isset($_POST['ann_enabled']) ? '1' : '0', 'plugin');
    settingModel()->set('ann_home_only', isset($_POST['ann_home_only']) ? '1' : '0', 'plugin');
    settingModel()->set('ann_title',     trim((string) ($_POST['ann_title'] ?? '')), 'plugin');
    settingModel()->set('ann_content',   (string) ($_POST['ann_content'] ?? ''), 'plugin');
    settingModel()->set('ann_button',    trim((string) ($_POST['ann_button'] ?? '')) ?: __('ann_default_btn'), 'plugin');
    settingModel()->set('ann_cooldown',  (string) max(0, (int) ($_POST['ann_cooldown'] ?? 1)), 'plugin');
    adminLog('plugin', 'update', __('ann_log_update'));
    success();
}

$enabled  = (string) config('ann_enabled', '0') === '1';
$homeOnly = (string) config('ann_home_only', '0') === '1';
$title    = (string) config('ann_title', __('ann_default_title'));
$content  = (string) config('ann_content', '');
$button   = (string) config('ann_button', __('ann_default_btn'));
$cooldown = (int) config('ann_cooldown', '1');

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl space-y-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1"><?php echo e(__('ann_admin_title')); ?></h2>
        <p class="text-sm text-gray-500 mb-5"><?php echo strtr(e(__('ann_admin_desc')), [':b' => '<b>', ':_b' => '</b>']); ?></p>

        <form id="annForm" class="space-y-5">
            <input type="hidden" name="ann_action" value="save">

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="ann_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm font-medium text-gray-700"><?php echo e(__('ann_enable')); ?></span>
            </label>

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('ann_f_title')); ?></label>
                <input type="text" name="ann_title" value="<?php echo htmlspecialchars($title, ENT_QUOTES); ?>" class="w-full border rounded px-3 py-2 text-sm" placeholder="<?php echo e(__('ann_default_title')); ?>">
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('ann_f_content')); ?></label>
                <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
                <div id="editor-container" class="border rounded-b-lg" style="min-height: 320px;"></div>
                <input type="hidden" name="ann_content" id="ann_content_input">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('ann_content_tip')); ?></p>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('ann_f_button')); ?></label>
                    <input type="text" name="ann_button" value="<?php echo htmlspecialchars($button, ENT_QUOTES); ?>" class="w-full border rounded px-3 py-2 text-sm" placeholder="<?php echo e(__('ann_default_btn')); ?>">
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('ann_button_tip')); ?></p>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('ann_f_freq')); ?></label>
                    <div class="flex items-center gap-2">
                        <input type="number" name="ann_cooldown" value="<?php echo $cooldown; ?>" min="0" class="w-20 border rounded px-3 py-2 text-sm">
                        <span class="text-sm text-gray-500"><?php echo e(__('ann_freq_unit')); ?></span>
                    </div>
                </div>
            </div>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="ann_home_only" value="1" <?php echo $homeOnly ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700"><?php echo e(__('ann_home_only')); ?></span>
            </label>

            <div class="pt-2">
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition"><?php echo e(__('ann_save')); ?></button>
            </div>
        </form>
    </div>

    <div class="bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-gray-600">
        <b><?php echo e(__('ann_tip_label')); ?></b><?php echo strtr(e(__('ann_test_tip')), [':code' => '<code>ik_ann_seen</code>']); ?>
    </div>
</div>

<?php
$annContentJson = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$extraJs = '<script>
var annEditor = initWangEditor("#toolbar-container", "#editor-container", {
    placeholder: ' . json_encode(__('ann_editor_ph'), JSON_UNESCAPED_UNICODE) . ',
    html: ' . $annContentJson . ',
    uploadUrl: "/admin/upload.php",
    onChange: function (ed) { document.getElementById("ann_content_input").value = ed.getHtml(); }
});
document.getElementById("ann_content_input").value = ' . $annContentJson . ';
document.getElementById("annForm").addEventListener("submit", function (e) {
    e.preventDefault();
    document.getElementById("ann_content_input").value = annEditor.getHtml();
    adminSave(this, { reload: true });
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
