<?php
/**
 * 定时任务健康（2.0.5）：从未运行 / 两天没运行要在控制台和站点体检里提示——
 * 不回访的站点，定时发布、自动备份、自动升级和远程升级都够不到。
 */
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use Cron;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/Cron.php';

final class CronHealthTest extends TestCase
{
    public function testHealthStates(): void
    {
        $now = 1_800_000_000;
        self::assertSame(['state' => 'never', 'last' => 0, 'days' => 0], Cron::healthFrom(0, $now));
        self::assertSame('ok', Cron::healthFrom($now - 300, $now)['state']);
        self::assertSame('ok', Cron::healthFrom($now - Cron::STALE_AFTER, $now)['state'], '正好两天还算正常');
        self::assertSame(['state' => 'stale', 'last' => $now - 5 * 86400 - 60, 'days' => 5], Cron::healthFrom($now - 5 * 86400 - 60, $now));
    }

    /** 心跳只来自计划任务入口（cron.php、命令行）；后台「立即运行」不算，否则手动点一次就把问题盖住了。 */
    public function testHeartbeatOnlyFromSchedulerEntrypoints(): void
    {
        $cron = (string) file_get_contents(ROOT_PATH . '/includes/Cron.php');
        self::assertMatchesRegularExpression('/function runDue\(.*?\{.*?self::beat\(\);/s', $cron);
        self::assertDoesNotMatchRegularExpression('/function runOne\(.*?\{[^}]*beat\(/s', $cron);
        self::assertStringContainsString("Cron::beat();\n    \$r = Cron::runOne(\$task);", str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/cron.php')));
        self::assertStringContainsString('Cron::beat();', (string) file_get_contents(ROOT_PATH . '/includes/commands/cron.php'));
        self::assertStringNotContainsString('beat(', (string) file_get_contents(ROOT_PATH . '/admin/cron.php'));
    }

    public function testDashboardAndSiteHealthSurfaceIt(): void
    {
        $index = (string) file_get_contents(ROOT_PATH . '/admin/index.php');
        // 2.0.6：提醒收进右上角铃铛（AdminNotices），关闭动作仍在控制台入口
        $notices = (string) file_get_contents(ROOT_PATH . '/includes/AdminNotices.php');
        self::assertStringContainsString("\$cron['state'] !== 'ok'", $notices);
        self::assertStringContainsString("if (!function_exists('hasPermission') || !hasPermission('*'))", $notices, '只给超管看');
        self::assertStringContainsString('if (!$startOnboarding)', $notices, '新站走开始建站引导时不打扰');
        self::assertStringContainsString("'action' => 'dismiss_cron_notice'", $notices);
        self::assertStringContainsString("post('action') === 'dismiss_cron_notice'", $index);
        self::assertStringContainsString('time() - 30 * 86400', $notices, '关掉 30 天后仍没修好会再提示');
        $health = (string) file_get_contents(ROOT_PATH . '/includes/SiteHealth.php');
        self::assertStringContainsString('self::checkCron(),', $health);
        self::assertStringContainsString("'health_cron_title', \$ok ? 'health_cron_good' : 'health_cron_bad', '/admin/cron.php'", $health);
    }
}
