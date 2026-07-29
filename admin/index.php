<?php
/**
 * YikaiCMS - 后台控制台
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

// 新站引导：关闭提示（AJAX）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'dismiss_onboard') {
    verifyCsrf();
    settingModel()->set('onboarding_channel_dismissed', '1');
    success([], 'ok');
}

// 统计数据
$stats = [
    'contents' => contentModel()->count(),
    'channels' => channelModel()->count(),
    'forms' => formModel()->count(['status' => 0]),
    'media' => mediaModel()->count(),
];

// 新站栏目引导卡：站点默认语言下没有任何非首页栏目、且未关闭提示时显示
$onbChannelCount = (int) (db()->fetchOne(
    "SELECT COUNT(*) AS c FROM " . DB_PREFIX . "channels WHERE lang = ? AND is_home = 0",
    [siteLang()]
)['c'] ?? 0);
$showOnboard = $onbChannelCount === 0 && (string) config('onboarding_channel_dismissed', '') !== '1';

// 最新内容（关联栏目类型）—— 只显示源语言行，避免 EN/JA 翻译版本污染列表
$_dashDefaultLang = (string) config('site_lang', 'zh-CN');
$latestContents = contentModel()->query(
    'SELECT c.*, ch.type AS channel_type FROM ' . contentModel()->tableName() . ' c '
    . 'LEFT JOIN ' . channelModel()->tableName() . ' ch ON c.channel_id = ch.id '
    . 'WHERE c.lang = ? ORDER BY c.id DESC LIMIT 10',
    [$_dashDefaultLang]
);

// 最新表单
$latestForms = formModel()->where([], 'id DESC', 10);

$pageTitle = __('admin_dashboard');
$currentMenu = 'dashboard';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php
// 更新提醒级别：all=全部 / security=仅安全更新 / off=关闭（未设置时兼容旧的布尔开关）
$__notifyLv = (string) config('update_notify_level', '');
if ($__notifyLv === '') {
    $__notifyLv = config('dashboard_update_check', '1') === '0' ? 'off' : 'all';
}
?>
<?php if (hasPermission('*') && $__notifyLv !== 'off'): ?>
<!-- 版本检测：显示当前版本，异步检查更新（结果本地缓存 6h，避免频繁请求更新服务器）；可关闭 -->
<div id="uoBar" class="bg-white rounded-lg shadow px-5 py-3 mb-6 flex items-center justify-between flex-wrap gap-3">
    <div class="flex items-center gap-2 text-sm text-gray-600">
        <i class="ti ti-versions text-gray-400"></i>
        <span><?php echo __('dashboard_version'); ?>：<b class="text-gray-800">v<?php echo e(defined('CMS_VERSION') ? CMS_VERSION : '?'); ?></b></span>
        <span id="uoStatus" class="text-gray-400 flex items-center gap-1">
            <i class="ti ti-loader-2 animate-spin text-xs"></i><?php echo __('dashboard_update_check'); ?>
        </span>
    </div>
    <a id="uoGo" href="/admin/upgrade_online.php" class="hidden items-center gap-1 bg-amber-500 hover:bg-amber-600 text-white px-4 py-1.5 rounded text-sm font-medium transition">
        <i class="ti ti-cloud-download text-base"></i><span><?php echo __('dashboard_update_go'); ?></span>
    </a>
</div>
<script>
(function () {
    var cur = <?php echo json_encode(defined('CMS_VERSION') ? CMS_VERSION : ''); ?>;
    var statusEl = document.getElementById('uoStatus');
    var goEl = document.getElementById('uoGo');
    var T = {
        uptodate: <?php echo json_encode(__('dashboard_update_uptodate')); ?>,
        available: <?php echo json_encode(__('dashboard_update_available')); ?>
    };
    var NOTIFY_LEVEL = <?php echo json_encode($__notifyLv); ?>;
    function render(d) {
        // 仅安全更新：非 security 级别的版本不提示（后端 releases.json 的 level 字段）
        if (NOTIFY_LEVEL === 'security' && d && d.has_update && d.level !== 'security') {
            d = { has_update: false };
        }
        if (d && d.has_update) {
            statusEl.className = 'text-amber-600 font-medium';
            statusEl.textContent = T.available + ' v' + d.latest_version;
            goEl.classList.remove('hidden');
            goEl.classList.add('inline-flex');
        } else {
            statusEl.className = 'text-green-600 flex items-center gap-1';
            statusEl.innerHTML = '<i class="ti ti-circle-check"></i>' + T.uptodate;
        }
    }
    // 本地缓存（按当前版本键控，6 小时内不重复请求）
    var key = 'yk_upd_' + cur + '_' + NOTIFY_LEVEL, TTL = 6 * 3600 * 1000;
    try {
        var c = JSON.parse(localStorage.getItem(key) || 'null');
        if (c && (Date.now() - c.t) < TTL) { render(c.d); return; }
    } catch (e) {}
    fetch('/admin/upgrade_online.php?action=check', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || res.code !== 0) { statusEl.textContent = ''; return; }
            var d = res.data || {};
            render(d);
            try { localStorage.setItem(key, JSON.stringify({ t: Date.now(), d: { has_update: d.has_update, latest_version: d.latest_version, level: d.level } })); } catch (e) {}
        })
        .catch(function () { statusEl.textContent = ''; });
})();
</script>
<?php endif; ?>

<?php if ($showOnboard): ?>
<!-- 新站栏目引导卡 -->
<div id="onbCard" class="relative bg-blue-50 border border-blue-200 rounded-lg p-5 mb-6 flex items-start gap-4">
    <div class="w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center flex-shrink-0">
        <i class="ti ti-align-left text-xl"></i>
    </div>
    <div class="flex-1 min-w-0">
        <h3 class="font-bold text-gray-800 mb-1"><?php echo e(__('onb_title')); ?></h3>
        <p class="text-sm text-gray-600 mb-3"><?php echo e(__('onb_body')); ?></p>
        <a href="/admin/channel_batch.php" class="inline-flex items-center gap-1 bg-primary hover:bg-secondary text-white px-4 py-2 rounded text-sm font-medium">
            <i class="ti ti-plus text-base"></i>
            <?php echo e(__('chbatch_title')); ?>
        </a>
    </div>
    <button type="button" id="onbDismiss" class="text-gray-400 hover:text-gray-600 text-sm flex-shrink-0"><?php echo e(__('onb_dismiss')); ?></button>
</div>
<script>
document.getElementById('onbDismiss')?.addEventListener('click', async function () {
    var fd = new FormData();
    fd.set('_token', '<?php echo csrfToken(); ?>');
    fd.set('action', 'dismiss_onboard');
    try { await fetch('', { method: 'POST', body: fd }); } catch (e) {}
    var c = document.getElementById('onbCard'); if (c) c.remove();
});
</script>
<?php endif; ?>

<!-- クイックアクセス（按权限显示，非超管只见自己能用的入口） -->
<?php
// [url, 图标, 图标盒class(字面量供Tailwind扫描), 图标色, lang键, 所需权限]
$__quick = [
    ['/admin/setting.php',            'ti-settings',        'bg-blue-50 group-hover:bg-blue-100',     'text-blue-600',   'dashboard_quick_setting',  '*'],
    ['/admin/setting_home.php',       'ti-home',            'bg-green-50 group-hover:bg-green-100',   'text-green-600',  'dashboard_quick_home',     '*'],
    ['/admin/setting_contact.php',    'ti-phone',           'bg-cyan-50 group-hover:bg-cyan-100',     'text-cyan-600',   'dashboard_quick_contact',  '*'],
    ['/admin/database.php?tab=backup','ti-database',        'bg-purple-50 group-hover:bg-purple-100', 'text-purple-600', 'dashboard_quick_database', '*'],
    ['/admin/banner.php',             'ti-photo',           'bg-amber-50 group-hover:bg-amber-100',   'text-amber-600',  'dashboard_quick_banner',   'banner'],
    ['/admin/channel.php',            'ti-align-justified', 'bg-rose-50 group-hover:bg-rose-100',     'text-rose-600',   'dashboard_quick_channel',  '*'],
    // 内容编辑常用入口（非超管也可见，凭内容权限）
    ['/admin/article.php',            'ti-file-text',       'bg-indigo-50 group-hover:bg-indigo-100', 'text-indigo-600', 'dashboard_quick_article',  'edit_article'],
    ['/admin/product.php',            'ti-package',         'bg-teal-50 group-hover:bg-teal-100',     'text-teal-600',   'dashboard_quick_product',  'edit_product'],
];
$__quick = array_values(array_filter($__quick, fn($q) => hasPermission($q[5])));
?>
<?php if ($__quick): ?>
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
    <?php foreach ($__quick as [$url, $icon, $boxClass, $iconColor, $langKey]): ?>
    <a href="<?php echo e($url); ?>" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition flex flex-col items-center gap-2 group">
        <div class="w-10 h-10 <?php echo $boxClass; ?> rounded-lg flex items-center justify-center transition">
            <i class="ti <?php echo e($icon); ?> text-lg <?php echo $iconColor; ?>"></i>
        </div>
        <span class="text-sm text-gray-600 font-medium"><?php echo __($langKey); ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 統計カード -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="ti ti-file-text text-xl text-blue-600"></i>
            </div>
            <div class="ml-4">
                <p class="text-gray-500 text-sm"><?php echo __('dashboard_total_contents'); ?></p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['contents']); ?></p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="ti ti-align-justified text-xl text-green-600"></i>
            </div>
            <div class="ml-4">
                <p class="text-gray-500 text-sm"><?php echo __('dashboard_category_count'); ?></p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['channels']); ?></p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                <i class="ti ti-message-dots text-xl text-yellow-600"></i>
            </div>
            <div class="ml-4">
                <p class="text-gray-500 text-sm"><?php echo __('dashboard_pending_forms'); ?></p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['forms']); ?></p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="ti ti-photo text-xl text-purple-600"></i>
            </div>
            <div class="ml-4">
                <p class="text-gray-500 text-sm"><?php echo __('dashboard_media_files'); ?></p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['media']); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- 内容列表 -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- 最新内容 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h2 class="font-bold text-gray-800"><?php echo __('dashboard_latest_contents'); ?></h2>
            <a href="/admin/content.php" class="text-primary text-sm hover:underline"><?php echo __('dashboard_see_all'); ?></a>
        </div>
        <div class="p-6">
            <?php if (empty($latestContents)): ?>
            <p class="text-gray-500 text-center py-4"><?php echo __('dashboard_no_contents'); ?></p>
            <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($latestContents as $item): ?>
                <li class="flex justify-between items-center">
                    <div class="flex items-center">
                        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded mr-2">
                            <?php echo e($item['type']); ?>
                        </span>
                        <?php
                        $editUrl = ($item['channel_type'] ?? '') === 'page'
                            ? '/admin/page_edit_advance.php?id=' . $item['channel_id']
                            : '/admin/article_edit.php?id=' . $item['id'];
                        ?>
                        <a href="<?php echo $editUrl; ?>" class="text-gray-700 hover:text-primary truncate max-w-xs">
                            <?php echo e($item['title']); ?>
                        </a>
                    </div>
                    <span class="text-gray-400 text-sm"><?php echo date('m-d H:i', (int)$item['created_at']); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- 最新表单 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h2 class="font-bold text-gray-800"><?php echo __('dashboard_latest_forms'); ?></h2>
            <a href="/admin/form.php" class="text-primary text-sm hover:underline"><?php echo __('dashboard_see_all'); ?></a>
        </div>
        <div class="p-6">
            <?php if (empty($latestForms)): ?>
            <p class="text-gray-500 text-center py-4"><?php echo __('dashboard_no_forms'); ?></p>
            <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($latestForms as $item): ?>
                <li>
                    <a href="/admin/form.php?view=<?php echo $item['id']; ?>" class="flex justify-between items-center hover:bg-gray-50 -mx-2 px-2 py-1 rounded transition">
                        <div class="flex items-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded mr-2">
                                <?php echo e($item['type']); ?>
                            </span>
                            <span class="text-gray-700"><?php echo e($item['name']); ?></span>
                            <span class="text-gray-400 ml-2"><?php echo e($item['phone']); ?></span>
                        </div>
                        <span class="text-gray-400 text-sm"><?php echo date('m-d H:i', (int)$item['created_at']); ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
