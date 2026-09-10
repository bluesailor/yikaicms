<?php
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use InvalidArgumentException;
use ProductRouteModel;
use Yikai\Tests\TestCase;

final class ProductRouteModelTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, slug TEXT, lang TEXT, status INTEGER, deleted_at INTEGER DEFAULT NULL)',
            'CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT, lang TEXT, status INTEGER)',
            'CREATE TABLE product_routes (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT, entity_id INTEGER, path TEXT, path_key TEXT UNIQUE, UNIQUE(entity_type,entity_id))',
        ];
    }

    protected function tearDown(): void
    {
        productRouteModel()->clearCache();
        $this->resetDatabase();
        parent::tearDown();
    }

    public function testPathsRoundTripAndDefaultStaysEmpty(): void
    {
        $m = new ProductRouteModel();
        $id = $m->saveEntity('product', 0, ['title' => 'GD60', 'lang' => 'zh-CN', 'status' => 1], '/footmaster/GD-60/');
        self::assertSame('/footmaster/GD-60/', $m->pathFor('product', $id));
        self::assertSame($id, $m->resolve('/footmaster/GD-60')['id']);
        self::assertSame('/footmaster/GD-60/', $m->resolve('/footmaster/GD-60')['canonical']);
        self::assertNull($m->resolve('/footmaster/gd-60/'));
        $m->saveEntity('product', $id, ['title' => 'Updated'], '');
        self::assertSame('', $m->pathFor('product', $id));
        self::assertNull($m->resolve('/footmaster/GD-60/'));
    }

    public function testCategoryPaginationAndProductWithNoSuffix(): void
    {
        $m = new ProductRouteModel();
        $id = $m->saveEntity('category', 0, ['name' => 'Medical', 'slug' => 'medical', 'lang' => 'zh-CN', 'status' => 1], '/products/medical/');
        $hit = $m->resolve('/products/medical/page/2/');
        self::assertSame($id, $hit['id']);
        self::assertSame(2, $hit['page']);
        self::assertSame('/products/medical/page/2/', $hit['canonical']);
        $p = $m->saveEntity('product', 0, ['lang' => 'zh-CN', 'status' => 1], '/brand/gd-60');
        self::assertSame('/brand/gd-60', $m->pathFor('product', $p));
        self::assertNull($m->resolve('/brand/gd-60/page/2/'));
        self::assertSame('/product/gd-60/', $m->validate('product', $p, '/product/gd-60/', 'zh-CN'));
        self::assertSame('/product-category/medical/', $m->validate('category', $id, '/product-category/medical/', 'zh-CN'));
    }

    public function testConflictLeavesEntityAndOldRouteIntact(): void
    {
        $m = new ProductRouteModel();
        $p = $m->saveEntity('product', 0, ['title' => 'Original', 'lang' => 'zh-CN', 'status' => 1], '/old-product/');
        $m->saveEntity('category', 0, ['lang' => 'zh-CN', 'status' => 1], '/shared/');
        try { $m->saveEntity('product', $p, ['title' => 'Wrong'], '/shared'); self::fail('Conflict accepted'); }
        catch (InvalidArgumentException $e) { self::assertSame('product_url_conflict', $e->getMessage()); }
        self::assertSame('Original', productModel()->find($p)['title']);
        self::assertSame('/old-product/', $m->pathFor('product', $p));
    }

    public function testDatabaseFailureRollsBackContentAndRoute(): void
    {
        $m = new ProductRouteModel();
        $p = $m->saveEntity('product', 0, ['title' => 'Original', 'lang' => 'zh-CN', 'status' => 1], '/before/');
        db()->execute("CREATE TRIGGER deny_route BEFORE INSERT ON product_routes BEGIN SELECT RAISE(ABORT, 'fixture rejection'); END");
        try { $m->saveEntity('product', $p, ['title' => 'Wrong'], '/after/'); self::fail('Write accepted'); }
        catch (\PDOException|InvalidArgumentException $e) { self::assertNotSame('', $e->getMessage()); }
        self::assertSame('Original', productModel()->find($p)['title']);
        self::assertSame('/before/', $m->pathFor('product', $p));
    }

    public function testLanguageStatusAndUnicode(): void
    {
        $m = new ProductRouteModel();
        $p = $m->saveEntity('product', 0, ['lang' => 'ja', 'status' => 1], '/ja/製品/足/');
        self::assertSame('ja', $m->resolve('/ja/製品/足/')['lang']);
        self::assertSame($m->resolve('/ja/製品/足/'), $m->resolve('/ja/%E8%A3%BD%E5%93%81/%E8%B6%B3/'));
        productModel()->updateById($p, ['status' => 0]);
        self::assertFalse($m->resolve('/ja/製品/足/')['active']);
        productModel()->deleteById($p);
        self::assertFalse($m->resolve('/ja/製品/足/')['active']);
        $this->expectExceptionMessage('product_url_language');
        $m->saveEntity('category', 0, ['lang' => 'ja'], '/products/japanese/');
    }

    /** @dataProvider invalidPaths */
    public function testUnsafeAndReservedPathsAreRejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProductRouteModel())->validate('product', 0, $path, 'zh-CN');
    }

    public static function invalidPaths(): array
    {
        return array_map(static fn(string $path): array => [$path], [
            'https://example.com/x/', '//evil.test/x', '/a/../b/', '/a/%2e%2e/b/', '/a/%252e%252e/',
            '/foo?x=1', '/foo#bar', '/foo.php', '/admin/login/', '/api/v1/foo/', '/storage/a/',
            '/product/old.html', '/foo/page/2/', '/foo\\bar/', '/.git/config', "/x\r\ny/", '/photo.png',
        ]);
    }

    public function testMissingRegistryDoesNotBreakLegacyReadsOrSaves(): void
    {
        db()->execute('DROP TABLE product_routes');
        $m = new ProductRouteModel();
        self::assertSame('', $m->pathFor('product', 1));
        self::assertNull($m->resolve('/products/medical/'));
        self::assertGreaterThan(0, $m->saveEntity('product', 0, ['lang' => 'zh-CN'], ''));
        $this->expectExceptionMessage('product_url_upgrade');
        $m->saveEntity('product', 0, ['lang' => 'zh-CN'], '/new/');
    }
}
