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
            // Tabs 自动轮播的暂停 / 继续按钮：继续图标由 assets/js/yikay-tabs.js 换类（2.0.4）。
            'player-play',
            // 编辑器里最常选的通用图标（2026-10-10）：按钮箭头、列表/联系/文件/商务/社交品牌。
            // 页面内容（数据库里的 Blox 文档）用到的图标扫描不到；不在子集里就会整套加载 462 KB 的完整字体，
            // 首页一个「了解更多」按钮箭头就触发过。
            'adjustments', 'alert-circle', 'alert-triangle', 'arrow-back', 'arrow-back-up', 'arrow-big-right', 'arrow-down', 'arrow-down-circle',
            'arrow-down-left', 'arrow-down-right', 'arrow-forward', 'arrow-forward-up', 'arrow-left', 'arrow-left-circle', 'arrow-narrow-down', 'arrow-narrow-left',
            'arrow-narrow-right', 'arrow-narrow-up', 'arrow-right', 'arrow-right-bar', 'arrow-right-circle', 'arrow-up', 'arrow-up-circle', 'arrow-up-left',
            'arrow-up-right', 'award', 'basket', 'bell', 'book', 'bookmark', 'brand-alipay', 'brand-amazon',
            'brand-android', 'brand-apple', 'brand-bilibili', 'brand-discord', 'brand-facebook', 'brand-github', 'brand-google', 'brand-instagram',
            'brand-kakao-talk', 'brand-line', 'brand-linkedin', 'brand-mastercard', 'brand-medium', 'brand-messenger', 'brand-paypal', 'brand-pinterest',
            'brand-qq', 'brand-reddit', 'brand-skype', 'brand-snapchat', 'brand-spotify', 'brand-taobao', 'brand-teams', 'brand-telegram',
            'brand-threads', 'brand-tiktok', 'brand-twitter', 'brand-vimeo', 'brand-visa', 'brand-wechat', 'brand-weibo', 'brand-whatsapp',
            'brand-windows', 'brand-x', 'brand-youtube', 'brand-zhihu', 'briefcase', 'building', 'building-factory', 'building-store',
            'calendar', 'calendar-event', 'camera', 'caret-down', 'caret-left', 'caret-left-right', 'caret-right', 'caret-up',
            'caret-up-down', 'cash', 'certificate', 'chart-bar', 'chart-line', 'chart-pie', 'check', 'checks',
            'chevron-compact-down', 'chevron-compact-left', 'chevron-compact-right', 'chevron-compact-up', 'chevron-down', 'chevron-down-left', 'chevron-down-right', 'chevron-left',
            'chevron-left-pipe', 'chevron-right', 'chevron-right-pipe', 'chevron-up', 'chevron-up-left', 'chevron-up-right', 'chevrons-down', 'chevrons-left',
            'chevrons-right', 'chevrons-up', 'circle-plus', 'circle-x', 'clipboard-check', 'clock', 'cloud', 'code',
            'coin', 'copy', 'cpu', 'credit-card', 'database', 'device-mobile', 'discount', 'dots',
            'dots-vertical', 'download', 'external-link', 'file', 'file-description', 'file-download', 'file-text', 'file-type-doc',
            'file-type-pdf', 'file-type-xls', 'file-zip', 'filter', 'flag', 'folder', 'gift', 'heart',
            'help-circle', 'home', 'id-badge', 'key', 'language', 'layout-grid', 'leaf', 'link',
            'list-check', 'list-details', 'mail', 'mail-opened', 'map', 'map-pin', 'medal', 'menu-2',
            'message', 'message-circle', 'messages', 'minus', 'moon', 'news', 'package', 'phone',
            'phone-call', 'photo', 'plant', 'player-pause', 'plug', 'plus', 'printer', 'question-mark',
            'quote', 'receipt', 'recycle', 'refresh', 'report-analytics', 'rocket', 'route', 'school',
            'search', 'send', 'server', 'share', 'share-2', 'shield', 'shopping-bag', 'shopping-cart',
            'speakerphone', 'sun', 'tag', 'tags', 'target', 'tool', 'tools', 'trophy',
            'truck-delivery', 'upload', 'user', 'user-check', 'video', 'volume', 'x', 'zoom-in',
        ],
        'bootstrap' => [],
    ],
];
