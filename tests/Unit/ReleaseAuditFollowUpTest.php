<?php
/**
 * 2026-09-17 发版前审计 F05–F08 的回归守卫。
 *
 * F05 旧下载入口忽略发布状态；F06 安装器把双引号写成 \"；
 * F07 SQLite LIKE 未转义导致演示 SEO 标题残留；F08 取消演示后仍装示例业务数据。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PDO;
use Yikai\Tests\TestCase;

final class ReleaseAuditFollowUpTest extends TestCase
{
    /** @return list<string> */
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT DEFAULT "download",
                title TEXT,
                attachment TEXT DEFAULT "",
                channel_id INTEGER DEFAULT 0,
                lang TEXT DEFAULT "zh-CN",
                status INTEGER DEFAULT 1,
                deleted_at INTEGER NULL
            )',
            'CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, slug TEXT, type TEXT)',
        ];
    }

    private function source(string $relative): string
    {
        $path = ROOT_PATH . '/' . $relative;
        self::assertFileExists($path);
        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    /** F05：旧 id 入口必须与新入口同一套可下载判定。 */
    public function testLegacyDownloadEntryOnlyServesPublishedContent(): void
    {
        self::assertStringContainsString(
            '$content = contentModel()->getPublished($id);',
            $this->source('download.php')
        );

        db()->getPdo()->exec("INSERT INTO contents (id, type, title, attachment, status, deleted_at) VALUES
            (1, 'download', '已发布', '/uploads/ok.pdf', 1, NULL),
            (2, 'download', '草稿',   '/uploads/draft.pdf', 0, NULL),
            (3, 'download', '定时',   '/uploads/later.pdf', 3, NULL),
            (4, 'download', '回收站', '/uploads/gone.pdf', 1, 1789000000)");

        self::assertSame('已发布', contentModel()->getPublished(1)['title'] ?? null);
        foreach ([2, 3, 4] as $hidden) {
            self::assertNull(contentModel()->getPublished($hidden), "#{$hidden} 不该对外可下载");
        }
    }

    /** F06：生成的 config.php 必须原样往返，双引号不能被写成 \" 。 */
    public function testInstallerConfigEscapingRoundTrips(): void
    {
        $installer = $this->source('install/index.php');
        self::assertStringNotContainsString('addslashes((string)$v)', $installer);
        self::assertStringContainsString(
            '$esc = static fn ($v): string => str_replace([\'\\\\\', "\'"], [\'\\\\\\\\\', "\\\\\'"], (string) $v);',
            $installer
        );

        // 用安装器同款转义生成单引号字面量，再让 PHP 解析回来
        $esc = static fn ($v): string => str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $v);
        foreach ([
            'Audit "Double" Quote',
            "O'Reilly & Co",
            'back\\slash',
            'mixed "\'\\ 中文站点',
            'audit"doublequote',
        ] as $value) {
            $literal = "'" . $esc($value) . "'";
            $parsed = eval('return ' . $literal . ';');
            self::assertSame($value, $parsed, '配置字面量往返不相等：' . $value);
        }
    }

    /** F07：清除演示 SEO 标题的语句在 SQLite 上也要真的删掉。 */
    public function testDemoSeoTitlesAreRemovedOnSqlite(): void
    {
        self::assertStringContainsString(
            "SUBSTR(`key`, 1, 10) = 'seo_title_'",
            $this->source('install/index.php')
        );
        self::assertStringNotContainsString("LIKE 'seo\\_title\\_%'", $this->source('install/index.php'));

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "key" TEXT, "value" TEXT)');
        $pdo->exec("INSERT INTO settings (\"key\", \"value\") VALUES
            ('seo_title', 'Release Audit ja'),
            ('seo_title_en', 'YikaiCMS - Professional Enterprise CMS'),
            ('seo_title_ja', 'YikaiCMS - 企業向けプロフェッショナル CMS'),
            ('seo_keywords', 'keep me')");

        // 与 install/index.php 同一条语句
        $pdo->exec("DELETE FROM settings WHERE `key` <> 'seo_title' AND SUBSTR(`key`, 1, 10) = 'seo_title_'");

        $remaining = $pdo->query('SELECT "key" FROM settings ORDER BY "key"')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['seo_keywords', 'seo_title'], $remaining);
    }

    /** F08：取消演示数据后，安装种子与示例种子迁移都不得写入公开业务内容。 */
    public function testOptingOutOfDemoDataLeavesNoBusinessExamples(): void
    {
        foreach (['sqlite', 'mysql'] as $driver) {
            $quote = $driver === 'sqlite' ? '"' : '`';
            $seed = $this->source('install/sql/' . $driver . '.sql');
            $clean = (string) preg_replace('/--\s*@demo:start.*?--\s*@demo:end/s', '', $seed);

            foreach (['downloads', 'jobs', 'links', 'timelines'] as $table) {
                self::assertStringContainsString("INSERT INTO {$quote}yikai_{$table}{$quote}", $seed, "{$driver}: 勾选演示时应有示例 {$table}");
                self::assertStringNotContainsString(
                    "INSERT INTO {$quote}yikai_{$table}{$quote}",
                    $clean,
                    "{$driver}: 取消演示后不该残留示例 {$table}"
                );
            }
            // banners 自 v1.18.8 起是多语言骨架，不随演示剥离
            self::assertStringContainsString("INSERT INTO {$quote}yikai_banners{$quote}", $clean);
        }

        // 安装器记录选择，迁移据此跳过
        $installer = $this->source('install/index.php');
        self::assertStringContainsString("'install_demo_data'", $installer);
        self::assertStringContainsString('$demoFlag = $installDemo ? \'1\' : \'0\';', $installer);

        self::assertStringContainsString('function demoSeedsAllowed(): bool', $this->source('includes/functions.php'));
        $migrations = $this->source('migrations/_inline_upgrades.php');
        self::assertSame(
            2,
            substr_count($migrations, "if (function_exists('demoSeedsAllowed') && !demoSeedsAllowed()) return true;"),
            '两个示例内容迁移都要看安装时的演示选择'
        );
    }

    /** 未记录该选项的老站升级后行为不变（缺键 = 允许）。 */
    public function testSitesInstalledBeforeTheFlagKeepTheirBehaviour(): void
    {
        $previous = $GLOBALS['_test_config'] ?? null;

        $GLOBALS['_test_config'] = [];
        self::assertTrue($this->demoAllowed(), '缺键时应保持原有行为');

        $GLOBALS['_test_config'] = ['install_demo_data' => '0'];
        self::assertFalse($this->demoAllowed());

        $GLOBALS['_test_config'] = ['install_demo_data' => '1'];
        self::assertTrue($this->demoAllowed());

        if ($previous === null) {
            unset($GLOBALS['_test_config']);
        } else {
            $GLOBALS['_test_config'] = $previous;
        }
    }

    /** functions.php 太重，测试进程里按同一规则复算一次。 */
    private function demoAllowed(): bool
    {
        return (string) config('install_demo_data', '1') !== '0';
    }
}
