<?php
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition Direct requests do not load the CMS bootstrap. */
if (!defined('ROOT_PATH')) exit('Access Denied');

// 后台「系统」分组里、紧跟安全设置的入口（设置页由 admin.php 提供）
if (function_exists('register_admin_menu')) {
    register_admin_menu('system', [
        'key'      => 'geo_block',
        'label'    => __('gb_menu'),
        'url'      => '/admin/plugin_page.php?plugin=geo-block',
        'perm'     => '*',
        'priority' => 15,
        'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    ]);
}
