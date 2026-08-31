<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseMaintenance.php';

final class UpgradeDatabaseRollback
{
    /** @return array{statements:int,errors:list<string>} */
    public static function restore(string $backupDir): array
    {
        $sql = @file_get_contents($backupDir . '/database.sql');
        if ($sql === false || trim($sql) === '') {
            return ['statements' => 0, 'errors' => ['Database snapshot is missing or unreadable; rollback was not completed.']];
        }
        if (DatabaseMaintenance::splitSql($sql) === []) {
            return ['statements' => 0, 'errors' => ['Database snapshot contains no SQL statements.']];
        }
        try {
            $result = DatabaseMaintenance::restoreSql($sql);
        } catch (Throwable $e) {
            $result = ['statements' => 0, 'errors' => [mb_substr($e->getMessage(), 0, 200)]];
        }
        // Restore bypasses SettingModel writes, including after a partial MySQL restore.
        settingModel()->clearCache();
        if (function_exists('do_action')) {
            do_action('data_changed', 'settings', 0);
            do_action('setting_saved', []);
        }
        return $result;
    }
}
