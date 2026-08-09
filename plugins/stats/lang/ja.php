<?php
/**
 * アクセス解析プラグイン · 日本語
 *
 * 插件自带语言包：由 loadPluginLang() 在插件加载前并入语言表。
 * key 一律加 stt_ 前缀，避免与核心或其它插件撞车。
 */

declare(strict_types=1);

return [
    'stt_title'            => 'アクセス解析',
    'stt_desc'             => 'プロバイダーを選び、サイト ID を入力してください。トラッキングコードはフロントの :head に自動で挿入されます。',
    'stt_provider'         => 'プロバイダー',
    'stt_disabled'         => '使用しない',
    'stt_site_id'          => 'サイト ID',
    'stt_custom_code'      => 'カスタム計測コード',
    'stt_custom_warn'      => '⚠ そのままページに挿入されます。信頼できる提供元のコードのみ貼り付けてください。',
    'stt_save'             => '保存',
    'stt_dashboard'        => 'ダッシュボード',
    'stt_pro_unlocked'     => '✓ Pro が有効です。プロバイダー側でリアルタイムの数値を確認できます。API 経由で PV/UV 推移を表示する管理画面ダッシュボードは近日公開予定です。',
    'stt_open_console'     => ':provider を開く →',
    'stt_pick_first'       => 'まず上のプリセットからプロバイダーを選択してください。',
    'stt_pro_pitch'        => ':bPro:_b にアップグレードすると管理画面ダッシュボードが使えます。第三者プラットフォームにログインせず、PV / UV の推移を確認できます。',
    'stt_log_update'       => 'アクセス解析設定を更新',
    'stt_p_baidu'          => '百度統計',
    'stt_p_51la'           => '51.la アクセス解析',
    'stt_p_cnzz'           => 'CNZZ / Umeng+',
    'stt_p_custom'         => 'カスタムコード',
    'stt_h_baidu'          => '管理 → コード取得。hm.js?id= の後ろの文字列がサイト ID です',
    'stt_h_51la'           => 'サイト作成後に発行される数値 ID',
    'stt_h_cnzz'           => 'サイト統計の ID',
    'stt_h_ga4'            => '測定 ID（G-XXXXXXXXXX の形式）',
    'stt_h_custom'         => '第三者の計測コードを貼り付けてください（<head> にそのまま挿入されます）',
    'stt_pro_badge'        => 'Pro',
    'stt_go_license'       => 'ライセンス管理へ →',
];
