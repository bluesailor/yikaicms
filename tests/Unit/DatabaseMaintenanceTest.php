<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/Backup.php';
require_once ROOT_PATH . '/includes/DatabaseMaintenance.php';

final class DatabaseMaintenanceTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE maintenance_items (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT NOT NULL)',
        ];
    }

    public function testSqliteBackupCanRestoreQuotedAndSemicolonData(): void
    {
        db()->insert('maintenance_items', ['note' => "O'Reilly; handbook\\chapter"]);
        $backup = \Backup::generateSql(['maintenance_items']);

        db()->execute('DELETE FROM maintenance_items');
        db()->insert('maintenance_items', ['note' => 'changed']);
        $result = \DatabaseMaintenance::restoreSql($backup);

        self::assertSame([], $result['errors']);
        self::assertGreaterThanOrEqual(3, $result['statements']);
        $rows = db()->fetchAll('SELECT note FROM maintenance_items ORDER BY id');
        self::assertSame(["O'Reilly; handbook\\chapter"], array_column($rows, 'note'));
    }

    public function testSqliteRestoreRollsBackEntireImportOnFailure(): void
    {
        db()->insert('maintenance_items', ['note' => 'before']);
        $result = \DatabaseMaintenance::restoreSql(
            "INSERT INTO maintenance_items (note) VALUES ('temporary');\nINVALID SQL;"
        );

        self::assertSame(0, $result['statements']);
        self::assertCount(1, $result['errors']);
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM maintenance_items'));
        self::assertSame('before', db()->fetchColumn('SELECT note FROM maintenance_items'));
    }

    public function testSqliteClearUsesDeleteAndResetsSequence(): void
    {
        db()->insert('maintenance_items', ['note' => 'one']);
        db()->insert('maintenance_items', ['note' => 'two']);

        self::assertSame(2, \DatabaseMaintenance::clearTables(['maintenance_items']));
        self::assertSame(1, (int) db()->insert('maintenance_items', ['note' => 'new']));
        self::assertSame(1, \DatabaseMaintenance::optimize(['maintenance_items']));
    }
}
