<?php
/**
 * 301/302 跳转（核心免费版，2.0.4）：存在网址登记表 + metas，不加表；规范化与冲突检查与自定义网址共用。
 */
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ProductRouteModel;
use Redirects;
use Yikai\Tests\TestCase;

#[RunTestsInSeparateProcesses]
final class RedirectsTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, lang TEXT DEFAULT "zh-CN", status INTEGER DEFAULT 1, deleted_at INTEGER DEFAULT NULL)',
            'CREATE TABLE metas (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT, owner_id INTEGER, meta_key TEXT, meta_value TEXT, created_at INTEGER, updated_at INTEGER)',
            // 与正式库一致的唯一索引：每个标签 / 跳转的 meta_key 必须不同（漏了它测试曾放过「每种语言只能存一个标签」）
            'CREATE UNIQUE INDEX uk_owner_key_metas ON metas (owner_type, owner_id, meta_key)',
            'CREATE TABLE product_routes (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT, entity_id INTEGER, path TEXT, path_key TEXT UNIQUE, UNIQUE(entity_type,entity_id))',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT_PATH . '/includes/Redirects.php';
    }

    public function testSavedRedirectResolvesThroughTheRegistry(): void
    {
        $id = Redirects::save(0, '/old-product/gear', '/product/worm-gear/', 301);
        $hit = (new ProductRouteModel())->resolve('/old-product/gear/');
        self::assertSame(['redirect', $id, true], [$hit['kind'], $hit['id'], $hit['active']]);
        self::assertSame('/product/worm-gear/', $hit['entity']['target']);
        self::assertSame(301, $hit['entity']['code']);
        self::assertSame($id, Redirects::idForSource('/old-product/gear'));
    }

    public function testEditingKeepsTheIdAndChangesTargetAndCode(): void
    {
        $id = Redirects::save(0, '/a/', '/b/', 301);
        self::assertSame($id, Redirects::save($id, '/a-renamed/', 'https://example.com/x?y=1', 302));
        self::assertNull((new ProductRouteModel())->resolve('/a/'), '旧地址改了就释放');
        $row = Redirects::list()[0];
        self::assertSame(['/a-renamed/', 'https://example.com/x?y=1', 302], [$row['source'], $row['target'], $row['code']]);
    }

    public function testSourceTakenByContentIsAConflictAndNothingIsWritten(): void
    {
        $content = $this->insertRow('contents', ['title' => 'Live']);
        (new ProductRouteModel())->assign('content', $content, '/live-page/', 'zh-CN');
        try {
            Redirects::save(0, '/live-page/', '/elsewhere/');
            self::fail('冲突未拦');
        } catch (InvalidArgumentException $e) {
            self::assertSame('product_url_conflict', $e->getMessage());
        }
        self::assertSame(0, (int) db()->fetchColumn("SELECT COUNT(*) FROM metas WHERE owner_type = 'redirect'"), '失败不留孤儿目标行');
    }

    #[DataProvider('badTargets')]
    public function testUnsafeTargetsAreRejected(string $target): void
    {
        $this->expectExceptionMessage('redirect_target_invalid');
        Redirects::save(0, '/from/', $target);
    }

    public static function badTargets(): array
    {
        return array_map(static fn (string $t): array => [$t], ['', 'javascript:alert(1)', '//evil.example', 'relative/path',
            "/a\r\nSet-Cookie: x", '/a b', '/\\evil.example', 'ftp://example.com/']);
    }

    public function testLoopsAreRejected(): void
    {
        try {
            Redirects::save(0, '/same/', '/same');
            self::fail('自跳未拦');
        } catch (InvalidArgumentException $e) {
            self::assertSame('redirect_loop', $e->getMessage());
        }
        Redirects::save(0, '/one/', '/two/');
        $this->expectExceptionMessage('redirect_loop');
        Redirects::save(0, '/two/', '/one/');
    }

    public function testReservedSourcesStayRejected(): void
    {
        $this->expectExceptionMessage('product_url_reserved');
        Redirects::save(0, '/admin/login/', '/');
    }

    public function testImportSavesGoodLinesUpdatesExistingAndReportsBadOnes(): void
    {
        Redirects::save(0, '/kept/', '/old-target/');
        $result = Redirects::import(implode("\n", [
            '# 注释行',
            '/kept/ /new-target/',
            '/tab-separated/' . "\t" . '/x/' . "\t" . '302',
            '/comma/,https://example.com/',
            '',
            '/bad-target/ javascript:x',
            '/too/ many /fields/ 301',
            '/bad-code/ /x/ 307',
        ]));
        self::assertSame(3, $result['saved']);
        self::assertSame([6, 7, 8], array_column($result['errors'], 'line'));
        self::assertSame(['redirect_target_invalid', 'redirect_import_line_invalid', 'redirect_import_line_invalid'], array_column($result['errors'], 'error'));
        self::assertSame(3, Redirects::count());
        self::assertSame('/new-target/', Redirects::find(Redirects::idForSource('/kept/'))['target'], '已有的旧地址改成新目标');
        self::assertSame(302, Redirects::find(Redirects::idForSource('/tab-separated/'))['code']);
    }

    public function testSearchMatchesSourceTargetAndEncodedSource(): void
    {
        Redirects::save(0, '/how-does-it-work？/', '/faq/');
        Redirects::save(0, '/other/', '/blog/worm/');
        self::assertCount(1, Redirects::list('worm'));
        self::assertCount(1, Redirects::list('work？'));
        self::assertSame(1, Redirects::count('faq'));
        self::assertSame(0, Redirects::count('100%'));
    }

    public function testDeleteFreesTheSourceAndRemovesTheTarget(): void
    {
        $id = Redirects::save(0, '/gone/', '/here/');
        Redirects::delete($id);
        self::assertNull(Redirects::find($id));
        self::assertNull((new ProductRouteModel())->resolve('/gone/'));
        $content = $this->insertRow('contents', ['title' => 'New owner']);
        self::assertSame('/gone/', (new ProductRouteModel())->assign('content', $content, '/gone/', 'zh-CN'));
    }
}
