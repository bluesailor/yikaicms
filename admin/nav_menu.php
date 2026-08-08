<?php
/**
 * YikaiCMS - 导航菜单管理
 *
 * 集中管理「哪些栏目出现在导航、什么顺序」——原本这些开关散在每个栏目的编辑
 * 表单里，发现性差（r12 mega menu 上线后暴露）。导航 = 栏目树的 is_nav 投影，
 * 本页是该投影的专用视图，不引入独立菜单实体（两套真相是分叉之源）。
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

// ============ 保存：is_nav / sort_order 批量（仅这两个字段，白名单） ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
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
    header('Location: /admin/nav_menu.php?saved=1' . ($_viewLang !== $_defaultLang ? '&lang=' . urlencode($_viewLang) : ''));
    exit;
}

// ============ 树：当前查看语言的启用栏目（含未上导航的，才能勾选加入） ============
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
    <a href="?lang=<?php echo e($_lc); ?>"
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

<form method="post" class="bg-white rounded-lg shadow">
    <?php echo csrfField(); ?>
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-medium text-gray-800"><?php echo e(__('admin_nav_menu')); ?></h2>
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

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
