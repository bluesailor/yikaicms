<?php
/**
 * YikaiCMS 页面构建器 —— 预设库（P2）。
 *
 * 区块级预设（Hero/特性/CTA/团队/画廊/数据/评价）+ 整页模板，后台「预设库」弹窗一键插入。
 * 插件可用 builder_presets 过滤器追加/改写。仅后台构建器加载，view-time 渲染不需要。
 *
 * 数据形状：每个预设 = ['key','label','icon','desc','sections'=>[section,...]]，
 * section/element 结构与 blocks_data 一致（插入时前端 freshSection 重生成各级 id）。
 */

declare(strict_types=1);

function builderPresets(): array
{
    $presets = [
        // ---- 区块级预设：单区块一键插入 ----
        'sections' => [
            [
                'key' => 'hero', 'label' => __('blox_ps_hero'), 'icon' => 'layout-navbar',
                'desc' => __('blox_ps_hero_d'),
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'xl', 'max_width' => 'default', 'align_items' => 'center', 'justify_items' => 'center', 'gap' => 'lg'],
                    'columns' => [[
                        'id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => __('blox_ps_hero_h1'), 'level' => 'h1']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p class="yk-center">' . __('blox_ps_hero_p') . '</p>']],
                            ['id' => 'e', 'type' => 'button', 'data' => ['text' => __('home_learn_more'), 'url' => '/about.html', 'new_tab' => false]],
                        ],
                    ]],
                ]],
            ],
            [
                'key' => 'features', 'label' => __('blox_ps_features'), 'icon' => 'layout-grid',
                'desc' => __('blox_ps_features_d'),
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'center', 'gap' => 'lg', 'col_card' => true],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'icon-box', 'data' => ['icon' => 'award', 'title' => __('blox_ps_f1_t'), 'text' => __('blox_ps_f1_x')]],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'icon-box', 'data' => ['icon' => 'shield', 'title' => __('blox_ps_f2_t'), 'text' => __('blox_ps_f2_x')]],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'icon-box', 'data' => ['icon' => 'headset', 'title' => __('blox_ps_f3_t'), 'text' => __('blox_ps_f3_x')]],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'pricing', 'label' => __('blox_ps_pricing'), 'icon' => 'currency-yen',
                'desc' => __('blox_ps_pricing_d'),
                'sections' => [
                    ['id' => 's',
                    'settings' => ['title' => __('blox_ps_pricing_t'), 'subtitle' => __('blox_ps_pricing_s'), 'bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'gap' => 'md', 'col_card' => true],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => __('blox_ps_plan_basic'), 'level' => 'h3']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p class="yk-center"><span class="yk-price">¥99</span> <span class="yk-price-note">/ ' . __('blox_ps_per_month') . '</span></p>']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<ul class="yk-plan-list"><li>' . __('blox_ps_pb_1') . '</li><li>' . __('blox_ps_pb_2') . '</li><li>' . __('blox_ps_pb_3') . '</li></ul>']],
                            ['id' => 'e', 'type' => 'button', 'data' => ['text' => __('blox_ps_pick_basic'), 'url' => '/contact.html', 'new_tab' => false]],
                        ]],
                        ['id' => 'c', 'card_bg' => '#fffbeb', 'elements' => [
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<div class="yk-badge-row"><span class="yk-badge">★ ' . __('blox_ps_recommended') . '</span></div>']],
                            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => __('blox_ps_plan_pro'), 'level' => 'h3']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p class="yk-center"><span class="yk-price yk-price--hot">¥299</span> <span class="yk-price-note">/ ' . __('blox_ps_per_month') . '</span></p>']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<ul class="yk-plan-list"><li>' . __('blox_ps_pp_1') . '</li><li>' . __('blox_ps_pp_2') . '</li><li>' . __('blox_ps_pp_3') . '</li><li>' . __('blox_ps_pp_4') . '</li></ul>']],
                            ['id' => 'e', 'type' => 'button', 'data' => ['text' => __('blox_ps_pick_pro'), 'url' => '/contact.html', 'new_tab' => false]],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => __('blox_ps_plan_flagship'), 'level' => 'h3']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p class="yk-center"><span class="yk-price">¥999</span> <span class="yk-price-note">/ ' . __('blox_ps_per_month') . '</span></p>']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<ul class="yk-plan-list"><li>' . __('blox_ps_pf_1') . '</li><li>' . __('blox_ps_pf_2') . '</li><li>' . __('blox_ps_pf_3') . '</li><li>' . __('blox_ps_pf_4') . '</li></ul>']],
                            ['id' => 'e', 'type' => 'button', 'data' => ['text' => __('blox_ps_contact_sales'), 'url' => '/contact.html', 'new_tab' => false]],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'cta', 'label' => __('blox_el_cta'), 'icon' => 'speakerphone',
                'desc' => __('blox_ps_cta_d'),
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'md', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'md'],
                    'columns' => [[
                        'id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'cta', 'data' => ['title' => __('blox_ps_cta_t'), 'text' => __('blox_ps_cta_x'), 'btn_text' => __('blox_ps_contact_now'), 'btn_url' => '/contact.html']],
                        ],
                    ]],
                ]],
            ],
            [
                'key' => 'team', 'label' => __('blox_ps_team'), 'icon' => 'users',
                'desc' => __('blox_ps_team_d'),
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'center', 'gap' => 'lg'],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'card', 'data' => ['image' => '/images/case-demo.jpg', 'title' => __('blox_ps_m1_t'), 'text' => __('blox_ps_m1_x'), 'link' => '']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'card', 'data' => ['image' => '/images/case-demo.jpg', 'title' => __('blox_ps_m2_t'), 'text' => __('blox_ps_m2_x'), 'link' => '']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'card', 'data' => ['image' => '/images/case-demo.jpg', 'title' => __('blox_ps_m3_t'), 'text' => __('blox_ps_m3_x'), 'link' => '']],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'gallery', 'label' => __('blox_ps_gallery'), 'icon' => 'photo',
                'desc' => __('blox_ps_gallery_d'),
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'md'],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'image', 'data' => ['src' => '/images/case-demo.jpg', 'alt' => '', 'click_action' => 'lightbox', 'link_url' => '', 'link_new_tab' => false]],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'image', 'data' => ['src' => '/images/case-demo.jpg', 'alt' => '', 'click_action' => 'lightbox', 'link_url' => '', 'link_new_tab' => false]],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'image', 'data' => ['src' => '/images/case-demo.jpg', 'alt' => '', 'click_action' => 'lightbox', 'link_url' => '', 'link_new_tab' => false]],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'stats', 'label' => __('blox_ps_stats'), 'icon' => 'chart-bar',
                'desc' => __('blox_ps_stats_d'),
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '#0f172a', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'center', 'gap' => 'lg'],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'code', 'data' => ['html' => '<div class="text-center"><div style="color:#fff" class="text-4xl font-bold">10+</div><div style="color:rgba(255,255,255,.6)" class="text-sm mt-1">' . __('blox_ps_s1') . '</div></div>']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'code', 'data' => ['html' => '<div class="text-center"><div style="color:#fff" class="text-4xl font-bold">500+</div><div style="color:rgba(255,255,255,.6)" class="text-sm mt-1">' . __('home_stat_1') . '</div></div>']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'code', 'data' => ['html' => '<div class="text-center"><div style="color:#fff" class="text-4xl font-bold">98%</div><div style="color:rgba(255,255,255,.6)" class="text-sm mt-1">' . __('blox_ps_s3') . '</div></div>']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'code', 'data' => ['html' => '<div class="text-center"><div style="color:#fff" class="text-4xl font-bold">24h</div><div style="color:rgba(255,255,255,.6)" class="text-sm mt-1">' . __('blox_ps_s4') . '</div></div>']],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'testimonial', 'label' => __('blox_hb_testimonials'), 'icon' => 'message-star',
                'desc' => __('blox_ps_quote_d'),
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'narrow', 'align_items' => 'center', 'justify_items' => 'center', 'gap' => 'md'],
                    'columns' => [[
                        'id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'quote', 'data' => ['text' => __('blox_ps_quote_x'), 'author' => __('blox_ps_quote_a')]],
                        ],
                    ]],
                ]],
            ],
            [
                'key' => 'faq', 'label' => __('blox_ps_faq'), 'icon' => 'help-circle',
                'desc' => __('blox_ps_faq_d'),
                'sections' => [[
                    'id' => 's',
                    // 用 section 级标题（blk-title + 装饰条），与首页其它版块标题风格统一
                    'settings' => ['title' => __('blox_ps_faq_t'), 'subtitle' => __('blox_ps_faq_s'), 'bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'md'],
                    'columns' => [[
                        'id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'accordion', 'data' => [
                                'items' => [
                                    ['question' => __('blox_faq_seed_q1'), 'answer' => __('blox_ps_faq_a1')],
                                    ['question' => __('blox_faq_seed_q2'), 'answer' => __('blox_ps_faq_a2')],
                                    ['question' => __('blox_ps_faq_q3'), 'answer' => __('blox_ps_faq_a3')],
                                    ['question' => __('blox_ps_faq_q4'), 'answer' => __('blox_ps_faq_a4')],
                                ],
                                'open_first' => true,
                                'seo_schema' => true,
                            ]],
                        ],
                    ]],
                ]],
            ],
        ],

        // ---- 整页模板：一键插入整套区块 ----
        'pages' => [
            [
                'key' => 'company_intro', 'label' => __('blox_ps_company'), 'icon' => 'file-text',
                'desc' => __('blox_ps_company_d'),
                'sections' => [
                    [
                        'id' => 's', 'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'center', 'justify_items' => 'center', 'gap' => 'lg'],
                        'columns' => [[
                            'id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => __('blox_ps_co_t'), 'level' => 'h2']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p class="yk-center">' . __('blox_ps_co_p') . '</p>']],
                            ],
                        ]],
                    ],
                    [
                        'id' => 's', 'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'center', 'justify_items' => 'stretch', 'gap' => 'lg'],
                        'columns' => [
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'image', 'data' => ['src' => '/images/case-demo.jpg', 'alt' => __('blox_ps_co_alt'), 'click_action' => '', 'link_url' => '', 'link_new_tab' => false]],
                            ]],
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => __('blox_ps_story_t'), 'level' => 'h3']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p>' . __('blox_ps_story_p1') . '</p><p>' . __('blox_ps_story_p2') . '</p>']],
                                ['id' => 'e', 'type' => 'button', 'data' => ['text' => __('home_learn_more'), 'url' => '#', 'new_tab' => false]],
                            ]],
                        ],
                    ],
                    [
                        'id' => 's', 'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'center', 'gap' => 'lg', 'col_card' => true],
                        'columns' => [
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'icon', 'data' => ['icon' => 'award', 'size' => 'lg', 'color' => '', 'text' => '']],
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => __('home_adv_1_title'), 'level' => 'h4']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p class="yk-center">' . __('blox_ps_a1') . '</p>']],
                            ]],
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'icon', 'data' => ['icon' => 'shield', 'size' => 'lg', 'color' => '', 'text' => '']],
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => __('blox_ps_f2_t'), 'level' => 'h4']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p class="yk-center">' . __('blox_ps_a2') . '</p>']],
                            ]],
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'icon', 'data' => ['icon' => 'users', 'size' => 'lg', 'color' => '', 'text' => '']],
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => __('blox_ps_f3_t'), 'level' => 'h4']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p class="yk-center">' . __('blox_ps_a3') . '</p>']],
                            ]],
                        ],
                    ],
                    [
                        'id' => 's', 'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'md', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'md'],
                        'columns' => [[
                            'id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'code', 'data' => ['html' =>
                                    '<div style="background:linear-gradient(120deg,var(--color-primary),var(--color-secondary))" class="rounded-2xl px-6 py-14 md:py-16 text-center shadow-xl">'
                                    . '<div style="color:#fff" class="text-2xl md:text-4xl font-bold mb-3">' . __('blox_ps_cta_t') . '</div>'
                                    . '<div style="color:rgba(255,255,255,.9)" class="text-base md:text-lg mb-8 max-w-2xl mx-auto">' . __('blox_ps_cta_x2') . '</div>'
                                    . '<a href="/contact.html" style="background:#fff;color:var(--color-primary);text-decoration:none" class="inline-flex items-center gap-2 font-semibold px-8 py-3.5 rounded-full shadow-lg hover:-translate-y-1 transition">' . __('blox_ps_contact_us_now') . ' <i class="ti ti-arrow-right text-lg"></i></a>'
                                    . '</div>',
                                ]],
                            ],
                        ]],
                    ],
                ],
            ],
        ],
    ];

    if (function_exists('apply_filters')) {
        $presets = apply_filters('builder_presets', $presets);
    }
    return $presets;
}
