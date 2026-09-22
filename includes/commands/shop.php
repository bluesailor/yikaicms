<?php
/**
 * 命令组：shop（商城插件）
 *   shop:orders [--status=] [--limit=] [--out=文件]
 *     导出订单 CSV。停用插件期间订单表仍在，本命令是评审定案的
 *     「停用后查询」通道——数据保留不等于在线可查，导出不依赖插件启用状态。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

// 插件未安装（未随包分发）时整组命令不存在——不报错、不污染 help
if (!is_dir(ROOT_PATH . '/plugins/shop')) return;

require_once ROOT_PATH . '/plugins/shop/lib/money.php';
require_once ROOT_PATH . '/plugins/shop/lib/tables.php';
require_once ROOT_PATH . '/plugins/shop/lib/orders.php';
require_once ROOT_PATH . '/plugins/shop/lib/export.php';

CLI::register('shop:orders', '导出商城订单 CSV（插件停用期间也可用）', function (array $args, array $opts): int {
    try {
        shopEnsureSchema();
    } catch (Throwable $e) {
        CLI::err('商城订单表不可用：' . $e->getMessage());
        return 1;
    }

    $status = (string) ($opts['status'] ?? 'all');
    $filters = ['status' => $status];
    if (!in_array($status, array_merge(['all'], shopOrderStatuses()), true)) {
        CLI::err('未知状态：' . $status);
        return 1;
    }

    $total = shopOrderPage($filters, 1, 0)['total'];
    if ($total === 0) {
        CLI::out('无订单');
        return 0;
    }

    $out = (string) ($opts['out'] ?? '');
    $handle = $out !== '' ? @fopen($out, 'wb') : fopen('php://stdout', 'wb');
    if ($handle === false) {
        CLI::err('无法写入：' . $out);
        return 1;
    }
    $written = shopWriteOrdersCsv($handle, $filters);
    if ($handle !== STDOUT) {
        fclose($handle);
    }
    CLI::ok("导出 {$written} 条订单" . ($out !== '' ? ' → ' . $out : ''));

    return 0;
}, ['usage' => 'shop:orders [--status=pending_payment|awaiting_ship|shipped|completed|closed] [--out=shop-orders.csv]']);
