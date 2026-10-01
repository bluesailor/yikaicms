<?php
/**
 * 模板行业分类：主题清单（theme.json 的 category）、整站模板市场目录、官网模板页共用这一份。
 *
 * 新增行业：在 groups 里加一行（键只用小写字母，名称至少给 zh-CN / en / ja，其他语言缺译时按
 * LanguageRegistry 的回落顺序显示）。改名：把旧键写进 aliases，指向新键，旧主题只会收到提示。
 * 读取请用 TemplateCategories，不要在别处另写一份列表。
 */

declare(strict_types=1);

return [
    'groups' => [
        'general' => ['zh-CN' => '通用模板', 'en' => 'General', 'ja' => '汎用'],
        'manufacturing' => ['zh-CN' => '制造与工业', 'en' => 'Manufacturing & industry', 'ja' => '製造・工業'],
        'food' => ['zh-CN' => '餐饮与食品', 'en' => 'Food & dining', 'ja' => '飲食・食品'],
        'home' => ['zh-CN' => '家居与生活', 'en' => 'Home & lifestyle', 'ja' => '住まい・暮らし'],
        'service' => ['zh-CN' => '专业服务', 'en' => 'Professional services', 'ja' => '専門サービス'],
        'auto' => ['zh-CN' => '汽车服务', 'en' => 'Automotive', 'ja' => '自動車サービス'],
        'energy' => ['zh-CN' => '能源与物流', 'en' => 'Energy & logistics', 'ja' => 'エネルギー・物流'],
        'creative' => ['zh-CN' => '创意与教育', 'en' => 'Creative & education', 'ja' => 'クリエイティブ・教育'],
    ],
    // 2026-09-28 之前主题校验器用的旧词表 → 现在的分组
    'aliases' => [
        'services' => 'service',
        'tech' => 'creative',
        'trade' => 'manufacturing',
        'retail' => 'home',
    ],
];
