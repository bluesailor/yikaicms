<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

add_action('admin_init', function (): void {
    if (!function_exists('register_admin_menu')) {
        return;
    }

    register_admin_menu('appearance', [
        'key' => 'logo-maker',
        'label' => __('logo_maker_menu'),
        'url' => '/admin/plugin_page.php?plugin=logo-maker',
        'priority' => 45,
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5v-9Z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m8 9 4 2.25L16 9M12 11.25V17"/>',
    ]);
});
