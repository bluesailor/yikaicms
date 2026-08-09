<?php
/**
 * Cookie 同意プラグイン · 日本語（管理画面）
 *
 * 只覆盖后台配置页：前台横幅的文案在 main.php 的 \$i18n 数组里已三语齐全，
 * 它自成一体且按 SITE_LANG 取值，搬过来是零收益的回归风险。
 */

declare(strict_types=1);

return [
    'cc_title'               => 'Cookie 同意バナー',
    'cc_desc'                => 'GDPR / PIPL 対応：3 区分の同意（必須／解析／マーケティング）と、いつでも撤回できる入口を提供します。他のスクリプトは :api1 または :api2 イベント（PHP 側は :api3）で区分ごとに読み込みを制御できます。',
    'cc_policy_url'          => 'プライバシーポリシーのリンク',
    'cc_policy_tip'          => '入力すると、バナー本文にプライバシーポリシーへのリンクが表示されます（GDPR 上ほぼ必須）。',
    'cc_policy_ver'          => 'ポリシーのバージョン',
    'cc_policy_ver_tip'      => 'Cookie の利用方法やプライバシーポリシーに実質的な変更があったら :b+1:_b してください。既存の同意はすべて無効となり、バナーが再表示されます。',
    'cc_consent_mode'        => 'Google Consent Mode v2 を有効にする',
    'cc_consent_mode_tip'    => 'gtag に consent の default/update シグナルを送信します（analytics_storage / ad_storage / ad_user_data / ad_personalization）。',
    'cc_footer_link'         => '「Cookie 設定」の常設入口を表示（左下）',
    'cc_footer_link_tip'     => '訪問者がいつでも同意を撤回・変更できるようにします。GDPR 第 7 条 3 項は撤回を同意と同じくらい容易にすることを求めているため、有効のままを推奨します。',
    'cc_save'                => '保存',
    'cc_example_title'       => '導入例：同意後に GA を読み込む',
    'cc_example_note'        => 'Consent Mode v2 を有効にしている場合は gtag.js を常時読み込んでも構いません。Google が consent シグナルに応じて Cookie なしの ping に切り替えます。',
    'cc_log_update'          => 'Cookie 同意設定を更新',
];
