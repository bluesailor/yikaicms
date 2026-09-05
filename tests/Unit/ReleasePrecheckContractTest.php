<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleasePrecheckContractTest extends TestCase
{
    public function testSchemaCheckUsesAReleaseBaselineInsteadOfOnlyThePreviousCommit(): void
    {
        $source = file_get_contents(ROOT_PATH . '/tools/release-precheck.sh');
        self::assertNotFalse($source);

        self::assertStringContainsString('--baseline=*', $source);
        self::assertStringContainsString('YK_RELEASE_BASELINE', $source);
        self::assertStringContainsString("repo_git describe --tags --abbrev=0 --match 'v[0-9]*'", $source);
        self::assertStringContainsString('repo_git show "${schema_baseline}:install/sql/mysql.sql"', $source);
        self::assertStringContainsString('php tools/release-schema-diff.php', $source);
        self::assertStringNotContainsString('git diff HEAD~1 -- install/sql/mysql.sql', $source);
    }

    public function testDemoEncodingScanIsPartOfTheReleaseGate(): void
    {
        $source = file_get_contents(ROOT_PATH . '/tools/release-precheck.sh');
        self::assertNotFalse($source);

        self::assertStringContainsString('tools/scan_demo_mojibake.php', $source);
        self::assertStringContainsString('演示数据 UTF-8 完整性', $source);
        self::assertStringContainsString('演示数据 U+FFFD 扫描未通过', $source);
    }
}
