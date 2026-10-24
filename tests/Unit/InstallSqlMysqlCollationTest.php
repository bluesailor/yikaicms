<?php
/**
 * 安装 SQL 的每张表都要写明 COLLATE=utf8mb4_general_ci。
 *
 * 不写就用服务器默认排序规则：MySQL 8 是 utf8mb4_0900_ai_ci，5.7 不认识它；同库表的
 * 排序规则不一致时跨表比较字符串还会报「Illegal mix of collations」。
 * 2.1.3 之前 blocks_library、content_revisions 两张表漏写。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InstallSqlMysqlCollationTest extends TestCase
{
    public function testEveryTablePinsTheCollation(): void
    {
        $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/mysql.sql');
        preg_match_all('/CREATE TABLE `([^`]+)`.*?\)\s*(ENGINE=[^;]*);/s', $sql, $tables, PREG_SET_ORDER);

        self::assertGreaterThan(40, count($tables));
        $missing = [];
        foreach ($tables as [, $name, $options]) {
            if (!str_contains($options, 'COLLATE=utf8mb4_general_ci')) $missing[] = $name;
        }
        self::assertSame([], $missing, 'Tables without COLLATE=utf8mb4_general_ci');
    }
}
