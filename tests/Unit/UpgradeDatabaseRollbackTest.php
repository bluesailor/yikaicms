<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/Backup.php';
require_once ROOT_PATH . '/includes/UpgradeDatabaseRollback.php';

final class UpgradeDatabaseRollbackTest extends TestCase
{
    private string $directory;

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE rollback_items (id INTEGER PRIMARY KEY AUTOINCREMENT, note TEXT)',
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE,
                `value` TEXT, `group` TEXT, name TEXT, tip TEXT, type TEXT)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        settingModel()->clearCache();
        $this->directory = sys_get_temp_dir() . '/yikai-db-rollback-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if (is_file($this->directory . '/database.sql')) unlink($this->directory . '/database.sql');
        rmdir($this->directory);
        settingModel()->clearCache();
        parent::tearDown();
    }

    public function testRestoresRealBackupAndRefreshesSettingCache(): void
    {
        $note = "O'Reilly; handbook\\part\nsecond line";
        db()->insert('rollback_items', ['note' => $note]);
        settingModel()->set('site_name', 'before upgrade');
        file_put_contents($this->directory . '/database.sql', \Backup::generateSql(['settings', 'rollback_items']));
        db()->execute('ALTER TABLE rollback_items ADD COLUMN migrated INTEGER DEFAULT 1');
        db()->execute('UPDATE rollback_items SET note = ?', ['after upgrade']);
        settingModel()->set('site_name', 'after upgrade');
        self::assertSame('after upgrade', settingModel()->get('site_name'));

        $result = \UpgradeDatabaseRollback::restore($this->directory);

        self::assertSame([], $result['errors']);
        self::assertGreaterThan(0, $result['statements']);
        self::assertSame($note, db()->fetchColumn('SELECT note FROM rollback_items'));
        self::assertSame('before upgrade', settingModel()->get('site_name'));
        self::assertSame(['id', 'note'], array_column(db()->fetchAll('PRAGMA table_info(rollback_items)'), 'name'));
    }

    public function testMissingEmptyOrInvalidBackupCannotReportSuccess(): void
    {
        db()->insert('rollback_items', ['note' => 'preserve']);
        foreach ([null, '', '-- no SQL', 'DELETE FROM rollback_items; INVALID SQL;'] as $sql) {
            if ($sql !== null) file_put_contents($this->directory . '/database.sql', $sql);
            $result = \UpgradeDatabaseRollback::restore($this->directory);
            self::assertNotEmpty($result['errors']);
            self::assertSame('preserve', db()->fetchColumn('SELECT note FROM rollback_items'));
        }
    }

    public function testRollbackRestoresDatabaseBeforeFilesAndKeepsEvidenceOnFailure(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        $rollback = substr($source, strpos($source, 'function upgrade_rollback('));
        $restore = strpos($rollback, 'UpgradeDatabaseRollback::restore($bakDir)');
        $files = strpos($rollback, 'uo_copy_tree($filesDir, ROOT_PATH)');
        self::assertNotFalse($restore);
        self::assertNotFalse($files);
        self::assertLessThan($files, $restore);
        self::assertStringContainsString("if (\$database['errors'] !== [])", substr($rollback, $restore, $files - $restore));
    }
}
