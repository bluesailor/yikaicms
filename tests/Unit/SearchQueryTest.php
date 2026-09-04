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
        $pdo->exec("INSERT INTO test_contents VALUES (1, 'Shared article', 'Shared summary', '', 100, 'article', 1, 1)");
        $pdo->exec("INSERT INTO test_products VALUES (2, 'Shared product', 'Shared summary', '', 300, 1, 1)");
        $pdo->exec("INSERT INTO test_downloads VALUES (3, 'Shared download', 'Shared summary', 200, 1, 1)");

        $statement = $pdo->prepare(globalSearchQuery('test_'));
        $statement->execute(['%Shared%', '%Shared%', '%Shared%', '%Shared%', '%Shared%', '%Shared%', 15, 0]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(['product', 'download', 'article'], array_column($rows, '_type'));
        self::assertSame(['product', 'download', 'article'], array_column($rows, 'type'));
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE test_channels (id INTEGER, name TEXT, slug TEXT)');
        $pdo->exec('CREATE TABLE test_contents (id INTEGER, title TEXT, summary TEXT, cover TEXT, publish_time INTEGER, type TEXT, channel_id INTEGER, status INTEGER)');
        $pdo->exec('CREATE TABLE test_product_categories (id INTEGER, name TEXT, slug TEXT)');
        $pdo->exec('CREATE TABLE test_products (id INTEGER, title TEXT, summary TEXT, cover TEXT, updated_at INTEGER, category_id INTEGER, status INTEGER)');
        $pdo->exec('CREATE TABLE test_download_categories (id INTEGER, name TEXT)');
        $pdo->exec('CREATE TABLE test_downloads (id INTEGER, title TEXT, description TEXT, created_at INTEGER, category_id INTEGER, status INTEGER)');
    }
}
