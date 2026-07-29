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
        success($st, '已重新校验');
    }

    // 保存授权码：换码即作废旧缓存，立即重新校验
    $key = trim((string) ($_POST['license_key'] ?? ''));
    settingModel()->saveBatch(['license_key' => $key, 'license_state' => '']);
    $st = license_refresh(true);
    adminLog('license', 'update', '更新授权码');
    success($st, '已保存并校验');
}

$moduleLabels = [
    'stats'      => '统计接入 Pro',
    'leads'      => '表单线索中心',
    'ai'         => 'AI 内容助手',
    'seo'        => '百度 SEO 套件',
    'oss'        => '媒体加速 OSS',
    'icon-maker' => '图标工坊 Pro',
    'seo-pro'    => 'SEO 工坊 Pro',
];
$planLabels   = ['free' => '免费版', 'basic' => '基础版', 'pro' => '专业版'];
$reasonLabels = [
    'ok'              => '授权有效',
    'expired'         => '授权已过期，请续费',
    'no_key'          => '尚未填写授权码',
    'not_found'       => '授权码无效',
    'domain_mismatch' => '授权码与本站域名不匹配',
    'disabled'        => '该授权已被停用',
    'unreachable'     => '无法连接授权服务器（暂用本地缓存）',
    'grace_expired'   => '离线超过宽限期，请联网重新校验',
];

$st     = license();
$key    = license_key();
$valid  = !empty($st['valid']);
$reason = (string) ($st['reason'] ?? '');
$plan   = (string) ($st['plan'] ?? 'free');
$exp    = $st['expires_at'] ?? null;
$mods   = (array) ($st['modules'] ?? []);

$pageTitle   = '授权管理';
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
                        授权有效
                    </span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-100 text-amber-700 text-sm font-medium">
                        <i class="ti ti-alert-triangle text-base"></i>
                        <?php echo e($plan === 'free' ? '免费版' : '授权异常'); ?>
                    </span>
                <?php endif; ?>
                <span class="text-lg font-bold text-gray-800"><?php echo e($planLabels[$plan] ?? $plan); ?></span>
            </div>
            <button type="button" id="btnRefresh" class="text-sm text-primary hover:underline inline-flex items-center gap-1">
                <i class="ti ti-refresh text-base"></i>
                立即校验
            </button>
        </div>

        <div class="mt-4 text-sm <?php echo $valid ? 'text-gray-500' : 'text-amber-600'; ?>">
            <?php echo e($reasonLabels[$reason] ?? $reason ?: '—'); ?>
        </div>

        <dl class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
            <div>
                <dt class="text-gray-400">到期日期</dt>
                <dd class="font-medium text-gray-800 mt-0.5"><?php echo e($exp ? (string) $exp : ($valid ? '永久' : '—')); ?></dd>
            </div>
            <div class="col-span-2">
                <dt class="text-gray-400">已开通模块</dt>
                <dd class="font-medium text-gray-800 mt-0.5">
                    <?php if ($mods): ?>
                        <?php echo e(implode('、', array_map(fn($m) => $moduleLabels[$m] ?? $m, $mods))); ?>
                    <?php else: ?>
                        <span class="text-gray-400">无（仅基础功能）</span>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
    </div>

    <!-- 授权码 -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1">授权码</h2>
        <?php if (function_exists('license_valid') && license_valid()): ?>
        <?php // 白标：已授权站点不展示购买链接，只留功能说明 ?>
        <p class="text-sm text-gray-500 mb-4">填写授权码并保存即自动开通对应模块。授权与本站域名绑定。</p>
        <?php else: ?>
        <p class="text-sm text-gray-500 mb-4">在 <a href="https://yikaicms.com" target="_blank" rel="noopener" class="text-primary hover:underline">yikaicms.com</a> 购买后获得授权码，填写并保存即自动开通对应模块。授权与本站域名绑定。</p>
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
            <button type="button" onclick="licShowInput()" class="border border-gray-300 hover:bg-gray-100 text-gray-700 px-6 py-2 rounded transition whitespace-nowrap">更换授权码</button>
        </div>
        <?php endif; ?>
        <form id="licenseForm" class="flex flex-col sm:flex-row gap-3 <?php echo $key !== '' ? 'hidden' : ''; ?>">
            <input type="text" name="license_key" value="" placeholder="输入完整授权码，如 XXXX-XXXX-XXXX-XXXX"
                   autocomplete="off" class="flex-1 border rounded px-4 py-2 font-mono tracking-wider">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition whitespace-nowrap">保存并校验</button>
            <?php if ($key !== ''): ?>
            <button type="button" onclick="licHideInput()" class="border border-gray-300 hover:bg-gray-100 text-gray-700 px-4 py-2 rounded transition whitespace-nowrap">取消</button>
            <?php endif; ?>
        </form>
        <p class="text-xs text-gray-400 mt-3">本站域名：<code class="bg-gray-100 px-1.5 py-0.5 rounded"><?php echo e(license_domain()); ?></code>　授权到期后仅停用付费模块，网站正常运行不受影响。</p>
    </div>

    <?php if (!$valid): ?>
    <!-- 未授权：展示专业版权益（已授权即白标，不展示营销内容） -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1">专业授权包含什么？</h2>
        <p class="text-sm text-gray-500 mb-4">一个授权码解锁全部官方 Pro 插件与模块，含后续新出的官方 Pro 插件，不另收费。</p>
        <div class="grid sm:grid-cols-2 gap-2 text-sm text-gray-600 mb-4">
            <div class="flex gap-2"><span class="text-primary">✓</span> SEO 工坊 Pro（AI 优化 meta / 301 重定向 / 全站体检）</div>
            <div class="flex gap-2"><span class="text-primary">✓</span> 图标工坊 Pro（全套图标包 / favicon 直接应用）</div>
            <div class="flex gap-2"><span class="text-primary">✓</span> AI 内容助手（撰写 / 润色 / 对话式改站）</div>
            <div class="flex gap-2"><span class="text-primary">✓</span> 统计接入 Pro + 表单线索中心</div>
            <div class="flex gap-2"><span class="text-primary">✓</span> 百度 SEO 套件 + 媒体加速 OSS</div>
            <div class="flex gap-2"><span class="text-primary">✓</span> 后台去版权白标（建站交付不露 CMS 品牌）</div>
        </div>
        <a href="https://www.yikaicms.com/pro.html" target="_blank" rel="noopener" class="inline-flex items-center gap-1 bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition text-sm">
            了解专业版权益与购买 <i class="ti ti-external-link text-base"></i>
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
document.getElementById('licenseForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var v = this.querySelector('input[name="license_key"]').value.trim();
    if (v === '' && !confirm('授权码为空——确定要清除本站授权吗？付费模块将停用。')) return;
    adminSave(this, { reload: true });
});
document.getElementById('btnRefresh').addEventListener('click', function () {
    adminSave({ action: 'refresh' }, { successMsg: '已重新校验', reload: true, button: this });
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
