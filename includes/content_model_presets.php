<?php
/**
 * 自定义内容模型 —— 预置方案。
 *
 * 新手在「新建模型」时可一键套用：填充模型基本信息 + 自动创建常用字段。
 * 结构：key => [name/name_en/name_ja, url_prefix, has_detail, icon, fields[]]
 *   fields[] 每项对应 extfields 定义（field_key/field_name/field_type，可选 options/is_required/help_text）。
 * field_type 取值见 ExtFieldModel::TYPES。
 * 文案三语并列（name / name_en / name_ja，字段同理），套用时按后台语言取——
 * 英文站新建模型不应得到一堆中文字段名。取值走 presetText()。
 *
 * 用于 admin/content_model.php。
 */

declare(strict_types=1);

return [
    'team' => [
        'name'       => '团队成员',
        'name_en'    => 'Team',
        'name_ja'    => 'チーム',
        'url_prefix' => 'team',
        'has_detail' => 1,
        'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 100-8 4 4 0 000 8z"></path>',
        'fields'     => [
            ['field_key' => 'position', 'field_name' => '职位', 'field_name_en' => 'Position', 'field_name_ja' => '役職', 'field_type' => 'text', 'is_required' => 1],
            ['field_key' => 'email', 'field_name' => '邮箱', 'field_name_en' => 'Email', 'field_name_ja' => 'メールアドレス', 'field_type' => 'text'],
            ['field_key' => 'phone', 'field_name' => '电话', 'field_name_en' => 'Phone', 'field_name_ja' => '電話番号', 'field_type' => 'text'],
            ['field_key' => 'wechat', 'field_name' => '微信', 'field_name_en' => 'WeChat', 'field_name_ja' => 'WeChat', 'field_type' => 'text'],
        ],
    ],
    'solution' => [
        'name'       => '解决方案',
        'name_en'    => 'Solution',
        'name_ja'    => 'ソリューション',
        'url_prefix' => 'solution',
        'has_detail' => 1,
        'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>',
        'fields'     => [
            ['field_key' => 'industry', 'field_name' => '适用行业', 'field_name_en' => 'Industry', 'field_name_ja' => '対象業界', 'field_type' => 'text'],
            ['field_key' => 'highlights', 'field_name' => '方案亮点', 'field_name_en' => 'Highlights', 'field_name_ja' => 'ソリューションの特長', 'field_type' => 'textarea'],
            ['field_key' => 'related_products', 'field_name' => '相关产品', 'field_name_en' => 'Related products', 'field_name_ja' => '関連製品', 'field_type' => 'text', 'help_text' => '产品名称，逗号分隔', 'help_text_en' => 'Product names, comma separated', 'help_text_ja' => '製品名（カンマ区切り）'],
        ],
    ],
    'faq' => [
        'name'       => '常见问题',
        'name_en'    => 'FAQ',
        'name_ja'    => 'よくある質問',
        'url_prefix' => 'faq',
        'has_detail' => 0,
        'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
        'fields'     => [
            ['field_key' => 'category', 'field_name' => '问题分类', 'field_name_en' => 'Question category', 'field_name_ja' => '質問カテゴリ', 'field_type' => 'text', 'help_text' => '如：售前 / 售后 / 技术', 'help_text_en' => 'e.g. pre-sales / after-sales / technical', 'help_text_ja' => '例：販売前 / 販売後 / 技術'],
        ],
    ],
    'honor' => [
        'name'       => '荣誉资质',
        'name_en'    => 'Honor',
        'name_ja'    => '認証・受賞',
        'url_prefix' => 'honor',
        'has_detail' => 0,
        'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>',
        'fields'     => [
            ['field_key' => 'issuer', 'field_name' => '颁发机构', 'field_name_en' => 'Issued by', 'field_name_ja' => '発行機関', 'field_type' => 'text'],
            ['field_key' => 'issue_date', 'field_name' => '获得日期', 'field_name_en' => 'Date awarded', 'field_name_ja' => '取得日', 'field_type' => 'date'],
            ['field_key' => 'certificate', 'field_name' => '证书图', 'field_name_en' => 'Certificate image', 'field_name_ja' => '証書画像', 'field_type' => 'image'],
        ],
    ],
    'service' => [
        'name'       => '服务项目',
        'name_en'    => 'Service',
        'name_ja'    => 'サービス',
        'url_prefix' => 'service',
        'has_detail' => 1,
        'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>',
        'fields'     => [
            ['field_key' => 'icon_img', 'field_name' => '服务图标', 'field_name_en' => 'Service icon', 'field_name_ja' => 'サービスアイコン', 'field_type' => 'image'],
            ['field_key' => 'brief', 'field_name' => '一句话简述', 'field_name_en' => 'One-line summary', 'field_name_ja' => '一言説明', 'field_type' => 'text'],
            ['field_key' => 'price', 'field_name' => '价格 / 套餐', 'field_name_en' => 'Price / package', 'field_name_ja' => '価格・プラン', 'field_type' => 'text'],
        ],
    ],
    'partner' => [
        'name'       => '合作伙伴',
        'name_en'    => 'Partner',
        'name_ja'    => 'パートナー',
        'url_prefix' => 'partner',
        'has_detail' => 0,
        'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>',
        'fields'     => [
            ['field_key' => 'website', 'field_name' => '官网链接', 'field_name_en' => 'Website', 'field_name_ja' => '公式サイト', 'field_type' => 'text'],
            ['field_key' => 'partner_type', 'field_name' => '合作类型', 'field_name_en' => 'Partnership type', 'field_name_ja' => '提携タイプ', 'field_type' => 'select', 'options' => "代理商\n供应商\n技术伙伴\n战略合作", 'options_en' => "Reseller\nSupplier\nTechnology partner\nStrategic partner", 'options_ja' => "代理店\nサプライヤー\n技術パートナー\n戦略提携"],
        ],
    ],
];
