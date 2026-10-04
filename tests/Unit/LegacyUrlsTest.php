<?php
/** 旧站地址兜底（2.0.4，WordPress 迁移）：?s= 搜索、?p= 等按 WordPress ID 找新地址、feed / 作者 / 附件 / 日期归档。 */
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use LegacyUrls;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Yikai\Tests\TestCase;

#[RunTestsInSeparateProcesses]
final class LegacyUrlsTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE metas (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT, owner_id INTEGER, meta_key TEXT, meta_value TEXT, created_at INTEGER, updated_at INTEGER)',
            'CREATE UNIQUE INDEX uk_owner_key_metas ON metas (owner_type, owner_id, meta_key)',
            'CREATE TABLE product_routes (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT, entity_id INTEGER, path TEXT, path_key TEXT UNIQUE, UNIQUE(entity_type,entity_id))',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT_PATH . '/includes/Redirects.php';
        require_once ROOT_PATH . '/includes/LegacyUrls.php';
        require_once __DIR__ . '/../fixtures/legacy-urls-stubs.php';
    }

    public function testSearchQueryGoesToTheSearchPageOnAnySite(): void
    {
        self::assertSame('/search.php?keyword=slewing%20ring', LegacyUrls::homeTarget(['s' => ' slewing ring '], false));
        self::assertNull(LegacyUrls::homeTarget(['s' => '   '], false));
        self::assertNull(LegacyUrls::homeTarget(['p' => '12'], false), '没导入过 WordPress 的站点不接 ?p=');
        self::assertNull(LegacyUrls::homeTarget(['utm_source' => 'x'], true));
    }

    public function testWordPressIdsResolveToTheImportedContent(): void
    {
        $routes = new \ProductRouteModel();
        $routes->assign('content', 7, '/worm-gear-guide/', 'zh-CN');
        $routes->assign('channel', 3, '/about-us/', 'zh-CN');
        $routes->assign('product', 9, '/product/sb-200/', 'zh-CN');
        db()->insert('metas', ['owner_type' => 'wp_import', 'owner_id' => 120, 'meta_key' => 'post', 'meta_value' => '7', 'created_at' => 0, 'updated_at' => 0]);
        db()->insert('metas', ['owner_type' => 'wp_import', 'owner_id' => 55, 'meta_key' => 'page', 'meta_value' => '3', 'created_at' => 0, 'updated_at' => 0]);
        db()->insert('metas', ['owner_type' => 'wp_import', 'owner_id' => 300, 'meta_key' => 'product', 'meta_value' => '9', 'created_at' => 0, 'updated_at' => 0]);

        self::assertSame('/worm-gear-guide/', LegacyUrls::homeTarget(['p' => '120'], true));
        self::assertSame('/product/sb-200/', LegacyUrls::homeTarget(['p' => '300', 'post_type' => 'product'], true), '?p= 依次试文章、产品、页面');
        self::assertSame('/about-us/', LegacyUrls::homeTarget(['page_id' => '55'], true));
        self::assertNull(LegacyUrls::homeTarget(['p' => '999'], true), '没导入的 ID 照常显示首页');
        self::assertNull(LegacyUrls::homeTarget(['p' => '12abc'], true));
        self::assertSame('/', LegacyUrls::homeTarget(['attachment_id' => '5'], true));
    }

    public function testWordPressPathPatterns(): void
    {
        $cases = [
            '/feed/' => '/', '/comments/feed/' => '/', '/worm-gear-guide/feed/' => '/worm-gear-guide/',
            '/category/news/feed/rss2/' => '/category/news/', '/author/admin/' => '/', '/author/admin/page/2/' => '/',
            '/about-us/attachment/team-photo/' => '/about-us/', '/2024/05/' => '/', '/2024/05/12/' => '/', '/2024/page/3/' => '/',
            '/page/2/' => '/', '/en/feed/' => '/en/', '/en/products/feed/' => '/en/products/',
            '/products/' => null, '/product/feeder-gear/' => null, '/2024-catalog/' => null,
        ];
        foreach ($cases as $path => $expected) {
            self::assertSame($expected, LegacyUrls::wordpressPathTarget($path), $path);
        }
    }
}
