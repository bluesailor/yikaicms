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

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
