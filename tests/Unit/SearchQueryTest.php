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
        $pdo->exec("INSERT INTO test_contents VALUES (1, 'Shared article', 'Shared summary', '', 100, 'article', 1, 1, 'zh-CN')");
        $pdo->exec("INSERT INTO test_products VALUES (2, 'Shared product', 'Shared summary', '', 300, 1, 1, 'zh-CN')");
        $pdo->exec("INSERT INTO test_downloads VALUES (3, 'Shared download', 'Shared summary', 200, 1, 1, 'zh-CN')");

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
        $pdo->exec("INSERT INTO test_downloads VALUES (3, 'Manual', 'Readable description', 200, 1, 1, 'en')");

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
        $pdo->exec("INSERT INTO test_contents VALUES (1, 'Shared Chinese', 'Shared', '', 100, 'article', 1, 1, 'zh-CN')");
        $pdo->exec("INSERT INTO test_products VALUES (2, 'Shared English', 'Shared', '', 300, 1, 1, 'en')");
        $pdo->exec("INSERT INTO test_downloads VALUES (3, 'Shared Japanese', 'Shared', 200, 1, 1, 'ja')");

        $statement = $pdo->prepare(globalSearchQuery('test_'));
        $statement->execute(['en', '%Shared%', '%Shared%', 'en', '%Shared%', '%Shared%', 'en', '%Shared%', '%Shared%', 15, 0]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(1, $rows);
        self::assertSame('Shared English', $rows[0]['title']);
        self::assertSame('product', $rows[0]['_type']);
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE test_channels (id INTEGER, name TEXT, slug TEXT)');
        $pdo->exec('CREATE TABLE test_contents (id INTEGER, title TEXT, summary TEXT, cover TEXT, publish_time INTEGER, type TEXT, channel_id INTEGER, status INTEGER, lang TEXT)');
        $pdo->exec('CREATE TABLE test_product_categories (id INTEGER, name TEXT, slug TEXT)');
        $pdo->exec('CREATE TABLE test_products (id INTEGER, title TEXT, summary TEXT, cover TEXT, updated_at INTEGER, category_id INTEGER, status INTEGER, lang TEXT)');
        $pdo->exec('CREATE TABLE test_download_categories (id INTEGER, name TEXT)');
        $pdo->exec('CREATE TABLE test_downloads (id INTEGER, title TEXT, description TEXT, created_at INTEGER, category_id INTEGER, status INTEGER, lang TEXT)');
    }
}
