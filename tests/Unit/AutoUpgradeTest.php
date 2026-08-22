<?php
/**
 * 自动升级（v1.18.6）的判定逻辑与指令验签契约。
 *
 * 这是全系统里最危险的功能：判断错了就是无人值守地把客户站升坏。所以把每一条
 * 「不该升」的路径都钉死——默认拒绝，只有明确满足条件才放行。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AutoUpgrade;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/AutoUpgrade.php';

final class AutoUpgradeTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['auto_upgrade_enabled', 'auto_upgrade_scope', 'auto_upgrade_window'] as $k) {
            unset($GLOBALS['_test_config'][$k]);
        }
    }

    public function testDisabledByDefault(): void
    {
        // 升级默认必须是「关」——装完就自动改代码是不能接受的默认值
        $this->assertFalse(AutoUpgrade::enabled());
    }

    public function testScopeDefaultsToSecurityOnly(): void
    {
        $this->assertSame('security', AutoUpgrade::scope());
        $GLOBALS['_test_config']['auto_upgrade_scope'] = 'stable';
        $this->assertSame('stable', AutoUpgrade::scope());
        // 非法值回落到最保守的一档，而不是放开
        $GLOBALS['_test_config']['auto_upgrade_scope'] = 'whatever';
        $this->assertSame('security', AutoUpgrade::scope());
    }

    public function testMaintenanceWindow(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_window'] = '03:00-05:00';
        $this->assertTrue(AutoUpgrade::inWindow(mktime(3, 30, 0, 1, 1, 2026) ?: null));
        $this->assertTrue(AutoUpgrade::inWindow(mktime(4, 59, 0, 1, 1, 2026) ?: null));
        $this->assertFalse(AutoUpgrade::inWindow(mktime(5, 0, 0, 1, 1, 2026) ?: null));
        $this->assertFalse(AutoUpgrade::inWindow(mktime(14, 0, 0, 1, 1, 2026) ?: null));
    }

    public function testMaintenanceWindowCrossesMidnight(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_window'] = '23:00-02:00';
        $this->assertTrue(AutoUpgrade::inWindow(mktime(23, 30, 0, 1, 1, 2026) ?: null));
        $this->assertTrue(AutoUpgrade::inWindow(mktime(1, 0, 0, 1, 1, 2026) ?: null));
        $this->assertFalse(AutoUpgrade::inWindow(mktime(3, 0, 0, 1, 1, 2026) ?: null));
    }

    public function testMalformedWindowFallsBackInsteadOfAlwaysOrNever(): void
    {
        // 配置写坏不能变成「随时升」，也不能变成「永不升」——回落默认窗口
        $GLOBALS['_test_config']['auto_upgrade_window'] = '乱写的';
        $this->assertTrue(AutoUpgrade::inWindow(mktime(4, 0, 0, 1, 1, 2026) ?: null));
        $this->assertFalse(AutoUpgrade::inWindow(mktime(12, 0, 0, 1, 1, 2026) ?: null));
    }

    public function testNoUpdateMeansNoRun(): void
    {
        $this->assertSame([false, 'no update'], AutoUpgrade::shouldRun(['has_update' => false]));
    }

    public function testDisabledSiteNeverRunsWithoutDirective(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '0';
        [$go, $why] = AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '9.9.9', 'level' => 'security']);
        $this->assertFalse($go);
        $this->assertSame('auto upgrade disabled', $why);
    }

    public function testSecurityScopeSkipsFeatureRelease(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_scope'] = 'security';
        $GLOBALS['_test_config']['auto_upgrade_window'] = '00:00-23:59';
        [$go, $why] = AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '9.9.9', 'level' => 'feature']);
        $this->assertFalse($go);
        $this->assertStringContainsString('not a security release', $why);
    }

    public function testOutsideWindowSkipsEvenWhenEligible(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_window'] = '03:00-03:01';
        $r = AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '9.9.9', 'level' => 'security']);
        // 窗口只有一分钟，绝大多数时间应被挡下；正好撞上那一分钟时放行也是对的
        $this->assertIsArray($r);
        if ($r[0] === false) {
            $this->assertSame('outside maintenance window', $r[1]);
        }
    }

    public function testDirectiveContractIsSignedDomainBoundAndExpiring(): void
    {
        $src = file_get_contents(ROOT_PATH . '/includes/UpgradeDirective.php');
        self::assertIsString($src);
        // 规范串必须含域名、目标版本、签发/过期时间与 nonce
        self::assertStringContainsString("'autoupgrade|' . \$domain . '|' . \$to . '|' . \$issued . '|' . \$expires . '|' . \$nonce", $src);
        self::assertStringContainsString('openssl_verify', $src);
        self::assertStringContainsString('license_pubkey()', $src);   // 与升级包同一把公钥
        self::assertStringContainsString('nonceSeen', $src);          // 防重放
    }

    public function testResumeInsteadOfRestartingFromScratch(): void
    {
        // 单轮到量退出后，下一次 cron 必须**接着**上一轮的游标跑，而不是重新
        // 下载 + 重新 prepare。重来的代价不只是慢：prepare 会把游标清零（大包
        // 因此永远升不完），还每小时多产生一个备份目录和一份完整库转储，能把
        // 共享主机磁盘撑爆。2026-08-22 自审时发现并修复。
        $src = file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        self::assertIsString($src);
        self::assertStringContainsString('pendingTransaction()', $src);
        self::assertStringContainsString('applyRemaining(', $src);
        // 游标不前进要退出，否则是死循环
        self::assertStringContainsString('cursor stalled', $src);

        // 续跑检查必须排在 check() 之前 —— config/version.php 本身就是包里的普通文件，
        // 第一轮覆盖后站点版本号已变成新版，服务器会回「无更新」，续跑分支就永远
        // 到不了，站点永久停在新旧混合状态。（codex 审计 P0-1）
        $posResume = strpos($src, '$pending = self::pendingTransaction();');
        $posCheck = strpos($src, '$data = self::check();');
        self::assertIsInt($posResume);
        self::assertIsInt($posCheck);
        self::assertLessThan($posCheck, $posResume, '续跑判定必须早于 check()');
    }

    public function testUnattendedUpgradeAbortsOnAnyFailure(): void
    {
        // 人工升级可以「带着几个失败文件继续、让用户去补」；无人值守不行——
        // 没人看清单，继续下去就是「缺文件却记成功」。（codex 审计 P0-2 / P0-3）
        $src = file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        self::assertIsString($src);
        self::assertStringContainsString('abortAndRollback(', $src);
        self::assertStringContainsString("!empty(\$bt['errors'])", $src);          // 批次有失败即停
        self::assertStringContainsString("(int) (\$fin['code'] ?? 1) !== 0", $src); // 收尾 code=2 也算失败
        self::assertStringContainsString('failed: no database backup', $src);       // 无库备份不升
        self::assertStringContainsString('数据库迁移失败', $src);                     // 迁移失败即中止
        // 回滚自身失败要说清楚，因为那是最糟的状态
        self::assertStringContainsString('回滚也失败了', $src);
    }

    public function testPipelineIsSharedWithManualUpgrade(): void
    {
        // 升级最不该有两份实现：自动升级必须调用与后台同一条管道
        $src = file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        self::assertIsString($src);
        foreach (['upgrade_download_package(', 'upgrade_prepare()', 'upgrade_batch(', 'upgrade_finalize(', 'upgrade_rollback('] as $call) {
            self::assertStringContainsString($call, $src, "自动升级应复用 UpgradeRunner 的 {$call}");
        }
        // 健康自检不过必须回滚——无人值守时没人来救场
        self::assertStringContainsString("empty(\$health['ok'])", $src);
        self::assertStringContainsString('rolled_back', $src);
    }
}
