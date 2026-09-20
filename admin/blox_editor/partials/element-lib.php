<?php
/**
 * 元素库构建（从 admin/blox_editor.php 原样拆出，500KB 预算门禁 · v1.29）。
 *
 * 期望作用域变量：$registryMeta、$bloxPlaceholders、$professionalElements、$professionalFeatures。
 * 产出：$elementLib（含 __section 合成项与 __query_cards 预设瓦片）。
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 元素库（对齐 Bricks：布局组里 区块/容器 并排为瓦片）。__section 是合成项，
// 点击走 addSection(1)——它不是注册表元素，只是「插区块」在库里的入口。
$elementLib = [[
    'type'     => '__section',
    'label'    => __('blox_section_label'),
    'category' => 'layout',
    'icon'     => 'crop-landscape',
    'defaults' => [],
    'paletteVisible' => true,
    'deprecated' => false,
]];
foreach ($registryMeta as $type => $m) {
    $defaults = $m['defaults'];
    foreach ($bloxPlaceholders[$type] ?? [] as $k => $v) {
        $defaults[$k] = $v;
    }
    $proFeature = $professionalElements[$type] ?? '';
    $locked = $proFeature !== '' && empty($professionalFeatures[$proFeature]['allowed'])
        && !empty($professionalFeatures[$proFeature]['visible']);
    $elementLib[] = [
        'type'     => $type,
        'label'    => $m['label'],
        'category' => $m['category'],
        'icon'     => $m['icon'],
        'defaults' => $defaults,
        'paletteVisible' => $m['paletteVisible'] || $locked,
        'deprecated' => $m['deprecated'],
        'proFeature' => $proFeature,
        'locked' => $locked,
    ];
}

// 查询卡片网格（v1.25）：容器 Loop 的预设瓦片，list-dynamic 退役路径上的替身入口。
// 真实插入类型是 container（defaults 带 _query + 预绑定 {{loop.*}} 卡片子树，
// newElementNode 深拷贝并逐级发新 id）；用合成 type 保持瓦片自己的收藏/测试标识，
// 不与素的 container 瓦片相撞。锁定语义与 professionalElements 同款（query_loop）。
$queryCardsLocked = empty($professionalFeatures['query_loop']['allowed'])
    && !empty($professionalFeatures['query_loop']['visible']);
$elementLib[] = [
    'type' => '__query_cards',
    'insertType' => 'container',
    'label' => __('blox_ps_query_cards'),
    'category' => 'dynamic',
    'icon' => 'repeat',
    'defaults' => [
        'layout' => 'grid',
        'grid_cols' => '3',
        '_query' => ['source' => 'type:article', 'limit' => 6, 'empty_mode' => 'hidden'],
        'children' => [[
            'type' => 'div',
            'data' => ['children' => [
                ['type' => 'image', 'data' => ['src' => '{{loop.cover}}', 'alt' => '{{loop.title}}', 'click_action' => 'link', 'link_url' => '{{loop.url}}', 'link_new_tab' => false]],
                ['type' => 'heading', 'data' => ['text' => '{{loop.title}}', 'level' => 'h3', 'url' => '{{loop.url}}']],
                ['type' => 'text', 'data' => ['html' => '<p>{{loop.summary}}</p>']],
            ]],
        ]],
    ],
    'paletteVisible' => !empty($professionalFeatures['query_loop']['allowed']) || $queryCardsLocked,
    'deprecated' => false,
    'proFeature' => 'query_loop',
    'locked' => $queryCardsLocked,
];
