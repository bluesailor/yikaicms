<?php
/** 独立进程模拟旧核心：插件加载时后台菜单 API 尚未载入。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));

function __(string $key, array $params = []): string
{
    return $key;
}

require_once ROOT_PATH . '/includes/hooks.php';

$_SERVER['SCRIPT_FILENAME'] = ROOT_PATH . '/admin/index.php';
require ROOT_PATH . '/plugins/shop/register.php';

echo json_encode([
    'api_loaded' => function_exists('register_admin_menu'),
    'registration' => $GLOBALS['_yikai_admin_menu_registry'][0] ?? null,
    'order_registration' => $GLOBALS['_yikai_admin_menu_registry'][1] ?? null,
], JSON_THROW_ON_ERROR);
