<?php
/**
 * 轻量商城 - 管理页面（M1-a + 加固：评审 P1-1/P1-2/P2-1/P2-2）。
 *
 * 由 /admin/plugin_page.php?plugin=shop 加载：宿主页已完成 checkLogin +
 * requirePermission(shop_manage)（plugin.json 声明的权限键，G1 机制）。
 *
 * 关键语义（评审后定案）：
 * - 售价：**只有空字符串表示继承产品价**；'0'/'0.00' 一律视为非法（实物商品
 *   售价必须为正），不再出现「两种零写法结果不同」。
 * - 多语言：销售配置挂翻译组（shopCanonicalProductId），各语言版本共享
 *   SKU/售价/库存。
 * - 列表筛选/分页在 SQL 层完成（lib/sales.php）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/lib/money.php';
require_once __DIR__ . '/lib/tables.php';
require_once __DIR__ . '/lib/sales.php';

try {
    shopEnsureSchema();
} catch (Throwable $schemaError) {
    // 固定提示给用户，细节进日志——原始异常可能带表名/SQL/驱动信息（评审 P2-2）
    error_log('[shop] ensure schema failed: ' . $schemaError->getMessage());
    die('<div style="padding:40px">' . e(__('shop_sales_title')) . ' — ' . e(__('shop_err_schema')) . '</div>');
}

// ============================================================
// POST：保存单个产品的销售配置（普通表单 + PRG，无需 JS）
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_sales') {
    verifyCsrf();

    $productId = (int) ($_POST['product_id'] ?? 0);
    $product = $productId > 0 ? productModel()->find($productId) : null;
    if (!$product || ($product['deleted_at'] ?? null) !== null) {
        header('Location: /admin/plugin_page.php?plugin=shop&err=' . urlencode(__('shop_err_product')));
        exit;
    }
    // 多语言共享：写入翻译组键，而不是当前语言行的 id
    $salesKey = shopCanonicalProductId($product);

    $sku = trim((string) ($_POST['sku'] ?? ''));
    $priceRaw = trim((string) ($_POST['price'] ?? ''));
    $stockRaw = (string) ($_POST['stock'] ?? '');
    $status = (int) ($_POST['status'] ?? 0) === 1 ? 1 : 0;

    $error = '';
    if (mb_strlen($sku) > 64) {
        $error = __('shop_err_sku_len');
    } elseif ($priceRaw === '') {
        $priceCents = null;   // 空 = 继承产品价（唯一的继承写法）
    } else {
        // '0'、负数、超 decimal(10,2)、超长串、畸形 → 统一非法文案
        $priceCents = shopValidSalePriceCents($priceRaw);
        if ($priceCents === null) {
            $error = __('shop_err_price');
        }
    }
    if ($error === '') {
        $stock = shopValidStock($stockRaw);
        if ($stock === null) {
            $error = __('shop_err_stock');
        }
    }
    if ($error !== '') {
        header('Location: /admin/plugin_page.php?plugin=shop&err=' . urlencode($error));
        exit;
    }

    $now = time();
    $data = [
        'sku' => $sku,
        'price' => $priceCents !== null ? shopCentsToDecimal($priceCents) : null,
        'stock' => $stock,
        'status' => $status,
        'updated_at' => $now,
    ];
    $exists = db()->fetchOne(
        'SELECT product_id FROM ' . DB_PREFIX . 'shop_products WHERE product_id = ?',
        [$salesKey]
    );
    if ($exists) {
        db()->update('shop_products', $data, 'product_id = ?', [$salesKey]);
    } else {
        $data['product_id'] = $salesKey;
        $data['created_at'] = $now;
        db()->insert('shop_products', $data);
    }

    adminLog('shop', 'save_sales', 'Save sales config for product #' . $productId . ' (key ' . $salesKey . ') status=' . $status . ' stock=' . $stock);
    do_action('data_changed');

    header('Location: /admin/plugin_page.php?plugin=shop&saved=1');
    exit;
}

// ============================================================
// 列表：筛选与分页全部在数据层（lib/sales.php）
// ============================================================
$keyword = trim((string) ($_GET['keyword'] ?? ''));
$salesFilter = in_array(($_GET['sales'] ?? 'all'), ['all', 'on', 'off'], true) ? (string) $_GET['sales'] : 'all';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$list = shopSalesPage(['keyword' => $keyword, 'sales' => $salesFilter], $perPage, ($page - 1) * $perPage);
$items = $list['items'];
$totalPages = max(1, (int) ceil($list['total'] / $perPage));

/** 行的展示售价：覆盖价 > 产品价（均为两位小数字符串）。 */
$rowPrice = static fn(array $row): string
    => ($row['sale_price'] !== null && (string) $row['sale_price'] !== '')
        ? (string) $row['sale_price']
        : number_format((float) $row['price'], 2, '.', '');

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
                <?php $on = (int) ($row['status'] ?? 0) === 1; ?>
                <tr class="border-t border-gray-100 align-middle" data-testid="shop-row-<?php echo (int) $row['id']; ?>">
                <form method="post" action="/admin/plugin_page.php?plugin=shop">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_sales">
                    <input type="hidden" name="product_id" value="<?php echo (int) $row['id']; ?>">
                    <td class="px-3 py-2">
                        <div class="font-medium text-gray-900"><?php echo e((string) $row['title']); ?></div>
                        <div class="text-xs text-gray-400"><?php echo e((string) $row['model']); ?> · <?php echo e((string) $row['lang']); ?><?php echo (int) ($row['sales_key'] ?? 0) > 0 && (int) $row['sales_key'] !== (int) $row['id'] ? ' · ' . e(__('shop_shared_group')) : ''; ?></div>
                    </td>
                    <td class="px-3 py-2 text-gray-500"><?php echo e($rowPrice($row)); ?></td>
                    <td class="px-3 py-2">
                        <input type="text" name="price" value="<?php echo e($row['sale_price'] !== null ? (string) $row['sale_price'] : ''); ?>"
                               placeholder="<?php echo e($rowPrice($row)); ?>"
                               class="border border-gray-300 rounded px-2 py-1 text-sm w-24" data-testid="shop-price-<?php echo (int) $row['id']; ?>">
                    </td>
                    <td class="px-3 py-2">
                        <input type="text" name="sku" value="<?php echo e((string) ($row['sku'] ?? '')); ?>"
                               maxlength="64" class="border border-gray-300 rounded px-2 py-1 text-sm w-28">
                    </td>
                    <td class="px-3 py-2">
                        <input type="number" name="stock" min="0" step="1" value="<?php echo e((string) ($row['stock'] ?? '0')); ?>"
                               class="border border-gray-300 rounded px-2 py-1 text-sm w-20" data-testid="shop-stock-<?php echo (int) $row['id']; ?>">
                    </td>
                    <td class="px-3 py-2">
                        <label class="inline-flex items-center gap-1 text-sm">
                            <input type="checkbox" name="status" value="1"<?php echo $on ? ' checked' : ''; ?>
                                   data-testid="shop-status-<?php echo (int) $row['id']; ?>">
                            <?php echo e($on ? __('shop_status_on') : __('shop_status_off')); ?>
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
