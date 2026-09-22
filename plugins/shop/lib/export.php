<?php
/** 商城订单 CSV 导出（后台下载与 CLI 共用）。 */

declare(strict_types=1);

require_once __DIR__ . '/orders.php';

function shopCsvSafe(string $value): string
{
    $trimmed = ltrim($value);
    if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }
    return $value;
}

/** @return list<string> */
function shopOrderExportHeaders(): array
{
    return [
        'order_no', 'status', 'payment_status', 'items', 'qty', 'amount_total',
        'contact_name', 'contact_phone', 'province', 'city', 'district', 'address',
        'remark', 'tracking_company', 'tracking_no', 'created_at', 'paid_at',
    ];
}

/** @return array<int,list<array<string,mixed>>> */
function shopOrderExportItems(array $orderIds): array
{
    if ($orderIds === []) {
        return [];
    }
    $ids = array_values(array_unique(array_map('intval', $orderIds)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $grouped = [];
    foreach (db()->fetchAll(
        'SELECT order_id, snapshot_json, qty FROM ' . DB_PREFIX . "shop_order_items WHERE order_id IN ({$placeholders}) ORDER BY id",
        $ids
    ) as $item) {
        $grouped[(int) $item['order_id']][] = $item;
    }
    return $grouped;
}

/**
 * @param resource $handle
 * @param array{status?:string,keyword?:string} $filters
 */
function shopWriteOrdersCsv($handle, array $filters = []): int
{
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, shopOrderExportHeaders());
    $total = shopOrderPage($filters, 1, 0)['total'];
    $offset = 0;
    $written = 0;
    while ($offset < $total) {
        $orders = shopOrderPage($filters, 200, $offset)['items'];
        $itemsByOrder = shopOrderExportItems(array_column($orders, 'id'));
        foreach ($orders as $order) {
            $contact = json_decode((string) ($order['contact_json'] ?? '{}'), true) ?: [];
            $address = json_decode((string) ($order['address_json'] ?? '{}'), true) ?: [];
            $itemNames = [];
            $qty = 0;
            foreach ($itemsByOrder[(int) $order['id']] ?? [] as $item) {
                $snapshot = json_decode((string) ($item['snapshot_json'] ?? '{}'), true) ?: [];
                $itemQty = (int) ($item['qty'] ?? 0);
                $qty += $itemQty;
                $label = trim((string) ($snapshot['title'] ?? ''));
                if ((string) ($snapshot['variant_label'] ?? '') !== '') {
                    $label .= ' [' . (string) $snapshot['variant_label'] . ']';
                }
                $itemNames[] = $label . ' x' . $itemQty;
            }
            $values = [
                (string) $order['order_no'],
                (string) $order['status'],
                (string) ($order['payment_status'] ?? ''),
                implode('; ', $itemNames),
                (string) $qty,
                (string) $order['amount_total'],
                (string) ($contact['name'] ?? ''),
                (string) ($contact['phone'] ?? ''),
                (string) ($address['province'] ?? ''),
                (string) ($address['city'] ?? ''),
                (string) ($address['district'] ?? ''),
                (string) ($address['address'] ?? ''),
                (string) $order['remark'],
                (string) ($order['tracking_company'] ?? ''),
                (string) ($order['tracking_no'] ?? ''),
                (int) $order['created_at'] > 0 ? date('Y-m-d H:i:s', (int) $order['created_at']) : '',
                (int) $order['paid_at'] > 0 ? date('Y-m-d H:i:s', (int) $order['paid_at']) : '',
            ];
            fputcsv($handle, array_map('shopCsvSafe', $values));
            $written++;
        }
        $offset += count($orders);
        if ($orders === []) {
            break;
        }
    }
    return $written;
}
