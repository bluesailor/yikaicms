<?php
/**
 * Announcement plugin - English
 *
 * 插件自带语言包：由 loadPluginLang() 在插件加载前并入语言表。
 * key 一律加 ann_ 前缀，避免与核心或其它插件撞车。
 */

declare(strict_types=1);

return [
    'ann_admin_title'      => 'Announcement popup',
    'ann_admin_desc'       => 'When enabled, visitors see a popup on arrival — useful for notices such as holiday closures or official statements. Each visitor sees it once within the cool-down period you set. :bIf you change the title or the body, it shows again to everyone.:_b',
    'ann_enable'           => 'Enable the popup',
    'ann_f_title'          => 'Popup title',
    'ann_f_content'        => 'Announcement body',
    'ann_content_tip'      => 'Visual editing with bold, centring, images and so on. The title and button are set separately below.',
    'ann_f_button'         => 'Button label',
    'ann_button_tip'       => 'For example: Got it, Agree and continue, or Close.',
    'ann_f_freq'           => 'How often to show',
    'ann_freq_unit'        => 'days between showings (0 shows it every time)',
    'ann_home_only'        => 'Home page only (unchecked shows it on every page)',
    'ann_save'             => 'Save',
    'ann_test_tip'         => 'If you cannot see the popup while testing, the cool-down cookie (:code) is doing its job. Use a private window, clear the cookie, or set the frequency to 0 for a moment.',
    'ann_tip_label'        => 'Tip:',
    'ann_editor_ph'        => 'Announcement text…',
    'ann_log_update'       => 'Updated announcement settings',
    'ann_default_title'    => 'Announcement',
    'ann_default_btn'      => 'Got it',
    'ann_close'            => 'Close',
];
