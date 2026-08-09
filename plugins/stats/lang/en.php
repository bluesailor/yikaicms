<?php
/**
 * Analytics plugin - English
 *
 * 插件自带语言包：由 loadPluginLang() 在插件加载前并入语言表。
 * key 一律加 stt_ 前缀，避免与核心或其它插件撞车。
 */

declare(strict_types=1);

return [
    'stt_title'            => 'Analytics',
    'stt_desc'             => 'Pick a provider and enter your site ID. The tracking code is injected into the front-end :head automatically — no hand-written script needed.',
    'stt_provider'         => 'Provider',
    'stt_disabled'         => 'Disabled',
    'stt_site_id'          => 'Site ID',
    'stt_custom_code'      => 'Custom tracking code',
    'stt_custom_warn'      => '⚠ Injected into the page as-is. Only paste code from a source you trust.',
    'stt_save'             => 'Save',
    'stt_dashboard'        => 'Dashboard',
    'stt_pro_unlocked'     => '✓ Pro is active. Open your provider for live figures. An in-admin dashboard pulling PV/UV trends over the API is coming.',
    'stt_open_console'     => 'Open :provider →',
    'stt_pick_first'       => 'Choose one of the preset providers above first.',
    'stt_pro_pitch'        => 'Upgrade to :bPro:_b for the in-admin dashboard: PV and UV trends without signing in to a third-party platform.',
    'stt_log_update'       => 'Updated analytics settings',
    'stt_p_baidu'          => 'Baidu Tongji',
    'stt_p_51la'           => '51.la Analytics',
    'stt_p_cnzz'           => 'CNZZ / Umeng+',
    'stt_p_custom'         => 'Custom code',
    'stt_h_baidu'          => 'Admin → Get code; the string after hm.js?id= is your site ID',
    'stt_h_51la'           => 'The numeric ID you get after creating a site',
    'stt_h_cnzz'           => 'Your site statistics ID',
    'stt_h_ga4'            => 'Measurement ID, in the form G-XXXXXXXXXX',
    'stt_h_custom'         => 'Paste third-party tracking code; it is injected into <head> as-is',
    'stt_pro_badge'        => 'Pro',
    'stt_go_license'       => 'Go to licensing →',
];
