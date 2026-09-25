<?php
/**
 * Base PHPUnit TestCase for Yikai CMS.
 *
 * Provides:
 *   - resetDatabase()  — wipes & re-creates fresh test schema between tests
 *   - createTable()    — convenience for ad-hoc schema in a single test
 *   - The shared db()  singleton, so each test sees the same in-memory PDO.
 *
 * Tests should typically subclass this and override schemaSql() to declare
 * the exact tables they need.
 */

declare(strict_types=1);

namespace Yikai\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * By default every test runs with no registered actions.
     *
     * Actions are process-wide: includes/HtmlCache.php, plugins/shop/register.php and
     * others register them when a test file is loaded. BatchStatusCacheInvalidationTest
     * used to clear them in setUp without restoring, which isolated every test that
     * happened to run after it — so which tests failed depended on file order, and Linux
     * CI and Windows differed (first full CI run, 2026-09-25). Tests that exercise the
     * real load-time actions set this to false. Filters are left alone.
     */
    protected bool $isolateActions = true;

    /** @var array<string,mixed> */
    private array $actionsBeforeTest = [];

    /**
     * Before/After hooks run even when a subclass overrides setUp()/tearDown() without
     * calling parent (several do), so the restore below always happens.
     */
    #[Before]
    protected function isolateActionsBeforeTest(): void
    {
        $this->actionsBeforeTest = $GLOBALS['ik_actions'] ?? [];
        if ($this->isolateActions) {
            $GLOBALS['ik_actions'] = [];
        }
    }

    #[After]
    protected function restoreActionsAfterTest(): void
    {
        $GLOBALS['ik_actions'] = $this->actionsBeforeTest;
        // An assertion that fails before commit() leaves the shared connection inside a
        // transaction; without this every later test reports "already an active transaction".
        $pdo = db()->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetDatabase();
        foreach ($this->schemaSql() as $sql) {
            db()->getPdo()->exec($sql);
        }
    }

    /**
     * Drop every table in the in-memory SQLite DB so each test starts clean.
     * SQLite's `DELETE FROM sqlite_master` is allowed when foreign_keys=OFF.
     */
    protected function resetDatabase(): void
    {
        $pdo = db()->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('PRAGMA foreign_keys = OFF');
        // Exclude SQLite-internal tables (e.g. sqlite_sequence) — those
        // are auto-managed and cannot be DROPped explicitly.
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $pdo->exec("DROP TABLE IF EXISTS \"{$t}\"");
        }
        // Reset AUTOINCREMENT counters so a new schema starts at id=1.
        // sqlite_sequence is only created lazily after the first AUTOINCREMENT
        // table — guard against it not existing on the very first setUp.
        $hasSeq = $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name='sqlite_sequence'"
        )->fetchColumn();
        if ($hasSeq) {
            $pdo->exec("DELETE FROM sqlite_sequence");
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Override in subclasses to declare CREATE TABLE statements the test
     * needs. Default: no schema (subclasses opt in).
     *
     * @return string[]
     */
    protected function schemaSql(): array
    {
        return [];
    }

    /**
     * Quick row insertion helper. Returns lastInsertId.
     *
     * @param array<string,mixed> $data
     */
    protected function insertRow(string $table, array $data): int
    {
        return (int) db()->insert($table, $data);
    }
}
