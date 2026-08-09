<?php
/**
 * 管理メニュー並び替えプラグイン · 日本語
 *
 * 插件自带语言包：由 loadPluginLang() 在插件加载前并入语言表。
 * key 一律加 mnsort_ 前缀，避免与核心或其它插件撞车；撞车时核心优先。
 */

declare(strict_types=1);

return [
    'mnsort_title'           => '管理メニュー並び替え',
    'mnsort_tip'             => 'ドラッグで並び替え、名前をクリックで改名、👁 で表示/非表示。:b変更は自動保存され:_b、管理画面を再読み込みすると反映されます。改名は現在の管理言語（:lang）にのみ適用され、空欄にすると既定に戻ります。',
    'mnsort_reset'           => '既定に戻す',
    'mnsort_save_now'        => 'すぐ保存',
    'mnsort_toggle_group'    => 'グループ全体の表示/非表示',
    'mnsort_toggle_item'     => '表示/非表示',
    'mnsort_rename_tip'      => '改名すると即座に保存されます。空欄にすると既定に戻ります。',
    'mnsort_saving'          => '保存中…',
    'mnsort_autosaved'       => '✓ 保存しました ',
    'mnsort_saved_hint'      => '保存しました。管理画面を再読み込みするとサイドバーに反映されます。',
    'mnsort_save_failed'     => '保存に失敗しました',
    'mnsort_reset_confirm'   => 'メニューの並び順を既定に戻しますか？',
    'mnsort_reset_done'      => '既定に戻しました',
    'mnsort_bad_data'        => '並び順データが不正です',
    'mnsort_log_update'      => 'メニュー並び順を更新',
    'mnsort_log_reset'       => 'メニュー並び順をリセット',
    'mnsort_reset_msg'       => '並び順を既定に戻しました',
    'mnsort_grp_system'      => 'システム',
    'mnsort_grp_setting'     => 'サイト設定',
    'mnsort_grp_plugin'      => 'プラグイン',
    'mnsort_from_plugin'     => 'プラグイン：',
];
