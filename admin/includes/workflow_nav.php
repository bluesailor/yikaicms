<?php
declare(strict_types=1);
require_once __DIR__ . '/module_nav.php';
require_once __DIR__ . '/trans_pills.php';

$workflowRoute = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
[$workflowLabel, $workflowRoutes] = match ($workflowRoute) {
    'article', 'article_category' => ['admin_article', [
        'article' => ['article_tab_list', 'article', 'edit_article'],
        'article_category' => ['article_tab_category', 'category', 'edit_article'],
    ]],
    'download', 'download_category' => ['admin_download', [
        'download' => ['download_tab_list', 'download', 'edit_download'],
        'download_category' => ['download_tab_category', 'category', 'edit_download'],
    ]],
    'case', 'case_category' => ['admin_case', [
        'case' => ['case_tab_list', 'briefcase', 'edit_case'],
        'case_category' => ['case_tab_category', 'category', 'edit_case'],
    ]],
    'form', 'form_design', 'form_spam' => ['admin_form', [
        'form' => ['inq_tab_data', 'inbox', 'form'],
        'form_design' => ['inq_tab_design', 'forms', 'form'],
        'form_spam' => ['fsp_title', 'shield-check', '*'],
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
        'url' => '/admin/' . $route . '.php' . (in_array($workflowRoute, ['article', 'article_category', 'download', 'download_category', 'case', 'case_category', 'form', 'form_design'], true) ? '?lang=' . rawurlencode($workflowLang) : ''),
        'icon' => $icon,
        'active' => $workflowRoute === $route,
        'testid' => 'admin-module-' . $route,
    ];
}
adminModuleStart($workflowItems, __($workflowLabel));
