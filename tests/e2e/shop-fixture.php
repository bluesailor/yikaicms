<?php
/**
 * 商城 e2e fixture：一次性站准备/校验/复位。
 * 动作：
 *   products         返回第一个产品 {id, slug}
 *   ensure-sales     写销售配置（price 19.90 / stock 5 / 上架）+ 运费设置
 *   ensure-blox-template 发布含 shop/purchase 的指定产品详情模板
 *   read             订单数与 1 号产品库存
 *   reset            删测试产生的订单/明细/支付/销售配置/模板，恢复库存
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable smoke site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/hooks.php';   // plugin.php 的 loadActivePlugins 会调 do_action
require ROOT_PATH . '/includes/plugin.php';
// 插件启用时 main.php 已被上面加载（含 lib）；未启用则手动补载。require_once 防重复。
require_once ROOT_PATH . '/plugins/shop/lib/money.php';
require_once ROOT_PATH . '/plugins/shop/lib/tables.php';
require_once ROOT_PATH . '/plugins/shop/lib/cart.php';
require_once ROOT_PATH . '/plugins/shop/lib/sales.php';
require_once ROOT_PATH . '/plugins/shop/lib/orders.php';

$action = $argv[1] ?? '';
$productId = 1;

if ($action === 'products') {
    $row = db()->fetchOne('SELECT id, slug, title FROM ' . DB_PREFIX . 'products WHERE id = ?', [$productId]);
    echo json_encode($row, JSON_THROW_ON_ERROR);
} elseif ($action === 'ensure-sales') {
    shopEnsureSchema();
    db()->delete('shop_products', 'product_id = ?', [$productId]);
    db()->insert('shop_products', [
        'product_id' => $productId, 'sku' => 'E2E-SKU', 'price' => '19.90',
        'stock' => 5, 'status' => 1, 'created_at' => time(), 'updated_at' => time(),
    ]);
    settingModel()->saveBatch([
        'shop_shipping_fee_cents' => '1000',
        'shop_free_shipping_threshold_cents' => '10000',
        'shop_order_expire_minutes' => '30',
    ]);
    echo "ok\n";
} elseif ($action === 'ensure-blox-template') {
    require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    require_once ROOT_PATH . '/includes/HtmlCache.php';
    db()->delete('blox_templates', 'name = ?', ['E2E shop purchase']);
    $document = BloxDocumentPipeline::decode(ProductTemplateDocument::seed('zh-CN'));
    $document['settings']['product_template']['ids'] = [$productId];
    $document['sections'][0]['columns'][1]['elements'][] = [
        'type' => 'shop/purchase',
        'data' => ['show_price' => true, 'show_stock' => true, 'layout' => 'stacked', 'radius' => 'md'],
    ];
    $json = BloxDocumentPipeline::process(
        json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'template'
    )['json'];
    $templateId = bloxTemplateModel()->createDraft('product-detail', 'E2E shop purchase', $json);
    bloxTemplateModel()->publishDraft($templateId);
    HtmlCache::invalidate();
    echo json_encode(['id' => $templateId], JSON_THROW_ON_ERROR);
} elseif ($action === 'read') {
    $orders = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'shop_orders');
    $sales = db()->fetchOne('SELECT stock, specs_json FROM ' . DB_PREFIX . 'shop_products WHERE product_id = ?', [$productId]);
    $variants = shopProductVariantsFromJson(isset($sales['specs_json']) ? (string) $sales['specs_json'] : null);
    echo json_encode([
        'orders' => $orders,
        'stock' => (int) ($sales['stock'] ?? 0),
        'variantStock' => (int) ($variants[0]['stock'] ?? 0),
    ], JSON_THROW_ON_ERROR);
} elseif ($action === 'enable' || $action === 'disable') {
    // spec 里切换插件启用态：直接走 PluginModel（与后台 POST activate/deactivate 同源）
    $model = pluginModel();
    $row = $model->findBySlug('shop');
    if ($row === null) {
        $model->create(['slug' => 'shop', 'status' => 1, 'installed_at' => time(), 'activated_at' => time()]);
    } else {
        $model->updateById((int) $row['id'], ['status' => $action === 'enable' ? 1 : 0]);
    }
    echo "ok\n";
} elseif ($action === 'reset') {
    // 清理测试数据：订单明细/支付/退款/通知 → 订单 → 销售配置；库存复原
    shopEnsureSchema();
    require_once ROOT_PATH . '/includes/HtmlCache.php';
    $orders = DB_PREFIX . 'shop_orders';
    foreach (db()->fetchAll("SELECT id FROM {$orders}") as $row) {
        $id = (int) $row['id'];
        db()->delete('shop_order_items', 'order_id = ?', [$id]);
        db()->delete('shop_payments', 'order_id = ?', [$id]);
        db()->delete('shop_refunds', 'order_id = ?', [$id]);
    }
    db()->execute("DELETE FROM {$orders}");
    db()->delete('shop_payment_notifications', '1 = 1', []);
    db()->delete('shop_products', 'product_id = ?', [$productId]);
    db()->delete('blox_templates', 'name = ?', ['E2E shop purchase']);
    settingModel()->saveBatch([
        'shop_manual_payment_methods' => '[]',
        'shop_shipping_region_surcharges' => '',
    ]);
    HtmlCache::invalidate();
    echo "ok\n";
} else {
    throw new RuntimeException('Invalid action');
}
