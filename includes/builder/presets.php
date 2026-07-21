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
                'key' => 'hero', 'label' => 'Hero 主视觉', 'icon' => 'layout-navbar',
                'desc' => '大标题 + 副文 + 按钮',
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'xl', 'max_width' => 'default', 'align_items' => 'center', 'justify_items' => 'center', 'gap' => 'lg'],
                    'columns' => [[
                        'id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => '值得信赖的合作伙伴', 'level' => 'h1']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p style="text-align:center">一句话说清你的核心价值——为谁、解决什么问题、凭什么选择你。</p>']],
                            ['id' => 'e', 'type' => 'button', 'data' => ['text' => '了解更多', 'url' => '/about.html', 'new_tab' => false]],
                        ],
                    ]],
                ]],
            ],
            [
                'key' => 'features', 'label' => '特性三栏', 'icon' => 'layout-grid',
                'desc' => '图标盒 × 3（卡片）',
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'center', 'gap' => 'lg', 'col_card' => true],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'icon-box', 'data' => ['icon' => 'award', 'title' => '专业可靠', 'text' => '经验丰富的团队，为您提供全流程支持。']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'icon-box', 'data' => ['icon' => 'shield', 'title' => '品质保证', 'text' => '严格的品质管理体系，每个环节值得信赖。']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'icon-box', 'data' => ['icon' => 'headset', 'title' => '贴心服务', 'text' => '快速响应的售前售后，与您携手创造价值。']],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'pricing', 'label' => '价格表三栏', 'icon' => 'currency-yen',
                'desc' => '总标题 + 3 档定价卡片（加宽）',
                'sections' => [
                    ['id' => 's', 'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default'], 'columns' => [['id' => 'c', 'elements' => [
                        ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<div class="text-center"><h2 class="blk-title mb-2">价格方案</h2><span class="section-title-bar"></span><p class="blk-sub">选择最适合你的套餐，随时可升级</p></div>']],
                    ]]]],
                    ['id' => 's',
                    'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'gap' => 'md', 'col_card' => true],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => '基础版', 'level' => 'h3']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p style="text-align:center"><span style="font-size:2rem;font-weight:700">¥99</span> <span style="color:#888">/ 月</span></p>']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<ul style="list-style:none;padding:0;margin:0;line-height:2.2;color:#555"><li>核心功能</li><li>5 个项目</li><li>邮件支持</li></ul>']],
                            ['id' => 'e', 'type' => 'button', 'data' => ['text' => '选择基础版', 'url' => '/contact.html', 'new_tab' => false]],
                        ]],
                        ['id' => 'c', 'card_bg' => '#fffbeb', 'elements' => [
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<div style="text-align:center;margin-bottom:6px"><span style="display:inline-block;background:#4f46e5;color:#fff;font-size:12px;font-weight:600;padding:3px 14px;border-radius:9999px">★ 推荐</span></div>']],
                            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => '专业版', 'level' => 'h3']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p style="text-align:center"><span style="font-size:2rem;font-weight:700;color:#4f46e5">¥299</span> <span style="color:#888">/ 月</span></p>']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<ul style="list-style:none;padding:0;margin:0;line-height:2.2;color:#555"><li>全部基础功能</li><li>无限项目</li><li>优先支持</li><li>数据分析</li></ul>']],
                            ['id' => 'e', 'type' => 'button', 'data' => ['text' => '选择专业版', 'url' => '/contact.html', 'new_tab' => false]],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'heading', 'data' => ['text' => '旗舰版', 'level' => 'h3']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p style="text-align:center"><span style="font-size:2rem;font-weight:700">¥999</span> <span style="color:#888">/ 月</span></p>']],
                            ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<ul style="list-style:none;padding:0;margin:0;line-height:2.2;color:#555"><li>全部专业功能</li><li>专属客户经理</li><li>定制开发</li><li>SLA 保障</li></ul>']],
                            ['id' => 'e', 'type' => 'button', 'data' => ['text' => '联系销售', 'url' => '/contact.html', 'new_tab' => false]],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'cta', 'label' => '行动号召', 'icon' => 'speakerphone',
                'desc' => 'CTA 横幅 + 按钮',
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'md', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'md'],
                    'columns' => [[
                        'id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'cta', 'data' => ['title' => '想进一步了解我们？', 'text' => '欢迎随时联系，专业团队竭诚为您提供咨询与解决方案。', 'btn_text' => '立即联系', 'btn_url' => '/contact.html']],
                        ],
                    ]],
                ]],
            ],
            [
                'key' => 'team', 'label' => '团队成员', 'icon' => 'users',
                'desc' => '头像卡片 × 3',
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'center', 'gap' => 'lg'],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'card', 'data' => ['image' => '/uploads/images/case-demo.jpg', 'title' => '张三 · 创始人', 'text' => '15 年行业经验，负责整体战略。', 'link' => '']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'card', 'data' => ['image' => '/uploads/images/case-demo.jpg', 'title' => '李四 · 技术总监', 'text' => '架构与研发团队负责人。', 'link' => '']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'card', 'data' => ['image' => '/uploads/images/case-demo.jpg', 'title' => '王五 · 客户成功', 'text' => '让每一位客户用得顺手。', 'link' => '']],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'gallery', 'label' => '图片画廊', 'icon' => 'photo',
                'desc' => '图片 × 3（点击放大）',
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'md'],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'image', 'data' => ['src' => '/uploads/images/case-demo.jpg', 'alt' => '', 'click_action' => 'lightbox', 'link_url' => '', 'link_new_tab' => false]],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'image', 'data' => ['src' => '/uploads/images/case-demo.jpg', 'alt' => '', 'click_action' => 'lightbox', 'link_url' => '', 'link_new_tab' => false]],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'image', 'data' => ['src' => '/uploads/images/case-demo.jpg', 'alt' => '', 'click_action' => 'lightbox', 'link_url' => '', 'link_new_tab' => false]],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'stats', 'label' => '数据亮点', 'icon' => 'chart-bar',
                'desc' => '数字 × 4',
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '#0f172a', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'center', 'gap' => 'lg'],
                    'columns' => [
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'code', 'data' => ['html' => '<div class="text-center"><div style="color:#fff" class="text-4xl font-bold">10+</div><div style="color:rgba(255,255,255,.6)" class="text-sm mt-1">行业经验（年）</div></div>']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'code', 'data' => ['html' => '<div class="text-center"><div style="color:#fff" class="text-4xl font-bold">500+</div><div style="color:rgba(255,255,255,.6)" class="text-sm mt-1">服务客户</div></div>']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'code', 'data' => ['html' => '<div class="text-center"><div style="color:#fff" class="text-4xl font-bold">98%</div><div style="color:rgba(255,255,255,.6)" class="text-sm mt-1">客户满意度</div></div>']],
                        ]],
                        ['id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'code', 'data' => ['html' => '<div class="text-center"><div style="color:#fff" class="text-4xl font-bold">24h</div><div style="color:rgba(255,255,255,.6)" class="text-sm mt-1">响应时效</div></div>']],
                        ]],
                    ],
                ]],
            ],
            [
                'key' => 'testimonial', 'label' => '客户评价', 'icon' => 'message-star',
                'desc' => '引文 + 署名',
                'sections' => [[
                    'id' => 's',
                    'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'narrow', 'align_items' => 'center', 'justify_items' => 'center', 'gap' => 'md'],
                    'columns' => [[
                        'id' => 'c', 'elements' => [
                            ['id' => 'e', 'type' => 'quote', 'data' => ['text' => '合作非常顺畅，交付质量超出预期，是可以长期信赖的伙伴。', 'author' => '某客户 · 项目负责人']],
                        ],
                    ]],
                ]],
            ],
        ],

        // ---- 整页模板：一键插入整套区块 ----
        'pages' => [
            [
                'key' => 'company_intro', 'label' => '公司介绍', 'icon' => 'file-text',
                'desc' => '简介 + 图文 + 优势 + CTA',
                'sections' => [
                    [
                        'id' => 's', 'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'center', 'justify_items' => 'center', 'gap' => 'lg'],
                        'columns' => [[
                            'id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => '公司简介', 'level' => 'h2']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p style="text-align:center">我们是一家专注于行业领域的企业，自成立以来始终坚持以客户为中心，凭借专业的团队与可靠的品质，为国内外客户提供优质的产品与服务。</p>']],
                            ],
                        ]],
                    ],
                    [
                        'id' => 's', 'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'center', 'justify_items' => 'stretch', 'gap' => 'lg'],
                        'columns' => [
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'image', 'data' => ['src' => '/uploads/images/case-demo.jpg', 'alt' => '公司环境 / 团队照片', 'click_action' => '', 'link_url' => '', 'link_new_tab' => false]],
                            ]],
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => '我们的故事', 'level' => 'h3']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p>多年来，我们深耕行业，持续投入研发与创新，建立了完善的品质管理体系。我们相信，只有真正理解客户需求，才能创造长久的价值。</p><p>未来，我们将继续秉持匠心，为客户与合作伙伴带来更卓越的体验。</p>']],
                                ['id' => 'e', 'type' => 'button', 'data' => ['text' => '了解更多', 'url' => '#', 'new_tab' => false]],
                            ]],
                        ],
                    ],
                    [
                        'id' => 's', 'settings' => ['bg_color' => '#f8fafc', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'center', 'gap' => 'lg', 'col_card' => true],
                        'columns' => [
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'icon', 'data' => ['icon' => 'award', 'size' => 'lg', 'color' => '', 'text' => '']],
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => '专业团队', 'level' => 'h4']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p style="text-align:center">经验丰富的专业团队，为您提供全流程的贴心支持。</p>']],
                            ]],
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'icon', 'data' => ['icon' => 'shield', 'size' => 'lg', 'color' => '', 'text' => '']],
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => '品质保证', 'level' => 'h4']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p style="text-align:center">严格的品质管理体系，确保每一个环节都值得信赖。</p>']],
                            ]],
                            ['id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'icon', 'data' => ['icon' => 'users', 'size' => 'lg', 'color' => '', 'text' => '']],
                                ['id' => 'e', 'type' => 'heading', 'data' => ['text' => '贴心服务', 'level' => 'h4']],
                                ['id' => 'e', 'type' => 'text', 'data' => ['html' => '<p style="text-align:center">快速响应的售前售后服务，与您携手共创价值。</p>']],
                            ]],
                        ],
                    ],
                    [
                        'id' => 's', 'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'md', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'md'],
                        'columns' => [[
                            'id' => 'c', 'elements' => [
                                ['id' => 'e', 'type' => 'code', 'data' => ['html' =>
                                    '<div style="background:linear-gradient(120deg,var(--color-primary),var(--color-secondary))" class="rounded-2xl px-6 py-14 md:py-16 text-center shadow-xl">'
                                    . '<div style="color:#fff" class="text-2xl md:text-4xl font-bold mb-3">想进一步了解我们？</div>'
                                    . '<div style="color:rgba(255,255,255,.9)" class="text-base md:text-lg mb-8 max-w-2xl mx-auto">欢迎随时与我们联系，专业团队将竭诚为您提供咨询与解决方案。</div>'
                                    . '<a href="/contact.html" style="background:#fff;color:var(--color-primary);text-decoration:none" class="inline-flex items-center gap-2 font-semibold px-8 py-3.5 rounded-full shadow-lg hover:-translate-y-1 transition">立即联系我们 <i class="ti ti-arrow-right text-lg"></i></a>'
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
