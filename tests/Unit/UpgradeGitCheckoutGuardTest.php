<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 站点目录是 Git 检出时，升级不得静默覆盖源码（2026-10-10：本机开发站在线升级到 2.0.6，改写了 97 个工作树文件）。
 * 自动 / 远程升级跳过；后台手工升级要 confirm_git_checkout 才继续。
 */
final class UpgradeGitCheckoutGuardTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDetectsGitDirectoryAndWorktreeFile(): void
    {
        require_once ROOT_PATH . '/includes/UpgradeRunner.php';
        $root = sys_get_temp_dir() . '/yk-git-guard-' . bin2hex(random_bytes(4));
        mkdir($root);
        self::assertFalse(uo_is_git_checkout($root), '普通部署目录不受影响');

        mkdir($root . '/.git');
        self::assertTrue(uo_is_git_checkout($root), '.git 目录 = 检出');
        rmdir($root . '/.git');

        file_put_contents($root . '/.git', "gitdir: D:/repo/.git/worktrees/x\n");
        self::assertTrue(uo_is_git_checkout($root . '/'), 'git worktree 的 .git 是文件，也算检出');
        unlink($root . '/.git');
        rmdir($root);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testExplicitConstantTurnsTheGuardOff(): void
    {
        define('YK_UPGRADE_ALLOW_GIT_CHECKOUT', true);
        require_once ROOT_PATH . '/includes/UpgradeRunner.php';
        $root = sys_get_temp_dir() . '/yk-git-guard-' . bin2hex(random_bytes(4));
        mkdir($root . '/.git', 0777, true);
        self::assertFalse(uo_is_git_checkout($root));
        rmdir($root . '/.git');
        rmdir($root);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPrepareStopsBeforeTouchingAnythingUnlessConfirmed(): void
    {
        if (!file_exists(ROOT_PATH . '/.git')) {
            self::markTestSkipped('测试目录不是 Git 检出（发行包里跑）');
        }
        require_once ROOT_PATH . '/includes/UpgradeRunner.php';

        $blocked = upgrade_prepare();
        self::assertSame(1, $blocked['code']);
        self::assertSame('git_checkout', $blocked['error_code']);

        // 确认之后才走到原来的流程（这里没有下载好的包，所以停在「未找到安装包」，而不是 Git 检出）
        $confirmed = upgrade_prepare('', '', false, false, false, true);
        self::assertSame(1, $confirmed['code']);
        self::assertArrayNotHasKey('error_code', $confirmed);
    }

    public function testAutomaticAndRemoteUpgradesSkipCheckoutsAfterResumingPendingWork(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        $run = substr($source, (int) strpos($source, 'public static function run('));
        $guard = strpos($run, 'uo_is_git_checkout(ROOT_PATH)');
        self::assertNotFalse($guard, 'AutoUpgrade::run 必须检查 Git 检出');
        // 已开始的事务先续完（不能停在新旧混合状态），再判断检出；判断在联系更新服务器之前
        self::assertLessThan($guard, strpos($run, 'self::pendingTransaction()'));
        self::assertLessThan(strpos($run, 'self::check()'), $guard);

        $page = (string) file_get_contents(ROOT_PATH . '/admin/upgrade_online.php');
        self::assertStringContainsString("post('confirm_git_checkout') === '1'", $page);
        self::assertStringContainsString("pre.error_code === 'git_checkout'", $page, '后台要让站长确认后重试');
    }
}
