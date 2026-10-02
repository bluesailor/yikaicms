<?php
/**
 * 网址登记表推广到所有内容（2.0.4，WordPress 迁移）：文章、单页 / 栏目、相册、文章标签、产品标签；
 * 结尾斜杠、多层路径、非英文网址；内置页面名可用；删除释放网址；按 path_key 直接查。
 */
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ProductRouteModel;
use Yikai\Tests\TestCase;

#[RunTestsInSeparateProcesses]
final class RouteRegistryKindsTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, lang TEXT DEFAULT "zh-CN", status INTEGER DEFAULT 1, deleted_at INTEGER DEFAULT NULL)',
            'CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT, type TEXT DEFAULT "page", parent_id INTEGER DEFAULT 0, lang TEXT DEFAULT "zh-CN", status INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0)',
            'CREATE TABLE albums (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT, lang TEXT DEFAULT "zh-CN", status INTEGER DEFAULT 1, deleted_at INTEGER DEFAULT NULL)',
            'CREATE TABLE product_tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT, lang TEXT DEFAULT "zh-CN", status INTEGER DEFAULT 1)',
            'CREATE TABLE metas (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT, owner_id INTEGER, meta_key TEXT, meta_value TEXT, created_at INTEGER, updated_at INTEGER)',
            'CREATE TABLE product_routes (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT, entity_id INTEGER, path TEXT, path_key TEXT UNIQUE, UNIQUE(entity_type,entity_id))',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('channelPrettyUrl')) {
            eval('function channelPrettyUrl(array $c): string { return "/" . $c["slug"] . ".html"; }');
        }
    }

    public function testEveryKindRoundTripsWithWordPressStylePaths(): void
    {
        $m = new ProductRouteModel();
        $article = $this->insertRow('contents', ['title' => 'Install', 'slug' => 'install']);
        $page = $this->insertRow('channels', ['name' => 'Team', 'slug' => 'engineer-team']);
        $news = $this->insertRow('channels', ['name' => 'Tech', 'slug' => 'technical-information', 'type' => 'list']);
        $album = $this->insertRow('albums', ['name' => 'Lab', 'slug' => 'lab-equipment']);
        $ptag = $this->insertRow('product_tags', ['name' => 'Worm gear', 'slug' => 'worm-gear']);
        $ctag = $m->contentTagId('worm gear', 'zh-CN');

        $paths = [
            ['content', $article, '/slewing-bearing-installation-procedure/'],
            ['channel', $page, '/about/engineer-team/'],
            ['channel', $news, '/category/resource/technical-information/'],
            ['album', $album, '/albums/lab-equipment/'],
            ['product_tag', $ptag, '/product-tag/worm-gear/'],
            ['content_tag', $ctag, '/tag/worm-gear/'],
        ];
        foreach ($paths as [$kind, $id, $path]) {
            self::assertSame($path, $m->assign($kind, $id, $path, 'zh-CN'), $kind);
            self::assertSame($path, $m->pathFor($kind, $id), $kind);
            $hit = $m->resolve(rtrim($path, '/'));
            self::assertSame([$kind, $id, true, $path], [$hit['kind'], $hit['id'], $hit['active'], $hit['canonical']], $kind);
        }
        self::assertSame('worm gear', $m->resolve('/tag/worm-gear/')['entity']['name']);
        self::assertSame($ctag, $m->contentTagId('worm gear', 'zh-CN'), '同名标签只登记一次');
    }

    public function testListingKindsTakePaginationButDetailsDoNot(): void
    {
        $m = new ProductRouteModel();
        $news = $this->insertRow('channels', ['name' => 'News', 'slug' => 'news', 'type' => 'list']);
        $article = $this->insertRow('contents', ['title' => 'A']);
        $m->assign('channel', $news, '/category/news/', 'zh-CN');
        $m->assign('content', $article, '/some-article/', 'zh-CN');
        self::assertSame('/category/news/page/2/', $m->resolve('/category/news/page/2/')['canonical']);
        self::assertSame(2, $m->resolve('/category/news/page/2')['page']);
        self::assertNull($m->resolve('/some-article/page/2/'));
    }

    public function testBuiltInPageNamesAndFullWidthPunctuationAreAllowed(): void
    {
        $m = new ProductRouteModel();
        $contact = $this->insertRow('channels', ['name' => 'Contact', 'slug' => 'contact-us']);
        $article = $this->insertRow('contents', ['title' => 'Q']);
        $jp = $this->insertRow('channels', ['name' => '品質試験', 'slug' => 'quality', 'lang' => 'zh-CN']);
        self::assertSame('/contact/', $m->assign('channel', $contact, '/contact/', 'zh-CN'), 'WordPress 的 /contact/ 要能保留');
        self::assertSame('/how-does-a-slewing-bearing-work%EF%BC%9F/', $m->assign('content', $article, '/how-does-a-slewing-bearing-work？/', 'zh-CN'));
        self::assertSame($article, $m->resolve('/how-does-a-slewing-bearing-work%ef%bc%9f/')['id'], 'WordPress 输出的小写编码也能命中');
        $path = $m->assign('channel', $jp, '/サービス/旋回ベアリングの品質試験/', 'zh-CN');
        self::assertSame($jp, $m->resolve(rawurldecode($path))['id']);
    }

    #[DataProvider('stillRejected')]
    public function testUnsafeCharactersAndSystemPathsStayRejected(string $path): void
    {
        $id = $this->insertRow('contents', ['title' => 'X']);
        $this->expectException(InvalidArgumentException::class);
        (new ProductRouteModel())->assign('content', $id, $path, 'zh-CN');
    }

    public static function stillRejected(): array
    {
        return array_map(static fn (string $p): array => [$p], [
            '/a b/', '/a?b/', '/a%25b/', "/a'b/", '/a"b/', '/a<b>/', '/a&b/', '/admin/x/', '/install/', '/sitemap/',
            '/uploads/x/', '/x/page/3/', '/.env/',
        ]);
    }

    public function testDeletedOrUnpublishedEntitiesAre404AndRemoveFreesThePath(): void
    {
        $m = new ProductRouteModel();
        $a = $this->insertRow('contents', ['title' => 'A']);
        $b = $this->insertRow('contents', ['title' => 'B']);
        $m->assign('content', $a, '/shared-slug/', 'zh-CN');
        db()->execute('UPDATE contents SET deleted_at = 1 WHERE id = ?', [$a]);
        self::assertFalse($m->resolve('/shared-slug/')['active'], '回收站里的内容是 404');
        try { $m->assign('content', $b, '/shared-slug/', 'zh-CN'); self::fail('冲突未拦'); } catch (InvalidArgumentException $e) {
            self::assertSame('product_url_conflict', $e->getMessage());
        }
        $m->remove('content', $a);
        self::assertSame('/shared-slug/', $m->assign('content', $b, '/shared-slug/', 'zh-CN'), '删除后网址释放给别的条目');
    }

    public function testChannelMaySetItsOwnDefaultHtmlPathButNotAnothersOne(): void
    {
        $m = new ProductRouteModel();
        $a = $this->insertRow('channels', ['name' => 'A', 'slug' => 'about']);
        $b = $this->insertRow('channels', ['name' => 'B', 'slug' => 'brand']);
        self::assertSame('/about.html', $m->assign('channel', $a, '/about.html', 'zh-CN'));
        $this->expectException(InvalidArgumentException::class);
        $m->assign('channel', $b, '/about.html', 'zh-CN');
    }
}
