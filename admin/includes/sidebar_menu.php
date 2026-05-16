<?php
/**
 * Yikai CMS — admin sidebar menu definition.
 *
 * Returns the canonical default menu structure. Plugins extend it via
 * `register_admin_menu()` (see includes/hooks.php) or by hooking the
 * `admin_sidebar` filter directly:
 *
 *   add_filter('admin_sidebar', function (array $menu): array {
 *       $menu['my_group']['items'][] = [
 *           'key'   => 'my_thing',
 *           'label' => 'My Thing',
 *           'url'   => '/admin/myplugin/things.php',
 *           'icon'  => '<path stroke-linecap="round" ... />',
 *       ];
 *       return $menu;
 *   });
 *
 * Structure:
 *   $menu[<group_key>] = [
 *     'label'        => string,             // group heading (i18n)
 *     'priority'     => int,                // group order; ascending; default 100
 *     'super_only'   => bool,               // hide unless isSuperAdmin()
 *     'items'        => [
 *       [
 *         'key'         => string,          // matches $currentMenu for active state
 *         'label'       => string,          // anchor text (i18n)
 *         'url'         => string,
 *         'icon'        => string,          // raw <path/> SVG markup (24x24 viewBox)
 *         'active_keys' => string[],        // optional — extra $currentMenu values that
 *                                           //            should also light this item up
 *         'priority'    => int,             // item order within group; ascending; default 100
 *       ],
 *       ...
 *     ],
 *   ];
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

return [
    'content' => [
        'label'    => __('admin_group_content'),
        'priority' => 10,
        'items'    => [
            [
                'key'   => 'channel',
                'label' => __('admin_channel'),
                'url'   => '/admin/channel.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>',
            ],
            [
                'key'   => 'page',
                'label' => __('admin_page'),
                'url'   => '/admin/page.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>',
            ],
        ],
    ],
    'product' => [
        'label'    => __('admin_group_product'),
        'priority' => 20,
        'items'    => [
            [
                'key'         => 'product',
                'label'       => __('admin_product'),
                'url'         => '/admin/product.php',
                'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>',
                'active_keys' => ['product', 'product_setting'],
            ],
            [
                'key'   => 'case',
                'label' => __('admin_case'),
                'url'   => '/admin/case.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>',
            ],
        ],
    ],
    'article' => [
        'label'    => __('admin_group_article'),
        'priority' => 30,
        'items'    => [
            [
                'key'   => 'article',
                'label' => __('admin_article'),
                'url'   => '/admin/article.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path>',
            ],
            [
                'key'   => 'download',
                'label' => __('admin_download'),
                'url'   => '/admin/download.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>',
            ],
            [
                'key'   => 'job',
                'label' => __('admin_job'),
                'url'   => '/admin/job.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>',
            ],
        ],
    ],
    'media' => [
        'label'    => __('admin_group_media'),
        'priority' => 40,
        'items'    => [
            [
                'key'   => 'media',
                'label' => __('admin_media'),
                'url'   => '/admin/media.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>',
            ],
            [
                'key'   => 'album',
                'label' => __('admin_album'),
                'url'   => '/admin/album.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>',
            ],
            [
                'key'   => 'banner',
                'label' => __('admin_banner'),
                'url'   => '/admin/banner.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>',
            ],
            [
                'key'   => 'timeline',
                'label' => __('admin_timeline'),
                'url'   => '/admin/timeline.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
            ],
            [
                'key'   => 'link',
                'label' => __('admin_link'),
                'url'   => '/admin/link.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>',
            ],
        ],
    ],
    'data' => [
        'label'    => __('admin_group_data'),
        'priority' => 50,
        'items'    => [
            [
                'key'         => 'form',
                'label'       => __('admin_form'),
                'url'         => '/admin/form.php',
                'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>',
                'active_keys' => ['form', 'form_design'],
            ],
            [
                'key'         => 'member',
                'label'       => __('admin_member'),
                'url'         => '/admin/member.php',
                'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>',
                'active_keys' => ['member', 'setting_member'],
            ],
        ],
    ],
    'site' => [
        'label'    => __('admin_group_site'),
        'priority' => 60,
        'items'    => [
            [
                'key'   => 'setting',
                'label' => __('admin_setting'),
                'url'   => '/admin/setting.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>',
            ],
            [
                'key'   => 'setting_home',
                'label' => __('admin_setting_home'),
                'url'   => '/admin/setting_home.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>',
            ],
            [
                'key'   => 'setting_contact',
                'label' => __('admin_setting_contact'),
                'url'   => '/admin/setting_contact.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>',
            ],
            [
                'key'   => 'setting_social',
                'label' => __('admin_setting_social'),
                'url'   => '/admin/setting_social.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path>',
            ],
            [
                'key'   => 'setting_email',
                'label' => __('admin_setting_email'),
                'url'   => '/admin/setting_email.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>',
            ],
            [
                'key'   => 'setting_seo',
                'label' => __('admin_setting_seo'),
                'url'   => '/admin/setting_seo.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>',
            ],
            [
                'key'   => 'setting_lang',
                'label' => __('admin_setting_lang'),
                'url'   => '/admin/setting_lang.php',
                // 圆形地球+经纬线 icon — 多语言入口的标准视觉
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
            ],
        ],
    ],
    'appearance' => [
        'label'    => __('admin_group_appearance'),
        'priority' => 70,
        'items'    => [
            [
                'key'   => 'theme',
                'label' => __('admin_theme'),
                'url'   => '/admin/theme.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>',
            ],
            [
                'key'   => 'plugin',
                'label' => __('admin_plugin'),
                'url'   => '/admin/plugin.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"></path>',
            ],
            [
                'key'   => 'recipe',
                'label' => __('admin_recipe'),
                'url'   => '/admin/recipe.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>',
            ],
            [
                'key'   => 'extfield',
                'label' => __('admin_extfield'),
                'url'   => '/admin/extfield.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h10"/>',
            ],
            [
                'key'   => 'setting_ai',
                'label' => __('admin_setting_ai'),
                'url'   => '/admin/setting_ai.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>',
            ],
            [
                'key'   => 'ai_assistant',
                'label' => __('admin_ai_assistant'),
                'url'   => '/admin/ai_assistant.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>',
            ],
        ],
    ],
    'system' => [
        'label'      => __('admin_group_system'),
        'priority'   => 80,
        'super_only' => true,
        'items'      => [
            [
                'key'         => 'user',
                'label'       => __('admin_user'),
                'url'         => '/admin/user.php',
                'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>',
                'active_keys' => ['user', 'role'],
            ],
            [
                'key'   => 'setting_security',
                'label' => __('admin_setting_security'),
                'url'   => '/admin/setting_security.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>',
            ],
            [
                'key'         => 'system',
                'label'       => __('admin_system_info'),
                'url'         => '/admin/system.php',
                'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>',
                'active_keys' => ['system', 'system_log'],
            ],
            [
                'key'   => 'database',
                'label' => __('admin_database'),
                'url'   => '/admin/database.php',
                'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>',
            ],
            [
                'key'         => 'upgrade',
                'label'       => __('admin_upgrade'),
                'url'         => '/admin/upgrade.php',
                'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>',
                'active_keys' => ['upgrade', 'online_upgrade'],
            ],
        ],
    ],
];
