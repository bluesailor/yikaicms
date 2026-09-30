<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 整站模板往返验收工具（2.0.3）的源码契约：它会起临时站、装机并登录后台，
 * 这些安全边界必须一直成立。完整跑一遍需要起 HTTP 服务，不放进单元测试
 * （2026-09-30 以英文模板 Flow 包实测通过，且对画布修复前的代码能报出 canvas_missing_theme_css）。
 */
final class SiteTemplateRoundtripToolTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(ROOT_PATH . '/tools/site-template-roundtrip.php');
    }

    public function testRunsOnlyFromTheCommandLineOnLoopback(): void
    {
        $source = $this->source();
        self::assertStringContainsString("if (PHP_SAPI !== 'cli')", $source);
        self::assertStringContainsString("'127.0.0.1:' . \$port", $source, 'the disposable site only listens on loopback');
        self::assertStringNotContainsString('0.0.0.0', $source);
    }

    public function testCredentialsAreRandomAndNeverPrinted(): void
    {
        $source = $this->source();
        self::assertStringContainsString("\$adminPass = 'Rt#' . bin2hex(random_bytes(8));", $source);
        // 报告与输出里只出现步骤名，不回显账号密码
        self::assertDoesNotMatchRegularExpression('/(?:fwrite|echo)[^;]*\$admin(?:Pass|User)/', $source);
        self::assertDoesNotMatchRegularExpression("/'steps'\\]\\[\\][^;]*\\\$admin/", $source);
        self::assertStringContainsString('YK_ROUNDTRIP_MYSQL_PASS', $source, 'MySQL password comes from the environment, not argv');
    }

    public function testDisposableMysqlDatabaseAndSandboxCleanup(): void
    {
        $source = $this->source();
        self::assertStringContainsString("\$mysqlDb = 'yk_roundtrip_' . bin2hex(random_bytes(5));", $source);
        self::assertStringContainsString("DROP DATABASE IF EXISTS `' . \$mysqlDb . '`", $source);
        self::assertStringContainsString("'/yk-roundtrip-' . bin2hex(random_bytes(5))", $source);
    }

    public function testReportIsBoundToThePackageAndChecksCanvasThemeStyles(): void
    {
        $source = $this->source();
        self::assertStringContainsString("'sha256' => hash_file('sha256', \$package)", $source);
        self::assertStringContainsString("'canvas_missing_theme_css'", $source);
        self::assertStringContainsString("' import '", $source, 'import and sampling run in separate processes');
        self::assertStringContainsString("' sample 2>&1'", $source);
    }

    /** 2026-09-30：Flow 包往返全绿，后台却提示 6 项升级待执行——安装后与导入后都要查迁移 */
    public function testFailsWhenInstallOrImportLeavesPendingMigrations(): void
    {
        $source = $this->source();
        self::assertStringContainsString("' pending 2>&1'", $source);
        self::assertStringContainsString("\$report['pending_migrations']['install'] === []", $source);
        self::assertStringContainsString("\$report['pending_migrations']['import'] === []", $source);
    }
}
