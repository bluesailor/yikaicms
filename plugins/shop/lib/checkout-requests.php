<?php
/** 结算请求保留重放凭据；与一次消费即销毁的普通表单 nonce 不同。 */

declare(strict_types=1);

/** @param list<array{id:int,variant?:string,qty:int}> $lines */
function shopCheckoutLinesHash(array $lines): string
{
    $normalized = [];
    foreach ($lines as $line) {
        $normalized[] = ['id' => (int) $line['id'], 'variant' => (string) ($line['variant'] ?? ''), 'qty' => (int) $line['qty']];
    }
    usort($normalized, static fn(array $a, array $b): int => [$a['id'], $a['variant']] <=> [$b['id'], $b['variant']]);
    return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
}

function shopCheckoutRequestOwner(): string
{
    if (!isset($_SESSION['shop_checkout_owner']) || !is_string($_SESSION['shop_checkout_owner'])) {
        $_SESSION['shop_checkout_owner'] = bin2hex(random_bytes(32));
    }
    return hash('sha256', $_SESSION['shop_checkout_owner'] . ':' . (int) ($_SESSION['member_id'] ?? 0));
}

/** @param list<array{id:int,variant?:string,qty:int}> $lines */
function shopCheckoutIssueRequest(array $lines): string
{
    $now = time();
    $requests = $_SESSION['shop_checkout_requests'] ?? [];
    if (!is_array($requests)) {
        $requests = [];
    }
    foreach ($requests as $key => $request) {
        if (!is_array($request) || (int) ($request['expires'] ?? 0) < $now) {
            unset($requests[$key]);
        }
    }
    $key = bin2hex(random_bytes(16));
    $requests[$key] = ['owner' => shopCheckoutRequestOwner(), 'lines' => $lines, 'expires' => $now + 7200];
    while (count($requests) > 20) {
        array_shift($requests);
    }
    $_SESSION['shop_checkout_requests'] = $requests;
    return $key;
}

/** @return null|array{owner:string,lines:array,expires:int} */
function shopCheckoutRequest(string $key): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/D', $key) !== 1) {
        return null;
    }
    $request = $_SESSION['shop_checkout_requests'][$key] ?? null;
    if (!is_array($request) || !is_string($request['owner'] ?? null)
        || !is_array($request['lines'] ?? null) || (int) ($request['expires'] ?? 0) < time()
        || !hash_equals($request['owner'], shopCheckoutRequestOwner())) {
        return null;
    }
    return $request;
}

/** 查询只返回同归属、同内容的已提交订单；调用方仍须验证前台签名。 */
function shopCheckoutReplay(string $key, string $owner, string $payloadHash): ?array
{
    $row = db()->fetchOne(
        'SELECT r.owner_hash, r.payload_hash, r.order_id, o.order_no FROM ' . DB_PREFIX . 'shop_checkout_requests r'
        . ' LEFT JOIN ' . DB_PREFIX . 'shop_orders o ON o.id = r.order_id WHERE r.request_key = ?',
        [$key]
    );
    if ($row === null) {
        return null;
    }
    if (!hash_equals((string) $row['owner_hash'], $owner) || !hash_equals((string) $row['payload_hash'], $payloadHash)) {
        return ['ok' => false, 'error' => 'shop_err_checkout_changed'];
    }
    if ((int) $row['order_id'] <= 0 || empty($row['order_no'])) {
        return ['ok' => false, 'error' => 'shop_err_order_failed'];
    }
    $_SESSION['shop_recent_order'] = (string) $row['order_no'];
    return ['ok' => true, 'error' => '', 'order_id' => (int) $row['order_id'], 'order_no' => (string) $row['order_no'], 'idempotent' => true];
}
