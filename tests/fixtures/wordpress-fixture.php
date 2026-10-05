<?php
/**
 * 一个迷你 WordPress 数据库（SQLite），表结构与真站一致（只建导入用到的列）：
 * WordPress 核心 + WooCommerce 商品 + Yoast SEO + WPML 多语言（英文默认、日文翻译，子域名模式）。
 * 单测与 e2e 共用：wordPressFixture(PDO) 建表灌数据，返回各条目的 WordPress id。
 *
 * 内容按 slewing-bearing.com 的真实形态设计：文章挂根目录 /别名/、单页两层、产品 /product/别名/、
 * 分类 /category/…、标签 /tag/…、产品分类 /product-category/父/子/、产品标签 /product-tag/…。
 */
declare(strict_types=1);

/** @return array<string,int> */
function wordPressFixture(PDO $pdo, string $p = 'wp_'): array
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ([
        "CREATE TABLE {$p}options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT DEFAULT 'yes')",
        "CREATE TABLE {$p}users (ID INTEGER PRIMARY KEY AUTOINCREMENT, user_login TEXT, user_nicename TEXT, display_name TEXT)",
        "CREATE TABLE {$p}posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_author INTEGER DEFAULT 1, post_date TEXT, post_date_gmt TEXT, post_content TEXT DEFAULT '',
            post_title TEXT DEFAULT '', post_excerpt TEXT DEFAULT '', post_status TEXT DEFAULT 'publish', post_name TEXT DEFAULT '', post_modified TEXT, post_modified_gmt TEXT,
            post_parent INTEGER DEFAULT 0, guid TEXT DEFAULT '', menu_order INTEGER DEFAULT 0, post_type TEXT DEFAULT 'post', post_mime_type TEXT DEFAULT '')",
        "CREATE TABLE {$p}postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)",
        "CREATE TABLE {$p}terms (term_id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT, term_group INTEGER DEFAULT 0)",
        "CREATE TABLE {$p}term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER, taxonomy TEXT, description TEXT DEFAULT '', parent INTEGER DEFAULT 0, count INTEGER DEFAULT 0)",
        "CREATE TABLE {$p}term_relationships (object_id INTEGER, term_taxonomy_id INTEGER, term_order INTEGER DEFAULT 0, PRIMARY KEY (object_id, term_taxonomy_id))",
        "CREATE TABLE {$p}termmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER, meta_key TEXT, meta_value TEXT)",
        "CREATE TABLE {$p}icl_translations (translation_id INTEGER PRIMARY KEY AUTOINCREMENT, element_type TEXT, element_id INTEGER, trid INTEGER, language_code TEXT, source_language_code TEXT)",
    ] as $sql) $pdo->exec($sql);

    $opt = static function (string $name, mixed $value) use ($pdo, $p): void {
        $pdo->prepare("INSERT INTO {$p}options (option_name, option_value) VALUES (?, ?)")->execute([$name, is_array($value) ? serialize($value) : (string) $value]);
    };
    $opt('siteurl', 'https://www.slewing-bearing.com');
    $opt('home', 'https://www.slewing-bearing.com');
    $opt('blogname', 'TY Slewing Bearing');
    $opt('permalink_structure', '/%postname%/');
    $opt('category_base', '');
    $opt('tag_base', '');
    $opt('page_on_front', '0');
    $opt('woocommerce_permalinks', ['product_base' => '/product/', 'category_base' => 'product-category', 'tag_base' => 'product-tag', 'attribute_base' => '']);
    $opt('wpseo_titles', ['separator' => 'sc-dash', 'title-post' => '%%title%% %%sep%% %%sitename%%']);
    $opt('wpseo_taxonomy_meta', ['category' => [], 'product_cat' => []]);
    $opt('icl_sitepress_settings', ['default_language' => 'en', 'language_negotiation_type' => 2,
        'language_domains' => ['ja' => 'ja.slewing-bearing.com']]);
    $pdo->exec("INSERT INTO {$p}users (user_login, user_nicename, display_name) VALUES ('admin', 'admin', 'Admin')");

    $ids = [];
    $post = static function (array $f) use ($pdo, $p): int {
        $f += ['post_date' => '2023-05-10 09:00:00', 'post_date_gmt' => '2023-05-10 01:00:00', 'post_modified' => '2023-06-01 09:00:00', 'post_modified_gmt' => '2023-06-01 01:00:00'];
        $cols = implode(', ', array_keys($f));
        $pdo->prepare("INSERT INTO {$p}posts ({$cols}) VALUES (" . implode(', ', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
        return (int) $pdo->lastInsertId();
    };
    $meta = static function (int $id, string $key, mixed $value) use ($pdo, $p): void {
        $pdo->prepare("INSERT INTO {$p}postmeta (post_id, meta_key, meta_value) VALUES (?, ?, ?)")->execute([$id, $key, is_array($value) ? serialize($value) : (string) $value]);
    };
    $term = static function (string $name, string $slug, string $taxonomy, int $parentTermId = 0, string $description = '') use ($pdo, $p): array {
        $pdo->prepare("INSERT INTO {$p}terms (name, slug) VALUES (?, ?)")->execute([$name, $slug]);
        $termId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO {$p}term_taxonomy (term_id, taxonomy, description, parent) VALUES (?, ?, ?, ?)")->execute([$termId, $taxonomy, $description, $parentTermId]);
        return ['term_id' => $termId, 'tt_id' => (int) $pdo->lastInsertId()];
    };
    $relate = static function (int $object, array $term) use ($pdo, $p): void {
        $pdo->prepare("INSERT INTO {$p}term_relationships (object_id, term_taxonomy_id) VALUES (?, ?)")->execute([$object, $term['tt_id']]);
    };
    $wpml = static function (string $type, int $elementId, int $trid, string $lang, ?string $source) use ($pdo, $p): void {
        $pdo->prepare("INSERT INTO {$p}icl_translations (element_type, element_id, trid, language_code, source_language_code) VALUES (?, ?, ?, ?, ?)")
            ->execute([$type, $elementId, $trid, $lang, $source]);
    };

    // 附件
    $ids['img_gear'] = $post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'gear', 'post_mime_type' => 'image/jpeg',
        'guid' => 'https://www.slewing-bearing.com/wp-content/uploads/2023/05/gear.jpg']);
    $meta($ids['img_gear'], '_wp_attached_file', '2023/05/gear.jpg');
    $ids['img_ring'] = $post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'ring', 'post_mime_type' => 'image/png',
        'guid' => 'https://www.slewing-bearing.com/wp-content/uploads/2023/05/ring.png']);
    $meta($ids['img_ring'], '_wp_attached_file', '2023/05/ring.png');

    // 文章分类（父子）、标签
    $catResource = $term('Resource', 'resource', 'category');
    $catTech = $term('Technical Information', 'technical-information', 'category', $catResource['term_id']);
    $catNews = $term('News', 'news', 'category');
    $catTechJa = $term('技術情報', 'technical-information-ja', 'category');
    $tagWorm = $term('worm gear', 'worm-gear', 'post_tag');
    $tagRing = $term('slewing ring', 'slewing-ring', 'post_tag');
    $wpml('tax_category', $catResource['tt_id'], 101, 'en', null);
    $wpml('tax_category', $catTech['tt_id'], 102, 'en', null);
    $wpml('tax_category', $catTechJa['tt_id'], 102, 'ja', 'en');
    $wpml('tax_category', $catNews['tt_id'], 103, 'en', null);
    $wpml('tax_post_tag', $tagWorm['tt_id'], 104, 'en', null);
    $wpml('tax_post_tag', $tagRing['tt_id'], 105, 'en', null);
    $ids['cat_tech'] = $catTech['term_id'];
    $ids['cat_tech_ja'] = $catTechJa['term_id'];

    // 文章：经典编辑器 + WPBakery，Yoast 自定义标题与描述，主分类
    $ids['post_install'] = $post(['post_type' => 'post', 'post_title' => 'Slewing Bearing Installation Procedure', 'post_name' => 'slewing-bearing-installation-procedure',
        'post_excerpt' => 'How to install a slewing bearing.',
        'post_content' => "Check the mounting surface first.\nClean it.\n\n[vc_row][vc_column][vc_single_image image=\"{$ids['img_gear']}\"][/vc_column][/vc_row]\n\n<a href=\"https://www.slewing-bearing.com/product/worm-gear-slew-drive/\">Our slew drive</a>"]);
    $meta($ids['post_install'], '_thumbnail_id', (string) $ids['img_gear']);
    $meta($ids['post_install'], '_yoast_wpseo_title', 'Installing a Slewing Bearing %%sep%% %%sitename%%');
    $meta($ids['post_install'], '_yoast_wpseo_metadesc', 'Step-by-step slewing bearing installation.');
    $meta($ids['post_install'], '_yoast_wpseo_focuskw', 'slewing bearing installation');
    $meta($ids['post_install'], '_yoast_wpseo_primary_category', (string) $catTech['term_id']);
    $relate($ids['post_install'], $catNews);
    $relate($ids['post_install'], $catTech);
    $relate($ids['post_install'], $tagWorm);
    $relate($ids['post_install'], $tagRing);
    $wpml('post_post', $ids['post_install'], 201, 'en', null);
    // 日文翻译：非 ASCII 别名（WordPress 存成小写百分号编码）
    $ids['post_install_ja'] = $post(['post_type' => 'post', 'post_title' => '旋回ベアリングの取付手順', 'post_name' => rawurlencode('旋回ベアリングの取付手順'),
        'post_content' => '<p>取付面を確認します。</p>']);
    $relate($ids['post_install_ja'], $catTechJa);
    $wpml('post_post', $ids['post_install_ja'], 201, 'ja', 'en');
    // 草稿不导入
    $ids['post_draft'] = $post(['post_type' => 'post', 'post_title' => 'Draft', 'post_name' => 'draft-post', 'post_status' => 'draft']);
    // 全角问号别名
    $ids['post_faq'] = $post(['post_type' => 'post', 'post_title' => 'How does a slewing bearing work?', 'post_name' => 'how-does-a-slewing-bearing-work%ef%bc%9f',
        'post_content' => "<!-- wp:paragraph -->\n<p>It carries axial, radial and moment loads.</p>\n<!-- /wp:paragraph -->\n[contact-form-7 id=\"5\" title=\"Inquiry\"]"]);
    $relate($ids['post_faq'], $catNews);
    $wpml('post_post', $ids['post_faq'], 202, 'en', null);

    // 单页：两层；Elementor 页正文为空
    $ids['page_about'] = $post(['post_type' => 'page', 'post_title' => 'About Us', 'post_name' => 'about', 'post_content' => '<p>Founded in 1998.</p>', 'menu_order' => 1]);
    $meta($ids['page_about'], '_yoast_wpseo_metadesc', 'About TY slewing bearings.');
    $wpml('post_page', $ids['page_about'], 301, 'en', null);
    $ids['page_team'] = $post(['post_type' => 'page', 'post_title' => 'Engineer Team', 'post_name' => 'engineer-team', 'post_parent' => $ids['page_about'], 'post_content' => '']);
    $meta($ids['page_team'], '_elementor_edit_mode', 'builder');
    $meta($ids['page_team'], '_elementor_data', json_encode([['elType' => 'section', 'elements' => [['elType' => 'column', 'elements' => [
        ['elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Our engineers']],
        ['elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['editor' => '<p>Twenty engineers.</p>']],
    ]]]]]));
    $wpml('post_page', $ids['page_team'], 302, 'en', null);
    // Contact Form 7：询盘表单（英文为默认语言，日文、德文翻译；新版 CF7 短代码用 hash 前 7 位）
    $quoteForm = "<label>Company name\n    [text company placeholder \"Your company\"]</label>\n<label>Your name *\n    [text* your-name]</label>\n"
        . "<label>Email *\n    [email* your-email]</label>\n<label>Bearing type\n    [select bearing-type \"Single row\" \"Double row\"]</label>\n"
        . "<label>Message\n    [textarea your-message]</label>\n[acceptance privacy]I agree to the privacy policy[/acceptance]\n[quiz robot \"1+1=?|2\"]\n[submit \"Send inquiry\"]";
    $ids['form_quote'] = $post(['post_type' => 'wpcf7_contact_form', 'post_title' => 'Request a Quote', 'post_name' => 'request-a-quote', 'post_content' => $quoteForm]);
    $meta($ids['form_quote'], '_form', $quoteForm);
    $meta($ids['form_quote'], '_hash', 'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678');
    $meta($ids['form_quote'], '_messages', ['mail_sent_ok' => 'Thank you, we will reply within 24 hours.']);
    $wpml('post_wpcf7_contact_form', $ids['form_quote'], 601, 'en', null);
    $quoteJa = "<label>会社名\n    [text company]</label>\n<label>お名前 *\n    [text* your-name]</label>\n<label>メール *\n    [email* your-email]</label>\n[submit \"送信\"]";
    $ids['form_quote_ja'] = $post(['post_type' => 'wpcf7_contact_form', 'post_title' => 'お見積り依頼', 'post_name' => 'request-a-quote-ja', 'post_content' => $quoteJa]);
    $meta($ids['form_quote_ja'], '_form', $quoteJa);
    $meta($ids['form_quote_ja'], '_messages', ['mail_sent_ok' => 'お問い合わせありがとうございます。']);
    $wpml('post_wpcf7_contact_form', $ids['form_quote_ja'], 601, 'ja', 'en');
    $quoteDe = "<label>Firma\n    [text company]</label>\n<label>Name *\n    [text* your-name]</label>\n<label>E-Mail *\n    [email* your-email]</label>\n[submit \"Senden\"]";
    $ids['form_quote_de'] = $post(['post_type' => 'wpcf7_contact_form', 'post_title' => 'Angebotsanfrage', 'post_name' => 'request-a-quote-de', 'post_content' => $quoteDe]);
    $meta($ids['form_quote_de'], '_form', $quoteDe);
    $meta($ids['form_quote_de'], '_messages', ['mail_sent_ok' => 'Vielen Dank für Ihre Anfrage.']);
    $wpml('post_wpcf7_contact_form', $ids['form_quote_de'], 601, 'de', 'en');
    $ids['page_contact'] = $post(['post_type' => 'page', 'post_title' => 'Contact', 'post_name' => 'contact',
        'post_content' => "Email us.\n\n[contact-form-7 id=\"a1b2c3d\" title=\"Request a Quote\"]\n\n<table><tr><th>Model</th><th>Size</th></tr><tr><td>SE7</td><td>7 in</td></tr></table>"]);
    $wpml('post_page', $ids['page_contact'], 303, 'en', null);

    // WooCommerce：产品分类（父子）、产品标签、属性、图集、SKU
    $pcSlew = $term('Slewing Drive', 'slewing-drive', 'product_cat', 0, 'All slewing drives.');
    $pcWorm = $term('Worm Gear Slew Drive', 'worm-gear-slew-drive-cat', 'product_cat', $pcSlew['term_id']);
    $ptHeavy = $term('heavy duty', 'heavy-duty', 'product_tag');
    $wpml('tax_product_cat', $pcSlew['tt_id'], 401, 'en', null);
    $wpml('tax_product_cat', $pcWorm['tt_id'], 402, 'en', null);
    $wpml('tax_product_tag', $ptHeavy['tt_id'], 403, 'en', null);
    $ids['pc_worm'] = $pcWorm['term_id'];
    $ids['product_drive'] = $post(['post_type' => 'product', 'post_title' => 'Worm Gear Slew Drive SE7', 'post_name' => 'worm-gear-slew-drive',
        'post_excerpt' => 'Compact slew drive for solar trackers.', 'post_content' => '<p>Rated torque 1.5 kN·m.</p>']);
    $meta($ids['product_drive'], '_thumbnail_id', (string) $ids['img_gear']);
    $meta($ids['product_drive'], '_product_image_gallery', $ids['img_gear'] . ',' . $ids['img_ring']);
    $meta($ids['product_drive'], '_sku', 'SE7');
    $meta($ids['product_drive'], '_price', '999');
    $meta($ids['product_drive'], '_product_attributes', ['ratio' => ['name' => 'Ratio', 'value' => '73:1', 'is_visible' => 1, 'is_taxonomy' => 0],
        'output-torque' => ['name' => 'Output torque', 'value' => '1.5 kN·m | 2 kN·m', 'is_visible' => 1, 'is_taxonomy' => 0]]);
    $meta($ids['product_drive'], '_yoast_wpseo_title', 'SE7 Worm Gear Slew Drive %%sep%% %%sitename%%');
    $meta($ids['product_drive'], '_yoast_wpseo_metadesc', 'SE7 slew drive specifications.');
    $relate($ids['product_drive'], $pcWorm);
    $relate($ids['product_drive'], $ptHeavy);
    $wpml('post_product', $ids['product_drive'], 501, 'en', null);

    // 导航菜单：三级以上（第四级并到第三级）、页面 / 产品分类 / 产品 / 自定义链接混排；日文菜单是同一翻译组
    $menuMain = $term('Main Menu', 'main-menu', 'nav_menu');
    $menuJa = $term('メインメニュー', 'main-menu-ja', 'nav_menu');
    $wpml('tax_nav_menu', $menuMain['tt_id'], 701, 'en', null);
    $wpml('tax_nav_menu', $menuJa['tt_id'], 701, 'ja', 'en');
    $opt('stylesheet', 'flatsome-child');
    $opt('theme_mods_flatsome-child', ['nav_menu_locations' => ['primary' => $menuMain['term_id']]]);
    $item = static function (array $menu, string $title, array $m, int $order, int $parent = 0) use ($post, $meta, $relate): int {
        $id = $post(['post_type' => 'nav_menu_item', 'post_title' => $title, 'menu_order' => $order]);
        foreach ($m + ['_menu_item_menu_item_parent' => (string) $parent, '_menu_item_target' => ''] as $key => $value) $meta($id, $key, $value);
        $relate($id, $menu);
        return $id;
    };
    $mAbout = $item($menuMain, '', ['_menu_item_type' => 'post_type', '_menu_item_object' => 'page', '_menu_item_object_id' => (string) $ids['page_about']], 1);
    $item($menuMain, 'Our Team', ['_menu_item_type' => 'post_type', '_menu_item_object' => 'page', '_menu_item_object_id' => (string) $ids['page_team']], 2, $mAbout);
    $mProducts = $item($menuMain, 'Products', ['_menu_item_type' => 'custom', '_menu_item_object' => 'custom', '_menu_item_url' => '#'], 3);
    $mWorm = $item($menuMain, '', ['_menu_item_type' => 'taxonomy', '_menu_item_object' => 'product_cat', '_menu_item_object_id' => (string) $pcWorm['term_id']], 4, $mProducts);
    $mSe7 = $item($menuMain, '', ['_menu_item_type' => 'post_type', '_menu_item_object' => 'product', '_menu_item_object_id' => (string) $ids['product_drive']], 5, $mWorm);
    $item($menuMain, 'SE7 manual', ['_menu_item_type' => 'post_type', '_menu_item_object' => 'post', '_menu_item_object_id' => (string) $ids['post_install']], 6, $mSe7);
    $item($menuMain, 'Contact us', ['_menu_item_type' => 'custom', '_menu_item_object' => 'custom', '_menu_item_url' => 'https://www.slewing-bearing.com/contact/'], 7);
    $item($menuJa, '', ['_menu_item_type' => 'post_type', '_menu_item_object' => 'post', '_menu_item_object_id' => (string) $ids['post_install_ja']], 1);
    $ids['menu_main_tt'] = $menuMain['tt_id'];

    // Advanced Custom Fields：产品字段组（重复器 / 文件 / 链接 / 开关 / 下拉 / 多选 / 关联 / 相册 / 日期 + 跳过的布局与地图字段）、
    // 按产品分类挂载的字段组、产品分类字段组、全站选项页
    $acfGroup = static function (string $title, array $location) use ($post): int {
        return $post(['post_type' => 'acf-field-group', 'post_title' => $title, 'post_excerpt' => strtolower(str_replace(' ', '-', $title)),
            'post_content' => serialize(['location' => $location, 'menu_order' => 0])]);
    };
    $acfField = static function (int $parent, string $name, string $label, array $settings, int $order) use ($post): int {
        return $post(['post_type' => 'acf-field', 'post_parent' => $parent, 'post_excerpt' => $name, 'post_title' => $label,
            'post_name' => 'field_' . substr(md5($parent . $name), 0, 13), 'menu_order' => $order, 'post_content' => serialize($settings)]);
    };
    $gSpecs = $acfGroup('Product specs', [[['param' => 'post_type', 'operator' => '==', 'value' => 'product']]]);
    $fTable = $acfField($gSpecs, 'spec_table', 'Spec table', ['type' => 'repeater', 'button_label' => 'Add model'], 0);
    $acfField($fTable, 'model', 'Model', ['type' => 'text'], 0);
    $acfField($fTable, 'load', 'Axial load (kN)', ['type' => 'number'], 1);
    $acfField($fTable, 'drawing', 'Drawing', ['type' => 'file'], 2);
    $acfField($gSpecs, 'datasheet', 'Datasheet', ['type' => 'file', 'instructions' => 'PDF datasheet'], 1);
    $acfField($gSpecs, 'buy_link', 'Buy link', ['type' => 'link'], 2);
    $acfField($gSpecs, 'certified', 'CE certified', ['type' => 'true_false'], 3);
    $acfField($gSpecs, 'mount', 'Mounting', ['type' => 'select', 'choices' => ['horizontal' => 'Horizontal', 'vertical' => 'Vertical']], 4);
    $acfField($gSpecs, 'finish', 'Finish', ['type' => 'checkbox', 'choices' => ['galvanized' => 'Galvanized', 'painted' => 'Painted']], 5);
    $acfField($gSpecs, 'related_posts', 'Related articles', ['type' => 'relationship', 'post_type' => ['post']], 6);
    $acfField($gSpecs, 'extra_photos', 'Extra photos', ['type' => 'gallery'], 7);
    $acfField($gSpecs, 'release_date', 'Release date', ['type' => 'date_picker'], 8);
    $acfField($gSpecs, 'tab_more', 'More', ['type' => 'tab'], 9);
    $acfField($gSpecs, 'factory_map', 'Factory map', ['type' => 'google_map'], 10);
    $gWorm = $acfGroup('Worm drive extras', [[['param' => 'post_taxonomy', 'operator' => '==', 'value' => 'product_cat:worm-gear-slew-drive-cat']]]);
    $acfField($gWorm, 'torque_curve', 'Torque curve', ['type' => 'image'], 0);
    $gCat = $acfGroup('Category banner', [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'product_cat']]]);
    $acfField($gCat, 'banner_text', 'Banner text', ['type' => 'text'], 0);
    $gOpt = $acfGroup('Company', [[['param' => 'options_page', 'operator' => '==', 'value' => 'acf-options']]]);
    $fCerts = $acfField($gOpt, 'certificates', 'Certificates', ['type' => 'repeater'], 0);
    $acfField($fCerts, 'name', 'Name', ['type' => 'text'], 0);
    $acfField($fCerts, 'image', 'Image', ['type' => 'image'], 1);

    $d = $ids['product_drive'];
    foreach ([
        'spec_table' => '2', '_spec_table' => 'field_spec', 'spec_table_0_model' => 'SE7A', 'spec_table_0_load' => '35', 'spec_table_0_drawing' => (string) $ids['img_ring'],
        'spec_table_1_model' => 'SE7B', 'spec_table_1_load' => '42',
        'datasheet' => (string) $ids['img_gear'],
        'buy_link' => ['title' => 'Buy SE7', 'url' => 'https://shop.example.com/se7', 'target' => '_blank'],
        'certified' => '1', 'mount' => 'vertical', 'finish' => ['galvanized', 'painted'],
        'related_posts' => [(string) $ids['post_install']], 'extra_photos' => [(string) $ids['img_gear'], (string) $ids['img_ring']],
        'release_date' => '20230510', 'torque_curve' => (string) $ids['img_ring'],
    ] as $key => $value) $meta($d, $key, $value);
    $pdo->prepare("INSERT INTO {$p}termmeta (term_id, meta_key, meta_value) VALUES (?, ?, ?)")->execute([$pcWorm['term_id'], 'banner_text', 'Worm drives for solar trackers']);
    $opt('options_certificates', '1');
    $opt('options_certificates_0_name', 'ISO 9001');
    $opt('options_certificates_0_image', (string) $ids['img_ring']);

    // 2.0.5：主题自带的自定义类型（Betheme 作品集），网址前缀被主题改成 portfolio-item；正文在 Muffin 构建器里，SEO 用 Easy WP Meta Description
    $opt('rewrite_rules', ['portfolio-item/([^/]+)(?:/([0-9]+))?/?$' => 'index.php?portfolio=$matches[1]&page=$matches[2]',
        'portfolio-types/([^/]+)/?$' => 'index.php?portfolio-types=$matches[1]', '([^/]+)(?:/([0-9]+))?/?$' => 'index.php?name=$matches[1]&page=$matches[2]']);
    $ptWorks = $term('Customer Projects', 'customer-projects', 'portfolio-types');
    $ids['portfolio_crane'] = $post(['post_type' => 'portfolio', 'post_title' => 'Tower Crane Retrofit', 'post_name' => 'tower-crane-retrofit', 'post_content' => '']);
    $meta($ids['portfolio_crane'], 'mfn-page-items', base64_encode(serialize([['jsclass' => 'section', 'wraps' => [['items' => [
        ['type' => 'visual', 'attr' => ['content' => '<p>Replaced a 2.5 m slewing ring on site.</p>']]]]]]])));
    $meta($ids['portfolio_crane'], '_easy_wp_meta_description', 'Tower crane slewing ring retrofit case.');
    $relate($ids['portfolio_crane'], $ptWorks);
    $ids['event_expo'] = $post(['post_type' => 'tribe_events', 'post_title' => 'Expo 2026', 'post_name' => 'expo-2026']);

    return $ids;
}
