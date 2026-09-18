<?php
/**
 * MySQL 只把「-- 后面跟空白」认作注释，SQLite 则 `--` 直接就是注释。
 *
 * 2026-09-18 种子里一行写成 `--（站点名…`，SQLite 安装与所有单测（全走 SQLite）都正常，
 * MySQL 安装当场报 1064 语法错误、装不上。这里按 MySQL 的规则逐行检查两份种子，
 * 以及安装器剥离演示区块之后的结果。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InstallSqlMysqlCommentSyntaxTest extends TestCase
{
    /**
     * 只查 mysql.sql：MySQL 安装只执行它。sqlite.sql 由转换器生成，字符串里的 \n 会被展开成
     * 真换行，值里的 Markdown 分隔线（---）会落在行首，但 SQLite 本来就不受这条规则约束。
     * mysql.sql 里所有换行都是转义过的，因此每一个物理行都是一条语句或注释的开头。
     */
    public function testEveryCommentLineIsValidForMysql(): void
    {
        $file = 'mysql.sql';
        $sql = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/install/sql/' . $file));
        foreach (['含演示' => $sql, '去演示' => (string) preg_replace('/--\s*@demo:start.*?--\s*@demo:end/s', '', $sql)] as $variant => $text) {
            $bad = [];
            foreach (explode("\n", $text) as $no => $line) {
                // 行首的 --：MySQL 要求紧跟空格/制表符，或者就是行尾
                if (preg_match('/^--(?![ \t]|$)/', $line) === 1) {
                    $bad[] = ($no + 1) . ': ' . mb_substr($line, 0, 40);
                }
            }
            self::assertSame([], $bad, "{$file}（{$variant}）有 MySQL 不认的注释行：\n" . implode("\n", $bad));
        }
    }
}
