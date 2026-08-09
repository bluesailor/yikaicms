<?php
/**
 * Cookie 同意插件 · 简体中文（后台配置页）
 *
 * 只覆盖后台配置页：前台横幅的文案在 main.php 的 \$i18n 数组里已三语齐全，
 * 它自成一体且按 SITE_LANG 取值，搬过来是零收益的回归风险。
 */

declare(strict_types=1);

return [
    'cc_title'               => 'Cookie 同意横幅',
    'cc_desc'                => 'GDPR / PIPL 合规：三档授权（必要/分析/营销）+ 随时撤回入口。其他脚本用 :api1 或 :api2 事件按类别门控加载（PHP 端用 :api3）。',
    'cc_policy_url'          => '隐私政策链接',
    'cc_policy_tip'          => '填写后横幅正文中显示「隐私政策」链接（GDPR 建议必填）。',
    'cc_policy_ver'          => '政策版本号',
    'cc_policy_ver_tip'      => 'Cookie 使用方式或隐私政策有实质变更时 :b+1:_b：所有访客的旧同意即失效，横幅重新弹出征求同意。',
    'cc_consent_mode'        => '启用 Google Consent Mode v2',
    'cc_consent_mode_tip'    => '向 gtag 输出 consent default/update 信号（analytics_storage / ad_storage / ad_user_data / ad_personalization）。',
    'cc_footer_link'         => '显示「Cookie 设置」常驻入口（左下角）',
    'cc_footer_link_tip'     => '让访客随时撤回或变更同意——GDPR 第 7(3) 条要求撤回同意与给出同意同样容易，建议保持开启。',
    'cc_save'                => '保存',
    'cc_example_title'       => '接入示例：按同意加载 GA',
    'cc_example_note'        => '若启用了 Consent Mode v2，也可以直接常载 gtag.js——Google 会按 consent 信号自行降级（无 Cookie 的 ping）。',
    'cc_log_update'          => '更新 Cookie 同意配置',
];
