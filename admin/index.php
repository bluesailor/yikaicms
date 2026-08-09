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
        available: <?php echo json_encode(__('dashboard_update_available')); ?>,
        checking: <?php echo json_encode(__('dashboard_update_checking')); ?>,
        recheck: <?php echo json_encode(__('dashboard_update_recheck')); ?>
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
    // 本地缓存（按当前版本 + 提醒级别键控）。
    // 「已是最新」只缓存 1 小时：新版发布后，这条结果就成了错的，而它和
    // 「检测坏了」在界面上看不出区别——压着 6 小时不重查，管理员会以为升级检测挂了。
    // 「有新版」缓存 6 小时：横幅已经挂出来了，再频繁复查没有意义。
    var key = 'yk_upd_' + cur + '_' + NOTIFY_LEVEL;
    var TTL_NONE = 3600 * 1000, TTL_HAS = 6 * 3600 * 1000;

    function cached() {
        try {
            var c = JSON.parse(localStorage.getItem(key) || 'null');
            if (!c) return null;
            var ttl = (c.d && c.d.has_update) ? TTL_HAS : TTL_NONE;
            return (Date.now() - c.t) < ttl ? c.d : null;
        } catch (e) { return null; }
    }

    function check(force) {
        if (!force) {
            var c = cached();
            if (c) { render(c); return; }
        }
        statusEl.className = 'text-gray-400';
        statusEl.textContent = T.checking;
        fetch('/admin/upgrade_online.php?action=check', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || res.code !== 0) { statusEl.textContent = ''; return; }
                var d = res.data || {};
                render(d);
                try { localStorage.setItem(key, JSON.stringify({ t: Date.now(), d: { has_update: d.has_update, latest_version: d.latest_version, level: d.level } })); } catch (e) {}
            })
            .catch(function () { statusEl.textContent = ''; });
    }

    // 点状态文字可强制重查——缓存没到期时也能立刻拿到真实结果，
    // 省得怀疑是检测坏了。
    statusEl.style.cursor = 'pointer';
    statusEl.title = T.recheck;
    statusEl.addEventListener('click', function () { check(true); });

    check(false);
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

<?php
require_once ROOT_PATH . '/admin/includes/menu_usage.php';
$__adminId = (int) (getAdminInfo()['id'] ?? 0);
// ── 常用面板默认清单：按安装预置角色分套（1超管/2投稿者/3内容编辑/4内容主管/5运营），
//    自建角色回退超管清单；无论哪套都再过一遍权限闸兜底。管理员收藏过（☆）则收藏优先。──
$__qkCatalog = [
    '/admin/setting_home.php'    => ['ti-home',             'bg-green-50 group-hover:bg-green-100',   'text-green-600',   'dashboard_quick_home',    '*'],
    '/admin/setting_contact.php' => ['ti-phone',            'bg-cyan-50 group-hover:bg-cyan-100',     'text-cyan-600',    'dashboard_quick_contact', '*'],
    '/admin/setting.php'         => ['ti-settings',         'bg-blue-50 group-hover:bg-blue-100',     'text-blue-600',    'dashboard_quick_setting', '*'],
    '/admin/database.php'        => ['ti-database',         'bg-purple-50 group-hover:bg-purple-100', 'text-purple-600',  'dashboard_quick_database','*'],
    '/admin/banner.php'          => ['ti-photo',            'bg-amber-50 group-hover:bg-amber-100',   'text-amber-600',   'dashboard_quick_banner',  'banner'],
    '/admin/channel.php'         => ['ti-align-justified',  'bg-rose-50 group-hover:bg-rose-100',     'text-rose-600',    'dashboard_quick_channel', '*'],
    '/admin/page.php'            => ['ti-file-description', 'bg-lime-50 group-hover:bg-lime-100',     'text-lime-600',    'dashboard_quick_page',    'edit_page'],
    '/admin/article.php'         => ['ti-file-text',        'bg-indigo-50 group-hover:bg-indigo-100', 'text-indigo-600',  'dashboard_quick_article', 'edit_article'],
    '/admin/product.php'         => ['ti-package',          'bg-teal-50 group-hover:bg-teal-100',     'text-teal-600',    'dashboard_quick_product', 'edit_product'],
    '/admin/download.php'        => ['ti-download',         'bg-sky-50 group-hover:bg-sky-100',       'text-sky-600',     'admin_download',          'edit_download'],
    '/admin/case.php'            => ['ti-briefcase',        'bg-orange-50 group-hover:bg-orange-100', 'text-orange-600',  'admin_case',              'edit_case'],
    '/admin/media.php'           => ['ti-photo-cog',        'bg-fuchsia-50 group-hover:bg-fuchsia-100','text-fuchsia-600','admin_media',             'media'],
    '/admin/job.php'             => ['ti-id-badge-2',       'bg-emerald-50 group-hover:bg-emerald-100','text-emerald-600','admin_job',               'edit_job'],
    '/admin/timeline.php'        => ['ti-timeline',         'bg-slate-50 group-hover:bg-slate-100',   'text-slate-600',   'admin_timeline',          'edit_timeline'],
    '/admin/form.php'            => ['ti-message-dots',     'bg-yellow-50 group-hover:bg-yellow-100', 'text-yellow-600',  'admin_form',              'form'],
    '/admin/member.php'          => ['ti-users',            'bg-violet-50 group-hover:bg-violet-100', 'text-violet-600',  'admin_member',            'member'],
    '/admin/link.php'            => ['ti-link',             'bg-pink-50 group-hover:bg-pink-100',     'text-pink-600',    'admin_link',              'link'],
];
$__qkByRole = [
    // 1 超级管理员：站点管理全景
    1 => ['/admin/setting_home.php', '/admin/setting_contact.php', '/admin/setting.php', '/admin/database.php',
          '/admin/banner.php', '/admin/channel.php', '/admin/page.php', '/admin/product.php', '/admin/download.php'],
    // 2 投稿者：只写文章相关
    2 => ['/admin/article.php', '/admin/job.php', '/admin/timeline.php'],
    // 3 内容编辑：全类内容 + 媒体
    3 => ['/admin/article.php', '/admin/product.php', '/admin/page.php', '/admin/case.php',
          '/admin/download.php', '/admin/media.php'],
    // 4 内容主管：内容编辑 + 招聘/时间轴
    4 => ['/admin/article.php', '/admin/product.php', '/admin/page.php', '/admin/case.php',
          '/admin/download.php', '/admin/job.php', '/admin/media.php'],
    // 5 运营：内容 + 表单/会员/轮播/友链
    5 => ['/admin/article.php', '/admin/product.php', '/admin/banner.php', '/admin/form.php',
          '/admin/member.php', '/admin/link.php', '/admin/media.php', '/admin/page.php'],
];
$__roleId = (int) (getAdminInfo()['role_id'] ?? 0);
$__quick = [];
foreach (($__qkByRole[$__roleId] ?? $__qkByRole[1]) as $__u) {
    if (!isset($__qkCatalog[$__u])) continue;
    [$__icon, $__box, $__color, $__lang, $__perm] = $__qkCatalog[$__u];
    if (!hasPermission($__perm)) continue;
    $__quick[] = [$__u, $__icon, $__box, $__color, $__lang];
}
// 标题/图标按当前语言取自侧栏定义（FindItem 返回的就是本地化后的 label）。
// 库里存的 title 是「使用当刻」的语言快照——英文后台里点过的页面会以英文标题
// 入库，切回中文后原样吐出（用户实测报过）。渲染时一律重取，存量脏数据自愈。
$__recent = [];
foreach (adminMenuUsageRecent($__adminId, 8) as $row) {
    $item = adminMenuUsageFindItem($sidebarMenu, (string) ($row['url'] ?? ''));
    if ($item === null) {
        continue;
    }
    $row['title'] = $item['title'] !== '' ? $item['title'] : (string) ($row['title'] ?? '');
    $row['icon']  = $item['icon'] !== '' ? $item['icon'] : (string) ($row['icon'] ?? '');
    $__recent[] = $row;
}
?>
<?php if ($__quick): ?>
<div class="mb-8">
    <div class="flex items-center justify-between mb-2">
        <span class="text-xs text-gray-400 inline-flex items-center gap-1">
            <i class="ti ti-bolt text-sm"></i><?php echo __('dashboard_quick_title'); ?>
            <span class="hidden sm:inline">（<?php echo __('dashboard_fav_hint'); ?>）</span>
        </span>
    </div>
    <div id="quickGrid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        <?php foreach ($__quick as [$url, $icon, $boxClass, $iconColor, $langKey]): ?>
        <a href="<?php echo e($url); ?>" data-qk="<?php echo e($url); ?>" draggable="false"
           class="bg-white rounded-lg shadow p-4 hover:shadow-md transition flex flex-col items-center gap-2 group cursor-move">
            <div class="w-10 h-10 <?php echo $boxClass; ?> rounded-lg flex items-center justify-center transition">
                <i class="ti <?php echo e($icon); ?> text-lg <?php echo $iconColor; ?>"></i>
            </div>
            <span class="text-sm text-gray-600 font-medium"><?php echo __($langKey); ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// 常用面板 × 星标收藏：有收藏时用收藏渲染（可拖拽排序），没有收藏保留默认卡片。
// 图标与文案从侧栏菜单 DOM 采集（header 的 ykFav 模块保证其已就绪）。
(function () {
    var QK_COLORS = [
        ['bg-blue-50', 'text-blue-600'], ['bg-green-50', 'text-green-600'],
        ['bg-cyan-50', 'text-cyan-600'], ['bg-purple-50', 'text-purple-600'],
        ['bg-amber-50', 'text-amber-600'], ['bg-rose-50', 'text-rose-600'],
        ['bg-lime-50', 'text-lime-600'], ['bg-indigo-50', 'text-indigo-600'],
        ['bg-teal-50', 'text-teal-600'], ['bg-orange-50', 'text-orange-600'],
    ];
    function labelOf(a) {
        var c = a.cloneNode(true);
        Array.prototype.forEach.call(c.querySelectorAll('svg, .yk-fav-btn, .rounded-full'), function (n) { n.remove(); });
        return (c.textContent || '').trim();
    }
    function render() {
        var grid = document.getElementById('quickGrid');
        if (!grid || !window.ykFav) return;
        var list = ykFav.get();
        if (!list || !list.length) return;   // 未收藏 → 默认卡片原样保留
        grid.innerHTML = '';
        list.forEach(function (url, i) {
            var src = ykFav.links().filter(function (a) { return a.getAttribute('href') === url; })[0];
            if (!src) return;
            var c = QK_COLORS[i % QK_COLORS.length];
            var a = document.createElement('a');
            a.href = url;
            a.setAttribute('data-qk', url);
            a.className = 'bg-white rounded-lg shadow p-4 hover:shadow-md transition flex flex-col items-center gap-2 cursor-move';
            // <a> 的原生链接拖拽会抢走指针，Sortable 就拖不动了
            a.setAttribute('draggable', 'false');
            var box = document.createElement('span');
            box.className = 'w-10 h-10 ' + c[0] + ' rounded-lg flex items-center justify-center';
            var svg = src.querySelector('svg');
            if (svg) {
                var s2 = svg.cloneNode(true);
                s2.setAttribute('class', 'w-5 h-5 ' + c[1]);
                box.appendChild(s2);
            } else {
                box.innerHTML = '<i class="ti ti-star text-lg ' + c[1] + '"></i>';
            }
            a.appendChild(box);
            var t = document.createElement('span');
            t.className = 'text-sm text-gray-600 font-medium';
            t.textContent = labelOf(src);
            a.appendChild(t);
            grid.appendChild(a);
        });
        bindSort(grid);
    }

    // 拖拽排序：默认卡片与收藏卡片都要能拖，所以独立于 render 的早退分支
    function bindSort(grid) {
        if (!grid || typeof Sortable === 'undefined' || grid._srt) return;
        grid._srt = new Sortable(grid, {
            animation: 150,
            draggable: '[data-qk]',
            // 触屏上先长按再拖，避免与页面滚动冲突
            delay: 120,
            delayOnTouchOnly: true,
            // 拖动全程锁定十字箭头光标：掠过其他元素时不会跳回默认指针
            onStart: function () { document.body.style.cursor = 'move'; },
            onEnd: function () {
                document.body.style.cursor = '';
                if (!window.ykFav) return;
                var order = Array.prototype.map.call(grid.querySelectorAll('[data-qk]'), function (el) {
                    return el.getAttribute('data-qk');
                });
                ykFav.save(order);
            }
        });
    }
    window.addEventListener('ykfav:change', render);
    render();
    bindSort(document.getElementById('quickGrid'));
})();
</script>

<?php if ($__recent): ?>
<div class="mb-8">
    <div class="text-xs text-gray-400 mb-2 inline-flex items-center gap-1">
        <i class="ti ti-history text-sm"></i><?php echo __('dashboard_recent'); ?>
    </div>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($__recent as $row): ?>
        <a href="<?php echo e((string) $row['url']); ?>" class="bg-white rounded-lg shadow px-3 py-1.5 text-sm text-gray-600 hover:text-primary hover:shadow-md transition inline-flex items-center gap-1.5">
            <i class="ti <?php echo e((string) ($row['icon'] ?: 'ti-corner-up-right')); ?> text-sm text-gray-400"></i>
            <?php echo e((string) ($row['title'] ?: $row['url'])); ?>
        </a>
        <?php endforeach; ?>
    </div>
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
