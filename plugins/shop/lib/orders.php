<?php
/**
 * 商城订单核心（M1-c）：状态机、事务创建、收款确认、关闭释放。
 *
 * 状态分离原则（立项 §四）：订单状态与支付流水状态**分开**记录——
 *   订单：pending_payment(待付款) → awaiting_ship(待发货) → shipped(已发货)
 *         → completed(完成)；pending_payment → closed(关闭)
 *   支付流水：created → succeeded（线下=商家确认收款；线上=回调验签成功）
 *   「已付款」是支付维度的词，订单列表由支付流水补全显示——同一个 status
 *   不包办两件事（立项红线）。
 *
 * 下单事务（防超卖）：逐行 `UPDATE ... SET stock=stock-? WHERE product_id=? AND stock>=?`
 * 影响行数≠1 即库存不足回滚。SQLite 并发写锁（BUSY）由 M1 验收的接线层重试策略
 * 覆盖，本层保证单事务内原子。
 */

declare(strict_types=1);

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/tables.php';
require_once __DIR__ . '/sales.php';

/** @return list<string> 订单合法状态（推进顺序即此列表顺序；closed 只从 pending_payment 进入） */
function shopOrderStatuses(): array
{
    return ['pending_payment', 'awaiting_ship', 'shipped', 'completed', 'closed'];
}

/** @return list<string> 支付流水合法状态 */
function shopPaymentStatuses(): array
{
    return ['created', 'succeeded', 'failed', 'refunded'];
}

/** 状态推进表：只允许一步一格（closed 例外：仅 pending_payment 可关）。 */
function shopOrderCanTransition(string $from, string $to): bool
{
    $map = [
        'pending_payment' => ['awaiting_ship', 'closed'],
        'awaiting_ship' => ['shipped'],
        'shipped' => ['completed'],
        'completed' => [],
        'closed' => [],
    ];

    return in_array($to, $map[$from] ?? [], true);
}

/** 运费（分）：满额包邮阈值 > 0 且商品小计 ≥ 阈值 → 0；否则固定运费。 */
function shopShippingFeeCents(int $goodsCents, ?int $fixedFee = null, ?int $freeThreshold = null): int
{
    $fixedFee ??= (int) config('shop_shipping_fee_cents', 1500);
    $freeThreshold ??= (int) config('shop_free_shipping_threshold_cents', 0);
    if ($goodsCents < 0) {
        throw new InvalidArgumentException('shop: negative goods amount');
    }
    if ($freeThreshold > 0 && $goodsCents >= $freeThreshold) {
        return 0;
    }

    return max(0, $fixedFee);
}

/** 订单超时（秒）。下限 2 分钟：括号把分钟先换算成秒再取 max，防止误配成 2 秒关单。 */
function shopOrderExpireSeconds(): int
{
    return max(120, ((int) config('shop_order_expire_minutes', 30)) * 60);
}

/** 生成对外单号（时间 + 4 位随机；唯一索引兜底，冲突由创建方重试）。 */
function shopOrderNo(): string
{
    return date('YmdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * 下单（事务）。$lines 必须是 [canonicalId => qty] 这类已清洗行；
 * 价格/库存**全部现场重查重算**——购物车价格不可信（立项红线）。
 *
 * @param list<array{id:int,qty:int}> $lines
 * @param array<string,string> $contact name/phone/email
 * @param array<string,string> $address region/address
 * @return array{ok:bool, error:string, order_no?:string, order_id?:int}
 */
function shopOrderCreate(array $lines, array $contact, array $address, string $remark = ''): array
{
    if ($lines === []) {
        return ['ok' => false, 'error' => 'shop_err_empty_order'];
    }
    shopEnsureSchema();

    $memberId = (int) ($_SESSION['member_id'] ?? 0);
    $lang = function_exists('siteLang') ? siteLang() : 'zh-CN';
    $now = time();

    // 第一步：只读重算（不进事务——把慢操作挡在锁外）
    $priced = [];
    $goodsCents = 0;
    foreach ($lines as $line) {
        $product = shopResolveProductRow($line['id']);
        if ($product === null) {
            return ['ok' => false, 'error' => 'shop_err_product'];
        }
        $sales = shopCartSalesLookupDefault($line['id']);
        if ($sales === null) {
            return ['ok' => false, 'error' => 'shop_err_not_on_sale'];
        }
        if ($line['qty'] > (int) $sales['stock']) {
            return ['ok' => false, 'error' => 'shop_err_out_of_stock'];
        }
        $unitCents = shopEffectivePriceCents($product, $sales);
        if ($unitCents <= 0) {
            return ['ok' => false, 'error' => 'shop_err_price'];
        }
        $subtotal = shopMoneyMultiply($unitCents, $line['qty']);
        $goodsCents = shopMoneySum([$goodsCents, $subtotal]);
        $priced[] = [
            'key' => $line['id'],
            'qty' => $line['qty'],
            'unit_cents' => $unitCents,
            'subtotal_cents' => $subtotal,
            'snapshot' => [
                'title' => (string) $product['title'],
                'model' => (string) ($product['model'] ?? ''),
                'cover' => (string) ($product['cover'] ?? ''),
                'sku' => (string) ($sales['sku'] ?? ''),
                'lang' => (string) ($product['lang'] ?? $lang),
                'unit_price' => shopCentsToDecimal($unitCents),
            ],
        ];
    }
    $shippingCents = shopShippingFeeCents($goodsCents);
    $totalCents = shopMoneySum([$goodsCents, $shippingCents]);

    // 第二步：事务内扣库存 + 落单（唯一索引冲突重试换单号）
    db()->beginTransaction();
    try {
        foreach ($priced as $row) {
            // 条件扣减：库存不足时影响行数=0，绝不超卖
            $affected = db()->execute(
                'UPDATE ' . DB_PREFIX . 'shop_products SET stock = stock - ? WHERE product_id = ? AND stock >= ?',
                [$row['qty'], $row['key'], $row['qty']]
            );
            if ($affected !== 1) {
                throw new RuntimeException('shop_err_out_of_stock');
            }
        }

        $orderId = 0;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $orderNo = shopOrderNo();
            try {
                $orderId = (int) db()->insert('shop_orders', [
                    'order_no' => $orderNo,
                    'member_id' => $memberId,
                    'lang' => $lang,
                    'status' => 'pending_payment',
                    'currency' => 'CNY',
                    'amount_goods' => shopCentsToDecimal($goodsCents),
                    'amount_shipping' => shopCentsToDecimal($shippingCents),
                    'amount_total' => shopCentsToDecimal($totalCents),
                    'contact_json' => json_encode($contact, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'address_json' => json_encode($address, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'remark' => mb_substr($remark, 0, 500),
                    'expire_at' => $now + shopOrderExpireSeconds(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                break;
            } catch (Throwable $e) {
                if ($attempt === 2) {
                    throw $e;
                }
            }
        }

        foreach ($priced as $row) {
            db()->insert('shop_order_items', [
                'order_id' => $orderId,
                'product_id' => $row['key'],
                'snapshot_json' => json_encode($row['snapshot'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'qty' => $row['qty'],
                'unit_price' => shopCentsToDecimal($row['unit_cents']),
                'subtotal' => shopCentsToDecimal($row['subtotal_cents']),
                'created_at' => $now,
            ]);
        }

        db()->insert('shop_payments', [
            'order_id' => $orderId,
            'gateway' => 'offline',
            'status' => 'created',
            'amount' => shopCentsToDecimal($totalCents),
            'created_at' => $now,
        ]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        $message = $e->getMessage();
        if ($message === 'shop_err_out_of_stock') {
            return ['ok' => false, 'error' => 'shop_err_out_of_stock'];
        }
        error_log('[shop] order create failed: ' . $message);
        return ['ok' => false, 'error' => 'shop_err_order_failed'];
    }

    // 成功：清购物车（车已结算完成）。cart.php 可能未随本文件加载（如 CLI 调用），先补载
    if (!function_exists('shopCartClear')) {
        require_once __DIR__ . '/cart.php';
    }
    shopCartClear();
    // 下单者本会话可直接查看订单（订单页据此免手机尾号——尾号是给「换设备查单」用的）
    $_SESSION['shop_recent_order'] = $orderNo;
    do_action('data_changed');
    do_action('shop_order_placed', $orderId);

    return ['ok' => true, 'error' => '', 'order_no' => $orderNo, 'order_id' => $orderId];
}

/**
 * 收款确认（线下网关=商家人工确认；线上网关=M2 的回调入口复用本函数）。
 * 状态守卫：仅 pending_payment → awaiting_ship；支付流水 created → succeeded。
 * 幂等：重复确认同单直接返回成功（已 succeeded/已推进不再重复动作）。
 */
function shopOrderMarkPaid(int $orderId, string $gatewayTradeNo = ''): array
{
    shopEnsureSchema();
    $order = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'shop_orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return ['ok' => false, 'error' => 'shop_err_order_not_found'];
    }
    if ((string) $order['status'] === 'closed') {
        return ['ok' => false, 'error' => 'shop_err_order_closed'];
    }
    if ((string) $order['status'] !== 'pending_payment') {
        return ['ok' => true, 'error' => '', 'idempotent' => true];   // 已推进过
    }

    $payment = db()->fetchOne(
        'SELECT * FROM ' . DB_PREFIX . 'shop_payments WHERE order_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
        [$orderId, 'created']
    );

    db()->beginTransaction();
    try {
        db()->update('shop_orders', [
            'status' => 'awaiting_ship',
            'paid_at' => time(),
            'updated_at' => time(),
        ], 'id = ? AND status = ?', [$orderId, 'pending_payment']);
        if ($payment !== null) {
            db()->update('shop_payments', [
                'status' => 'succeeded',
                'gateway_trade_no' => $gatewayTradeNo !== '' ? mb_substr($gatewayTradeNo, 0, 64) : null,
                'paid_at' => time(),
            ], 'id = ?', [(int) $payment['id']]);
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('[shop] mark paid failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'shop_err_order_failed'];
    }
    do_action('data_changed');
    do_action('shop_order_paid', $orderId);

    return ['ok' => true, 'error' => ''];
}

/**
 * 关闭订单并释放库存（超时 cron / 商家关闭 / 买家取消共用）。
 * 仅 pending_payment 可关；释放 = 把扣减的库存加回（幂等：已关闭直接成功）。
 */
function shopOrderClose(int $orderId, string $reason = ''): array
{
    shopEnsureSchema();
    $order = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'shop_orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return ['ok' => false, 'error' => 'shop_err_order_not_found'];
    }
    if ((string) $order['status'] === 'closed') {
        return ['ok' => true, 'error' => '', 'idempotent' => true];
    }
    if ((string) $order['status'] !== 'pending_payment') {
        return ['ok' => false, 'error' => 'shop_err_order_cannot_close'];
    }

    db()->beginTransaction();
    try {
        db()->update('shop_orders', [
            'status' => 'closed',
            'remark' => mb_substr($reason !== '' ? $reason : (string) $order['remark'], 0, 500),
            'closed_at' => time(),
            'updated_at' => time(),
        ], 'id = ? AND status = ?', [$orderId, 'pending_payment']);
        foreach (db()->fetchAll('SELECT product_id, qty FROM ' . DB_PREFIX . 'shop_order_items WHERE order_id = ?', [$orderId]) as $item) {
            db()->execute(
                'UPDATE ' . DB_PREFIX . 'shop_products SET stock = stock + ? WHERE product_id = ?',
                [(int) $item['qty'], (int) $item['product_id']]
            );
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('[shop] order close failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'shop_err_order_failed'];
    }
    do_action('data_changed');

    return ['ok' => true, 'error' => ''];
}

/**
 * 商家侧统一状态推进（发货 shipped / 完成 completed）。
 * 走 shopOrderCanTransition 守卫；标记发货时间/完成时间。
 * 发货时可带物流信息（快递公司 + 单号，长度服务端再限一次）。
 */
function shopOrderTransition(int $orderId, string $to, string $trackingCompany = '', string $trackingNo = ''): array
{
    shopEnsureSchema();
    $order = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'shop_orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return ['ok' => false, 'error' => 'shop_err_order_not_found'];
    }
    if (!shopOrderCanTransition((string) $order['status'], $to)) {
        return ['ok' => false, 'error' => 'shop_err_order_transition'];
    }
    if ($trackingCompany !== '' && mb_strlen($trackingCompany) > 50) {
        return ['ok' => false, 'error' => 'shop_err_tracking'];
    }
    if ($trackingNo !== '' && (mb_strlen($trackingNo) > 64 || preg_match('/^[A-Za-z0-9\-]+$/', $trackingNo) !== 1)) {
        return ['ok' => false, 'error' => 'shop_err_tracking'];
    }

    $fields = ['status' => $to, 'updated_at' => time()];
    if ($to === 'shipped') {
        $fields['shipped_at'] = time();
        $fields['tracking_company'] = $trackingCompany;
        $fields['tracking_no'] = $trackingNo;
    } elseif ($to === 'completed') {
        $fields['completed_at'] = time();
    }
    db()->update('shop_orders', $fields, 'id = ? AND status = ?', [$orderId, $order['status']]);
    do_action('data_changed');

    return ['ok' => true, 'error' => ''];
}

/** 商家备注（追加覆盖 remark 列；仅文案，不推进状态）。 */
function shopOrderUpdateRemark(int $orderId, string $remark): array
{
    shopEnsureSchema();
    $order = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'shop_orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return ['ok' => false, 'error' => 'shop_err_order_not_found'];
    }
    db()->update('shop_orders', [
        'remark' => mb_substr($remark, 0, 500),
        'updated_at' => time(),
    ], 'id = ?', [$orderId]);

    return ['ok' => true, 'error' => ''];
}

/**
 * 商家后台订单分页列表（状态过滤 + 单号/联系方式模糊搜索）。
 * 附带最新支付流水状态与明细件数，避免逐单 N+1。
 *
 * @return array{items: list<array<string,mixed>>, total: int}
 */
function shopOrderPage(array $filters, int $limit, int $offset): array
{
    shopEnsureSchema();
    $where = [];
    $params = [];

    $status = (string) ($filters['status'] ?? 'all');
    if ($status !== 'all' && in_array($status, shopOrderStatuses(), true)) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }
    $keyword = trim((string) ($filters['keyword'] ?? ''));
    if ($keyword !== '') {
        $where[] = '(o.order_no LIKE ? OR o.contact_json LIKE ? OR o.remark LIKE ?)';
        $like = '%' . $keyword . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $whereSQL = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

    $orders = DB_PREFIX . 'shop_orders';
    $payments = DB_PREFIX . 'shop_payments';
    $items = DB_PREFIX . 'shop_order_items';

    $total = (int) db()->fetchColumn("SELECT COUNT(*) FROM {$orders} o {$whereSQL}", $params);

    // MySQL 5.7 兼容：无窗口函数——最新支付流水用「自连接取最大 id」子查询
    $rows = db()->fetchAll(
        "SELECT o.*, p.status AS payment_status,
                (SELECT COUNT(*) FROM {$items} i WHERE i.order_id = o.id) AS item_count
         FROM {$orders} o
         LEFT JOIN {$payments} p ON p.id = (
             SELECT MAX(p2.id) FROM {$payments} p2 WHERE p2.order_id = o.id
         )
         {$whereSQL}
         ORDER BY o.id DESC LIMIT ? OFFSET ?",
        array_merge($params, [$limit, $offset])
    );

    return ['items' => $rows, 'total' => $total];
}

/**
 * 订单详情（商家后台展示用）：订单 + 明细 + 支付流水。快照渲染，不查实时商品。
 * 订单行并入最新支付流水状态（payment_status 键，与列表页同口径）。
 * @return array<string,mixed>|null
 */
function shopOrderDetail(int $orderId): ?array
{
    shopEnsureSchema();
    $order = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'shop_orders WHERE id = ?', [$orderId]);
    if ($order === null) {
        return null;
    }
    $payments = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'shop_payments WHERE order_id = ? ORDER BY id', [$orderId]);
    $latestPayment = db()->fetchOne(
        'SELECT * FROM ' . DB_PREFIX . 'shop_payments WHERE order_id = ? ORDER BY id DESC LIMIT 1',
        [$orderId]
    );
    $order['payment_status'] = $latestPayment !== null ? (string) $latestPayment['status'] : 'created';

    return [
        'order' => $order,
        'items' => db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'shop_order_items WHERE order_id = ? ORDER BY id', [$orderId]),
        'payments' => $payments,
    ];
}

/**
 * 订单查询（游客凭单号 + 手机尾号校验；会员凭 id 直查）。
 * $sessionBypass=true 时跳过尾号校验——仅由下单会话持有 shop_recent_order 的
 * 页面调用，外部请求拿不到该值。
 * 展示时隐藏联系电话全号（页面上只显示脱敏版），库内快照保留原始值。
 *
 * @return array{ok:bool, error:string, order?:array<string,mixed>, items?:list<array<string,mixed>>, payment?:array<string,mixed>}
 */
function shopOrderLookup(string $orderNo, string $phoneTail = '', ?int $memberId = null, bool $sessionBypass = false): array
{
    shopEnsureSchema();
    $order = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'shop_orders WHERE order_no = ?', [$orderNo]);
    if ($order === null) {
        return ['ok' => false, 'error' => 'shop_err_order_not_found'];
    }
    // 会员本人可查；游客必须手机尾号（后 4 位）匹配
    $contact = json_decode((string) ($order['contact_json'] ?? '{}'), true) ?: [];
    $phone = (string) ($contact['phone'] ?? '');
    if (!$sessionBypass
        && ($memberId === null || $memberId <= 0 || (int) $order['member_id'] !== $memberId)) {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 4 || $phoneTail === '' || !hash_equals(substr($digits, -4), preg_replace('/\D/', '', $phoneTail))) {
            return ['ok' => false, 'error' => 'shop_err_order_verify'];
        }
    }
    $items = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'shop_order_items WHERE order_id = ? ORDER BY id', [(int) $order['id']]);
    $payment = db()->fetchOne(
        'SELECT * FROM ' . DB_PREFIX . 'shop_payments WHERE order_id = ? ORDER BY id DESC LIMIT 1',
        [(int) $order['id']]
    );

    return [
        'ok' => true,
        'error' => '',
        'order' => $order,
        'items' => $items,
        'payment' => $payment,
    ];
}
