<?php
/**
 * YikaiCMS - 导航菜单管理
 *
 * 两种菜单来源，一页管理：
 *   - 默认导航 = 栏目树 is_nav 投影（哪些栏目上导航、顺序）——第一个 Tab；
 *   - 菜单组（多组，WP menus 语义）= 组名 + 项（栏目引用/自定义链接，三级嵌套）。
 *     nav-mega / nav-drawer 元素在 Blox 里选「菜单来源」即用组渲染。
 * 菜单外观（mega/下拉/抽屉样式）在 Blox 头部模板里编辑，页顶给引导入口。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];

$activeGroup = (int) ($_GET['group'] ?? 0);
$homeTextKey = $_viewLang === $_defaultLang ? 'nav_home_text' : 'nav_home_text_' . $_viewLang;
$homeShowKey = $_viewLang === $_defaultLang ? 'nav_home_show' : 'nav_home_show_' . $_viewLang;
$footerNavKey = $_viewLang === $_defaultLang ? 'footer_nav' : 'footer_nav_' . $_viewLang;
$rootOrderKey = $_viewLang === $_defaultLang ? 'nav_root_order' : 'nav_root_order_' . $_viewLang;

$defaultHomeLabel = static function () use ($_viewLang): string {
    $viewLangFile = ROOT_PATH . '/lang/' . basename($_viewLang) . '.php';
    $viewLangData = is_file($viewLangFile) ? require $viewLangFile : [];
    return is_array($viewLangData) && isset($viewLangData['nav_home'])
        ? (string) $viewLangData['nav_home']
        : __('nav_home');
};
$currentHomeLabel = static function () use ($homeTextKey, $defaultHomeLabel): string {
    $label = trim((string) config($homeTextKey, ''));
    return $label !== '' ? $label : $defaultHomeLabel();
};
$syncFooterHome = static function (?bool $visible, string $label) use ($footerNavKey): void {
    $groups = json_decode((string) config($footerNavKey, '[]'), true);
    $groups = is_array($groups) ? $groups : [];
    $wasVisible = false;
    foreach ($groups as &$group) {
        $links = is_array($group['links'] ?? null) ? $group['links'] : [];
        foreach ($links as $link) {
            if (is_array($link) && (string) ($link['url'] ?? '') === '/') {
                $wasVisible = true;
            }
        }
        $group['links'] = array_values(array_filter(
            $links,
            static fn (mixed $link): bool => is_array($link) && (string) ($link['url'] ?? '') !== '/'
        ));
    }
    unset($group);

    $visible ??= $wasVisible;
    if ($visible) {
        if ($groups === []) {
            $groups[] = ['title' => '', 'links' => []];
        }
        $groups[0]['links'] = is_array($groups[0]['links'] ?? null) ? $groups[0]['links'] : [];
        $groups[0]['links'][] = ['name' => $label, 'url' => '/', 'target' => '_self'];
    }
    $groups = array_values(array_filter(
        $groups,
        static fn (mixed $group): bool => is_array($group) && !empty($group['links'])
    ));
    settingModel()->saveBatch([
        $footerNavKey => json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
};

// 升级期守卫：文件已更新但迁移未跑（nav_menus 表缺）→ 菜单组功能降级隐藏，
// 页面显示升级引导而不是 500（默认导航 Tab 只依赖 channels 表，照常可用）
$navMenusReady = db()->tableExists('nav_menus');

// ============ POST 分发 ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? 'save_nav');
    $backLang = $_viewLang !== $_defaultLang ? '&lang=' . urlencode($_viewLang) : '';

    $groupActions = ['create_group', 'rename_group', 'delete_group', 'save_group'];
    if (in_array($action, $groupActions, true) && !$navMenusReady) {
        header('Location: /admin/nav_menu.php');
        exit;
    }

    if ($action === 'sort_nav') {
        // 首页只参与一级菜单排序，不允许成为子菜单；栏目顺序仍写回原 sort_order。
        $tokens = is_array($_POST['ids'] ?? null) ? array_values(array_map('strval', $_POST['ids'])) : [];
        $parentId = postInt('parent');
        $ids = array_values(array_filter(array_map('intval', $tokens), static fn (int $id): bool => $id > 0));
        channelModel()->updateSort($ids);
        if ($parentId === 0 && in_array('home', $tokens, true)) {
            $cleanTokens = array_values(array_unique(array_filter(
                $tokens,
                static fn (string $token): bool => $token === 'home' || ctype_digit($token) && (int) $token > 0
            )));
            settingModel()->saveBatch([
                $rootOrderKey => json_encode($cleanTokens, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        }
        success();
    }

    if ($action === 'toggle_nav') {
        $id = postInt('id');
        if ($id > 0) {
            channelModel()->updateById($id, ['is_nav' => postInt('val') === 1 ? 1 : 0]);
        }
        success();
    }

    if ($action === 'toggle_nav_icons') {
        settingModel()->saveBatch(['nav_icons_enabled' => postInt('val') === 1 ? '1' : '0']);
        success();
    }

    if ($action === 'toggle_home_nav') {
        $homeShow = postInt('val') === 1 ? '1' : '0';
        settingModel()->saveBatch([$homeShowKey => $homeShow]);
        adminLog('setting', 'nav_home_show', '切换 ' . $homeShowKey . ' = ' . $homeShow);
        success();
    }

    if ($action === 'toggle_home_footer_nav') {
        $visible = postInt('val') === 1;
        $syncFooterHome($visible, $currentHomeLabel());
        adminLog('setting', 'nav_home_footer', '切换 ' . $footerNavKey . ' 首页入口 = ' . ($visible ? '1' : '0'));
        success();
    }

    if ($action === 'save_home_nav') {
        $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 100);
        settingModel()->saveBatch([$homeTextKey => $label]);
        $syncFooterHome(null, $label !== '' ? $label : $defaultHomeLabel());
        adminLog('setting', 'nav_home_text', '更新 ' . $homeTextKey);
        header('Location: /admin/nav_menu.php?saved=1' . $backLang);
        exit;
    }

    if ($action === 'save_nav') {
        // 栏目投影：is_nav / sort_order 批量（仅这两个字段，白名单）
        $ids = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
        $navMap = is_array($_POST['nav'] ?? null) ? $_POST['nav'] : [];
        $sortMap = is_array($_POST['sort'] ?? null) ? $_POST['sort'] : [];
        foreach ($ids as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            // 走 Model 层更新 → data_changed 钩子自动失效 HtmlCache/静态页
            channelModel()->updateById($id, [
                'is_nav' => isset($navMap[$id]) ? 1 : 0,
                'sort_order' => (int) ($sortMap[$id] ?? 0),
            ]);
        }
        header('Location: /admin/nav_menu.php?saved=1' . $backLang);
        exit;
    }

    if ($action === 'create_group') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
        if ($name !== '') {
            $newId = navMenuModel()->create([
                'name' => $name,
                'items' => '[]',
                'created_at' => time(),
                'updated_at' => time(),
            ]);
            header('Location: /admin/nav_menu.php?group=' . (int) $newId . $backLang);
            exit;
        }
        header('Location: /admin/nav_menu.php' . ($backLang !== '' ? '?' . ltrim($backLang, '&') : ''));
        exit;
    }

    if ($action === 'rename_group') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
        if ($id > 0 && $name !== '') {
            navMenuModel()->updateById($id, ['name' => $name, 'updated_at' => time()]);
        }
        header('Location: /admin/nav_menu.php?group=' . $id . '&saved=1' . $backLang);
        exit;
    }

    if ($action === 'delete_group') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            navMenuModel()->deleteById($id);
        }
        header('Location: /admin/nav_menu.php' . ($backLang !== '' ? '?' . ltrim($backLang, '&') : ''));
        exit;
    }

    if ($action === 'save_group') {
        $id = (int) ($_POST['id'] ?? 0);
        $decoded = json_decode((string) ($_POST['items'] ?? '[]'), true);
        if ($id > 0) {
            $count = 0;
            $clean = navMenuModel()->sanitizeItems($decoded, 1, $count);
            navMenuModel()->updateById($id, [
                'items' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => time(),
            ]);
        }
        header('Location: /admin/nav_menu.php?group=' . $id . '&saved=1' . $backLang);
        exit;
    }
}

// ============ 数据 ============
$groups = $navMenusReady ? navMenuModel()->all() : [];
$editGroup = null;
foreach ($groups as $g) {
    if ((int) $g['id'] === $activeGroup) {
        $editGroup = $g;
        break;
    }
}
if ($editGroup === null) {
    $activeGroup = 0;
}

// 栏目投影树（默认导航 Tab）
$langWhere = '';
$langParams = [];
if (isMultiLangEnabled('channels')) {
    $langWhere = ' AND lang = ?';
    $langParams[] = $_viewLang;
}
$rows = db()->fetchAll(
    'SELECT id, parent_id, name, slug, type, is_nav, sort_order, status FROM ' . DB_PREFIX . 'channels'
    . ' WHERE status = 1' . $langWhere . ' ORDER BY sort_order ASC, id ASC',
    $langParams
);
$byParent = [];
foreach ($rows as $row) {
    $byParent[(int) $row['parent_id']][] = $row;
}

$rootSequence = json_decode((string) config($rootOrderKey, ''), true);
$rootSequence = is_array($rootSequence) ? array_values(array_unique(array_map('strval', $rootSequence))) : [];
$rootRowsById = [];
foreach ($byParent[0] ?? [] as $rootRow) {
    $rootRowsById[(string) ((int) $rootRow['id'])] = $rootRow;
}
$orderedRootRows = [];
$homePosition = 0;
$homeFound = false;
foreach ($rootSequence as $token) {
    if ($token === 'home') {
        $homePosition = count($orderedRootRows);
        $homeFound = true;
        continue;
    }
    if (isset($rootRowsById[$token])) {
        $orderedRootRows[] = $rootRowsById[$token];
        unset($rootRowsById[$token]);
    }
}
foreach ($rootRowsById as $rootRow) {
    $orderedRootRows[] = $rootRow;
}
if (!$homeFound) {
    $homePosition = $rootSequence === [] ? 0 : count($orderedRootRows);
}
$byParent[0] = $orderedRootRows;

// 组编辑器：栏目平面下拉（缩进层级）+ id→名称映射（前端展示引用项）
$flatChannels = [];
$walkFlat = static function (array $byParent, int $pid, int $level) use (&$walkFlat, &$flatChannels): void {
    foreach ($byParent[$pid] ?? [] as $row) {
        $flatChannels[] = ['id' => (int) $row['id'], 'name' => str_repeat('　', $level) . (string) $row['name']];
        $walkFlat($byParent, (int) $row['id'], $level + 1);
    }
};
$walkFlat($byParent, 0, 0);
$channelNameMap = [];
foreach ($rows as $row) {
    $channelNameMap[(int) $row['id']] = (string) $row['name'];
}

$typeLabels = [
    'page' => __('channel_type_page'),
    'list' => __('channel_type_list'),
    'product' => __('channel_type_product'),
    'album' => __('channel_type_album'),
    'case' => __('channel_type_case'),
    'download' => __('channel_type_download'),
    'job' => __('channel_type_job'),
];
$homeTextValue = trim((string) config($homeTextKey, ''));
$homeName = $currentHomeLabel();
$homeVisible = (string) config($homeShowKey, '1') !== '0';
$footerNav = json_decode((string) config($footerNavKey, '[]'), true);
$homeInFooter = false;
foreach (is_array($footerNav) ? $footerNav : [] as $footerGroup) {
    foreach (is_array($footerGroup['links'] ?? null) ? $footerGroup['links'] : [] as $footerLink) {
        if (is_array($footerLink) && (string) ($footerLink['url'] ?? '') === '/') {
            $homeInFooter = true;
            break 2;
        }
    }
}
$totalChannels = count($rows) + 1;
$visibleChannels = count(array_filter($rows, static fn (array $row): bool => (int) $row['is_nav'] === 1))
    + ($homeVisible ? 1 : 0);

$pageTitle = __('admin_nav_menu');
$currentMenu = 'nav_menu';

require_once ROOT_PATH . '/admin/includes/header.php';
?>
<?php // 菜单项图标预览需要 bi: 前缀集（后台全局只带 Tabler） ?>
<link rel="stylesheet" href="/assets/bootstrap-icons/bootstrap-icons.min.css">
<?php
?>

<?php if (count($_enabledList) > 1): ?>
<div class="bg-white rounded-lg shadow mb-4 px-5 py-3 flex items-center gap-3 flex-wrap text-sm">
    <span class="text-gray-500"><?php echo e(__('admin_view_lang')); ?></span>
    <?php
    $_langLabels = ['zh-CN' => '中文', 'en' => 'English', 'ja' => '日本語'];
    foreach ($_enabledList as $_lc):
    ?>
    <a href="?lang=<?php echo e($_lc); ?>&group=<?php echo $activeGroup; ?>"
       class="px-3 py-1 rounded-full transition <?php echo $_lc === $_viewLang ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
        <?php echo e($_langLabels[$_lc] ?? $_lc); ?><?php if ($_lc === $_defaultLang): ?><span class="ml-1 text-[10px] opacity-70">(<?php echo e(__('lang_source')); ?>)</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php // 菜单图标站点级总开关：开=全部菜单项自动匹配图标（栏目/菜单项手动配置优先） ?>
<div class="bg-white rounded-lg shadow mb-4 px-5 py-3 flex items-center gap-3 flex-wrap text-sm" data-testid="nav-icons-toggle-card">
    <i class="ti ti-icons text-lg text-gray-500" aria-hidden="true"></i>
    <span class="font-medium text-gray-800"><?php echo e(__('nav_icons_enable_label')); ?></span>
    <label class="relative inline-flex items-center cursor-pointer">
        <input type="checkbox" id="navIconsToggle" class="sr-only peer" <?php echo (string) config('nav_icons_enabled', '0') === '1' ? 'checked' : ''; ?>
               onchange="ykToggleNavIcons(this)">
        <span class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:bg-primary transition-colors"></span>
        <span class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-4"></span>
    </label>
    <span class="text-gray-400 text-xs"><?php echo e(__('nav_icons_enable_help')); ?></span>
</div>
<script>
function ykToggleNavIcons(el) {
    var fd = new FormData();
    fd.append('action', 'toggle_nav_icons');
    fd.append('val', el.checked ? '1' : '0');
    fd.append('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
    fetch('nav_menu.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
}
</script>

<?php if (isset($_GET['saved'])): ?>
<div class="bg-emerald-50 border border-emerald-200 rounded-lg px-5 py-3 mb-4 text-sm text-emerald-700" data-testid="nav-menu-saved">
    <i class="ti ti-check"></i> <?php echo e(__('save_success')); ?>
</div>
<?php endif; ?>

<div class="bg-blue-50 border border-blue-100 rounded-lg px-5 py-3 mb-4 flex items-start gap-2 text-sm text-blue-700">
    <i class="ti ti-palette text-base mt-0.5"></i>
    <div>
        <?php echo e(__('nav_menu_style_hint')); ?>
        <a href="/admin/blox_templates.php" class="font-medium underline hover:no-underline"><?php echo e(__('blox_template_library')); ?></a>
    </div>
</div>

<script src="/assets/sortable/Sortable.min.js"></script>

<!-- Tab：默认导航 + 各菜单组 + 新建 -->
<div class="flex items-center gap-2 flex-wrap mb-4" data-testid="nav-menu-tabs">
    <a href="/admin/nav_menu.php" class="px-4 py-1.5 rounded-lg text-sm <?php echo $activeGroup === 0 ? 'bg-primary text-white' : 'bg-white shadow text-gray-600 hover:text-gray-900'; ?>">
        <?php echo e(__('nav_menu_default_tab')); ?>
    </a>
    <?php foreach ($groups as $g): ?>
    <a href="/admin/nav_menu.php?group=<?php echo (int) $g['id']; ?>"
       class="px-4 py-1.5 rounded-lg text-sm <?php echo $activeGroup === (int) $g['id'] ? 'bg-primary text-white' : 'bg-white shadow text-gray-600 hover:text-gray-900'; ?>">
        <?php echo e((string) $g['name']); ?>
    </a>
    <?php endforeach; ?>
    <?php if (!$navMenusReady): ?>
    <span class="text-xs text-amber-600 bg-amber-50 border border-amber-200 rounded-lg px-3 py-1.5">
        <i class="ti ti-alert-triangle"></i> <?php echo e(__('nav_menu_need_upgrade')); ?>
        <a href="/admin/upgrade.php" class="underline font-medium"><?php echo e(__('nav_menu_go_upgrade')); ?></a>
    </span>
    <?php else: ?>
    <form method="post" class="inline-flex items-center gap-1">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="create_group">
        <input type="text" name="name" required maxlength="100" placeholder="<?php echo e(__('nav_menu_new_group_placeholder')); ?>"
               class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-36" data-testid="nav-menu-new-group-name">
        <button type="submit" class="text-sm text-primary hover:opacity-80 px-2 py-1.5" data-testid="nav-menu-new-group">
            <i class="ti ti-plus"></i> <?php echo e(__('nav_menu_new_group')); ?>
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if ($activeGroup === 0 || $editGroup === null): ?>
<!-- ============ 默认导航：栏目投影（拖拽排序 + 即时显隐） ============ -->
<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden" data-nm-default>
    <div class="px-5 py-4 border-b border-gray-100 flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h2 class="font-semibold text-gray-800"><?php echo e(__('nav_menu_default_tab')); ?></h2>
            <p class="mt-1 text-xs text-gray-500"><?php echo e(__('nav_menu_default_desc')); ?></p>
        </div>
        <div class="flex items-center gap-2 text-xs" aria-label="<?php echo e(__('nav_menu_summary')); ?>">
            <span class="rounded border border-gray-200 bg-gray-50 px-2 py-1 text-gray-600">
                <?php echo e(__('nav_menu_total_count', ['n' => $totalChannels])); ?>
            </span>
            <span class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-emerald-700">
                <?php echo e(__('nav_menu_visible_count', ['n' => $visibleChannels])); ?>
            </span>
        </div>
    </div>

    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50/70 flex items-center gap-2 flex-wrap" data-nm-toolbar>
        <label class="relative flex-1 min-w-[14rem] max-w-md">
            <span class="sr-only"><?php echo e(__('nav_menu_search')); ?></span>
            <i class="ti ti-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
            <input type="search" data-nm-search placeholder="<?php echo e(__('nav_menu_search_placeholder')); ?>"
                   class="w-full h-9 rounded border border-gray-200 bg-white pl-9 pr-8 text-sm focus:border-primary focus:ring-2 focus:ring-primary/15">
            <button type="button" data-nm-search-clear class="hidden absolute right-2 top-1/2 -translate-y-1/2 w-6 h-6 items-center justify-center rounded text-gray-400 hover:text-gray-700" title="<?php echo e(__('clear')); ?>" aria-label="<?php echo e(__('clear')); ?>">
                <i class="ti ti-x"></i>
            </button>
        </label>
        <div class="inline-flex h-9 rounded border border-gray-200 bg-white p-0.5" role="group" aria-label="<?php echo e(__('nav_menu_filter')); ?>">
            <button type="button" data-nm-filter="all" aria-pressed="true" class="nm-filter-btn rounded px-3 text-xs bg-gray-800 text-white"><?php echo e(__('all')); ?></button>
            <button type="button" data-nm-filter="visible" aria-pressed="false" class="nm-filter-btn rounded px-3 text-xs text-gray-500 hover:text-gray-800"><?php echo e(__('nav_menu_filter_visible')); ?></button>
            <button type="button" data-nm-filter="hidden" aria-pressed="false" class="nm-filter-btn rounded px-3 text-xs text-gray-500 hover:text-gray-800"><?php echo e(__('nav_menu_filter_hidden')); ?></button>
        </div>
        <button type="button" data-nm-collapse-all class="h-9 rounded border border-gray-200 bg-white px-3 text-xs text-gray-600 hover:border-gray-300 inline-flex items-center gap-1.5">
            <i class="ti ti-arrows-minimize"></i><span><?php echo e(__('nav_menu_collapse_all')); ?></span>
        </button>
        <span class="ml-auto min-w-[7rem] text-right text-xs text-gray-500" data-nm-result-count aria-live="polite"></span>
        <span class="hidden items-center gap-1.5 text-xs" data-nm-status role="status" aria-live="polite"></span>
    </div>

    <div class="px-5 py-4" data-nm-tree>
        <?php ob_start(); ?>
        <div class="nm-menu-item nm-home-item" data-id="home" data-name="<?php echo e(mb_strtolower($homeName)); ?>" data-visible="<?php echo $homeVisible ? '1' : '0'; ?>" data-testid="nav-menu-home-row">
            <div class="nm-item-row nm-home-row min-h-11 flex items-center gap-2 rounded border border-blue-200 px-2.5 py-1.5 bg-blue-50/60 hover:bg-blue-50 transition <?php echo $homeVisible ? '' : 'opacity-60'; ?>">
                <span class="nm-drag w-7 h-7 cursor-grab text-gray-300 hover:text-gray-600 inline-flex items-center justify-center rounded hover:bg-blue-100" title="<?php echo e(__('nav_menu_drag_handle')); ?>">
                    <i class="ti ti-grip-vertical"></i>
                </span>
                <span class="w-7 h-7 text-blue-400 inline-flex items-center justify-center rounded" aria-hidden="true"><i class="ti ti-home"></i></span>
                <span class="min-w-0 text-sm font-semibold text-gray-800 truncate"><?php echo e($homeName); ?></span>
                <span class="ml-auto flex items-center gap-1 shrink-0">
                    <label class="relative inline-flex items-center gap-2 cursor-pointer px-1.5" title="<?php echo e(__('nav_menu_main_nav')); ?>">
                        <input type="checkbox" class="nm-home-toggle sr-only peer" aria-label="<?php echo e(__('nav_menu_show_item', ['name' => $homeName])); ?>" <?php echo $homeVisible ? 'checked' : ''; ?>>
                        <span class="relative w-9 h-5 rounded-full bg-gray-200 transition peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40 peer-checked:bg-primary after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-4 after:h-4 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:after:translate-x-4"></span>
                        <span class="hidden xl:inline text-xs text-gray-500"><?php echo e(__('nav_menu_main_nav')); ?></span>
                    </label>
                    <label class="relative inline-flex items-center gap-2 cursor-pointer px-1.5" title="<?php echo e(__('nav_menu_footer_nav')); ?>">
                        <input type="checkbox" class="nm-home-footer-toggle sr-only peer" aria-label="<?php echo e(__('nav_menu_footer_home_item', ['name' => $homeName])); ?>" <?php echo $homeInFooter ? 'checked' : ''; ?>>
                        <span class="relative w-9 h-5 rounded-full bg-gray-200 transition peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40 peer-checked:bg-primary after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-4 after:h-4 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:after:translate-x-4"></span>
                        <span class="hidden xl:inline text-xs text-gray-500"><?php echo e(__('nav_menu_footer_nav')); ?></span>
                    </label>
                    <button type="button" class="nm-move w-8 h-8 inline-flex items-center justify-center rounded text-gray-400 hover:bg-blue-100 hover:text-primary disabled:opacity-25" data-dir="-1" title="<?php echo e(__('nav_menu_move_up')); ?>" aria-label="<?php echo e(__('nav_menu_move_up_item', ['name' => $homeName])); ?>"><i class="ti ti-arrow-up"></i></button>
                    <button type="button" class="nm-move w-8 h-8 inline-flex items-center justify-center rounded text-gray-400 hover:bg-blue-100 hover:text-primary disabled:opacity-25" data-dir="1" title="<?php echo e(__('nav_menu_move_down')); ?>" aria-label="<?php echo e(__('nav_menu_move_down_item', ['name' => $homeName])); ?>"><i class="ti ti-arrow-down"></i></button>
                    <button type="button" data-nm-home-edit class="w-8 h-8 inline-flex items-center justify-center rounded text-gray-400 hover:bg-blue-100 hover:text-primary" title="<?php echo e(__('edit')); ?>" aria-label="<?php echo e(__('nav_menu_edit_item', ['name' => $homeName])); ?>"><i class="ti ti-pencil"></i></button>
                </span>
            </div>
            <form method="post" data-nm-home-form class="hidden mt-2 rounded border border-blue-100 bg-blue-50/50 p-3" data-testid="nav-menu-home-form">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_home_nav">
                <label class="block text-xs font-medium text-gray-600 mb-1.5" for="nav-home-label"><?php echo e(__('nav_menu_home_label')); ?></label>
                <div class="flex items-center gap-2">
                    <input id="nav-home-label" type="text" name="label" maxlength="100" value="<?php echo e($homeTextValue); ?>"
                           placeholder="<?php echo e($defaultHomeLabel()); ?>"
                           class="min-w-0 flex-1 h-9 rounded border border-gray-200 bg-white px-3 text-sm focus:border-primary focus:ring-2 focus:ring-primary/15"
                           data-testid="nav-menu-home-label">
                    <button type="button" data-nm-home-cancel class="h-9 px-3 rounded border border-gray-200 bg-white text-sm text-gray-600 hover:bg-gray-50"><?php echo e(__('cancel')); ?></button>
                    <button type="submit" class="h-9 px-3 rounded bg-primary text-white text-sm hover:opacity-90 inline-flex items-center gap-1.5" data-testid="nav-menu-home-save"><i class="ti ti-device-floppy"></i><?php echo e(__('save')); ?></button>
                </div>
                <p class="mt-1.5 text-xs text-gray-500"><?php echo e(__('nav_menu_home_label_hint')); ?></p>
            </form>
        </div>
        <?php $homeItemHtml = (string) ob_get_clean(); ?>
        <?php
        /** @param array<int,array<int,array<string,mixed>>> $byParent */
        function ykRenderNavTree(
            array $byParent,
            int $parentId,
            int $level,
            array $typeLabels,
            string $homeItemHtml = '',
            int $homePosition = 0
        ): void
        {
            $rows = $byParent[$parentId] ?? [];
            if ($rows === [] && $level > 0) {
                return;
            }
            $treeClass = $level > 0
                ? ' ml-5 pl-5 mt-1 border-l border-gray-200'
                : '';
            echo '<div class="nm-nav-sort space-y-1' . $treeClass . '" data-nm-parent="' . $parentId . '"' . ($level > 0 ? ' data-nm-children' : '') . '>';
            foreach ($rows as $index => $row) {
                if ($level === 0 && $index === $homePosition) {
                    echo $homeItemHtml;
                }
                $id = (int) $row['id'];
                $name = (string) $row['name'];
                $isVisible = (int) $row['is_nav'] === 1;
                $hasChildren = ($byParent[$id] ?? []) !== [];
                $rowBorder = $level === 0 ? 'border-gray-200' : 'border-gray-100';
                ?>
                <div class="nm-menu-item nm-nav-item" data-id="<?php echo $id; ?>" data-name="<?php echo e(mb_strtolower($name)); ?>" data-visible="<?php echo $isVisible ? '1' : '0'; ?>" data-level="<?php echo $level; ?>" data-testid="nav-menu-row">
                    <div class="nm-item-row nm-nav-row min-h-11 flex items-center gap-2 rounded border <?php echo $rowBorder; ?> px-2.5 py-1.5 hover:border-gray-300 hover:bg-gray-50/70 bg-white transition <?php echo $isVisible ? '' : 'opacity-60'; ?>">
                        <span class="nm-drag w-7 h-7 cursor-grab text-gray-300 hover:text-gray-600 inline-flex items-center justify-center rounded hover:bg-gray-100" title="<?php echo e(__('nav_menu_drag_handle')); ?>">
                            <i class="ti ti-grip-vertical"></i>
                        </span>
                        <?php if ($hasChildren): ?>
                        <button type="button" class="nm-node-toggle w-7 h-7 inline-flex items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700" aria-expanded="true" title="<?php echo e(__('nav_menu_toggle_children')); ?>">
                            <i class="ti ti-chevron-down transition-transform"></i>
                        </button>
                        <?php else: ?>
                        <span class="w-7 h-7" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="min-w-0 text-sm font-medium text-gray-800 truncate"><?php echo e($name); ?></span>
                        <span class="shrink-0 text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-500"><?php echo e($typeLabels[(string) $row['type']] ?? (string) $row['type']); ?></span>
                        <?php if ((string) $row['type'] === 'product'): ?>
                        <a href="/admin/product_category.php" class="w-7 h-7 shrink-0 inline-flex items-center justify-center rounded text-gray-400 hover:bg-blue-50 hover:text-primary" title="<?php echo e(__('nav_menu_product_hint')); ?>" aria-label="<?php echo e(__('nav_menu_product_hint')); ?>"><i class="ti ti-category"></i></a>
                        <?php endif; ?>
                        <span class="ml-auto flex items-center gap-1 shrink-0">
                            <label class="relative inline-flex items-center gap-2 cursor-pointer px-1.5" title="<?php echo e(__('nav_menu_show')); ?>">
                                <input type="checkbox" class="nm-nav-toggle sr-only peer" data-id="<?php echo $id; ?>" aria-label="<?php echo e(__('nav_menu_show_item', ['name' => $name])); ?>" <?php echo $isVisible ? 'checked' : ''; ?>>
                                <span class="relative w-9 h-5 rounded-full bg-gray-200 transition peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40 peer-checked:bg-primary after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-4 after:h-4 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:after:translate-x-4"></span>
                                <span class="hidden xl:inline text-xs text-gray-500"><?php echo e(__('nav_menu_show')); ?></span>
                            </label>
                            <button type="button" class="nm-move w-8 h-8 inline-flex items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-25" data-dir="-1" title="<?php echo e(__('nav_menu_move_up')); ?>" aria-label="<?php echo e(__('nav_menu_move_up_item', ['name' => $name])); ?>"><i class="ti ti-arrow-up"></i></button>
                            <button type="button" class="nm-move w-8 h-8 inline-flex items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-25" data-dir="1" title="<?php echo e(__('nav_menu_move_down')); ?>" aria-label="<?php echo e(__('nav_menu_move_down_item', ['name' => $name])); ?>"><i class="ti ti-arrow-down"></i></button>
                            <a href="/admin/channel.php?edit=<?php echo $id; ?>" class="w-8 h-8 inline-flex items-center justify-center rounded text-gray-400 hover:bg-blue-50 hover:text-primary" title="<?php echo e(__('edit')); ?>" aria-label="<?php echo e(__('nav_menu_edit_item', ['name' => $name])); ?>"><i class="ti ti-pencil"></i></a>
                        </span>
                    </div>
                    <?php ykRenderNavTree($byParent, $id, $level + 1, $typeLabels); ?>
                </div>
                <?php
            }
            if ($level === 0 && $homePosition >= count($rows)) {
                echo $homeItemHtml;
            }
            echo '</div>';
        }
        ykRenderNavTree($byParent, 0, 0, $typeLabels, $homeItemHtml, $homePosition);
        ?>
        <div class="hidden py-12 text-center text-sm text-gray-500" data-nm-empty>
            <i class="ti ti-search-off block text-2xl text-gray-300 mb-2"></i>
            <?php echo e(__('nav_menu_no_results')); ?>
        </div>
    </div>
    <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-500 bg-gray-50/50">
        <i class="ti ti-info-circle mr-1"></i><?php echo e(__('nav_menu_levels_hint')); ?>
    </div>
</div>
<script>
(function () {
    var root = document.querySelector('[data-nm-default]');
    if (!root) return;

    var csrf = <?php echo json_encode(csrfToken()); ?>;
    var ui = <?php echo json_encode([
        'saving' => __('nav_menu_saving'),
        'saved' => __('nav_menu_update_success'),
        'failed' => __('nav_menu_update_failed'),
        'collapse' => __('nav_menu_collapse_all'),
        'expand' => __('nav_menu_expand_all'),
        'results' => __('nav_menu_result_count'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    var search = root.querySelector('[data-nm-search]');
    var searchClear = root.querySelector('[data-nm-search-clear]');
    var filterButtons = Array.prototype.slice.call(root.querySelectorAll('[data-nm-filter]'));
    var collapseButton = root.querySelector('[data-nm-collapse-all]');
    var resultCount = root.querySelector('[data-nm-result-count]');
    var status = root.querySelector('[data-nm-status]');
    var empty = root.querySelector('[data-nm-empty]');
    var homeItem = root.querySelector('.nm-home-item');
    var homeEditButton = root.querySelector('[data-nm-home-edit]');
    var homeForm = root.querySelector('[data-nm-home-form]');
    var homeCancelButton = root.querySelector('[data-nm-home-cancel]');
    var currentFilter = 'all';
    var statusTimer = 0;

    function setStatus(kind, message) {
        window.clearTimeout(statusTimer);
        status.className = 'inline-flex items-center gap-1.5 text-xs ' + (kind === 'error' ? 'text-red-600' : (kind === 'success' ? 'text-emerald-600' : 'text-gray-500'));
        status.innerHTML = '<i class="ti ' + (kind === 'error' ? 'ti-alert-circle' : (kind === 'success' ? 'ti-check' : 'ti-loader-2 animate-spin')) + '"></i><span></span>';
        status.querySelector('span').textContent = message;
        if (kind !== 'loading') {
            statusTimer = window.setTimeout(function () { status.classList.add('hidden'); }, 2400);
        }
    }

    function post(fields) {
        var fd = new FormData();
        fd.append('_token', csrf);
        Object.keys(fields).forEach(function (key) {
            if (Array.isArray(fields[key])) {
                fields[key].forEach(function (value) { fd.append(key + '[]', value); });
            } else {
                fd.append(key, fields[key]);
            }
        });
        return fetch('/admin/nav_menu.php', { method: 'POST', body: fd })
            .then(function (response) {
                return response.json().catch(function () { return null; }).then(function (payload) {
                    if (!response.ok || !payload || payload.code !== 0) throw new Error('save failed');
                    return payload;
                });
            });
    }

    function directItems(list) {
        return Array.prototype.filter.call(list.children, function (node) { return node.classList.contains('nm-menu-item'); });
    }

    function directNavItems(list) {
        return directItems(list).filter(function (node) { return node.classList.contains('nm-nav-item'); });
    }

    function childList(item) {
        return Array.prototype.find.call(item.children, function (node) { return node.matches && node.matches('.nm-nav-sort[data-nm-children]'); }) || null;
    }

    function setExpanded(item, expanded) {
        var list = childList(item);
        var button = item.querySelector(':scope > .nm-nav-row .nm-node-toggle');
        if (!list || !button) return;
        list.classList.toggle('hidden', !expanded);
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        var icon = button.querySelector('i');
        if (icon) icon.classList.toggle('-rotate-90', !expanded);
    }

    function updateMoveButtons(list) {
        var items = directItems(list);
        items.forEach(function (item, index) {
            var buttons = item.querySelectorAll(':scope > .nm-item-row .nm-move');
            if (buttons[0]) buttons[0].disabled = index === 0;
            if (buttons[1]) buttons[1].disabled = index === items.length - 1;
        });
    }

    function updateAllMoveButtons() {
        root.querySelectorAll('.nm-nav-sort').forEach(updateMoveButtons);
    }

    function applyFilter() {
        var query = (search.value || '').trim().toLocaleLowerCase();
        var ownMatches = 0;
        var rootList = root.querySelector('.nm-nav-sort[data-nm-parent="0"]');

        if (homeItem) {
            var homeNameMatch = query === '' || (homeItem.dataset.name || '').indexOf(query) !== -1;
            var homeStateMatch = currentFilter === 'all'
                || (currentFilter === 'visible' && homeItem.dataset.visible === '1')
                || (currentFilter === 'hidden' && homeItem.dataset.visible === '0');
            var homeMatch = homeNameMatch && homeStateMatch;
            homeItem.classList.toggle('hidden', !homeMatch);
            if (homeMatch) ownMatches++;
        }

        function visit(item) {
            var list = childList(item);
            var childMatch = false;
            if (list) {
                directItems(list).forEach(function (child) {
                    if (visit(child)) childMatch = true;
                });
            }
            var nameMatch = query === '' || (item.dataset.name || '').indexOf(query) !== -1;
            var stateMatch = currentFilter === 'all'
                || (currentFilter === 'visible' && item.dataset.visible === '1')
                || (currentFilter === 'hidden' && item.dataset.visible === '0');
            var ownMatch = nameMatch && stateMatch;
            if (ownMatch) ownMatches++;
            var show = ownMatch || childMatch;
            item.classList.toggle('hidden', !show);
            if (childMatch && query !== '') setExpanded(item, true);
            return show;
        }

        directNavItems(rootList).forEach(visit);
        var total = root.querySelectorAll('.nm-nav-item').length + (homeItem ? 1 : 0);
        resultCount.textContent = ui.results.replace(':shown', String(ownMatches)).replace(':total', String(total));
        empty.classList.toggle('hidden', ownMatches !== 0);
        searchClear.classList.toggle('hidden', query === '');
        searchClear.classList.toggle('inline-flex', query !== '');
    }

    function orderOf(list) {
        return directItems(list).map(function (item) { return item.dataset.id; });
    }

    function restoreOrder(list, ids) {
        ids.forEach(function (id) {
            var item = directItems(list).find(function (candidate) { return candidate.dataset.id === id; });
            if (item) list.appendChild(item);
        });
        updateMoveButtons(list);
    }

    function saveOrder(list, before) {
        setStatus('loading', ui.saving);
        return post({ action: 'sort_nav', ids: orderOf(list), parent: list.dataset.nmParent || 0 })
            .then(function () {
                setStatus('success', ui.saved);
                updateMoveButtons(list);
            })
            .catch(function () {
                restoreOrder(list, before);
                setStatus('error', ui.failed);
            });
    }

    root.querySelectorAll('.nm-nav-sort').forEach(function (list) {
        var before = [];
        new Sortable(list, {
            handle: '.nm-drag',
            animation: 180,
            ghostClass: 'opacity-30',
            chosenClass: 'shadow-lg',
            onStart: function () { before = orderOf(list); },
            onEnd: function (event) {
                if (event.oldIndex === event.newIndex) return;
                saveOrder(list, before);
            }
        });
    });

    root.querySelectorAll('.nm-node-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            var item = button.closest('.nm-nav-item');
            setExpanded(item, button.getAttribute('aria-expanded') !== 'true');
        });
    });

    root.querySelectorAll('.nm-move').forEach(function (button) {
        button.addEventListener('click', function () {
            var item = button.closest('.nm-menu-item');
            var list = item.parentElement;
            var before = orderOf(list);
            var sibling = Number(button.dataset.dir) < 0 ? item.previousElementSibling : item.nextElementSibling;
            if (!sibling || !sibling.classList.contains('nm-menu-item')) return;
            if (Number(button.dataset.dir) < 0) list.insertBefore(item, sibling);
            else list.insertBefore(sibling, item);
            saveOrder(list, before);
        });
    });

    function bindVisibilityToggle(checkbox, row, fields) {
        if (!checkbox || !row) return;
        checkbox.addEventListener('change', function () {
            var previous = !checkbox.checked;
            checkbox.disabled = true;
            setStatus('loading', ui.saving);
            post(Object.assign({}, fields, { val: checkbox.checked ? 1 : 0 }))
                .then(function () {
                    row.dataset.visible = checkbox.checked ? '1' : '0';
                    var rowContent = row.querySelector(':scope > .nm-item-row');
                    if (rowContent) rowContent.classList.toggle('opacity-60', !checkbox.checked);
                    setStatus('success', ui.saved);
                    applyFilter();
                })
                .catch(function () {
                    checkbox.checked = previous;
                    setStatus('error', ui.failed);
                })
                .finally(function () { checkbox.disabled = false; });
        });
    }

    root.querySelectorAll('.nm-nav-toggle').forEach(function (checkbox) {
        bindVisibilityToggle(checkbox, checkbox.closest('.nm-nav-item'), {
            action: 'toggle_nav',
            id: checkbox.dataset.id
        });
    });
    bindVisibilityToggle(root.querySelector('.nm-home-toggle'), homeItem, {
        action: 'toggle_home_nav'
    });

    function bindSettingToggle(checkbox, fields) {
        if (!checkbox) return;
        checkbox.addEventListener('change', function () {
            var previous = !checkbox.checked;
            checkbox.disabled = true;
            setStatus('loading', ui.saving);
            post(Object.assign({}, fields, { val: checkbox.checked ? 1 : 0 }))
                .then(function () { setStatus('success', ui.saved); })
                .catch(function () {
                    checkbox.checked = previous;
                    setStatus('error', ui.failed);
                })
                .finally(function () { checkbox.disabled = false; });
        });
    }
    bindSettingToggle(root.querySelector('.nm-home-footer-toggle'), {
        action: 'toggle_home_footer_nav'
    });

    function setHomeEditor(open) {
        if (!homeForm) return;
        homeForm.classList.toggle('hidden', !open);
        if (open) {
            var input = homeForm.querySelector('input[name="label"]');
            if (input) {
                input.focus();
                input.select();
            }
        } else if (homeEditButton) {
            homeEditButton.focus();
        }
    }
    if (homeEditButton) homeEditButton.addEventListener('click', function () { setHomeEditor(homeForm.classList.contains('hidden')); });
    if (homeCancelButton) homeCancelButton.addEventListener('click', function () { setHomeEditor(false); });
    if (homeForm) homeForm.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            setHomeEditor(false);
        }
    });

    filterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            currentFilter = button.dataset.nmFilter;
            filterButtons.forEach(function (candidate) {
                var active = candidate === button;
                candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
                candidate.classList.toggle('bg-gray-800', active);
                candidate.classList.toggle('text-white', active);
                candidate.classList.toggle('text-gray-500', !active);
            });
            applyFilter();
        });
    });

    search.addEventListener('input', applyFilter);
    searchClear.addEventListener('click', function () { search.value = ''; search.focus(); applyFilter(); });
    collapseButton.addEventListener('click', function () {
        var lists = Array.prototype.slice.call(root.querySelectorAll('[data-nm-children]'));
        var shouldCollapse = lists.some(function (list) { return !list.classList.contains('hidden'); });
        root.querySelectorAll('.nm-nav-item').forEach(function (item) {
            if (childList(item)) setExpanded(item, !shouldCollapse);
        });
        collapseButton.querySelector('span').textContent = shouldCollapse ? ui.expand : ui.collapse;
        var icon = collapseButton.querySelector('i');
        icon.className = 'ti ' + (shouldCollapse ? 'ti-arrows-maximize' : 'ti-arrows-minimize');
    });

    updateAllMoveButtons();
    applyFilter();
})();
</script>

<?php else: ?>
<!-- ============ 菜单组编辑器 ============ -->
<div class="bg-white rounded-lg shadow"
     x-data='ykMenuGroupEditor(<?php echo json_encode([
         'items' => json_decode((string) ($editGroup['items'] ?? '[]'), true) ?: [],
         'channels' => $flatChannels,
         'names' => $channelNameMap,
         'maxDepth' => NavMenuModel::MAX_DEPTH,
     ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
    <div class="px-5 py-4 border-b border-gray-100 flex items-center gap-3 flex-wrap">
        <form method="post" class="inline-flex items-center gap-2">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="rename_group">
            <input type="hidden" name="id" value="<?php echo (int) $editGroup['id']; ?>">
            <input type="text" name="name" value="<?php echo e((string) $editGroup['name']); ?>" maxlength="100"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm font-medium" data-testid="nav-menu-group-name">
            <button type="submit" class="text-xs text-gray-400 hover:text-primary"><?php echo e(__('nav_menu_rename')); ?></button>
        </form>
        <div class="ml-auto flex items-center gap-2">
            <form method="post" onsubmit="return confirm(<?php echo e(json_encode(__('nav_menu_delete_confirm'), JSON_UNESCAPED_UNICODE)); ?>)">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete_group">
                <input type="hidden" name="id" value="<?php echo (int) $editGroup['id']; ?>">
                <button type="submit" class="text-xs text-red-400 hover:text-red-600 px-2 py-1.5" data-testid="nav-menu-group-delete">
                    <i class="ti ti-trash"></i> <?php echo e(__('delete')); ?>
                </button>
            </form>
            <form method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_group">
                <input type="hidden" name="id" value="<?php echo (int) $editGroup['id']; ?>">
                <input type="hidden" name="items" :value="JSON.stringify(items)">
                <button type="submit" class="bg-primary hover:opacity-90 text-white text-sm px-4 py-1.5 rounded-lg" data-testid="nav-menu-group-save">
                    <?php echo e(__('save')); ?>
                </button>
            </form>
        </div>
    </div>

    <div class="px-5 py-4">
        <!-- 添加项 -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4 pb-4 border-b border-gray-100" data-nm-add-items>
            <div data-nm-add-channel>
                <label class="block text-xs font-medium text-gray-600 mb-1.5"><?php echo e(__('nav_menu_add_channel')); ?></label>
                <div class="flex items-center gap-2">
                    <select x-model="addChannelId" class="min-w-0 flex-1 h-9 border border-gray-200 rounded px-3 text-sm bg-white" data-testid="nav-menu-add-channel">
                        <option value="0"><?php echo e(__('nav_menu_pick_channel')); ?></option>
                        <template x-for="c in channels" :key="c.id">
                            <option :value="c.id" x-text="c.name"></option>
                        </template>
                    </select>
                    <button type="button" @click="addChannel()" class="h-9 shrink-0 rounded bg-gray-800 hover:bg-gray-700 text-white text-sm px-3 inline-flex items-center gap-1.5" data-testid="nav-menu-add-channel-btn">
                        <i class="ti ti-plus"></i><span><?php echo e(__('nav_menu_add')); ?></span>
                    </button>
                </div>
            </div>
            <div class="lg:border-l lg:border-gray-200 lg:pl-4" data-nm-add-link>
                <label class="block text-xs font-medium text-gray-600 mb-1.5"><?php echo e(__('nav_menu_add_link')); ?></label>
                <div class="grid grid-cols-[minmax(0,1fr)_auto] sm:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)_auto] gap-2">
                    <input type="text" x-model="addLabel" placeholder="<?php echo e(__('nav_menu_custom_label')); ?>" class="col-span-2 sm:col-span-1 min-w-0 h-9 border border-gray-200 rounded px-3 text-sm">
                    <input type="text" inputmode="url" autocomplete="url" x-model="addUrl" placeholder="<?php echo e(__('nav_menu_url_placeholder')); ?>" class="min-w-0 h-9 border border-gray-200 rounded px-3 text-sm">
                    <button type="button" @click="addLink()" class="w-9 h-9 rounded border border-gray-200 text-gray-600 hover:border-primary hover:text-primary inline-flex items-center justify-center" data-testid="nav-menu-add-link-btn" title="<?php echo e(__('nav_menu_add_link')); ?>" aria-label="<?php echo e(__('nav_menu_add_link')); ?>">
                        <i class="ti ti-link-plus"></i>
                    </button>
                </div>
            </div>
        </div>
        <div x-show="_pendingParent" x-cloak class="mb-4 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-700 flex items-center gap-2" role="status" data-testid="nav-menu-child-target">
            <i class="ti ti-corner-down-right shrink-0"></i>
            <span class="min-w-0 flex-1" x-text="pendingChildText()"></span>
            <button type="button" @click="cancelChild()" class="w-7 h-7 rounded inline-flex items-center justify-center hover:bg-blue-100" title="<?php echo e(__('cancel')); ?>" aria-label="<?php echo e(__('cancel')); ?>"><i class="ti ti-x"></i></button>
        </div>
        <p x-show="items.length === 0" class="text-sm text-gray-400 py-6 text-center"><?php echo e(__('nav_menu_group_empty')); ?></p>
        <ul class="space-y-1" data-testid="nav-menu-group-items" data-nm-sort="" x-init="initSortable()">
            <template x-for="(item, i) in items" :key="i">
                <li>
                    <div class="flex items-center gap-2 rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50/60">
                        <span class="nm-drag cursor-grab text-gray-300 hover:text-gray-500"><i class="ti ti-grip-vertical"></i></span>
                        <i class="ti text-gray-300" :class="item.channel_id > 0 ? 'ti-sitemap' : 'ti-link'"></i>
                        <span class="text-sm text-gray-800" x-text="displayName(item)"></span>
                        <input type="text" x-model="item.label" placeholder="<?php echo e(__('nav_menu_label_override')); ?>"
                               class="border border-gray-100 rounded px-2 py-0.5 text-xs w-28 text-gray-500">
                        <span class="inline-flex items-center gap-1">
                            <i class="text-sm text-gray-500" :class="iconPreviewClass(item.icon)" aria-hidden="true"></i>
                            <input type="text" x-model="item.icon" placeholder="<?php echo e(__('nav_menu_icon_placeholder')); ?>"
                                   title="<?php echo e(__('nav_menu_icon_help')); ?>"
                                   class="border border-gray-100 rounded px-2 py-0.5 text-xs w-24 text-gray-500 font-mono">
                        </span>
                        <label x-show="item.channel_id === 0" class="text-[11px] text-gray-400 inline-flex items-center gap-1">
                            <input type="checkbox" :checked="item.target === '_blank'" @change="item.target = $event.target.checked ? '_blank' : ''" class="rounded border-gray-300"> <?php echo e(__('nav_menu_new_window')); ?>
                        </label>
                        <span class="ml-auto flex items-center gap-1 text-gray-300">
                            <button type="button" @click="move(items, i, -1)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-gray-100 hover:text-gray-600" title="<?php echo e(__('nav_menu_move_up')); ?>" aria-label="<?php echo e(__('nav_menu_move_up')); ?>"><i class="ti ti-arrow-up"></i></button>
                            <button type="button" @click="move(items, i, 1)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-gray-100 hover:text-gray-600" title="<?php echo e(__('nav_menu_move_down')); ?>" aria-label="<?php echo e(__('nav_menu_move_down')); ?>"><i class="ti ti-arrow-down"></i></button>
                            <button type="button" @click="addChildTo(item, 2)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-blue-50 hover:text-primary" title="<?php echo e(__('nav_menu_add_child')); ?>" aria-label="<?php echo e(__('nav_menu_add_child')); ?>"><i class="ti ti-corner-down-right"></i></button>
                            <button type="button" @click="items.splice(i, 1)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-red-50 hover:text-red-500" title="<?php echo e(__('nav_menu_remove_item')); ?>" aria-label="<?php echo e(__('nav_menu_remove_item')); ?>"><i class="ti ti-x"></i></button>
                        </span>
                    </div>
                    <ul class="ml-8 mt-1 space-y-1" :data-nm-sort="String(i)">
                        <template x-for="(kid, j) in item.children" :key="j">
                            <li>
                                <div class="flex items-center gap-2 rounded-lg border border-gray-50 px-3 py-1.5 hover:bg-gray-50/60">
                                    <span class="nm-drag cursor-grab text-gray-300 hover:text-gray-500"><i class="ti ti-grip-vertical text-sm"></i></span>
                                    <i class="ti text-gray-300 text-sm" :class="kid.channel_id > 0 ? 'ti-sitemap' : 'ti-link'"></i>
                                    <span class="text-sm text-gray-700" x-text="displayName(kid)"></span>
                                    <input type="text" x-model="kid.label" placeholder="<?php echo e(__('nav_menu_label_override')); ?>"
                                           class="border border-gray-100 rounded px-2 py-0.5 text-xs w-24 text-gray-500">
                                    <span class="inline-flex items-center gap-1">
                                        <i class="text-sm text-gray-500" :class="iconPreviewClass(kid.icon)" aria-hidden="true"></i>
                                        <input type="text" x-model="kid.icon" placeholder="<?php echo e(__('nav_menu_icon_placeholder')); ?>"
                                               title="<?php echo e(__('nav_menu_icon_help')); ?>"
                                               class="border border-gray-100 rounded px-2 py-0.5 text-xs w-20 text-gray-500 font-mono">
                                    </span>
                                    <span class="ml-auto flex items-center gap-1 text-gray-300">
                                        <button type="button" @click="move(item.children, j, -1)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-gray-100 hover:text-gray-600" title="<?php echo e(__('nav_menu_move_up')); ?>" aria-label="<?php echo e(__('nav_menu_move_up')); ?>"><i class="ti ti-arrow-up"></i></button>
                                        <button type="button" @click="move(item.children, j, 1)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-gray-100 hover:text-gray-600" title="<?php echo e(__('nav_menu_move_down')); ?>" aria-label="<?php echo e(__('nav_menu_move_down')); ?>"><i class="ti ti-arrow-down"></i></button>
                                        <button type="button" @click="addChildTo(kid, 3)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-blue-50 hover:text-primary" title="<?php echo e(__('nav_menu_add_child')); ?>" aria-label="<?php echo e(__('nav_menu_add_child')); ?>"><i class="ti ti-corner-down-right"></i></button>
                                        <button type="button" @click="item.children.splice(j, 1)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-red-50 hover:text-red-500" title="<?php echo e(__('nav_menu_remove_item')); ?>" aria-label="<?php echo e(__('nav_menu_remove_item')); ?>"><i class="ti ti-x"></i></button>
                                    </span>
                                </div>
                                <ul class="ml-8 mt-1 space-y-1" :data-nm-sort="i + '.' + j">
                                    <template x-for="(g, k) in kid.children" :key="k">
                                        <li class="flex items-center gap-2 rounded border border-gray-50 px-3 py-1 hover:bg-gray-50/60">
                                            <span class="nm-drag cursor-grab text-gray-300 hover:text-gray-500"><i class="ti ti-grip-vertical text-sm"></i></span>
                                            <i class="ti text-gray-300 text-sm" :class="g.channel_id > 0 ? 'ti-sitemap' : 'ti-link'"></i>
                                            <span class="text-xs text-gray-600" x-text="displayName(g)"></span>
                                            <span class="ml-auto flex items-center gap-1 text-gray-300">
                                                <button type="button" @click="move(kid.children, k, -1)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-gray-100 hover:text-gray-600" title="<?php echo e(__('nav_menu_move_up')); ?>" aria-label="<?php echo e(__('nav_menu_move_up')); ?>"><i class="ti ti-arrow-up"></i></button>
                                                <button type="button" @click="move(kid.children, k, 1)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-gray-100 hover:text-gray-600" title="<?php echo e(__('nav_menu_move_down')); ?>" aria-label="<?php echo e(__('nav_menu_move_down')); ?>"><i class="ti ti-arrow-down"></i></button>
                                                <button type="button" @click="kid.children.splice(k, 1)" class="w-8 h-8 inline-flex items-center justify-center rounded hover:bg-red-50 hover:text-red-500" title="<?php echo e(__('nav_menu_remove_item')); ?>" aria-label="<?php echo e(__('nav_menu_remove_item')); ?>"><i class="ti ti-x"></i></button>
                                            </span>
                                        </li>
                                    </template>
                                </ul>
                            </li>
                        </template>
                    </ul>
                </li>
            </template>
        </ul>
    </div>
    <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-400">
        <?php echo e(__('nav_menu_group_hint')); ?>
    </div>
</div>

<script>
function ykMenuGroupEditor(boot) {
    return {
        items: Array.isArray(boot.items) ? boot.items : [],
        channels: boot.channels || [],
        names: boot.names || {},
        maxDepth: boot.maxDepth || 3,
        addChannelId: 0,
        addLabel: "",
        addUrl: "",
        _pendingParent: null,   // 「+子项」先记下父节点，再用上方添加行选内容
        _pendingDepth: 1,

        displayName(item) {
            if (item.channel_id > 0) {
                var base = this.names[item.channel_id] || ("#" + item.channel_id);
                return item.label ? item.label + " (" + base + ")" : base;
            }
            return (item.label || "?") + " → " + (item.url || "");
        },
        normalize(node) {
            node.children = Array.isArray(node.children) ? node.children : [];
            return node;
        },
        _receiver() {
            if (this._pendingParent && this._pendingDepth <= this.maxDepth) {
                return this.normalize(this._pendingParent).children;
            }
            return this.items;
        },
        addChannel() {
            var id = parseInt(this.addChannelId, 10) || 0;
            if (id <= 0) return;
            this._receiver().push({ channel_id: id, label: "", url: "", target: "", icon: "", children: [] });
            this.addChannelId = 0;
            this.cancelChild();
            this.initSortable();
        },
        addLink() {
            var label = this.addLabel.trim(), url = this.addUrl.trim();
            if (!label || !url) return;
            this._receiver().push({ channel_id: 0, label: label, url: url, target: "", icon: "", children: [] });
            this.addLabel = ""; this.addUrl = "";
            this.cancelChild();
            this.initSortable();
        },
        // 图标实时预览：tabler 名称或 bi: 前缀（与前台 BloxIcon 同规则）
        iconPreviewClass(icon) {
            icon = (icon || "").trim();
            if (!icon) return "hidden";
            return icon.indexOf("bi:") === 0 ? "bi bi-" + icon.slice(3) : "ti ti-" + icon;
        },
        addChildTo(node, depth) {
            this._pendingParent = node;
            this._pendingDepth = depth;
            this.$nextTick(() => {
                var select = this.$root.querySelector('[data-nm-add-channel] select');
                if (select) {
                    select.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    select.focus();
                }
            });
        },
        cancelChild() {
            this._pendingParent = null;
            this._pendingDepth = 1;
        },
        pendingChildText() {
            if (!this._pendingParent) return '';
            return <?php echo json_encode(__('nav_menu_child_target'), JSON_UNESCAPED_UNICODE); ?>
                .replace(':name', this.displayName(this._pendingParent));
        },
        initSortable() {
            var self = this;
            this.$nextTick(function () {
                self.$root.querySelectorAll('[data-nm-sort]').forEach(function (el) {
                    if (el._nmSortable) el._nmSortable.destroy();
                    el._nmSortable = new Sortable(el, {
                        handle: '.nm-drag',
                        animation: 150,
                        ghostClass: 'opacity-30',
                        onEnd: function (evt) {
                            if (evt.oldIndex === evt.newIndex) return;
                            // DOM 还原给 Alpine（数组才是真相，splice 后由响应式重渲染）
                            evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex]);
                            var list = self.listAt(el.getAttribute('data-nm-sort'));
                            if (!list) return;
                            var moved = list.splice(evt.oldIndex, 1)[0];
                            list.splice(evt.newIndex, 0, moved);
                            self.initSortable(); // 子容器随行重建，重绑
                        },
                    });
                });
            });
        },
        listAt(path) {
            if (path === '' || path == null) return this.items;
            var parts = String(path).split('.').map(function (n) { return parseInt(n, 10); });
            var node = this.items[parts[0]];
            if (!node) return null;
            if (parts.length === 1) return this.normalize(node).children;
            var kid = (node.children || [])[parts[1]];
            return kid ? this.normalize(kid).children : null;
        },
        move(list, i, dir) {
            var j = i + dir;
            if (j < 0 || j >= list.length) return;
            var tmp = list[i]; list[i] = list[j]; list[j] = tmp;
        },
    };
}
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
