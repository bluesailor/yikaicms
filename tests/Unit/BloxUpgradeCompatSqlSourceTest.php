<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('YIKAI_BLOX_UPGRADE_COMPAT_HELPERS_ONLY')) {
    define('YIKAI_BLOX_UPGRADE_COMPAT_HELPERS_ONLY', true);
}
require_once ROOT_PATH . '/tests/smoke/blox_upgrade_compat.php';

final class BloxUpgradeCompatSqlSourceTest extends TestCase
{
    private string $sqlDir;
    private string|false $previousSqlDir;

    protected function setUp(): void
    {
        $this->previousSqlDir = getenv('YK_TAG_SQL_DIR');
        $this->sqlDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yikai-tag-sql-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($this->sqlDir, 0700));
    }

    protected function tearDown(): void
    {
        putenv($this->previousSqlDir === false
            ? 'YK_TAG_SQL_DIR'
            : 'YK_TAG_SQL_DIR=' . $this->previousSqlDir);
        foreach (glob($this->sqlDir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->sqlDir)) {
            rmdir($this->sqlDir);
        }
    }

    public function testEnvironmentDirectoryOverridesGitSource(): void
    {
        $sql = "CREATE TABLE yikai_fixture (id INTEGER PRIMARY KEY);\n";
        self::assertNotFalse(file_put_contents(
            $this->sqlDir . DIRECTORY_SEPARATOR . 'v1.17.2.sqlite.sql',
            $sql
        ));
        putenv('YK_TAG_SQL_DIR=' . $this->sqlDir);

        self::assertSame($sql, taggedInstallSql('Z:\\path-that-does-not-exist', 'v1.17.2'));
    }

    public function testMissingPreExportedTagFailsInsteadOfUsingCurrentSql(): void
    {
        putenv('YK_TAG_SQL_DIR=' . $this->sqlDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('v1.18.1.sqlite.sql');
        taggedInstallSql(ROOT_PATH, 'v1.18.1');
    }

    public function testEmptyPreExportedSqlIsRejected(): void
    {
        self::assertNotFalse(file_put_contents(
            $this->sqlDir . DIRECTORY_SEPARATOR . 'v1.18.1.sqlite.sql',
            " \n"
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is empty');
        preExportedTaggedInstallSql($this->sqlDir, 'v1.18.1');
    }

    public function testInvalidTagCannotEscapeTheSqlDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid release tag');
        preExportedTaggedInstallSql($this->sqlDir, '../sqlite');
    }

    public function testSmokeScriptDocumentsTheEnvironmentContract(): void
    {
        $source = file_get_contents(ROOT_PATH . '/tests/smoke/blox_upgrade_compat.php');
        self::assertIsString($source);
        self::assertStringContainsString("getenv('YK_TAG_SQL_DIR')", $source);
        self::assertStringContainsString("\$tag . '.sqlite.sql'", $source);
        self::assertStringContainsString("['git', '-C', \$root, 'show'", $source);
    }
}
