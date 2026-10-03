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
    $ids['page_contact'] = $post(['post_type' => 'page', 'post_title' => 'Contact', 'post_name' => 'contact', 'post_content' => "Email us.\n\n[contact-form-7 id=\"5\"]"]);
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

    return $ids;
}
