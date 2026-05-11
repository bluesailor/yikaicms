<?php
/**
 * Tests for the abstract Model base class (includes/models/Model.php).
 *
 * We exercise it against a synthetic `test_items` table so we don't depend
 * on any real CMS schema. A throwaway TestItemModel subclass exposes the
 * concrete table/order config the base class needs.
 *
 * Coverage targets all 24 public methods of Model plus the two protected
 * SQL-injection guards (assertColumnName, assertOrderBy).
 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use InvalidArgumentException;
use Yikai\Tests\TestCase;

/**
 * Test-only model wrapping a synthetic items table.
 * Lives in the global namespace because the production Model class is
 * loaded that way (no namespace) and inheritance requires a match.
 */
require_once __DIR__ . '/_fixtures/TestItemModel.php';

class ModelTest extends TestCase
{
    private \TestItemModel $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new \TestItemModel();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE test_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT,
                status INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 0,
                views INTEGER DEFAULT 0,
                is_top INTEGER DEFAULT 0
            )",
        ];
    }

    private function seed(): void
    {
        $this->insertRow('test_items', ['name' => 'alpha',   'slug' => 'a', 'status' => 1, 'sort_order' => 3]);
        $this->insertRow('test_items', ['name' => 'beta',    'slug' => 'b', 'status' => 1, 'sort_order' => 2]);
        $this->insertRow('test_items', ['name' => 'gamma',   'slug' => 'c', 'status' => 0, 'sort_order' => 1]);
        $this->insertRow('test_items', ['name' => 'delta',   'slug' => null,'status' => 1, 'sort_order' => 4]);
    }

    // ───── Read methods ─────

    public function testFindReturnsRowById(): void
    {
        $this->seed();
        $row = $this->m->find(1);
        $this->assertSame('alpha', $row['name']);
    }

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->m->find(999));
    }

    public function testFindBy(): void
    {
        $this->seed();
        $row = $this->m->findBy('slug', 'b');
        $this->assertSame('beta', $row['name']);
    }

    public function testFindByRejectsBadColumnName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->m->findBy('name; DROP TABLE--', 'x');
    }

    public function testFindWhereWithMultipleConditions(): void
    {
        $this->seed();
        $row = $this->m->findWhere(['status' => 1, 'sort_order' => 2]);
        $this->assertSame('beta', $row['name']);
    }

    public function testFindWhereHandlesNullValue(): void
    {
        $this->seed();
        $row = $this->m->findWhere(['slug' => null]);
        $this->assertSame('delta', $row['name']);
    }

    public function testAllReturnsEverything(): void
    {
        $this->seed();
        $rows = $this->m->all();
        $this->assertCount(4, $rows);
    }

    public function testAllRespectsCustomOrder(): void
    {
        $this->seed();
        $rows = $this->m->all('sort_order ASC');
        $this->assertSame('gamma', $rows[0]['name']);   // sort_order=1
        $this->assertSame('delta', $rows[3]['name']);   // sort_order=4
    }

    public function testWhereWithLimitOffset(): void
    {
        $this->seed();
        $rows = $this->m->where(['status' => 1], 'sort_order ASC', 2, 0);
        $this->assertCount(2, $rows);
        $this->assertSame('beta', $rows[0]['name']);
    }

    public function testWhereWithEmptyConditions(): void
    {
        $this->seed();
        $rows = $this->m->where([], 'id ASC');
        $this->assertCount(4, $rows);
    }

    public function testPaginate(): void
    {
        $this->seed();
        $result = $this->m->paginate(1, 2, ['status' => 1], 'sort_order ASC');
        $this->assertSame(3, $result['total']);              // 3 rows with status=1
        $this->assertCount(2, $result['items']);
    }

    public function testPaginateSecondPage(): void
    {
        $this->seed();
        $result = $this->m->paginate(2, 2, ['status' => 1], 'sort_order ASC');
        $this->assertCount(1, $result['items']);
    }

    public function testCountAll(): void
    {
        $this->seed();
        $this->assertSame(4, $this->m->count());
    }

    public function testCountWithConditions(): void
    {
        $this->seed();
        $this->assertSame(3, $this->m->count(['status' => 1]));
    }

    public function testRawQuery(): void
    {
        $this->seed();
        $rows = $this->m->query('SELECT name FROM test_items WHERE status = ?', [1]);
        $this->assertCount(3, $rows);
    }

    public function testQueryOne(): void
    {
        $this->seed();
        $row = $this->m->queryOne('SELECT * FROM test_items WHERE name = ?', ['alpha']);
        $this->assertSame('a', $row['slug']);
    }

    public function testQueryColumn(): void
    {
        $this->seed();
        $name = $this->m->queryColumn('SELECT name FROM test_items WHERE id = ?', [2]);
        $this->assertSame('beta', $name);
    }

    // ───── Write methods ─────

    public function testCreateReturnsLastInsertId(): void
    {
        $id = (int) $this->m->create(['name' => 'new', 'slug' => 'n']);
        $this->assertGreaterThan(0, $id);
        $this->assertSame('new', $this->m->find($id)['name']);
    }

    public function testUpdateById(): void
    {
        $this->seed();
        $affected = $this->m->updateById(1, ['name' => 'alpha2']);
        $this->assertSame(1, $affected);
        $this->assertSame('alpha2', $this->m->find(1)['name']);
    }

    public function testUpdateWhere(): void
    {
        $this->seed();
        $affected = $this->m->updateWhere(['status' => 99], 'sort_order > ?', [2]);
        $this->assertSame(2, $affected);                     // sort_order 3 & 4
    }

    public function testDeleteById(): void
    {
        $this->seed();
        $this->assertSame(1, $this->m->deleteById(1));
        $this->assertNull($this->m->find(1));
    }

    public function testDeleteByIds(): void
    {
        $this->seed();
        $this->assertSame(2, $this->m->deleteByIds([1, 2]));
        $this->assertSame(2, $this->m->count());
    }

    public function testDeleteByIdsEmptyArrayReturnsZero(): void
    {
        $this->seed();
        $this->assertSame(0, $this->m->deleteByIds([]));
        $this->assertSame(4, $this->m->count());             // nothing deleted
    }

    public function testToggle(): void
    {
        $this->seed();
        // alpha starts is_top=0
        $this->m->toggle(1, 'is_top');
        $this->assertSame(1, (int) $this->m->find(1)['is_top']);
        $this->m->toggle(1, 'is_top');
        $this->assertSame(0, (int) $this->m->find(1)['is_top']);
    }

    public function testToggleRejectsBadColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->m->toggle(1, 'is_top; DROP--');
    }

    public function testIncrementByOne(): void
    {
        $this->seed();
        $this->m->increment(1, 'views');
        $this->assertSame(1, (int) $this->m->find(1)['views']);
    }

    public function testIncrementByCustomAmount(): void
    {
        $this->seed();
        $this->m->increment(1, 'views', 10);
        $this->m->increment(1, 'views', 5);
        $this->assertSame(15, (int) $this->m->find(1)['views']);
    }

    // ───── Helpers ─────

    public function testTableNamePrefix(): void
    {
        // DB_PREFIX is '' in tests, so tableName() returns the bare table.
        $this->assertSame('test_items', $this->m->tableName());
    }

    public function testIsSlugUniqueTrueWhenAbsent(): void
    {
        $this->seed();
        $this->assertTrue($this->m->isSlugUnique('not-taken'));
    }

    public function testIsSlugUniqueFalseWhenPresent(): void
    {
        $this->seed();
        $this->assertFalse($this->m->isSlugUnique('a'));
    }

    public function testIsSlugUniqueIgnoresExcludeId(): void
    {
        $this->seed();
        // slug 'a' belongs to id 1; excluding id 1 should treat it as available
        $this->assertTrue($this->m->isSlugUnique('a', 1));
    }

    // ───── SQL injection guards ─────

    public function testWhereRejectsBadColumnInCondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->m->where(['name; DROP TABLE--' => 'x']);
    }

    public function testAllRejectsMaliciousOrderBy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->m->all('id; DELETE FROM test_items');
    }

    public function testAllAcceptsRandFunction(): void
    {
        $this->seed();
        // Should not throw — RAND/RANDOM are explicitly whitelisted
        $rows = $this->m->all('RANDOM()');
        $this->assertCount(4, $rows);
    }
}
