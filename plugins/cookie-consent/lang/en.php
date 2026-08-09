<?php
/**
 * Cookie Consent plugin - English (admin page)
 *
 * 只覆盖后台配置页：前台横幅的文案在 main.php 的 \$i18n 数组里已三语齐全，
 * 它自成一体且按 SITE_LANG 取值，搬过来是零收益的回归风险。
 */

declare(strict_types=1);

return [
    'cc_title'               => 'Cookie consent banner',
    'cc_desc'                => 'GDPR / PIPL ready: three consent categories (necessary, analytics, marketing) plus a permanent withdraw entry. Other scripts can gate themselves per category via :api1 or the :api2 event (:api3 on the PHP side).',
    'cc_policy_url'          => 'Privacy policy link',
    'cc_policy_tip'          => 'When set, the banner shows a link to your privacy policy. GDPR effectively requires this.',
    'cc_policy_ver'          => 'Policy version',
    'cc_policy_ver_tip'      => 'Increment this :b+1:_b whenever your cookie use or privacy policy materially changes. All existing consents are invalidated and the banner asks again.',
    'cc_consent_mode'        => 'Enable Google Consent Mode v2',
    'cc_consent_mode_tip'    => 'Emits consent default/update signals to gtag (analytics_storage / ad_storage / ad_user_data / ad_personalization).',
    'cc_footer_link'         => 'Show a permanent "Cookie settings" entry (bottom left)',
    'cc_footer_link_tip'     => 'Lets visitors withdraw or change consent at any time. GDPR Art. 7(3) requires withdrawing consent to be as easy as giving it, so keep this on.',
    'cc_save'                => 'Save',
    'cc_example_title'       => 'Example: load GA only after consent',
    'cc_example_note'        => 'With Consent Mode v2 enabled you can also load gtag.js unconditionally — Google degrades to cookieless pings based on the consent signals.',
    'cc_log_update'          => 'Updated cookie consent settings',
];
