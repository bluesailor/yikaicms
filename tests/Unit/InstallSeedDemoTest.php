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
    public function testSeedIsValidUtf8WithoutReplacementCharacters(string $driver): void
    {
        $sql = $this->seed($driver);

        $this->assertSame(1, preg_match('//u', $sql), "安装种子必须是合法 UTF-8 ({$driver})");
        $this->assertStringNotContainsString(
            "\xEF\xBF\xBD",
            $sql,
            "安装种子不得包含 Unicode 替换字符 U+FFFD ({$driver})"
        );
    }

    public function testJsonSeedSourcesAreValidUtf8WithoutReplacementCharacters(): void
    {
        $paths = glob(dirname(__DIR__, 2) . '/install/seed_data_*.json') ?: [];
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $seed = (string) file_get_contents($path);
            $name = basename($path);
            $this->assertSame(1, preg_match('//u', $seed), "JSON 种子必须是合法 UTF-8 ({$name})");
            $this->assertStringNotContainsString(
                "\xEF\xBF\xBD",
                $seed,
                "JSON 种子不得包含 Unicode 替换字符 U+FFFD ({$name})"
            );
        }
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
        foreach (['products', 'product_categories', 'contents', 'albums', 'album_photos'] as $table) {
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

        // 轮播 banners 自 v1.18.8 起属于骨架而非演示数据：首页 Blox 文档的 banner
        // 区块改为 items_mode=inherit 从 banners 表按语言取数（修英文/日文安装站
        // 首页显示中文），不勾演示也必须有各语言的初始轮播行可渲染、可管理。
        $this->assertStringContainsString(
            $this->insertOf($driver, 'banners'),
            $clean,
            "多语言轮播骨架应保留 ({$driver})"
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
     * 新安装的公司简介 FAQ 应直接使用结构化数据，不能再回退为竖线分隔文本。
     *
     * @dataProvider driverProvider
     */
    public function testCompanyFaqUsesStructuredItems(string $driver): void
    {
        $sql = $this->seed($driver);
        $this->assertStringContainsString(
            '"items":[{"question":"能否针对我们的产线做定制开发？","answer":',
            $sql,
            "公司简介 FAQ 应使用 question/answer 数组 ({$driver})"
        );
        $this->assertStringNotContainsString(
            '"items":"能否针对我们的产线做定制开发？|',
            $sql,
            "公司简介 FAQ 不应继续使用竖线分隔格式 ({$driver})"
        );
    }

    /**
     * 首页 Banner 的三个演示项必须同时提供中、英、日版本，避免新安装站切换语言后为空或回退中文。
     */
    public function testBannerDemoIncludesEnglishAndJapaneseTranslations(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite 未启用');
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($this->seed('sqlite'));

        $rows = $pdo->query(
            "SELECT translation_group_id, lang, title, subtitle FROM yikai_banners WHERE position = 'home' ORDER BY translation_group_id, lang"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(9, $rows);
        $byGroup = [];
        foreach ($rows as $row) {
            $byGroup[(int) $row['translation_group_id']][(string) $row['lang']] = $row;
        }

        $this->assertSame([1, 2, 3], array_keys($byGroup));
        foreach ($byGroup as $group) {
            $this->assertSame(['en', 'ja', 'zh-CN'], array_keys($group));
            $this->assertNotSame('', trim((string) $group['en']['title']));
            $this->assertNotSame('', trim((string) $group['en']['subtitle']));
            $this->assertNotSame('', trim((string) $group['ja']['title']));
            $this->assertNotSame('', trim((string) $group['ja']['subtitle']));
        }

        $this->assertSame('Expert Technology Team', $byGroup[2]['en']['title']);
        $this->assertSame('経験豊富な技術チーム', $byGroup[2]['ja']['title']);
    }

    public function testJobDemoStructuredFieldsAreLocalized(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite 未启用');
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($this->seed('sqlite'));
        $rows = $pdo->query(
            "SELECT lang, translation_group_id, summary, education, experience, requirements"
            . " FROM yikai_jobs WHERE lang IN ('en', 'ja') ORDER BY lang, translation_group_id"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $serialized = implode(' ', $row);
            $this->assertStringNotContainsString('本科', $serialized);
            $this->assertStringNotContainsString('熟悉', $serialized);
            $this->assertStringNotContainsString('负责公司', $serialized);
        }
        $this->assertSame('Bachelor degree', $rows[0]['education']);
        $this->assertSame('3+ years', $rows[0]['experience']);
        $this->assertSame('大卒', $rows[2]['education']);
    }

    public function testProductRootSlugsFollowLanguageConvention(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite 未启用');
        }

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec($this->seed('sqlite'));
        $statement = $pdo->prepare(
            'SELECT lang, slug FROM yikai_channels WHERE translation_group_id = ? AND parent_id = 0 ORDER BY lang'
        );
        $statement->execute([5]);
        $slugs = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $slugs[(string) $row['lang']] = (string) $row['slug'];
        }

        $this->assertSame('product', $slugs['zh-CN']);
        $this->assertSame('product-en', $slugs['en']);
        $this->assertSame('product-ja', $slugs['ja']);
    }

    /**
     * 中文联系页不能借用英文说明作为基础值，否则 zh-CN 会直接显示英文。
     *
     * @dataProvider driverProvider
     */
    public function testChineseContactFormDescriptionIsLocalized(string $driver): void
    {
        $sql = $this->seed($driver);
        $this->assertStringContainsString(
            "'contact_form_desc','给我们留言，我们会尽快与您联系。',",
            $sql,
            "中文联系表单说明应写入中文 ({$driver})"
        );
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

        foreach ([
            26 => ['lang' => 'en', 'marker' => 'Frequently asked questions'],
            51 => ['lang' => 'ja', 'marker' => 'よくある質問'],
        ] as $contentId => $expected) {
            $statement = $pdo->prepare(
                'SELECT lang, content_type, blocks_data FROM yikai_contents WHERE id = ? LIMIT 1'
            );
            $statement->execute([$contentId]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            $this->assertIsArray($row);
            $this->assertSame($expected['lang'], $row['lang']);
            $this->assertSame('blocks', $row['content_type']);
            $document = json_decode((string) $row['blocks_data'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(4, $document);
            $this->assertStringContainsString($expected['marker'], (string) $row['blocks_data']);
            $faqItems = $document[2]['columns'][0]['elements'][0]['data']['items'] ?? null;
            $this->assertIsArray($faqItems);
            $this->assertSame(['question', 'answer'], array_keys($faqItems[0]));
        }
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

    public function testSqliteBaselineFixtureIsValidUtf8WithoutReplacementCharacters(): void
    {
        $path = dirname(__DIR__) . '/fixtures/schema-baseline-sqlite.sql';
        $sql = (string) file_get_contents($path);

        $this->assertSame(1, preg_match('//u', $sql), 'SQLite 基线夹具必须是合法 UTF-8');
        $this->assertStringNotContainsString(
            "\xEF\xBF\xBD",
            $sql,
            'SQLite 基线夹具不得包含 Unicode 替换字符 U+FFFD'
        );
        $this->assertStringContainsString('プロジェクト', $sql);
        $this->assertStringContainsString('導入プロセス', $sql);
    }
}
