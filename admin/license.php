<?php
/**
 * YikaiCMS - 授权管理（客户端）
 * 填写授权码 → 向 update.yikaicms 校验 → 显示套餐 / 到期 / 已开通模块。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/License.php';   // 自取，便于移植到未在 auth.php 挂载的站点（如 longcool）

checkLogin();
requirePermission('*');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? 'save';

    if ($act === 'refresh') {
        $st = license_refresh(true);
        adminLog('license', 'refresh', '手动校验授权');
        success($st, __('lic_reverified'));
    }

    if ($act === 'blox_toggle') {
        $on = ($_POST['enabled'] ?? '') === '1' ? '1' : '0';
        settingModel()->saveBatch(['blox_editor_enabled' => $on]);
        adminLog('license', 'blox_toggle', 'Blox 编辑器开关 → ' . $on);
        success(['enabled' => $on], __('blox_switch_saved'));
    }

    // 保存授权码：换码即作废旧缓存，立即重新校验
    $key = trim((string) ($_POST['license_key'] ?? ''));
    settingModel()->saveBatch(['license_key' => $key, 'license_state' => '']);
    $st = license_refresh(true);
    adminLog('license', 'update', '更新授权码');
    success($st, __('lic_saved_verified'));
}

$moduleLabels = [
    'stats'      => __('lic_mod_stats'),
    'leads'      => __('lic_mod_leads'),
    'ai'         => __('lic_mod_ai'),
    'seo'        => __('lic_mod_seo'),
    'oss'        => __('lic_mod_oss'),
    'icon-maker' => __('lic_mod_icon_maker'),
    'seo-pro'    => __('lic_mod_seo_pro'),
];
$planLabels   = ['free' => __('lic_plan_free'), 'basic' => __('lic_plan_basic'), 'pro' => __('lic_plan_pro')];
$reasonLabels = [
    'ok'              => __('lic_reason_ok'),
    'expired'         => __('lic_reason_expired'),
    'no_key'          => __('lic_reason_no_key'),
    'not_found'       => __('lic_reason_not_found'),
    'domain_mismatch' => __('lic_reason_domain_mismatch'),
    'disabled'        => __('lic_reason_disabled'),
    'unreachable'     => __('lic_reason_unreachable'),
    'grace_expired'   => __('lic_reason_grace_expired'),
];

$st     = license();
$key    = license_key();
$valid  = !empty($st['valid']);
$reason = (string) ($st['reason'] ?? '');
$plan   = (string) ($st['plan'] ?? 'free');
$exp    = $st['expires_at'] ?? null;
$mods   = (array) ($st['modules'] ?? []);
$serviceActive = license_service_active($st);

$pageTitle   = __('admin_license');
$currentMenu = 'license';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-6">

    <!-- 状态卡 -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-center gap-3">
                <?php if ($valid): ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-green-100 text-green-700 text-sm font-medium">
                        <i class="ti ti-check text-base"></i>
                        <?php echo __('lic_status_valid'); ?>
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-100 text-amber-700 text-sm font-medium">
                        <i class="ti ti-alert-triangle text-base"></i>
                        <?php echo e($plan === 'free' ? __('lic_plan_free') : __('lic_status_abnormal')); ?>
                    </span>
                <?php endif; ?>
                <span class="text-lg font-bold text-gray-800"><?php echo e($planLabels[$plan] ?? $plan); ?></span>
            </div>
            <button type="button" id="btnRefresh" class="text-sm text-primary hover:underline inline-flex items-center gap-1">
                <i class="ti ti-refresh text-base"></i>
                <?php echo __('lic_verify_now'); ?>
            </button>
        </div>

        <div class="mt-4 text-sm <?php echo $valid ? 'text-gray-500' : 'text-amber-600'; ?>">
            <?php echo e($reasonLabels[$reason] ?? $reason ?: '—'); ?>
        </div>

        <dl class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
            <div>
                <dt class="text-gray-400"><?php echo __('lic_expire_date'); ?></dt>
                <dd class="font-medium text-gray-800 mt-0.5"><?php echo e($exp ? (string) $exp : ($valid ? __('lic_forever') : '—')); ?></dd>
            </div>
            <div class="col-span-2">
                <dt class="text-gray-400"><?php echo __('lic_modules_enabled'); ?></dt>
                <dd class="font-medium text-gray-800 mt-0.5">
                    <?php if ($mods): ?>
                        <?php echo e(implode('、', array_map(fn($m) => $moduleLabels[$m] ?? $m, $mods))); ?>
                    <?php else: ?>
                        <span class="text-gray-400"><?php echo __('lic_modules_none'); ?></span>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>

        <div class="mt-5 pt-4 border-t border-gray-100">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="text-sm font-medium text-gray-800"><?php echo __('lic_support_title'); ?></div>
                    <p class="mt-0.5 text-sm text-gray-500">
                        <?php echo e($serviceActive ? __('lic_support_active') : __('lic_support_inactive')); ?>
                    </p>
                </div>
                <a href="https://www.yikaicms.com/feedback.html" target="_blank" rel="noopener"
                   data-testid="lic-support-forum"
                   class="inline-flex items-center gap-1 shrink-0 border border-gray-300 px-3 py-1.5 rounded text-sm text-gray-700 hover:border-primary hover:text-primary transition">
                    <i class="ti ti-messages"></i><?php echo __('lic_support_forum'); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Blox 总开关默认开启；2026-08-28 起全部 Blox 能力对免费版开放，本开关是唯一闸。 -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-bold text-gray-800 mb-1"><?php echo __('blox_switch_title'); ?></h2>
                <p class="text-sm text-gray-500"><?php echo __('blox_switch_tip'); ?></p>
            </div>
            <form method="post" id="bloxSwitchForm">
                <input type="hidden" name="action" value="blox_toggle">
                <input type="hidden" name="enabled" value="<?php echo config('blox_editor_enabled', '1') === '1' ? '0' : '1'; ?>">
                <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
                <button type="submit" class="px-5 py-2 rounded text-sm font-medium transition <?php echo config('blox_editor_enabled', '1') === '1'
                    ? 'bg-emerald-500 hover:bg-emerald-600 text-white'
                    : 'bg-gray-200 hover:bg-gray-300 text-gray-700'; ?>">
                    <?php echo config('blox_editor_enabled', '1') === '1' ? __('blox_switch_on') : __('blox_switch_off'); ?>
                </button>
            </form>
        </div>
    </div>

    <!-- 授权码 -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1"><?php echo __('lic_key'); ?></h2>
        <?php if (function_exists('license_valid') && license_valid()): ?>
        <?php // 白标：已授权站点不展示购买链接，只留功能说明 ?>
        <p class="text-sm text-gray-500 mb-4"><?php echo __('lic_tip_licensed'); ?></p>
        <?php else: ?>
        <p class="text-sm text-gray-500 mb-4"><?php echo __('lic_tip_buy_before'); ?> <a href="https://yikaicms.com" target="_blank" rel="noopener" class="text-primary hover:underline">yikaicms.com</a> <?php echo __('lic_tip_buy_after'); ?></p>
        <?php endif; ?>
        <?php
        // 安全：完整授权码不回显页面（防截屏/共享后台泄露），只展示打码版；
        // 更换须显式点击后重新输入完整码。
        $keyMasked = '';
        if ($key !== '') {
            $keyMasked = mb_strlen($key) > 8
                ? substr($key, 0, 4) . str_repeat('*', max(4, mb_strlen($key) - 8)) . substr($key, -4)
                : str_repeat('*', mb_strlen($key));
        }
        ?>
        <?php if ($key !== ''): ?>
        <div id="licMaskedRow" class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
            <code class="flex-1 bg-gray-50 border border-gray-200 rounded px-4 py-2 font-mono tracking-wider text-gray-600 select-none"><?php echo e($keyMasked); ?></code>
            <button type="button" onclick="licShowInput()" class="border border-gray-300 hover:bg-gray-100 text-gray-700 px-6 py-2 rounded transition whitespace-nowrap"><?php echo __('lic_change_key'); ?></button>
        </div>
        <?php endif; ?>
        <form id="licenseForm" class="flex flex-col sm:flex-row gap-3 <?php echo $key !== '' ? 'hidden' : ''; ?>">
            <input type="text" name="license_key" value="" placeholder="<?php echo e(__('lic_key_ph')); ?>"
                   autocomplete="off" class="flex-1 border rounded px-4 py-2 font-mono tracking-wider">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition whitespace-nowrap"><?php echo __('lic_save_verify'); ?></button>
            <?php if ($key !== ''): ?>
            <button type="button" onclick="licHideInput()" class="border border-gray-300 hover:bg-gray-100 text-gray-700 px-4 py-2 rounded transition whitespace-nowrap"><?php echo __('admin_cancel'); ?></button>
            <?php endif; ?>
        </form>
        <p class="text-xs text-gray-400 mt-3"><?php echo __('lic_domain_label'); ?><code class="bg-gray-100 px-1.5 py-0.5 rounded"><?php echo e(license_domain()); ?></code>　<?php echo __('lic_expire_note'); ?></p>
    </div>

    <?php if (!$valid): ?>
    <!-- 未授权：展示专业版权益（已授权即白标，不展示营销内容） -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1"><?php echo __('lic_pro_title'); ?></h2>
        <p class="text-sm text-gray-500 mb-4"><?php echo __('lic_pro_desc'); ?></p>
        <div class="grid sm:grid-cols-2 gap-2 text-sm text-gray-600 mb-4">
            <div class="flex gap-2"><span class="text-primary">✓</span> <?php echo __('lic_pro_1'); ?></div>
            <div class="flex gap-2"><span class="text-primary">✓</span> <?php echo __('lic_pro_2'); ?></div>
            <div class="flex gap-2"><span class="text-primary">✓</span> <?php echo __('lic_pro_3'); ?></div>
            <div class="flex gap-2"><span class="text-primary">✓</span> <?php echo __('lic_pro_4'); ?></div>
            <div class="flex gap-2"><span class="text-primary">✓</span> <?php echo __('lic_pro_5'); ?></div>
            <div class="flex gap-2"><span class="text-primary">✓</span> <?php echo __('lic_pro_6'); ?></div>
        </div>
        <a href="https://www.yikaicms.com/pro.html" target="_blank" rel="noopener" class="inline-flex items-center gap-1 bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition text-sm">
            <?php echo __('lic_pro_cta'); ?> <i class="ti ti-external-link text-base"></i>
        </a>
    </div>
    <?php endif; ?>

</div>

<script>
function licShowInput() {
    var m = document.getElementById('licMaskedRow');
    if (m) m.classList.add('hidden');
    var f = document.getElementById('licenseForm');
    f.classList.remove('hidden');
    f.querySelector('input[name="license_key"]').focus();
}
function licHideInput() {
    var m = document.getElementById('licMaskedRow');
    if (m) m.classList.remove('hidden');
    var f = document.getElementById('licenseForm');
    f.classList.add('hidden');
    f.querySelector('input[name="license_key"]').value = '';
}
document.getElementById('bloxSwitchForm').addEventListener('submit', function (e) {
    e.preventDefault();
    adminSave(this, {
        successMsg: <?php echo json_encode(__('blox_switch_saved'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
        reload: true,
        button: this.querySelector('button[type="submit"]')
    });
});
document.getElementById('licenseForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var v = this.querySelector('input[name="license_key"]').value.trim();
    if (v === '' && !confirm(<?php echo json_encode(__('lic_clear_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>)) return;
    adminSave(this, { reload: true });
});
document.getElementById('btnRefresh').addEventListener('click', function () {
    adminSave({ action: 'refresh' }, { successMsg: <?php echo json_encode(__('lic_reverified'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>, reload: true, button: this });
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
