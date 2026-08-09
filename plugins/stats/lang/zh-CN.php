<?php
/**
 * 统计接入插件 · 简体中文
 *
 * 插件自带语言包：由 loadPluginLang() 在插件加载前并入语言表。
 * key 一律加 stt_ 前缀，避免与核心或其它插件撞车。
 */

declare(strict_types=1);

return [
    'stt_title'            => '统计接入',
    'stt_desc'             => '选择统计服务商并填写站点 ID，系统会自动把统计代码注入前台页面 :head，无需手写脚本。',
    'stt_provider'         => '统计服务商',
    'stt_disabled'         => '不启用',
    'stt_site_id'          => '站点 ID',
    'stt_custom_code'      => '自定义统计代码',
    'stt_custom_warn'      => '⚠ 原样注入页面，请仅粘贴可信来源的代码。',
    'stt_save'             => '保存',
    'stt_dashboard'        => '数据看板',
    'stt_pro_unlocked'     => '✓ 专业版已解锁。可前往所选服务商查看实时数据，后台 API 自动回拉看板（PV/UV 趋势）即将上线。',
    'stt_open_console'     => '打开 :provider 数据后台 →',
    'stt_pick_first'       => '先在上方选择一个预设服务商。',
    'stt_pro_pitch'        => '升级:b专业版:_b解锁后台数据看板：无需登录第三方平台，直接在后台查看 PV / UV 趋势。',
    'stt_log_update'       => '更新统计接入配置',
    'stt_p_baidu'          => '百度统计',
    'stt_p_51la'           => '51.la 网站统计',
    'stt_p_cnzz'           => 'CNZZ / 友盟+',
    'stt_p_custom'         => '自定义代码',
    'stt_h_baidu'          => '管理 → 代码获取，hm.js?id= 后面那串即站点 ID',
    'stt_h_51la'           => '创建站点后得到的数字 ID',
    'stt_h_cnzz'           => '站点统计的 ID',
    'stt_h_ga4'            => '衡量 ID，形如 G-XXXXXXXXXX',
    'stt_h_custom'         => '直接粘贴第三方统计代码（原样注入 <head>）',
    'stt_pro_badge'        => '专业版',
    'stt_go_license'       => '前往授权管理 →',
];
