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
    // 访问触发降级（立项 v2 修正 #3 的交付项）：无 crontab 的共享主机靠访问
    // 兜底跑「超时关单」。flock + 60s 最小间隔防堆叠；正常配置了 cron.php?token=
    // 或 CLI cron:run 的站点，两边共用 cron_shop_order_expire_last 不会双跑。
    shopRunFallbackTasks();
});

/**
 * 访问触发的商城兜底任务。只在「到点且拿得到锁」时执行一次；
 * 任何异常都吞掉——前台请求绝不能因为定时任务炸掉。
 */
function shopRunFallbackTasks(): void
{
    try {
        $lockDir = STORAGE_PATH . '/shop';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0777, true);
        }
        $handle = @fopen($lockDir . '/cron-fallback.lock', 'c');
        if ($handle === false) {
            return;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return;   // 别的请求正在跑
        }
        try {
            $last = (int) settingModel()->get('shop_fallback_last', '0');
            if (time() - $last < 60) {
                return;
            }
            require_once ROOT_PATH . '/includes/Cron.php';
            Cron::runOne('shop_order_expire');   // 注册在 cron_register（下方）
            settingModel()->set('shop_fallback_last', (string) time());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    } catch (\Throwable $e) {
        error_log('[shop] fallback task failed: ' . $e->getMessage());
    }
}

// 超时关单：待付款超时的订单自动关闭并回补库存（释放被锁的库存）
add_action('cron_register', function (): void {
    if (!function_exists('shopOrderClose')) {
        require_once __DIR__ . '/lib/orders.php';
    }
    Cron::register('shop_order_expire', __('cron_shop_order_expire'), 300, function (): string {
        if (!db()->tableExists('shop_orders')) {
            return __('cron_nothing_due');
        }
        $expired = db()->fetchAll(
            'SELECT id FROM ' . DB_PREFIX . 'shop_orders WHERE status = ? AND expire_at > 0 AND expire_at < ? LIMIT 200',
            ['pending_payment', time()]
        );
        $closed = 0;
        foreach ($expired as $row) {
            $r = shopOrderClose((int) $row['id'], 'expired');
            if ($r['ok']) {
                $closed++;
            }
        }
        return $closed > 0 ? str_replace(':n', (string) $closed, __('cron_shop_expired_n')) : __('cron_nothing_due');
    });
});

// 订单邮件：新订单通知商家；确认收款通知买家。sendMail 自带 mail_log 记录，
// 失败由既有 mail_retry cron 自动重试（复用核心邮件可靠性，不另造队列）。
// renderMailTemplate 在 includes/mail_notify.php，init 链不加载，这里补上。
$shopMailBootstrap = static function (): void {
    if (!function_exists('renderMailTemplate') && is_file(ROOT_PATH . '/includes/mail_notify.php')) {
        require_once ROOT_PATH . '/includes/mail_notify.php';
    }
};

add_action('shop_order_placed', function (int $orderId) use ($shopMailBootstrap): void {
    $shopMailBootstrap();
    require_once __DIR__ . '/lib/orders.php';
    $detail = shopOrderDetail($orderId);
    if ($detail === null) {
        return;
    }
    $order = $detail['order'];
    $admin = (string) config('mail_admin', '');
    if ($admin === '') {
        return;
    }
    $vars = [
        'order_no' => (string) $order['order_no'],
        'total' => formatPrice((string) $order['amount_total']),
    ];
    sendMail(
        $admin,
        renderMailTemplate(__('shop_mail_new_subject'), $vars),
        // 语言包是单引号 PHP 串，\\n 是字面量——发送前统一转成真实换行
        str_replace('\\n', "\n", renderMailTemplate(__('shop_mail_new_body'), $vars))
    );
});

add_action('shop_order_paid', function (int $orderId) use ($shopMailBootstrap): void {
    $shopMailBootstrap();
    require_once __DIR__ . '/lib/orders.php';
    $detail = shopOrderDetail($orderId);
    if ($detail === null) {
        return;
    }
    $contact = json_decode((string) $detail['order']['contact_json'], true) ?: [];
    $to = (string) ($contact['email'] ?? '');
    if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return;   // 买家没留邮箱：无通知对象，不视为失败
    }
    $vars = [
        'order_no' => (string) $detail['order']['order_no'],
        'total' => formatPrice((string) $detail['order']['amount_total']),
    ];
    sendMail(
        $to,
        renderMailTemplate(__('shop_mail_paid_subject'), $vars),
        str_replace('\\n', "\n", renderMailTemplate(__('shop_mail_paid_body'), $vars))
    );
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
