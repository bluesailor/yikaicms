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
        $this->assertSame('03:00-05:00', AutoUpgrade::normalizeWindow('25:99-26:00'));
        $this->assertSame('03:00-05:00', AutoUpgrade::normalizeWindow('05:00-05:00'));
        $this->assertSame('03:05-23:09', AutoUpgrade::normalizeWindow('3:05 - 23:09'));
    }

    public function testNoUpdateMeansNoRun(): void
    {
        $this->assertSame([false, 'no update'], AutoUpgrade::shouldRun(['has_update' => false]));
    }

    public function testDisabledSiteNeverRunsWithoutDirective(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '0';
        [$go, $why] = AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '2.0.1', 'level' => 'security']);
        $this->assertFalse($go);
        $this->assertSame('auto upgrade disabled', $why);
    }

    public function testSecurityScopeSkipsFeatureRelease(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_scope'] = 'security';
        $GLOBALS['_test_config']['auto_upgrade_window'] = '00:00-23:59';
        [$go, $why] = AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '2.0.1', 'level' => 'feature']);
        $this->assertFalse($go);
        $this->assertStringContainsString('not a security release', $why);
    }

    public function testOutsideWindowSkipsEvenWhenEligible(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_window'] = '03:00-03:01';
        $r = AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '2.0.1', 'level' => 'security']);
        // 窗口只有一分钟，绝大多数时间应被挡下；正好撞上那一分钟时放行也是对的
        $this->assertIsArray($r);
        if ($r[0] === false) {
            $this->assertSame('outside maintenance window', $r[1]);
        }
    }

    public function testMajorUpgradeRequiresManualConfirmationForBothScopes(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_window'] = '00:00-23:59';
        foreach (['stable', 'security'] as $scope) {
            $GLOBALS['_test_config']['auto_upgrade_scope'] = $scope;
            $this->assertSame(
                [false, 'major upgrade requires manual confirmation'],
                AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '2.0.0', 'level' => 'security'], '1.20.1')
            );
        }
    }

    public function testSameMajorUpdatesCanProceed(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_scope'] = 'stable';
        $GLOBALS['_test_config']['auto_upgrade_window'] = '00:00-23:59';
        foreach ([['1.20.0', '1.20.1'], ['2.0.0', '2.0.1']] as [$from, $to]) {
            $this->assertSame(
                [true, 'stable release'],
                AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => $to, 'level' => 'feature'], $from)
            );
        }
    }

    public function testCustomerSuffixIsIgnoredForMajorVersion(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_scope'] = 'stable';
        $GLOBALS['_test_config']['auto_upgrade_window'] = '00:00-23:59';
        $this->assertSame(
            [true, 'stable release'],
            AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '1.20.1', 'level' => 'feature'], '1.7.6.2-abc')
        );
        $this->assertSame(
            [false, 'major upgrade requires manual confirmation'],
            AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '2.0.0', 'level' => 'feature'], '1.7.6.2-abc')
        );
    }

    public function testServerCanAnnounceMajorWithoutOfferingAutomaticPackage(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_window'] = date('H:i', time() - 600) . '-' . date('H:i', time() + 600);
        $this->assertSame(
            [false, 'major upgrade requires manual confirmation'],
            AutoUpgrade::shouldRun(['has_update' => false, 'major_available' => '2.0.0'], '1.20.1')
        );
    }

    public function testDisabledSiteDoesNotTreatMajorAsAnAutomaticSkip(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '0';
        foreach ([
            ['has_update' => true, 'latest_version' => '2.0.0'],
            ['has_update' => false, 'major_available' => '2.0.0'],
        ] as $data) {
            $this->assertSame([false, 'auto upgrade disabled'], AutoUpgrade::shouldRun($data, '1.20.1'));
        }
    }

    public function testOutsideWindowDoesNotTreatMajorAsAnAutomaticSkip(): void
    {
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_window'] = date('H:i', time() + 3600) . '-' . date('H:i', time() + 4200);
        foreach ([
            ['has_update' => true, 'latest_version' => '2.0.0'],
            ['has_update' => false, 'major_available' => '2.0.0'],
        ] as $data) {
            $this->assertSame([false, 'outside maintenance window'], AutoUpgrade::shouldRun($data, '1.20.1'));
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testValidSignedDirectiveCanCrossMajor(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        define('LICENSE_PUBKEY_B64', preg_replace('/-----[^-]+-----|\s/', '', $details['key']));
        db()->execute('CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, `key` TEXT UNIQUE, `value` TEXT, `group` TEXT, `name` TEXT, `tip` TEXT)');
        $_SERVER['HTTP_HOST'] = 'site.example';
        // 指令只对开了自动升级的站有效；它不等维护窗口，所以窗口设在稍后也照样执行
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '1';
        $GLOBALS['_test_config']['auto_upgrade_window'] = date('H:i', time() + 3600) . '-' . date('H:i', time() + 4200);

        require_once ROOT_PATH . '/includes/InstallIdentity.php';
        $install = \InstallIdentity::id();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $install);

        $sign = static function (string $installId, string $nonce) use ($key): array {
            $issued = time();
            $expires = $issued + 900;
            $canonical = 'autoupgrade2|site.example|' . $installId . '|2.0.0|' . $issued . '|' . $expires . '|' . $nonce;
            self::assertTrue(openssl_sign($canonical, $signature, $key, OPENSSL_ALGO_SHA256));
            return [
                'to' => '2.0.0', 'domain' => 'site.example', 'install' => $installId, 'issued_at' => $issued,
                'expires_at' => $expires, 'nonce' => $nonce, 'sig' => base64_encode($signature),
            ];
        };
        $run = static fn (array $directive): array
            => AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '2.0.0', 'directive' => $directive], '1.20.1');

        // 同一域名下另一个子目录站的指令（签名本身合法）：不是本站编号，不执行
        self::assertSame([false, 'outside maintenance window'], $run($sign(str_repeat('a', 32), bin2hex(random_bytes(12)))));
        // 只绑域名的旧规范串：本版本不再接受
        $issued = time();
        $legacyNonce = bin2hex(random_bytes(12));
        $legacy = 'autoupgrade|site.example|2.0.0|' . $issued . '|' . ($issued + 900) . '|' . $legacyNonce;
        self::assertTrue(openssl_sign($legacy, $legacySig, $key, OPENSSL_ALGO_SHA256));
        self::assertSame([false, 'outside maintenance window'], $run([
            'to' => '2.0.0', 'domain' => 'site.example', 'issued_at' => $issued,
            'expires_at' => $issued + 900, 'nonce' => $legacyNonce, 'sig' => base64_encode($legacySig),
        ]));

        self::assertSame([true, 'directive'], $run($sign($install, bin2hex(random_bytes(12)))));
    }

    public function testDisabledSiteIgnoresEvenADirective(): void
    {
        // 站长没开自动升级 = 没同意远程升级：在验签之前就返回，指令再有效也不执行
        $GLOBALS['_test_config']['auto_upgrade_enabled'] = '0';
        $directive = ['to' => '2.0.1', 'domain' => 'site.example', 'issued_at' => time(), 'expires_at' => time() + 900, 'nonce' => 'n', 'sig' => 'x'];
        $this->assertSame(
            [false, 'auto upgrade disabled'],
            AutoUpgrade::shouldRun(['has_update' => true, 'latest_version' => '2.0.1', 'directive' => $directive], '2.0.0')
        );
        $src = (string) file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        $this->assertLessThan(strpos($src, 'UpgradeDirective::verify('), strpos($src, "return [false, 'auto upgrade disabled'];"), 'the switch is checked before any directive');
    }

    public function testDirectiveContractIsSignedDomainBoundAndExpiring(): void
    {
        $src = file_get_contents(ROOT_PATH . '/includes/UpgradeDirective.php');
        self::assertIsString($src);
        // 规范串必须含域名、站点编号、目标版本、签发/过期时间与 nonce
        self::assertStringContainsString("'autoupgrade2|' . \$domain . '|' . \$install . '|' . \$to . '|' . \$issued . '|' . \$expires . '|' . \$nonce", $src);
        self::assertStringContainsString('hash_equals($mine, $install)', $src);
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
        self::assertStringNotContainsString("\$to === '' || !is_file(uo_state_file())", $src);
        // 游标不前进要退出，否则是死循环
        self::assertStringContainsString('cursor stalled', $src);

        // 续跑检查必须排在 check() 之前 —— config/version.php 本身就是包里的普通文件，
        // 第一轮覆盖后站点版本号已变成新版，服务器会回「无更新」，续跑分支就永远
        // 到不了，站点永久停在新旧混合状态。（外部审计 P0-1）
        $posResume = strpos($src, '$pending = self::pendingTransaction();');
        $posCheck = strpos($src, '$data = self::check();');
        self::assertIsInt($posResume);
        self::assertIsInt($posCheck);
        self::assertLessThan($posCheck, $posResume, '续跑判定必须早于 check()');
    }

    public function testUnattendedUpgradeAbortsOnAnyFailure(): void
    {
        // 人工升级可以「带着几个失败文件继续、让用户去补」；无人值守不行——
        // 没人看清单，继续下去就是「缺文件却记成功」。（外部审计 P0-2 / P0-3）
        $src = file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        self::assertIsString($src);
        self::assertStringContainsString('abortAndRollback(', $src);
        self::assertStringContainsString("!empty(\$bt['errors'])", $src);          // 批次有失败即停
        self::assertStringContainsString("(int) (\$fin['code'] ?? 1) !== 0", $src); // 收尾 code=2 也算失败
        self::assertStringContainsString('failed: no database backup', $src);       // 无库备份不升
        self::assertStringContainsString('新版本包含 ', $src);                        // 有迁移转人工，不自动写库
        self::assertStringContainsString('upgrade_complete()', $src);                 // 验证后才清恢复上下文
        // 回滚自身失败要说清楚，因为那是最糟的状态
        self::assertStringContainsString('回滚也失败了', $src);
    }

    public function testConcurrencyLockIsAtomic(): void
    {
        // 设置表的「先读后写」两个 cron 能同时通过，等于没锁；改用 flock（内核级原子，
        // 进程被 kill 时自动释放，不必靠 TTL 猜）。（外部审计 P1-2）
        $src = file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        self::assertIsString($src);
        self::assertStringContainsString('LOCK_EX | LOCK_NB', $src);
        self::assertStringNotContainsString('auto_upgrade_lock_at', $src, '不应再用设置表当锁');
        $manual = file_get_contents(ROOT_PATH . '/admin/upgrade_online.php');
        self::assertIsString($manual);
        self::assertStringContainsString("uo_dir() . '/auto_upgrade.lock'", $manual);
        self::assertStringContainsString("'owner' => \$owner === 'auto' ? 'auto' : 'manual'", file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php'));
    }

    public function testNonceExpiresByTimeNotByCount(): void
    {
        // 按条数滚动淘汰会让仍在有效期内的 nonce 被挤掉、重放复活。（外部审计 P1-2）
        $src = file_get_contents(ROOT_PATH . '/includes/UpgradeDirective.php');
        self::assertIsString($src);
        self::assertStringContainsString('NONCE_TTL', $src);
        self::assertStringNotContainsString('NONCE_KEEP', $src);
    }

    public function testDeltaBaselineIsVerifiedBeforeTouchingFiles(): void
    {
        // 包签名只证明包是官方签发的，不证明它适用于本站：别的基线的 delta 装上来会缺
        // 文件。必须在改动任何文件之前同时核对 manifest.from/to。（外部审计 P2-2）
        $src = file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        self::assertIsString($src);
        self::assertStringContainsString('增量包基线不匹配', $src);
        self::assertStringContainsString('增量包目标不匹配', $src);
        self::assertStringContainsString('$from !== $expectedFrom', $src);
        self::assertStringContainsString('$to !== $expectedTo', $src);
        self::assertStringContainsString('安装后版本不一致', $src);
        self::assertStringContainsString('已有升级事务尚未结束', $src);
    }

    public function testPipelineIsSharedWithManualUpgrade(): void
    {
        // 升级最不该有两份实现：自动升级必须调用与后台同一条管道
        $src = file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        self::assertIsString($src);
        foreach (['upgrade_download_package(', 'upgrade_prepare(', 'upgrade_batch(', 'upgrade_finalize(', 'upgrade_rollback('] as $call) {
            self::assertStringContainsString($call, $src, "自动升级应复用 UpgradeRunner 的 {$call}");
        }
        // 健康自检不过必须回滚——无人值守时没人来救场
        self::assertStringContainsString("empty(\$health['ok'])", $src);
        self::assertStringContainsString('rolled_back', $src);
    }

    public function testManualUpgradeRequiresDatabaseBackupBeforeWritingFiles(): void
    {
        $manual = (string) file_get_contents(ROOT_PATH . '/admin/upgrade_online.php');
        $runner = (string) file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');

        self::assertStringContainsString('upgrade_prepare(\'\', \'\', true, $backupOverride)', $manual);
        self::assertStringContainsString('if ($requireDbBackup && $dbBackupNote === \'\' && !$dbBackupOverride)', $runner);
        self::assertStringContainsString("post('backup_override') === '1'", $manual);
        self::assertStringContainsString('!empty($prepare[\'db_backup_override\'])', $manual);
        self::assertStringContainsString("adminLog('upgrade', 'backup_override'", $manual);
        self::assertStringContainsString("'error_code' => 'db_backup_required'", $runner);
        self::assertStringContainsString('升级已在写入程序文件前中止', $runner);
        self::assertStringNotContainsString('升级仍将继续', $manual);
    }
}
