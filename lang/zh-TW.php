<?php
/**
 * 繁體中文（zh-TW）—— 标记文件（不是翻译表）。
 *
 * 繁体在本 CMS 中是简体（zh-CN）的「渲染视图」：UI 文案与内容都复用简体，
 * 出页面前由 includes/i18n/S2T.php 用 OpenCC 词库整页简→繁(台湾用词)。
 *
 * 本文件存在的唯一目的：让 availableLanguages() 扫到 zh-TW，
 * 从而可在后台「启用语言」里勾选、被 URL/切换器选用。
 * 返回空数组即可 —— loadLangData() 会回落到 zh-CN 文案，再由 S2T 转换。
 *
 * 若某些词你想固定用词（覆盖自动转换），可在此返回 ['key' => '繁體文案'] 覆盖。
 */

declare(strict_types=1);

return [
    // ── 文章摘要字段的说明文案 ──
    'sum_optional' => '選填',
    'sum_hint' => '列表頁顯示的一兩句話概述。正文請寫到上方的「內容」編輯器裡——摘要不是正文。留空時系統自動從正文擷取。',
    'sum_placeholder' => '一兩句話概括本文，留空則自動擷取',
    'sum_chars' => '字',
    'sum_too_long' => '摘要偏長，內容是不是該寫到上方的「內容」裡？',

    // ── Blox 全屏编辑器（实验）──
    'page_mode_blox' => 'Blox 全螢幕',
    'page_mode_blox_tip' => '全螢幕視覺化編輯（實驗功能）：左側結構樹、中間畫布、右側屬性，編輯的是同一份排版資料',
    'label_experimental' => '實驗',
];
