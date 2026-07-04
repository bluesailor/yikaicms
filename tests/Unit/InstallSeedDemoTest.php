<?php
/**
 * 验证安装种子里的演示数据被 @demo 标记正确隔离：
 * 当用户选择"不安装演示数据"时，install/index.php 会用
 *   preg_replace('/--\s*@demo:start.*?--\s*@demo:end/s', '', $sql)
 * 剥离 @demo 区块。本测试确保：
 *   - 标记成对出现（start == end）
 *   - 剥离后演示数据（产品/分类/内容/banner/相册）被清空
 *   - 栏目骨架等基础数据保留
 * mysql.sql 与 sqlite.sql 双驱动均覆盖。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class InstallSeedDemoTest extends TestCase
{
    private function seedPath(string $driver): string
    {
        return dirname(__DIR__, 2) . '/install/sql/' . ($driver === 'sqlite' ? 'sqlite.sql' : 'mysql.sql');
    }

    private function seed(string $driver): string
    {
        return (string) file_get_contents($this->seedPath($driver));
    }

    /** 与 install/index.php 中保持一致的剥离逻辑 */
    private function stripDemo(string $sql): string
    {
        return (string) preg_replace('/--\s*@demo:start.*?--\s*@demo:end/s', '', $sql);
    }

    /** 表名按驱动加引号：mysql 用反引号、sqlite 用双引号 */
    private function insertOf(string $driver, string $table): string
    {
        $q = $driver === 'sqlite' ? '"' : '`';
        return "INSERT INTO {$q}yikai_{$table}{$q}";
    }

    /** @return array<string, array{string}> */
    public static function driverProvider(): array
    {
        return ['mysql' => ['mysql'], 'sqlite' => ['sqlite']];
    }

    /**
     * @dataProvider driverProvider
     */
    public function testDemoMarkersAreBalanced(string $driver): void
    {
        $sql    = $this->seed($driver);
        $starts = substr_count($sql, '@demo:start');
        $ends   = substr_count($sql, '@demo:end');

        $this->assertGreaterThan(0, $starts, "种子应包含 @demo 标记 ({$driver})");
        $this->assertSame($starts, $ends, "@demo:start 与 @demo:end 数量应相等 ({$driver})");
    }

    /**
     * @dataProvider driverProvider
     */
    public function testDemoDataPresentWhenKept(string $driver): void
    {
        $sql = $this->seed($driver);
        // 不剥离时，演示数据应存在
        $this->assertStringContainsString($this->insertOf($driver, 'products'), $sql, "含演示时应有演示产品 ({$driver})");
        $this->assertStringContainsString($this->insertOf($driver, 'contents'), $sql, "含演示时应有演示内容 ({$driver})");
    }

    /**
     * @dataProvider driverProvider
     */
    public function testDemoDataStrippedButScaffoldKept(string $driver): void
    {
        $clean = $this->stripDemo($this->seed($driver));

        // 演示数据被剥离干净
        foreach (['products', 'product_categories', 'contents', 'banners', 'albums', 'album_photos'] as $table) {
            $this->assertStringNotContainsString(
                $this->insertOf($driver, $table),
                $clean,
                "剥离后不应残留演示 {$table} ({$driver})"
            );
        }

        // 栏目骨架保留（不属于演示数据）
        $this->assertStringContainsString(
            $this->insertOf($driver, 'channels'),
            $clean,
            "栏目骨架应保留 ({$driver})"
        );

        // 剥离不应破坏 SQL 结构：标记本身被移除
        $this->assertStringNotContainsString('@demo:start', $clean);
        $this->assertStringNotContainsString('@demo:end', $clean);
    }

    /**
     * 首页 SEO 标题(seo_title)应种子为空——即便安装演示数据也不预填默认标题，
     * 让首页 title 回退到站点名称、由用户自行填写。
     *
     * @dataProvider driverProvider
     */
    public function testHomeSeoTitleSeededEmpty(string $driver): void
    {
        $sql = $this->seed($driver);
        $this->assertStringNotContainsString("'seo_title','YikaiCMS", $sql, "首页SEO标题不应预填 YikaiCMS 默认 ({$driver})");
        $this->assertStringContainsString("'seo_title','',", $sql, "seo_title 应为空值 ({$driver})");
    }

    /**
     * SQLite 种子（含演示数据）能被 install/index.php 的 $pdo->exec($sql) 整体加载。
     * 内存库执行，验证语法可用。
     */
    public function testSqliteSeedLoadsWithDemo(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite 未启用');
        }
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($this->seed('sqlite')); // 不抛异常即通过
        $this->assertSame('1', (string) $pdo->query('SELECT 1')->fetchColumn());
    }

    /**
     * 关键回归防线：SQLite 种子在「不安装演示数据」（剥离 @demo 区块）后仍是合法 SQL。
     * 若 @demo 标记被误插进多行 INSERT 的字符串内容中间，剥离会切断语句，
     * 整体 exec 会报 near "<" —— 本测试正是为拦截这类错位。
     */
    public function testSqliteSeedLoadsWhenDemoStripped(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite 未启用');
        }
        $stripped = $this->stripDemo($this->seed('sqlite'));
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($stripped); // @demo 标记须落在语句边界，否则此处抛异常
        // 骨架保留、演示内容清空
        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM yikai_products')->fetchColumn(), '剥离后不应有演示产品');
        $this->assertGreaterThan(0, (int) $pdo->query('SELECT COUNT(*) FROM yikai_channels')->fetchColumn(), '栏目骨架应保留');
    }
}
