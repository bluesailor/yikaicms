<?php

declare(strict_types=1);

/**
 * 插入时的占位内容。
 *
 * 注册表的 defaults 把主内容字段留空（heading.text / text.html / quote.text 都是 ""），
 * 高级构建器照搬即可——它把元素显示成可编辑的卡片，空着也看得见。但 blox 的画布是
 * **渲染后的预览**：插入一个空标题，画布上什么都不会出现，像是没插进去。
 *
 * 所以这里给主内容字段种占位文本，和 Bricks / Elementor 的行为一致——插入即可见，
 * 再去改文字。只覆盖列出的字段，其余仍用注册表的 defaults。
 *
 * ⚠ 这是 blox 与高级构建器**有意**的行为差异（那边插入仍为空）。两者写进同一份
 *   blocks_data，占位文本只是普通内容、不影响渲染一致性。若日后统一，改这里即可。
 */
// 插入内容（元素占位、默认值、内置模板）的语言：首页按正在编辑的语言，页头/页尾按其语言，
// 带语言的页面按页面语言，其余按站点默认语言。界面文字仍用后台语言。
$bloxContentLanguage = $isHomeBlox
    ? siteLang()
    : ($areaEditorLanguage !== ''
        ? $areaEditorLanguage
        : ((!$templateId && trim((string) ($page['lang'] ?? '')) !== '') ? trim((string) $page['lang']) : (string) config('site_lang', 'zh-CN')));
$bloxPlaceholders = withLanguageStrings($bloxContentLanguage, static fn (): array => [
    'heading' => ['text' => __('blox_seed_heading')],
    'text'    => ['html' => '<p>' . __('blox_seed_text') . '</p>'],
    'quote'   => ['text' => __('blox_seed_quote'), 'author' => ''],
    'alert'   => ['text' => __('blox_seed_alert')],
    'icon-box' => ['title' => __('blox_field_title_short'), 'text' => __('blox_seed_desc')],
    'cta' => [
        'title' => __('blox_seed_cta_title'),
        'text' => __('blox_seed_cta_text'),
        'btn_text' => __('nav_contact'),
        'btn_url' => '/contact.html',
    ],
    'card' => [
        'title' => __('blox_seed_card_title'),
        'text' => __('blox_seed_card_text'),
        'image' => '',
        'link' => '',
    ],
    'home-block' => ['block_type' => 'banner', 'label' => __('blox_home_block_label'), 'enabled' => true, 'items_mode' => 'inherit', 'children' => []],
    'home-banner-item' => ['title' => __('blox_home_banner_item')],
    'site-copyright' => ['show_icp' => false, 'show_police' => false],
]);
