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
