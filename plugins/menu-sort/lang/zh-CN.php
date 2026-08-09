<?php
/**
 * 后台菜单排序插件 · 简体中文
 *
 * 插件自带语言包：由 loadPluginLang() 在插件加载前并入语言表。
 * key 一律加 mnsort_ 前缀，避免与核心或其它插件撞车；撞车时核心优先。
 */

declare(strict_types=1);

return [
    'mnsort_title'           => '后台菜单排序',
    'mnsort_tip'             => '拖拽排序、点击名称可改名、👁 切换显示隐藏——:b改动自动保存:_b，刷新后台页面后生效。改名仅作用于当前后台语言（:lang），清空恢复默认。',
    'mnsort_reset'           => '恢复默认',
    'mnsort_save_now'        => '立即保存',
    'mnsort_toggle_group'    => '显示/隐藏整个分组',
    'mnsort_toggle_item'     => '显示/隐藏',
    'mnsort_rename_tip'      => '改名后立即保存；清空则恢复默认',
    'mnsort_saving'          => '保存中…',
    'mnsort_autosaved'       => '✓ 已自动保存 ',
    'mnsort_saved_hint'      => '已保存，刷新后台页面后侧栏生效',
    'mnsort_save_failed'     => '保存失败',
    'mnsort_reset_confirm'   => '确定恢复默认菜单排序？',
    'mnsort_reset_done'      => '已恢复默认',
    'mnsort_bad_data'        => '无效的排序数据',
    'mnsort_log_update'      => '更新菜单排序配置',
    'mnsort_log_reset'       => '重置菜单排序',
    'mnsort_reset_msg'       => '已恢复默认排序',
    'mnsort_grp_system'      => '系统',
    'mnsort_grp_setting'     => '站点设置',
    'mnsort_grp_plugin'      => '插件管理',
    'mnsort_from_plugin'     => '插件·',
];
