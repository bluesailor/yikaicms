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
    adminLog('plugin', 'update', '更新 Cookie 同意配置');
    success([], '已保存');
}

$policyUrl     = (string) config('cc_policy_url', '');
$policyVersion = (int) config('cc_policy_version', '1');
$consentMode   = (string) config('cc_consent_mode', '0') === '1';
$footerLink    = (string) config('cc_footer_link', '1') === '1';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl space-y-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1">Cookie 同意横幅</h2>
        <p class="text-sm text-gray-500 mb-5">GDPR / PIPL 合规：三档授权（必要/分析/营销）+ 随时撤回入口。其他脚本用 <code class="bg-gray-100 px-1 rounded">window.IK_consent</code> 或 <code class="bg-gray-100 px-1 rounded">ik:consent</code> 事件按类别门控加载（PHP 端用 <code class="bg-gray-100 px-1 rounded">ik_consent_allows('analytics')</code>）。</p>

        <form id="ccForm" class="space-y-5">
            <input type="hidden" name="cc_action" value="save">

            <div>
                <label class="block text-sm text-gray-700 mb-1">隐私政策链接</label>
                <input type="text" name="cc_policy_url" value="<?php echo htmlspecialchars($policyUrl, ENT_QUOTES); ?>"
                       class="w-full border rounded px-3 py-2 text-sm" placeholder="/privacy.html">
                <p class="text-xs text-gray-400 mt-1">填写后横幅正文中显示「隐私政策」链接（GDPR 建议必填）。</p>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1">政策版本号</label>
                <input type="number" name="cc_policy_version" min="1" value="<?php echo $policyVersion; ?>"
                       class="w-32 border rounded px-3 py-2 text-sm">
                <p class="text-xs text-gray-400 mt-1">Cookie 使用方式或隐私政策有实质变更时 <b>+1</b>：所有访客的旧同意即失效，横幅重新弹出征求同意。</p>
            </div>

            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="cc_consent_mode" value="1" <?php echo $consentMode ? 'checked' : ''; ?>
                       class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700">
                    启用 Google Consent Mode v2
                    <span class="block text-xs text-gray-400 mt-0.5">向 gtag 输出 consent default/update 信号（analytics_storage / ad_storage / ad_user_data / ad_personalization）。站点使用 Google Analytics / Ads 且面向欧盟访客时必开；本插件的信号在 head 早段输出，请确保 gtag.js 在其后加载。</span>
                </span>
            </label>

            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="cc_footer_link" value="1" <?php echo $footerLink ? 'checked' : ''; ?>
                       class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700">
                    显示「Cookie 设置」常驻入口（左下角）
                    <span class="block text-xs text-gray-400 mt-0.5">让访客随时撤回或变更同意——GDPR 第 7(3) 条要求撤回同意与给出同意同样容易，建议保持开启。</span>
                </span>
            </label>

            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1">
                <i class="ti ti-check text-base"></i> 保存
            </button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="font-bold text-gray-800 mb-2 text-sm">接入示例：按同意加载 GA</h3>
        <pre class="bg-gray-50 rounded p-3 text-xs text-gray-600 overflow-x-auto">&lt;script&gt;
function loadGA() { /* 挂载 gtag.js */ }
if (window.IK_consent &amp;&amp; IK_consent.analytics) loadGA();
window.addEventListener('ik:consent', function (e) {
    if (e.detail.analytics) loadGA();
});
&lt;/script&gt;</pre>
        <p class="text-xs text-gray-400 mt-2">若启用了 Consent Mode v2，也可以直接常载 gtag.js——Google 会按 consent 信号自行降级（无 Cookie 的 ping）。</p>
    </div>
</div>

<script>
document.getElementById('ccForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    var resp = await fetch('', { method: 'POST', body: new FormData(this) });
    var data = await safeJson(resp);
    if (data.code === 0) showMessage('已保存');
    else showMessage(data.msg || '保存失败', 'error');
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
