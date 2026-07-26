<?php
/**
 * 常用网站栏目目录 —— 供后台「批量添加常用栏目」勾选生成。
 *
 * 每项声明一个企业站常见栏目的默认结构（名称/别名/类型/SEO/可选示例内容）。
 * type 取值须为合法栏目类型：list / page / product / case / download / job / link。
 * 名称提供 zh / en / ja，页面按站点语言挑选。
 *
 * presets 只是「勾选起点」（一键预选一组），生成逻辑仍是逐项按勾选来。
 */

declare(strict_types=1);

return [
    // 一键预选套餐（key => 该套餐默认勾选的栏目 key 列表）
    'presets' => [
        'enterprise'    => ['label' => '企业标准', 'label_en' => 'Enterprise', 'label_ja' => '企業標準',
                            'items' => ['about', 'products', 'news', 'cases', 'services', 'contact']],
        'foreign_trade' => ['label' => '外贸/产品', 'label_en' => 'Trade / Products', 'label_ja' => '貿易・製品',
                            'items' => ['products', 'cases', 'news', 'about', 'download', 'contact']],
        'content'       => ['label' => '内容/资讯', 'label_en' => 'Content / Blog', 'label_ja' => 'コンテンツ',
                            'items' => ['news', 'about', 'contact']],
    ],

    // 栏目条目
    'items' => [
        'about'    => ['slug' => 'about',    'type' => 'page',     'group' => '通用',
                       'name' => '关于我们', 'name_en' => 'About Us',  'name_ja' => '会社概要',
                       'seo_title' => '关于我们',
                       'content' => '<h2>关于我们</h2><p>请在后台编辑本页内容，介绍公司简介、发展历程与愿景。</p>'],
        'products' => ['slug' => 'products', 'type' => 'product',  'group' => '业务',
                       'name' => '产品中心', 'name_en' => 'Products',  'name_ja' => '製品',
                       'seo_title' => '产品中心'],
        'solutions'=> ['slug' => 'solutions','type' => 'list',     'group' => '业务',
                       'name' => '解决方案', 'name_en' => 'Solutions', 'name_ja' => 'ソリューション',
                       'seo_title' => '解决方案'],
        'cases'    => ['slug' => 'cases',    'type' => 'case',     'group' => '业务',
                       'name' => '成功案例', 'name_en' => 'Cases',     'name_ja' => '導入事例',
                       'seo_title' => '成功案例'],
        'services' => ['slug' => 'services', 'type' => 'list',     'group' => '业务',
                       'name' => '服务支持', 'name_en' => 'Services',  'name_ja' => 'サービス',
                       'seo_title' => '服务支持'],
        'news'     => ['slug' => 'news',     'type' => 'list',     'group' => '内容',
                       'name' => '新闻资讯', 'name_en' => 'News',      'name_ja' => 'ニュース',
                       'seo_title' => '新闻资讯'],
        'download' => ['slug' => 'download', 'type' => 'download', 'group' => '内容',
                       'name' => '资料下载', 'name_en' => 'Downloads', 'name_ja' => 'ダウンロード',
                       'seo_title' => '资料下载'],
        'jobs'     => ['slug' => 'jobs',     'type' => 'job',      'group' => '通用',
                       'name' => '人才招聘', 'name_en' => 'Careers',   'name_ja' => '採用情報',
                       'seo_title' => '人才招聘'],
        'culture'  => ['slug' => 'culture',  'type' => 'page',     'group' => '通用',
                       'name' => '企业文化', 'name_en' => 'Culture',   'name_ja' => '企業文化',
                       'seo_title' => '企业文化',
                       'content' => '<h2>企业文化</h2><p>请在后台编辑本页内容，介绍企业价值观、团队与文化。</p>'],
        'contact'  => ['slug' => 'contact',  'type' => 'page',     'group' => '通用',
                       'name' => '联系我们', 'name_en' => 'Contact',   'name_ja' => 'お問い合わせ',
                       'seo_title' => '联系我们',
                       'content' => '<h2>联系我们</h2><p>电话：<br>邮箱：<br>地址：</p>'],
        'faq'      => ['slug' => 'faq',      'type' => 'page',     'group' => '通用',
                       'name' => '常见问题', 'name_en' => 'FAQ',       'name_ja' => 'よくある質問',
                       'seo_title' => '常见问题',
                       // 与构建器折叠面板(FAQ)元素同款 details/summary 结构与样式类
                       'content' => '<h2>常见问题</h2>'
                           . '<div class="divide-y divide-gray-200 border border-gray-200 rounded-xl bg-white overflow-hidden">'
                           . '<details class="group px-5" open><summary class="flex items-center justify-between gap-3 py-4 cursor-pointer list-none font-medium text-gray-800 hover:text-primary transition"><span>如何购买你们的产品？</span><i class="ti ti-chevron-down text-gray-400 flex-shrink-0 transition-transform duration-200 group-open:rotate-180"></i></summary><div class="pb-4 text-sm text-gray-600 leading-relaxed">您可以通过在线表单留言或直接电话联系我们，客服会在一个工作日内回复您。</div></details>'
                           . '<details class="group px-5"><summary class="flex items-center justify-between gap-3 py-4 cursor-pointer list-none font-medium text-gray-800 hover:text-primary transition"><span>是否提供售后服务？</span><i class="ti ti-chevron-down text-gray-400 flex-shrink-0 transition-transform duration-200 group-open:rotate-180"></i></summary><div class="pb-4 text-sm text-gray-600 leading-relaxed">提供。所有产品均含一年质保与终身技术支持，让您没有后顾之忧。</div></details>'
                           . '<details class="group px-5"><summary class="flex items-center justify-between gap-3 py-4 cursor-pointer list-none font-medium text-gray-800 hover:text-primary transition"><span>可以定制吗？</span><i class="ti ti-chevron-down text-gray-400 flex-shrink-0 transition-transform duration-200 group-open:rotate-180"></i></summary><div class="pb-4 text-sm text-gray-600 leading-relaxed">支持。请把您的需求告诉我们，我们会评估后给出方案与报价。</div></details>'
                           . '<details class="group px-5"><summary class="flex items-center justify-between gap-3 py-4 cursor-pointer list-none font-medium text-gray-800 hover:text-primary transition"><span>发货周期是多久？</span><i class="ti ti-chevron-down text-gray-400 flex-shrink-0 transition-transform duration-200 group-open:rotate-180"></i></summary><div class="pb-4 text-sm text-gray-600 leading-relaxed">常规产品 3 个工作日内发货，定制类产品以合同约定为准。</div></details>'
                           . '</div>'],
    ],
];
