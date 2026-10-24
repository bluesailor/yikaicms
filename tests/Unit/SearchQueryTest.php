<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/search_query.php';

final class SearchQueryTest extends TestCase
{
    public function testAllSearchRunsOnSqliteAndCombinesEveryContentType(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo);

        $pdo->exec("INSERT INTO test_channels VALUES (1, 'News', 'news')");
        $pdo->exec("INSERT INTO test_product_categories VALUES (1, 'Products', 'products')");
        $pdo->exec("INSERT INTO test_download_categories VALUES (1, 'Downloads')");
        $pdo->exec("INSERT INTO test_contents VALUES (1, 'Shared article', 'Shared summary', '', 100, 'article', 1, 1, 'zh-CN', NULL)");
        $pdo->exec("INSERT INTO test_products VALUES (2, 'Shared product', 'Shared summary', '', 300, 1, 1, 'zh-CN', NULL)");
        $pdo->exec("INSERT INTO test_downloads VALUES (3, 'Shared download', 'Shared summary', 200, 1, 1, 'zh-CN', NULL)");

        $statement = $pdo->prepare(globalSearchQuery('test_'));
        $statement->execute(['zh-CN', '%Shared%', '%Shared%', 'zh-CN', '%Shared%', '%Shared%', 'zh-CN', '%Shared%', '%Shared%', 15, 0]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(['product', 'download', 'article'], array_column($rows, '_type'));
        self::assertSame(['product', 'download', 'article'], array_column($rows, 'type'));
    }

    public function testDownloadSearchAlwaysExposesDescriptionAsSummary(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo);
        $pdo->exec("INSERT INTO test_download_categories VALUES (1, 'Downloads')");
        $pdo->exec("INSERT INTO test_downloads VALUES (3, 'Manual', 'Readable description', 200, 1, 1, 'en', NULL)");

        $statement = $pdo->prepare(downloadSearchQuery('test_'));
        $statement->execute(['en', '%Manual%', '%Manual%', 15, 0]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('Readable description', $row['summary']);
        self::assertSame('download', $row['_type']);
    }

    public function testAllSearchOnlyReturnsTheRequestedLanguage(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo);

        $pdo->exec("INSERT INTO test_channels VALUES (1, 'News', 'news')");
        $pdo->exec("INSERT INTO test_product_categories VALUES (1, 'Products', 'products')");
        $pdo->exec("INSERT INTO test_download_categories VALUES (1, 'Downloads')");
        $pdo->exec("INSERT INTO test_contents VALUES (1, 'Shared Chinese', 'Shared', '', 100, 'article', 1, 1, 'zh-CN', NULL)");
        $pdo->exec("INSERT INTO test_products VALUES (2, 'Shared English', 'Shared', '', 300, 1, 1, 'en', NULL)");
        $pdo->exec("INSERT INTO test_downloads VALUES (3, 'Shared Japanese', 'Shared', 200, 1, 1, 'ja', NULL)");

        $statement = $pdo->prepare(globalSearchQuery('test_'));
        $statement->execute(['en', '%Shared%', '%Shared%', 'en', '%Shared%', '%Shared%', 'en', '%Shared%', '%Shared%', 15, 0]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(1, $rows);
        self::assertSame('Shared English', $rows[0]['title']);
        self::assertSame('product', $rows[0]['_type']);
    }

    /** 进回收站只写 deleted_at、status 仍是 1；详情页已经 404，搜索不能再把它列出来。 */
    public function testRecycledItemsAreLeftOutOfSearch(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema($pdo);

        $pdo->exec("INSERT INTO test_channels VALUES (1, 'News', 'news')");
        $pdo->exec("INSERT INTO test_product_categories VALUES (1, 'Products', 'products')");
        $pdo->exec("INSERT INTO test_download_categories VALUES (1, 'Downloads')");
        $pdo->exec("INSERT INTO test_contents VALUES (1, 'Pump article', 'Pump', '', 100, 'article', 1, 1, 'en', NULL)");
        $pdo->exec("INSERT INTO test_contents VALUES (2, 'Pump recycled article', 'Pump', '', 110, 'article', 1, 1, 'en', 1761300000)");
        $pdo->exec("INSERT INTO test_products VALUES (3, 'Pump recycled product', 'Pump', '', 300, 1, 1, 'en', 1761300000)");
        $pdo->exec("INSERT INTO test_downloads VALUES (4, 'Pump recycled manual', 'Pump', 200, 1, 1, 'en', 1761300000)");

        $all = $pdo->prepare(globalSearchQuery('test_'));
        $all->execute(['en', '%Pump%', '%Pump%', 'en', '%Pump%', '%Pump%', 'en', '%Pump%', '%Pump%', 15, 0]);
        self::assertSame(['Pump article'], array_column($all->fetchAll(PDO::FETCH_ASSOC), 'title'));

        $downloads = $pdo->prepare(downloadSearchQuery('test_'));
        $downloads->execute(['en', '%Pump%', '%Pump%', 15, 0]);
        self::assertSame([], $downloads->fetchAll(PDO::FETCH_ASSOC));
    }

    /** search.php 里按类型的计数与结果查询是手写 SQL，逐条守住回收站条件。 */
    public function testEverySearchPageQuerySkipsTheRecycleBin(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/search.php');
        preg_match_all('/WHERE\s+(?:([cpd])\.)?status\s*=\s*1\b[^"]*/', $source, $matches, PREG_SET_ORDER);

        self::assertGreaterThanOrEqual(14, count($matches));
        foreach ($matches as $match) {
            $alias = ($match[1] ?? '') !== '' ? $match[1] . '.' : '';
            self::assertStringContainsString($alias . 'deleted_at IS NULL', $match[0]);
        }
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE test_channels (id INTEGER, name TEXT, slug TEXT)');
        $pdo->exec('CREATE TABLE test_contents (id INTEGER, title TEXT, summary TEXT, cover TEXT, publish_time INTEGER, type TEXT, channel_id INTEGER, status INTEGER, lang TEXT, deleted_at INTEGER DEFAULT NULL)');
        $pdo->exec('CREATE TABLE test_product_categories (id INTEGER, name TEXT, slug TEXT)');
        $pdo->exec('CREATE TABLE test_products (id INTEGER, title TEXT, summary TEXT, cover TEXT, updated_at INTEGER, category_id INTEGER, status INTEGER, lang TEXT, deleted_at INTEGER DEFAULT NULL)');
        $pdo->exec('CREATE TABLE test_download_categories (id INTEGER, name TEXT)');
        $pdo->exec('CREATE TABLE test_downloads (id INTEGER, title TEXT, description TEXT, created_at INTEGER, category_id INTEGER, status INTEGER, lang TEXT, deleted_at INTEGER DEFAULT NULL)');
    }
}
