<?php
/**
 * WordPress 导入：读库（核心 / WooCommerce / Yoast / WPML）与固定链接还原。
 */
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use WordPressPermalinks;
use WordPressSource;

require_once ROOT_PATH . '/includes/migrate/WordPressSource.php';
require_once ROOT_PATH . '/includes/migrate/WordPressPermalinks.php';
require_once ROOT_PATH . '/tests/fixtures/wordpress-fixture.php';

final class WordPressSourceTest extends TestCase
{
    public function testReadsPostsMetaTermsAttachmentsAndWpml(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $ids = wordPressFixture($pdo);
        $src = new WordPressSource($pdo);
        self::assertTrue($src->looksLikeWordPress());

        $posts = array_column($src->posts(['post']), 'ID');
        self::assertContains($ids['post_install'], array_map('intval', $posts));
        self::assertNotContains($ids['post_draft'], array_map('intval', $posts), '草稿不读');

        $meta = $src->meta([$ids['product_drive']]);
        self::assertSame('SE7', $meta[$ids['product_drive']]['_sku']);
        self::assertSame('/wp-content/uploads/2023/05/ring.png', $src->attachments()[$ids['img_ring']]);

        $cats = $src->terms(['category']);
        $tech = array_values(array_filter($cats, static fn (array $t): bool => $t['slug'] === 'technical-information'))[0];
        self::assertSame($ids['cat_tech'], $tech['term_id']);
        self::assertGreaterThan(0, $tech['parent'], '父分类按 term_id 给出');

        $tr = $src->translations();
        self::assertSame(['trid' => 201, 'lang' => 'ja', 'source' => 'en'], $tr['post_post:' . $ids['post_install_ja']]);
        self::assertSame('en', $src->defaultLanguage());
        self::assertSame(['ja' => 'ja.slewing-bearing.com'], $src->languageDomains());
        self::assertSame(['www.slewing-bearing.com', 'slewing-bearing.com'], $src->hosts());
        self::assertSame('product-category', $src->optionArray('woocommerce_permalinks')['category_base']);
    }

    public function testRejectsSerializedObjects(): void
    {
        self::assertSame([], WordPressSource::unserializeArray('O:8:"stdClass":0:{}'));
        self::assertSame(['a' => 1], WordPressSource::unserializeArray(serialize(['a' => 1])));
    }

    public function testPermalinksFollowTheSiteStructure(): void
    {
        $links = new WordPressPermalinks('/%postname%/', '', '', ['product_base' => '/product/', 'category_base' => 'product-category', 'tag_base' => 'product-tag']);
        self::assertSame('/slewing-bearing-installation-procedure/', $links->post(['ID' => 7, 'post_name' => 'slewing-bearing-installation-procedure', 'post_date' => '2023-05-10 09:00:00']));
        self::assertSame('/about/engineer-team/', $links->page('engineer-team', ['about']));
        self::assertSame('/product/worm-gear-slew-drive/', $links->product('worm-gear-slew-drive'));
        self::assertSame('/category/resource/technical-information/', $links->term('category', 'resource/technical-information'));
        self::assertSame('/tag/worm-gear/', $links->term('post_tag', 'worm-gear'));
        self::assertSame('/product-category/slewing-drive/worm/', $links->term('product_cat', 'slewing-drive/worm'));
        self::assertSame('/product-tag/heavy-duty/', $links->term('product_tag', 'heavy-duty'));

        $dated = new WordPressPermalinks('/%year%/%monthnum%/%postname%.html', 'topics', 'label', ['product_base' => '/shop/%product_cat%/']);
        self::assertSame('/2023/05/hello.html', $dated->post(['ID' => 9, 'post_name' => 'hello', 'post_date' => '2023-05-10 09:00:00']));
        self::assertSame('/topics/news', $dated->term('category', 'news'));
        self::assertSame('/shop/drives/se7', $dated->product('se7', 'drives'));
        self::assertSame('/news/9/', (new WordPressPermalinks('/%category%/%post_id%/'))->post(['ID' => 9, 'post_name' => 'x', 'post_date' => ''], 'news'));
    }

    public function testPlainPermalinksCannotBeKept(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WordPressPermalinks('');
    }
}
