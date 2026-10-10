<?php
/**
 * 旧站首页的多语言种子数据（2026-10-10：日语首页客户评价显示中文、Banner 次按钮链接为 #）。
 *
 * 1. 客户评价：2026-09-18 起安装数据给首页客户评价轮播带了英文、日文译文绑定（_home_testimonials_i18n），
 *    之前装的站没有，外语首页照样显示中文。条目还是安装时那几条中文的，补上同一份绑定；
 *    站长换过内容的不动（绑定只替换仍等于原文的字段，补了也安全，但没必要）。
 * 2. Banner：英文、日文演示轮播的次按钮有文字没有链接，前台渲染成 href="#"。只补仍是安装原样的那几行。
 */

declare(strict_types=1);

$seedTestimonialsBinding = [
    'lang' => 'zh-CN',
    'source' => [
        'title' => '客户评价',
        'subtitle' => '听听合作客户怎么说',
        'items' => [
            [
                'name' => '陈思远',
                'role' => '采购总监 · 华东制造集团',
                'content' => '从选型到交付只用了三周，技术团队全程跟进，现场调试一次通过。后续两条产线也继续选择了他们。',
            ],
            [
                'name' => '林晓雯',
                'role' => '运营经理 · 星禾连锁',
                'content' => '门店系统上线后，库存盘点时间缩短了一半。遇到问题随时有人响应，这是我们最看重的。',
            ],
            [
                'name' => '王建国',
                'role' => '技术负责人 · 远拓物流',
                'content' => '方案很务实，没有堆砌功能。按我们的业务流程做了定制，培训一周员工就能独立使用。',
            ],
        ],
    ],
    'translations' => [
        'en' => [
            'title' => 'What Our Clients Say',
            'subtitle' => 'Hear from the partners we work with',
            'items' => [
                [
                    'name' => 'Siyuan Chen',
                    'role' => 'Procurement Director · East China Manufacturing Group',
                    'content' => 'From selection to delivery took just three weeks. Their engineers stayed with us the whole way, and on-site commissioning passed on the first try. We chose them again for our next two production lines.',
                ],
                [
                    'name' => 'Xiaowen Lin',
                    'role' => 'Operations Manager · Xinghe Retail',
                    'content' => 'Since the store system went live, stock-taking takes half the time. Whenever something comes up, someone responds right away — that is what we value most.',
                ],
                [
                    'name' => 'Jianguo Wang',
                    'role' => 'Head of Technology · Yuantuo Logistics',
                    'content' => 'A practical solution without feature bloat. It was tailored to our workflow, and after one week of training our staff could use it on their own.',
                ],
            ],
        ],
        'ja' => [
            'title' => 'お客様の声',
            'subtitle' => 'お取引先の皆さまからいただいたご感想です',
            'items' => [
                [
                    'name' => '陳 思遠',
                    'role' => '購買部長 · 華東製造グループ',
                    'content' => '選定から納品までわずか3週間。技術チームが最後まで伴走してくれ、現地での調整も一度で完了しました。その後の2本の生産ラインでも引き続き採用しています。',
                ],
                [
                    'name' => '林 暁雯',
                    'role' => '運営マネージャー · 星禾チェーン',
                    'content' => '店舗システムの導入後、棚卸しにかかる時間が半分になりました。困ったときにすぐ対応してもらえるのが、何よりありがたいです。',
                ],
                [
                    'name' => '王 建国',
                    'role' => '技術責任者 · 遠拓物流',
                    'content' => '機能を詰め込みすぎない、実用的な提案でした。業務フローに合わせてカスタマイズしてもらい、1週間の研修でスタッフが自分で使いこなせるようになりました。',
                ],
            ],
        ],
    ],
];

/** 按钮文字仍是安装原样、链接为空的演示 Banner：[语言, 主按钮文字, 次按钮原文字, 次按钮新文字, 次按钮链接] */
$seedBannerButtons = [
    ['en', 'Learn More', 'Contact Sales', 'Contact Sales', '/contact.html'],
    ['en', 'Our Services', 'Our Services', 'Contact Us', '/contact.html'],
    ['en', 'Learn More', 'View Cases', 'View Cases', '/cases.html'],
    ['ja', '詳しく見る', 'お問い合わせ', 'お問い合わせ', '/contact.html'],
    ['ja', 'サービス内容', 'サービスを見る', 'お問い合わせ', '/contact.html'],
    ['ja', '詳しく見る', '導入事例を見る', '導入事例を見る', '/cases.html'],
];

$seedTestimonials = static function (bool $apply) use ($seedTestimonialsBinding): int {
    $seedContents = array_column($seedTestimonialsBinding['source']['items'], 'content');
    // ESCAPE 用 '!'：反斜杠在 MySQL 与 SQLite 里转义规则不同（见 20260810_normalize_default_lang_shadow）
    $rows = db()->fetchAll(
        'SELECT id, value FROM ' . DB_PREFIX . "settings WHERE `key` LIKE ? ESCAPE '!'",
        ['home!_blox!_%']
    );
    $fixed = 0;
    foreach ($rows as $row) {
        $doc = json_decode((string) ($row['value'] ?? ''), true);
        if (!is_array($doc) || !is_array($doc['sections'] ?? null)) {
            continue;
        }
        $changed = false;
        foreach ($doc['sections'] as &$section) {
            foreach ((array) ($section['columns'] ?? []) as $ci => $column) {
                foreach ((array) ($column['elements'] ?? []) as $ei => $element) {
                    $data = is_array($element['data'] ?? null) ? $element['data'] : [];
                    if (($element['type'] ?? '') !== 'testimonial-carousel' || isset($data['_home_testimonials_i18n'])
                        || !is_array($data['items'] ?? null) || $data['items'] === []) {
                        continue;
                    }
                    $contents = array_map(static fn ($item): string => is_array($item) ? (string) ($item['content'] ?? '') : '', $data['items']);
                    if (array_diff($contents, $seedContents) !== []) {
                        continue;
                    }
                    $section['columns'][$ci]['elements'][$ei]['data']['_home_testimonials_i18n'] = $seedTestimonialsBinding;
                    $changed = true;
                    $fixed++;
                }
            }
        }
        unset($section);
        if ($changed && $apply) {
            db()->update('settings', ['value' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'id = ?', [(int) $row['id']]);
        }
    }
    return $fixed;
};

$seedBanners = static function (bool $apply) use ($seedBannerButtons): int {
    $fixed = 0;
    foreach ($seedBannerButtons as [$lang, $btn1, $btn2, $newText, $url]) {
        $where = "lang = ? AND btn1_text = ? AND btn2_text = ? AND (btn2_url = '' OR btn2_url IS NULL)";
        $params = [$lang, $btn1, $btn2];
        $count = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'banners WHERE ' . $where, $params);
        if ($count > 0 && $apply) {
            db()->update('banners', ['btn2_text' => $newText, 'btn2_url' => $url], $where, $params);
        }
        $fixed += $count;
    }
    return $fixed;
};

return [
    'id' => '20261010_home_seed_i18n',
    'title' => '首页演示数据补外语译文与按钮链接',
    'desc' => '旧安装的首页客户评价在英文、日文首页仍显示中文，演示 Banner 的次按钮没有链接。给仍是安装原样的客户评价补上英文、日文译文，给演示 Banner 次按钮补上链接；改过的内容不动。',
    'title_en' => 'Add translations and button links to the homepage demo data',
    'title_ja' => 'トップページのデモデータに翻訳とボタンリンクを追加',
    'desc_en' => 'On older installs the homepage testimonials still showed Chinese on the English and Japanese homepages, and the demo banners\' secondary buttons had no link. Testimonials still in their installed form get English and Japanese translations, and the demo banners\' secondary buttons get links; edited content is left alone.',
    'desc_ja' => '旧インストールでは、英語・日本語のトップページでもお客様の声が中国語のまま表示され、デモバナーのサブボタンにリンクがありませんでした。インストール時のままのお客様の声に英語・日本語訳を追加し、デモバナーのサブボタンにリンクを設定します。編集済みの内容はそのまま残します。',
    'check' => static fn (): bool => $seedTestimonials(false) === 0 && $seedBanners(false) === 0,
    'php' => static function () use ($seedTestimonials, $seedBanners): string {
        $testimonials = $seedTestimonials(true);
        $banners = $seedBanners(true);
        if ($testimonials + $banners > 0 && class_exists('HtmlCache')) {
            HtmlCache::invalidate();
        }
        return 'testimonials ' . $testimonials . ', banners ' . $banners;
    },
];
