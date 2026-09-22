<?php
/** 轻量商城后台访问边界：角色能力与可见视图、可执行动作的唯一映射。 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/** 无权访问任何商城视图时返回 null。 */
function shopAdminResolveView(string $requested, bool $canManage, bool $canOrders): ?string
{
    $requested = in_array($requested, ['sales', 'orders'], true) ? $requested : 'sales';
    if ($requested === 'orders') {
        return $canOrders ? 'orders' : ($canManage ? 'sales' : null);
    }
    return $canManage ? 'sales' : ($canOrders ? 'orders' : null);
}

/** 未知动作一律拒绝；商城管理与订单处理互不代替。 */
function shopAdminActionAllowed(string $action, bool $canManage, bool $canOrders): bool
{
    if (in_array($action, ['save_shipping', 'save_sales'], true)) {
        return $canManage;
    }
    if (str_starts_with($action, 'order_') || str_starts_with($action, 'refund_')) {
        return $canOrders;
    }
    return false;
}
