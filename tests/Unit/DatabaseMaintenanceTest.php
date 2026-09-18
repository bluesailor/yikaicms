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

    /**
     * SQLite 的 UNIQUE 多半是独立的 CREATE UNIQUE INDEX（settings.key 就是）。备份只导建表
     * 语句的话，恢复/回滚之后 INSERT … ON CONFLICT(key) 一律失败，后台登录都进不去
     *（2026-09-18 发版门禁的回滚 e2e 与权限矩阵同时因此 500）。
     */
    public function testSqliteBackupKeepsIndexesSoUpsertsStillWorkAfterRestore(): void
    {
        $pdo = db()->getPdo();
        $pdo->exec('CREATE TABLE kv_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "key" TEXT NOT NULL, value TEXT)');
        $pdo->exec('CREATE UNIQUE INDEX uk_kv_settings_key ON kv_settings ("key")');
        $pdo->exec('CREATE INDEX idx_kv_settings_value ON kv_settings (value)');
        $pdo->exec("INSERT INTO kv_settings (\"key\", value) VALUES ('site_name', 'before')");

        $backup = \Backup::generateSql(['kv_settings']);
        self::assertStringContainsString('CREATE UNIQUE INDEX IF NOT EXISTS uk_kv_settings_key', $backup);
        self::assertStringContainsString('CREATE INDEX IF NOT EXISTS idx_kv_settings_value', $backup);

        $pdo->exec("UPDATE kv_settings SET value = 'after' WHERE \"key\" = 'site_name'");
        $result = \DatabaseMaintenance::restoreSql($backup);
        self::assertSame([], $result['errors'] ?? ['missing'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame(5, $result['statements'] ?? 0, 'DROP + CREATE TABLE + 两条 CREATE INDEX + INSERT');

        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'kv_settings' AND sql IS NOT NULL ORDER BY name")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['idx_kv_settings_value', 'uk_kv_settings_key'], $indexes, '恢复后索引必须还在');

        // 与 SettingModel::set 同款 upsert：唯一约束丢了这里就会抛 ON CONFLICT 不匹配
        $pdo->exec("INSERT INTO kv_settings (\"key\", value) VALUES ('site_name', 'upsert') ON CONFLICT(\"key\") DO UPDATE SET value = excluded.value");
        self::assertSame('upsert', $pdo->query("SELECT value FROM kv_settings WHERE \"key\" = 'site_name'")->fetchColumn());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM kv_settings')->fetchColumn());
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
