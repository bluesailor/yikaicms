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

return [];
