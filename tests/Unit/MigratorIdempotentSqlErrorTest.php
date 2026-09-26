<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 老站升级时，同一状态可能被两条迁移分别覆盖，后跑的那条会撞上已存在的列/索引。
 * 这类错误必须被当成「已达成」跳过，否则 migrate:run 会在半途中止。
 */
final class MigratorIdempotentSqlErrorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/Migrator.php';
    }

    /** @dataProvider tolerated */
    public function testToleratedMessages(string $message): void
    {
        self::assertTrue(Migrator::isIdempotentSqlError($message), $message);
    }

    /** @return array<string, array{string}> */
    public static function tolerated(): array
    {
        return [
            // MySQL：重复索引的措辞与重复列不同，曾漏判导致 1.x → 2.0.0 升级中断
            'mysql duplicate index' => ["SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'idx_bn_trans'"],
            'mysql duplicate column' => ["SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'translation_group_id'"],
            'mysql duplicate entry' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'PRIMARY'"],
            'sqlite index exists' => ['SQLSTATE[HY000]: General error: 1 index idx_bn_trans already exists'],
            'sqlite table exists' => ['SQLSTATE[HY000]: General error: 1 table yikai_mail_log already exists'],
        ];
    }

    /** @dataProvider fatal */
    public function testRealFailuresStillAbort(string $message): void
    {
        self::assertFalse(Migrator::isIdempotentSqlError($message), $message);
    }

    /** @return array<string, array{string}> */
    public static function fatal(): array
    {
        return [
            'unknown column' => ["SQLSTATE[42S22]: Column not found: 1054 Unknown column 'lang' in 'field list'"],
            'missing table' => ["SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.yikai_banners' doesn't exist"],
            'syntax error' => ['SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax'],
            'access denied' => ["SQLSTATE[42000]: Access denied for user 'x'@'localhost'"],
        ];
    }
}
