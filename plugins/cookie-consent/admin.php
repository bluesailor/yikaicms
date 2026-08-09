<?php
/**
 * Cookie 同意横幅 - 配置页
 * 由 /admin/plugin_page.php?plugin=cookie-consent 加载（已 checkLogin + CSRF）。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['cc_action'] ?? '') === 'save') {
    settingModel()->set('cc_policy_url',     trim((string) ($_POST['cc_policy_url'] ?? '')), 'plugin');
    settingModel()->set('cc_policy_version', (string) max(1, (int) ($_POST['cc_policy_version'] ?? 1)), 'plugin');
    settingModel()->set('cc_consent_mode',   isset($_POST['cc_consent_mode']) ? '1' : '0', 'plugin');
    settingModel()->set('cc_footer_link',    isset($_POST['cc_footer_link']) ? '1' : '0', 'plugin');
    adminLog('plugin', 'update', __('cc_log_update'));
    success();
}

$policyUrl     = (string) config('cc_policy_url', '');
$policyVersion = (int) config('cc_policy_version', '1');
$consentMode   = (string) config('cc_consent_mode', '0') === '1';
$footerLink    = (string) config('cc_footer_link', '1') === '1';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl space-y-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1"><?php echo e(__('cc_title')); ?></h2>
        <p class="text-sm text-gray-500 mb-5"><?php echo strtr(e(__('cc_desc')), [
            ':api1' => '<code class="bg-gray-100 px-1 rounded">window.IK_consent</code>',
            ':api2' => '<code class="bg-gray-100 px-1 rounded">ik:consent</code>',
            ':api3' => "<code class=\"bg-gray-100 px-1 rounded\">ik_consent_allows('analytics')</code>",
        ]); ?></p>

        <form id="ccForm" class="space-y-5">
            <input type="hidden" name="cc_action" value="save">

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('cc_policy_url')); ?></label>
                <input type="text" name="cc_policy_url" value="<?php echo htmlspecialchars($policyUrl, ENT_QUOTES); ?>"
                       class="w-full border rounded px-3 py-2 text-sm" placeholder="/privacy.html">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('cc_policy_tip')); ?></p>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('cc_policy_ver')); ?></label>
                <input type="number" name="cc_policy_version" min="1" value="<?php echo $policyVersion; ?>"
                       class="w-32 border rounded px-3 py-2 text-sm">
                <p class="text-xs text-gray-400 mt-1"><?php echo strtr(e(__('cc_policy_ver_tip')), [':b' => '<b>', ':_b' => '</b>']); ?></p>
            </div>

            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="cc_consent_mode" value="1" <?php echo $consentMode ? 'checked' : ''; ?>
                       class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700">
                    <?php echo e(__('cc_consent_mode')); ?>
                    <span class="block text-xs text-gray-400 mt-0.5"><?php echo e(__('cc_consent_mode_tip')); ?>站点使用 Google Analytics / Ads 且面向欧盟访客时必开；本插件的信号在 head 早段输出，请确保 gtag.js 在其后加载。</span>
                </span>
            </label>

            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="cc_footer_link" value="1" <?php echo $footerLink ? 'checked' : ''; ?>
                       class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700">
                    <?php echo e(__('cc_footer_link')); ?>
                    <span class="block text-xs text-gray-400 mt-0.5"><?php echo e(__('cc_footer_link_tip')); ?></span>
                </span>
            </label>

            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1">
                <i class="ti ti-check text-base"></i> <?php echo e(__('cc_save')); ?>
            </button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="font-bold text-gray-800 mb-2 text-sm"><?php echo e(__('cc_example_title')); ?></h3>
        <pre class="bg-gray-50 rounded p-3 text-xs text-gray-600 overflow-x-auto">&lt;script&gt;
function loadGA() { /* 挂载 gtag.js */ }
if (window.IK_consent &amp;&amp; IK_consent.analytics) loadGA();
window.addEventListener('ik:consent', function (e) {
    if (e.detail.analytics) loadGA();
});
&lt;/script&gt;</pre>
        <p class="text-xs text-gray-400 mt-2"><?php echo e(__('cc_example_note')); ?></p>
    </div>
</div>

<script>
document.getElementById('ccForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var resp = await fetch('', { method: 'POST', body: new FormData(this) });
    var data = await safeJson(resp);
    if (data.code === 0) showMessage(<?php echo json_encode(__('operation_success'), JSON_UNESCAPED_UNICODE); ?>);
    else showMessage(data.msg || <?php echo json_encode(__('operation_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
