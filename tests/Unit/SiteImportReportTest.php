<?php
/**
 * 导入完成报告（E04）的判定契约。
 *
 * 重点不是"能出报告"，而是两条容易做错的判定：
 *   1. 分级——结构性问题必须是 failed，其余归 warning，不能把待办说成失败；
 *   2. 外来域名残留的判据必须收得够紧，否则普通外链会把报告淹没，报告就没人看了。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SiteImportReport;

require_once ROOT_PATH . '/includes/SiteImportReport.php';

final class SiteImportReportTest extends TestCase
{
    /** @return list<string> */
    private function foreignLinks(mixed $value, string $host): array
    {
        $method = new ReflectionMethod(SiteImportReport::class, 'foreignAssetLinks');
        $method->setAccessible(true);
        /** @var list<string> $result */
        $result = $method->invoke(null, $value, $host);
        return $result;
    }

    /** 指向别站的 /uploads/ 与 /themes/ 地址是残留，必须报出来。 */
    public function testForeignAssetLinksAreReported(): void
    {
        self::assertSame(
            ['https://author-demo.test/uploads/hero.jpg'],
            $this->foreignLinks('<img src="https://author-demo.test/uploads/hero.jpg">', 'client.test')
        );
        self::assertSame(
            ['http://old.example.com/themes/pet/banner.png'],
            $this->foreignLinks('background:url(http://old.example.com/themes/pet/banner.png)', 'client.test')
        );
    }

    /** 本站地址与普通外链都不是残留——误报会让报告失去价值。 */
    public function testOwnHostAndOrdinaryOutboundLinksAreNotReported(): void
    {
        self::assertSame([], $this->foreignLinks('<img src="https://client.test/uploads/hero.jpg">', 'client.test'),
            '本站绝对地址不是残留');
        self::assertSame([], $this->foreignLinks('<img src="/uploads/hero.jpg">', 'client.test'),
            '相对地址本来就是正确形态');
        self::assertSame([], $this->foreignLinks('<a href="https://partner.test/about">伙伴</a>', 'client.test'),
            '普通外链是内容的一部分，不得当成残留');
        self::assertSame([], $this->foreignLinks('<a href="https://weibo.com/yikai">微博</a>', 'client.test'),
            '社交链接同理');
    }

    /** JSON 形态的字段（Blox 文档、设置项）也要能穿透查出来。 */
    public function testLinksInsideJsonFieldsAreFound(): void
    {
        $json = json_encode(['blocks' => [['type' => 'image', 'src' => 'https://author-demo.test/uploads/a.png']]]);
        self::assertSame(['https://author-demo.test/uploads/a.png'], $this->foreignLinks($json, 'client.test'));
    }

    /** 分级：有 failed 即 failed；只有 warning 即 warning；都没有才 ok。 */
    public function testStatusFollowsTheWorstLevelPresent(): void
    {
        $push = new ReflectionMethod(SiteImportReport::class, 'push');
        $push->setAccessible(true);

        $items = [];
        $counts = [SiteImportReport::FAILED => 0, SiteImportReport::WARNING => 0];
        $push->invokeArgs(null, [&$items, &$counts, ['level' => SiteImportReport::WARNING, 'code' => 'x']]);
        self::assertSame(1, $counts[SiteImportReport::WARNING]);
        self::assertSame(0, $counts[SiteImportReport::FAILED]);

        $push->invokeArgs(null, [&$items, &$counts, ['level' => SiteImportReport::FAILED, 'code' => 'y']]);
        self::assertSame(1, $counts[SiteImportReport::FAILED]);
        self::assertCount(2, $items);
    }

    /** 每一级都有上限：内容极多的站点不能把整页刷满。 */
    public function testEachLevelIsBounded(): void
    {
        $push = new ReflectionMethod(SiteImportReport::class, 'push');
        $push->setAccessible(true);
        $items = [];
        $counts = [SiteImportReport::FAILED => 0, SiteImportReport::WARNING => 0];
        for ($i = 0; $i < 500; $i++) {
            $push->invokeArgs(null, [&$items, &$counts, ['level' => SiteImportReport::WARNING, 'code' => 'x']]);
        }
        self::assertLessThan(500, count($items), '必须有上限');
        self::assertGreaterThan(0, count($items));
    }
}
