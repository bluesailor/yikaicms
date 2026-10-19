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

    public function testDownloadableLanguageGapsBlockAReleaseButOnlyReportOnMerge(): void
    {
        $release = (string) file_get_contents(ROOT_PATH . '/tools/release-precheck.sh');
        self::assertStringContainsString('php tools/i18n/gaps.php', $release);
        self::assertStringContainsString('YK_ALLOW_I18N_GAPS', $release, '来不及翻完要显式放行并写进更新日志');
        self::assertMatchesRegularExpression('/else\s+fail "\$gaps_out/', $release);

        $premerge = (string) file_get_contents(ROOT_PATH . '/tools/premerge-check.sh');
        self::assertStringContainsString('php tools/i18n/gaps.php', $premerge);
        self::assertMatchesRegularExpression('/else\s+note "\$gaps_out/', $premerge, '并分支只报告不拦');
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
