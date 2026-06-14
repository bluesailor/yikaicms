<?php
/**
 * 集成测试：bin/yikai import 命令（CSV 批量导入产品）。
 * 用 sqlite 内存库验证：分类按 slug 解析、字段写入、按 slug 幂等、--update 更新。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class ImportCommandTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT, "key" TEXT, value TEXT, type TEXT, name TEXT, tip TEXT, options TEXT, sort_order INT DEFAULT 0)',
            'CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INT DEFAULT 0, name TEXT, slug TEXT, lang TEXT, status INT DEFAULT 1, sort_order INT DEFAULT 0, created_at INT DEFAULT 0)',
            'CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INT DEFAULT 0, title TEXT, slug TEXT, lang TEXT, cover TEXT, summary TEXT, content TEXT, price REAL DEFAULT 0, model TEXT, status INT DEFAULT 1, sort_order INT DEFAULT 0, created_at INT DEFAULT 0, updated_at INT DEFAULT 0)',
        ];
    }

    private function registerCommand(): void
    {
        if (!defined('IK_CLI')) {
            define('IK_CLI', true);
        }
        require_once ROOT_PATH . '/includes/CLI.php';
        require_once ROOT_PATH . '/includes/commands/import.php';
    }

    private function runImport(array $argv): void
    {
        ob_start();
        \CLI::dispatch(array_merge(['yikai'], $argv));
        ob_end_clean();
    }

    private function writeCsv(string $content): string
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'ikimp');
        file_put_contents($f, $content);
        return $f;
    }

    public function testImportsProductsResolvesCategoryAndIsIdempotent(): void
    {
        $this->registerCommand();

        db()->insert('product_categories', ['name' => '齿轮', 'slug' => 'gear', 'lang' => 'zh-CN', 'status' => 1]);
        $catId = (int) db()->getPdo()->lastInsertId();

        $csv = $this->writeCsv("title,slug,category,price,status\n减速机A,prod-a,gear,1200,1\n减速机B,prod-b,gear,,1\n");
        $this->runImport(['import', 'products', $csv]);

        $rows = db()->fetchAll('SELECT title, slug, category_id, price, status FROM products ORDER BY id');
        $this->assertCount(2, $rows, '应导入 2 条产品');
        $this->assertSame('减速机A', $rows[0]['title']);
        $this->assertSame('prod-a', $rows[0]['slug']);
        $this->assertSame($catId, (int) $rows[0]['category_id'], 'category 应按 slug 解析到分类 id');
        $this->assertSame(1200.0, (float) $rows[0]['price']);
        $this->assertSame(1, (int) $rows[1]['status']);

        // 幂等：相同 slug 再次导入应跳过
        $this->runImport(['import', 'products', $csv]);
        $this->assertSame(2, (int) db()->fetchColumn('SELECT COUNT(*) FROM products'), '重复导入不应新增');

        // --update：已存在则更新
        $csv2 = $this->writeCsv("title,slug,category,price,status\n减速机A改,prod-a,gear,1500,1\n");
        $this->runImport(['import', 'products', $csv2, '--update']);
        $row = db()->fetchOne("SELECT title, price FROM products WHERE slug = 'prod-a'");
        $this->assertSame('减速机A改', $row['title'], '--update 应更新标题');
        $this->assertSame(1500.0, (float) $row['price']);
        $this->assertSame(2, (int) db()->fetchColumn('SELECT COUNT(*) FROM products'), '--update 不应新增');

        @unlink($csv);
        @unlink($csv2);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->registerCommand();
        db()->insert('product_categories', ['name' => '齿轮', 'slug' => 'gear', 'lang' => 'zh-CN', 'status' => 1]);

        $csv = $this->writeCsv("title,slug,category\n仅试跑,dry-1,gear\n");
        $this->runImport(['import', 'products', $csv, '--dry-run']);

        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM products'), 'dry-run 不应写库');
        @unlink($csv);
    }
}
