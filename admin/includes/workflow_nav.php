<?php
declare(strict_types=1);
require_once __DIR__ . '/module_nav.php';
require_once __DIR__ . '/trans_pills.php';

$workflowRoute = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
[$workflowLabel, $workflowRoutes] = match ($workflowRoute) {
    'case', 'case_category' => ['admin_case', [
        'case' => ['case_tab_list', 'briefcase', 'edit_case'],
        'case_category' => ['case_tab_category', 'category', 'edit_case'],
    ]],
    'form', 'form_design' => ['admin_form', [
        'form' => ['inq_tab_data', 'inbox', 'form'],
        'form_design' => ['inq_tab_design', 'forms', 'form'],
    ]],
    'member', 'setting_member' => ['admin_member', [
        'member' => ['member_list', 'users', 'member'],
        'setting_member' => ['member_settings', 'settings', '*'],
    ]],
    'user', 'role' => ['admin_admins', [
        'user' => ['user_list', 'user', '*'],
        'role' => ['user_roles', 'shield-lock', '*'],
    ]],
    default => throw new LogicException('Unsupported admin workflow'),
};

// Carry only validated viewing language, never list filters or action parameters.
$workflowLang = (string) adminLangView()['view'];
$workflowItems = [];
foreach ($workflowRoutes as $route => [$key, $icon, $permission]) {
    if (!hasPermission($permission)) {
        continue;
    }
    $workflowItems[] = [
        'label' => __($key),
        'url' => '/admin/' . $route . '.php' . (in_array($workflowRoute, ['case', 'case_category', 'form', 'form_design'], true) ? '?lang=' . rawurlencode($workflowLang) : ''),
        'icon' => $icon,
        'active' => $workflowRoute === $route,
        'testid' => 'admin-module-' . $route,
    ];
}
adminModuleStart($workflowItems, __($workflowLabel));
