<?php
/**
 * 前台图标子集的显式动态 safelist。
 *
 * 静态 `ti-*` / `bi:*` 引用由 tools/build-icon-subsets.php 扫描；通过设置、Blox 文档或
 * 插件数据动态生成、源码里没有完整 class 字面量的名称必须列在这里。生成器会逐项对照
 * 上游完整 CSS，拼错或升级后消失的图标会令构建失败，不能静默产出空图标。
 */

declare(strict_types=1);

return [
    'schema' => 1,
    'scan_roots' => [
        'themes',
        'marketplace/themes',
        'includes/blocks',
        'includes/builder',
        'templates/blox',
        'plugins',
    ],
    'extensions' => ['php', 'js', 'json', 'html'],
    // 首页传统区块用内联 SVG 的旧值，不会生成字体 class。
    'ignored_icon_values' => ['academic-cap', 'check-circle'],
    'safelist' => [
        'tabler' => [
            // BloxIcon 默认值、旧 Feather 别名与企业站语义预设。
            'star', 'bolt', 'world', 'info-circle', 'lifebuoy', 'microphone', 'device-desktop',
            'pencil', 'mood-smile', 'device-tv', 'thumb-up', 'circle-check', 'box', 'settings',
            'headset', 'shield-check', 'users', 'truck', 'bulb', 'lock', 'heart-handshake',
            'trending-up',
        ],
        'bootstrap' => [],
    ],
];
