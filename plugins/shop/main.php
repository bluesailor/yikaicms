<?php
/**
 * 轻量商城 - 前台/公共引导。
 *
 * M0 阶段只做两件事：加载库、在 init 时懒建表（幂等，表在才继续——缺表状态
 * 下前台零行为，正好满足「停用/未初始化时产品页完全不受影响」的立项红线）。
 * 购物车路由（dispatch_routes）与 cron 任务在 M1-b/M1-d 增量加入。
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/lib/money.php';
require_once __DIR__ . '/lib/tables.php';

add_action('init', function (): void {
    try {
        shopEnsureSchema();
    } catch (\Throwable $e) {
        // 建表失败（权限/磁盘）不炸前台：商城功能不可用，但站点其余部分照常
        error_log('[shop] ensure schema failed: ' . $e->getMessage());
    }
});

// 产品详情页（原生回退版式）的购买入口：产品在售且有余量时渲染加购表单。
// Blox 详情模板路径的购买组件按立项排期属 M3，本钩子先覆盖原生版式。
add_action('product_detail_purchase', function (array $product): void {
    require_once __DIR__ . '/lib/sales.php';
    require_once __DIR__ . '/lib/cart.php';
    try {
        $sales = shopCartSalesLookupDefault(shopCanonicalProductId($product));
    } catch (\Throwable $e) {
        return;   // 表未就绪（未初始化/停用残留）：购买入口静默不渲染
    }
    if ($sales === null || (int) $sales['stock'] <= 0) {
        return;
    }
    $secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
    if ($secret === '') {
        return;
    }
    $ts = time();
    $sig = FormSubmissionToken::sign('shop_cart', $ts, $secret);
    $maxQty = min(999, (int) $sales['stock']);
    ?>
    <div class="mt-4" data-testid="shop-buy-form">
        <form method="post" action="/shop/api" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="op" value="add">
            <input type="hidden" name="pid" value="<?php echo (int) $product['id']; ?>">
            <input type="hidden" name="ts" value="<?php echo (int) $ts; ?>">
            <input type="hidden" name="sig" value="<?php echo e($sig); ?>">
            <label class="sr-only" for="shop-qty"><?php echo e(__('shop_col_qty')); ?></label>
            <input id="shop-qty" type="number" name="qty" min="1" max="<?php echo (int) $maxQty; ?>" step="1" value="1"
                   class="border border-gray-300 rounded px-3 py-2 text-sm w-20" data-testid="shop-buy-qty">
            <button type="submit" class="bg-primary hover:bg-secondary text-white text-sm px-5 py-2 rounded"
                    data-testid="shop-buy-submit"><?php echo e(__('shop_btn_add_cart')); ?></button>
            <span class="text-xs text-gray-400"><?php echo e(__('shop_stock_left', ['n' => (string) $sales['stock']])); ?></span>
        </form>
    </div>
    <?php
});
