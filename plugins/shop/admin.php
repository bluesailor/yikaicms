<?php
/**
 * 轻量商城 - 管理页面（M1-a：商品销售设置）。
 *
 * 由 /admin/plugin_page.php?plugin=shop 加载：宿主页已完成 checkLogin +
 * requirePermission(shop_manage)（plugin.json 声明的权限键，G1 机制）。
 * 「启用销售」是叠加配置（shop_products 关联表），不改动 products 表本身；
 * 停用插件后产品页照常展示（立项红线）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/lib/money.php';
require_once __DIR__ . '/lib/tables.php';

try {
    shopEnsureSchema();
} catch (Throwable $schemaError) {
    die('<div style="padding:40px">' . e(__('shop_sales_title')) . ': ' . e($schemaError->getMessage()) . '</div>');
}

// ============================================================
// POST：保存单个产品的销售配置（普通表单 + PRG，无需 JS）
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_sales') {
    verifyCsrf();

    $productId = (int) ($_POST['product_id'] ?? 0);
    $product = $productId > 0 ? productModel()->find($productId) : null;
    if (!$product || ($product['deleted_at'] ?? null) !== null) {
        // 表单校验失败回到列表页提示（PRG），错误文案走语言包
        header('Location: /admin/plugin_page.php?plugin=shop&err=' . urlencode(__('shop_err_product')));
        exit;
    }

    $sku = trim((string) ($_POST['sku'] ?? ''));
    $priceRaw = trim((string) ($_POST['price'] ?? ''));
    $stock = $_POST['stock'] ?? '';
    $status = (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0;

    $error = '';
    if (mb_strlen($sku) > 64) {
        $error = __('shop_err_sku_len');
    } elseif ($priceRaw !== '' && $priceRaw !== '0') {
        try {
            shopMoneyToCents($priceRaw);   // 只验格式合法，落库仍存两位小数字符串
        } catch (InvalidArgumentException $e) {
            $error = __('shop_err_price');
        }
    }
    if ($error === '' && preg_match('/^\d+$/', (string) $stock) !== 1) {
        $error = __('shop_err_stock');   // 纯数字（含 0）；负数/小数/科学计数一律拒绝
    }
    if ($error !== '') {
        header('Location: /admin/plugin_page.php?plugin=shop&err=' . urlencode($error));
        exit;
    }

    $now = time();
    $data = [
        'sku' => $sku,
        // 空串 = 未单独定价 → NULL = 沿用 products.price（语义在立项报告 §四）
        'price' => ($priceRaw !== '' && $priceRaw !== '0') ? shopCentsToDecimal(shopMoneyToCents($priceRaw)) : null,
        'stock' => (int) $stock,
        'status' => $status,
        'updated_at' => $now,
    ];
    $exists = db()->fetchOne(
        'SELECT product_id FROM ' . DB_PREFIX . 'shop_products WHERE product_id = ?',
        [$productId]
    );
    if ($exists) {
        db()->update('shop_products', $data, 'product_id = ?', [$productId]);
    } else {
        $data['product_id'] = $productId;
        $data['created_at'] = $now;
        db()->insert('shop_products', $data);
    }

    adminLog('shop', 'save_sales', 'Save sales config for product #' . $productId . ' status=' . $status . ' stock=' . (int) $stock);
    do_action('data_changed');

    header('Location: /admin/plugin_page.php?plugin=shop&saved=1');
    exit;
}

// ============================================================
// 列表查询：产品（模型层）+ 销售配置（一次取出关联）
// ============================================================
$keyword = trim((string) ($_GET['keyword'] ?? ''));
$salesFilter = (string) ($_GET['sales'] ?? 'all');   // all | on | off
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$list = productModel()->getAdminList(['keyword' => $keyword], $perPage, ($page - 1) * $perPage);
$productIds = array_map(static fn(array $row): int => (int) $row['id'], $list['items']);
$salesRows = [];
if ($productIds !== []) {
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    foreach (db()->fetchAll(
        'SELECT * FROM ' . DB_PREFIX . 'shop_products WHERE product_id IN (' . $placeholders . ')',
        $productIds
    ) as $row) {
        $salesRows[(int) $row['product_id']] = $row;
    }
}

// 状态过滤在 PHP 侧做（shop 配置不在产品模型的查询职责里）
$items = array_values(array_filter($list['items'], static function (array $row) use ($salesRows, $salesFilter): bool {
    $on = (int) (($salesRows[(int) $row['id']] ?? [])['status'] ?? 0) === 1;
    if ($salesFilter === 'on') return $on;
    if ($salesFilter === 'off') return !$on;
    return true;
}));

$totalPages = max(1, (int) ceil($list['total'] / $perPage));

/** 当前产品的有效售价（覆盖价或产品价），两位小数字符串 */
$effectivePrice = static function (array $row) use ($salesRows): string {
    $sales = $salesRows[(int) $row['id']] ?? null;
    if ($sales !== null && $sales['price'] !== null && (string) $sales['price'] !== '') {
        return (string) $sales['price'];
    }
    return number_format((float) $row['price'], 2, '.', '');
};

$currentMenu = 'shop_sales';
$pageTitle = __('shop_sales_title');
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 py-6">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-900"><?php echo e(__('shop_sales_title')); ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?php echo e(__('shop_sales_desc')); ?></p>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
    <div class="mb-4 rounded border border-green-200 bg-green-50 text-green-700 px-3 py-2 text-sm" data-testid="shop-saved-tip"><?php echo e(__('shop_saved')); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['err']) && $_GET['err'] !== ''): ?>
    <div class="mb-4 rounded border border-red-200 bg-red-50 text-red-700 px-3 py-2 text-sm" data-testid="shop-error-tip"><?php echo e((string) $_GET['err']); ?></div>
    <?php endif; ?>

    <form method="get" action="/admin/plugin_page.php" class="mb-4 flex flex-wrap items-center gap-2">
        <input type="hidden" name="plugin" value="shop">
        <input type="text" name="keyword" value="<?php echo e($keyword); ?>" placeholder="<?php echo e(__('shop_placeholder_search')); ?>"
               class="border border-gray-300 rounded px-3 py-1.5 text-sm w-64">
        <select name="sales" class="border border-gray-300 rounded px-2 py-1.5 text-sm">
            <option value="all"<?php echo $salesFilter === 'all' ? ' selected' : ''; ?>><?php echo e(__('shop_filter_all')); ?></option>
            <option value="on"<?php echo $salesFilter === 'on' ? ' selected' : ''; ?>><?php echo e(__('shop_filter_on')); ?></option>
            <option value="off"<?php echo $salesFilter === 'off' ? ' selected' : ''; ?>><?php echo e(__('shop_filter_off')); ?></option>
        </select>
        <button type="submit" class="bg-blue-600 text-white text-sm px-4 py-1.5 rounded hover:bg-blue-700"><?php echo e(__('shop_btn_search')); ?></button>
    </form>

    <div class="bg-white rounded border border-gray-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
            <tr class="bg-gray-50 text-left text-gray-500">
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_product')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_price_display')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_sale_price')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_sku')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_stock')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_status')); ?></th>
                <th class="px-3 py-2 font-medium"><?php echo e(__('shop_col_action')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $row): ?>
                <?php $sales = $salesRows[(int) $row['id']] ?? null; ?>
                <tr class="border-t border-gray-100 align-middle" data-testid="shop-row-<?php echo (int) $row['id']; ?>">
                <form method="post" action="/admin/plugin_page.php?plugin=shop">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_sales">
                    <input type="hidden" name="product_id" value="<?php echo (int) $row['id']; ?>">
                    <td class="px-3 py-2">
                        <div class="font-medium text-gray-900"><?php echo e((string) $row['title']); ?></div>
                        <div class="text-xs text-gray-400"><?php echo e((string) $row['model']); ?> · <?php echo e((string) $row['lang']); ?></div>
                    </td>
                    <td class="px-3 py-2 text-gray-500"><?php echo e($effectivePrice($row)); ?></td>
                    <td class="px-3 py-2">
                        <input type="text" name="price" value="<?php echo e($sales !== null ? (string) ($sales['price'] ?? '') : ''); ?>"
                               placeholder="<?php echo e($effectivePrice($row)); ?>"
                               class="border border-gray-300 rounded px-2 py-1 text-sm w-24" data-testid="shop-price-<?php echo (int) $row['id']; ?>">
                    </td>
                    <td class="px-3 py-2">
                        <input type="text" name="sku" value="<?php echo e($sales !== null ? (string) ($sales['sku'] ?? '') : ''); ?>"
                               maxlength="64" class="border border-gray-300 rounded px-2 py-1 text-sm w-28">
                    </td>
                    <td class="px-3 py-2">
                        <input type="number" name="stock" min="0" step="1" value="<?php echo e((string) ($sales['stock'] ?? '0')); ?>"
                               class="border border-gray-300 rounded px-2 py-1 text-sm w-20" data-testid="shop-stock-<?php echo (int) $row['id']; ?>">
                    </td>
                    <td class="px-3 py-2">
                        <label class="inline-flex items-center gap-1 text-sm">
                            <input type="checkbox" name="status" value="1"<?php echo $sales !== null && (int) $sales['status'] === 1 ? ' checked' : ''; ?>
                                   data-testid="shop-status-<?php echo (int) $row['id']; ?>">
                            <?php echo e($sales !== null && (int) $sales['status'] === 1 ? __('shop_status_on') : __('shop_status_off')); ?>
                        </label>
                    </td>
                    <td class="px-3 py-2">
                        <button type="submit" class="text-sm px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700"><?php echo e(__('shop_btn_save')); ?></button>
                    </td>
                </form>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr class="border-t border-gray-100"><td colspan="7" class="px-3 py-8 text-center text-gray-400">—</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="mt-4 flex items-center gap-2 text-sm">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p === $page): ?>
                <span class="px-3 py-1 rounded bg-blue-600 text-white"><?php echo $p; ?></span>
            <?php else: ?>
                <a class="px-3 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"
                   href="/admin/plugin_page.php?plugin=shop&keyword=<?php echo rawurlencode($keyword); ?>&sales=<?php echo e($salesFilter); ?>&page=<?php echo $p; ?>"><?php echo $p; ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
