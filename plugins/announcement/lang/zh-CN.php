<?php
/**
 * 网站公告插件 · 简体中文
 *
 * 插件自带语言包：由 loadPluginLang() 在插件加载前并入语言表。
 * key 一律加 ann_ 前缀，避免与核心或其它插件撞车。
 */

declare(strict_types=1);

return [
    'ann_admin_title'      => '网站公告弹窗',
    'ann_admin_desc'       => '开启后，访客进入网站时会弹出公告（如严正声明、放假通知）。同一访客在设定的冷却天数内只弹一次；:b你修改标题或内容后，会自动对所有访客重新弹出一次:_b。',
    'ann_enable'           => '启用公告弹窗',
    'ann_f_title'          => '弹窗标题',
    'ann_f_content'        => '公告内容',
    'ann_content_tip'      => '可视化编辑，支持加粗、居中、插入图片等；标题与按钮由下方单独设置。',
    'ann_f_button'         => '按钮文字',
    'ann_button_tip'       => '如「我知道了」「同意并继续」「关闭」等',
    'ann_f_freq'           => '弹出频率',
    'ann_freq_unit'        => '天一次（0＝每次都弹）',
    'ann_home_only'        => '仅首页显示（不勾选＝全站每页）',
    'ann_save'             => '保存',
    'ann_test_tip'         => '测试时如果自己看不到弹窗，是「冷却」cookie（:code）生效了——用无痕窗口或清 cookie，或临时把弹出频率设为 0 即可。',
    'ann_tip_label'        => '提示：',
    'ann_editor_ph'        => '公告正文……',
    'ann_log_update'       => '更新网站公告配置',
    'ann_default_title'    => '网站公告',
    'ann_default_btn'      => '我知道了',
    'ann_close'            => '关闭',
];
