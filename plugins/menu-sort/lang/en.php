<?php
/**
 * Admin Menu Sort plugin - English
 *
 * 插件自带语言包：由 loadPluginLang() 在插件加载前并入语言表。
 * key 一律加 mnsort_ 前缀，避免与核心或其它插件撞车；撞车时核心优先。
 */

declare(strict_types=1);

return [
    'mnsort_title'           => 'Admin Menu Sort',
    'mnsort_tip'             => 'Drag to reorder, click a name to rename, and use 👁 to show or hide. :bChanges save automatically:_b and take effect after you reload an admin page. Renaming applies only to the current admin language (:lang); clear the field to restore the default.',
    'mnsort_reset'           => 'Reset to default',
    'mnsort_save_now'        => 'Save now',
    'mnsort_toggle_group'    => 'Show or hide this whole group',
    'mnsort_toggle_item'     => 'Show or hide',
    'mnsort_rename_tip'      => 'Renaming saves immediately. Clear the field to restore the default.',
    'mnsort_saving'          => 'Saving…',
    'mnsort_autosaved'       => '✓ Saved ',
    'mnsort_saved_hint'      => 'Saved. Reload an admin page to see the new sidebar.',
    'mnsort_save_failed'     => 'Save failed',
    'mnsort_reset_confirm'   => 'Reset the menu order to default?',
    'mnsort_reset_done'      => 'Reset to default',
    'mnsort_bad_data'        => 'Invalid sort data',
    'mnsort_log_update'      => 'Updated menu order',
    'mnsort_log_reset'       => 'Reset menu order',
    'mnsort_reset_msg'       => 'Menu order reset to default',
    'mnsort_grp_system'      => 'System',
    'mnsort_grp_setting'     => 'Site settings',
    'mnsort_grp_plugin'      => 'Plugins',
    'mnsort_from_plugin'     => 'Plugin: ',
];
