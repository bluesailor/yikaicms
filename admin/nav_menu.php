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

// 升级期守卫：文件已更新但迁移未跑（nav_menus 表缺）→ 菜单组功能降级隐藏，
// 页面显示升级引导而不是 500（默认导航 Tab 只依赖 channels 表，照常可用）
$navMenusReady = db()->tableExists('nav_menus');

// ============ POST 分发 ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) ($_POST['action'] ?? 'save_nav');
    $backLang = $_viewLang !== $_defaultLang ? '&lang=' . urlencode($_viewLang) : '';

    if ($action !== 'save_nav' && !$navMenusReady) {
        header('Location: /admin/nav_menu.php');
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
];

$pageTitle = __('admin_nav_menu');
$currentMenu = 'nav_menu';

require_once ROOT_PATH . '/admin/includes/header.php';

/** @param array<int,array<int,array<string,mixed>>> $byParent */
function ykRenderNavRows(array $byParent, int $parentId, int $level, array $typeLabels): void
{
    foreach ($byParent[$parentId] ?? [] as $row) {
        $id = (int) $row['id'];
        $indent = $level * 28;
        ?>
        <tr class="border-b border-gray-50 hover:bg-gray-50/60" data-testid="nav-menu-row">
            <td class="py-2.5 pr-4">
                <div class="flex items-center gap-2" style="padding-left: <?php echo $indent; ?>px">
                    <?php if ($level > 0): ?><span class="text-gray-300">└</span><?php endif; ?>
                    <span class="text-gray-800"><?php echo e((string) $row['name']); ?></span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-400"><?php echo e($typeLabels[(string) $row['type']] ?? (string) $row['type']); ?></span>
                </div>
            </td>
            <td class="py-2.5 pr-4 text-center">
                <input type="hidden" name="ids[]" value="<?php echo $id; ?>">
                <input type="checkbox" name="nav[<?php echo $id; ?>]" value="1" <?php echo ((int) $row['is_nav']) === 1 ? 'checked' : ''; ?>
                       class="rounded border-gray-300 text-primary focus:ring-primary">
            </td>
            <td class="py-2.5 pr-4">
                <input type="number" name="sort[<?php echo $id; ?>]" value="<?php echo (int) $row['sort_order']; ?>"
                       class="w-20 border border-gray-200 rounded px-2 py-1 text-sm text-center">
            </td>
            <td class="py-2.5 text-right">
                <a href="/admin/channel.php?edit=<?php echo $id; ?>" class="text-xs text-gray-400 hover:text-primary"><?php echo e(__('edit')); ?></a>
            </td>
        </tr>
        <?php
        if ((string) $row['type'] === 'product') {
            ?>
            <tr class="border-b border-gray-50">
                <td colspan="4" class="py-1.5 text-xs text-gray-400" style="padding-left: <?php echo $indent + 46; ?>px">
                    <i class="ti ti-info-circle"></i>
                    <?php echo e(__('nav_menu_product_hint')); ?>
                    <a href="/admin/product_category.php" class="text-primary hover:underline"><?php echo e(__('admin_product_category')); ?></a>
                </td>
            </tr>
            <?php
        }
        ykRenderNavRows($byParent, $id, $level + 1, $typeLabels);
    }
}
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

<?php if ($activeGroup === 0): ?>
<!-- ============ 默认导航：栏目投影 ============ -->
<form method="post" class="bg-white rounded-lg shadow">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="save_nav">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-medium text-gray-800"><?php echo e(__('nav_menu_default_tab')); ?></h2>
        <button type="submit" class="bg-primary hover:opacity-90 text-white text-sm px-4 py-1.5 rounded-lg" data-testid="nav-menu-save">
            <?php echo e(__('save')); ?>
        </button>
    </div>
    <div class="px-5 py-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-400 border-b border-gray-100">
                    <th class="py-2 pr-4 font-normal"><?php echo e(__('admin_channel')); ?></th>
                    <th class="py-2 pr-4 font-normal text-center w-28"><?php echo e(__('nav_menu_show')); ?></th>
                    <th class="py-2 pr-4 font-normal w-24"><?php echo e(__('sort_order')); ?></th>
                    <th class="py-2 font-normal w-16"></th>
                </tr>
            </thead>
            <tbody>
                <?php ykRenderNavRows($byParent, 0, 0, $typeLabels); ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100 text-xs text-gray-400">
        <?php echo e(__('nav_menu_levels_hint')); ?>
    </div>
</form>

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
        <div class="flex items-center gap-2 flex-wrap mb-4 pb-4 border-b border-gray-100">
            <select x-model="addChannelId" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm max-w-[14rem]" data-testid="nav-menu-add-channel">
                <option value="0"><?php echo e(__('nav_menu_pick_channel')); ?></option>
                <template x-for="c in channels" :key="c.id">
                    <option :value="c.id" x-text="c.name"></option>
                </template>
            </select>
            <button type="button" @click="addChannel()" class="text-sm text-primary px-2 py-1.5" data-testid="nav-menu-add-channel-btn">
                <i class="ti ti-plus"></i> <?php echo e(__('nav_menu_add_channel')); ?>
            </button>
            <span class="text-gray-200">|</span>
            <input type="text" x-model="addLabel" placeholder="<?php echo e(__('nav_menu_custom_label')); ?>" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-28">
            <input type="text" x-model="addUrl" placeholder="https://" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-44">
            <button type="button" @click="addLink()" class="text-sm text-primary px-2 py-1.5" data-testid="nav-menu-add-link-btn">
                <i class="ti ti-link"></i> <?php echo e(__('nav_menu_add_link')); ?>
            </button>
        </div>

        <!-- 项树（三级递归模板） -->
        <p x-show="items.length === 0" class="text-sm text-gray-400 py-6 text-center"><?php echo e(__('nav_menu_group_empty')); ?></p>
        <ul class="space-y-1" data-testid="nav-menu-group-items">
            <template x-for="(item, i) in items" :key="i">
                <li>
                    <div class="flex items-center gap-2 rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50/60">
                        <i class="ti text-gray-300" :class="item.channel_id > 0 ? 'ti-sitemap' : 'ti-link'"></i>
                        <span class="text-sm text-gray-800" x-text="displayName(item)"></span>
                        <input type="text" x-model="item.label" placeholder="<?php echo e(__('nav_menu_label_override')); ?>"
                               class="border border-gray-100 rounded px-2 py-0.5 text-xs w-28 text-gray-500">
                        <label x-show="item.channel_id === 0" class="text-[11px] text-gray-400 inline-flex items-center gap-1">
                            <input type="checkbox" :checked="item.target === '_blank'" @change="item.target = $event.target.checked ? '_blank' : ''" class="rounded border-gray-300">_blank
                        </label>
                        <span class="ml-auto flex items-center gap-1 text-gray-300">
                            <button type="button" @click="move(items, i, -1)" class="hover:text-gray-600 px-1"><i class="ti ti-arrow-up"></i></button>
                            <button type="button" @click="move(items, i, 1)" class="hover:text-gray-600 px-1"><i class="ti ti-arrow-down"></i></button>
                            <button type="button" @click="addChildTo(item, 2)" class="hover:text-primary px-1" title="<?php echo e(__('nav_menu_add_child')); ?>"><i class="ti ti-corner-down-right"></i></button>
                            <button type="button" @click="items.splice(i, 1)" class="hover:text-red-500 px-1"><i class="ti ti-x"></i></button>
                        </span>
                    </div>
                    <ul class="ml-8 mt-1 space-y-1">
                        <template x-for="(kid, j) in item.children" :key="j">
                            <li>
                                <div class="flex items-center gap-2 rounded-lg border border-gray-50 px-3 py-1.5 hover:bg-gray-50/60">
                                    <i class="ti text-gray-300 text-sm" :class="kid.channel_id > 0 ? 'ti-sitemap' : 'ti-link'"></i>
                                    <span class="text-sm text-gray-700" x-text="displayName(kid)"></span>
                                    <input type="text" x-model="kid.label" placeholder="<?php echo e(__('nav_menu_label_override')); ?>"
                                           class="border border-gray-100 rounded px-2 py-0.5 text-xs w-24 text-gray-500">
                                    <span class="ml-auto flex items-center gap-1 text-gray-300">
                                        <button type="button" @click="move(item.children, j, -1)" class="hover:text-gray-600 px-1"><i class="ti ti-arrow-up"></i></button>
                                        <button type="button" @click="move(item.children, j, 1)" class="hover:text-gray-600 px-1"><i class="ti ti-arrow-down"></i></button>
                                        <button type="button" @click="addChildTo(kid, 3)" class="hover:text-primary px-1" title="<?php echo e(__('nav_menu_add_child')); ?>"><i class="ti ti-corner-down-right"></i></button>
                                        <button type="button" @click="item.children.splice(j, 1)" class="hover:text-red-500 px-1"><i class="ti ti-x"></i></button>
                                    </span>
                                </div>
                                <ul class="ml-8 mt-1 space-y-1">
                                    <template x-for="(g, k) in kid.children" :key="k">
                                        <li class="flex items-center gap-2 rounded border border-gray-50 px-3 py-1 hover:bg-gray-50/60">
                                            <i class="ti text-gray-300 text-sm" :class="g.channel_id > 0 ? 'ti-sitemap' : 'ti-link'"></i>
                                            <span class="text-xs text-gray-600" x-text="displayName(g)"></span>
                                            <span class="ml-auto flex items-center gap-1 text-gray-300">
                                                <button type="button" @click="move(kid.children, k, -1)" class="hover:text-gray-600 px-1"><i class="ti ti-arrow-up"></i></button>
                                                <button type="button" @click="move(kid.children, k, 1)" class="hover:text-gray-600 px-1"><i class="ti ti-arrow-down"></i></button>
                                                <button type="button" @click="kid.children.splice(k, 1)" class="hover:text-red-500 px-1"><i class="ti ti-x"></i></button>
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
            this._receiver().push({ channel_id: id, label: "", url: "", target: "", children: [] });
            this.addChannelId = 0;
            this._pendingParent = null;
        },
        addLink() {
            var label = this.addLabel.trim(), url = this.addUrl.trim();
            if (!label || !url) return;
            this._receiver().push({ channel_id: 0, label: label, url: url, target: "", children: [] });
            this.addLabel = ""; this.addUrl = "";
            this._pendingParent = null;
        },
        addChildTo(node, depth) {
            this._pendingParent = node;
            this._pendingDepth = depth;
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
