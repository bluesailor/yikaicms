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
        self::assertStringContainsString("git describe --tags --abbrev=0 --match 'v[0-9]*'", $source);
        self::assertStringContainsString('git diff "$schema_baseline" -- install/sql/mysql.sql', $source);
        self::assertStringNotContainsString('git diff HEAD~1 -- install/sql/mysql.sql', $source);
    }
}
