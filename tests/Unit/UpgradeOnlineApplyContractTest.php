<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UpgradeOnlineApplyContractTest extends TestCase
{
    public function testOnlineUpgradeUsesPersistentServerCursorForApplyBatches(): void
    {
        $source = file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        self::assertNotFalse($source);

        self::assertStringContainsString("'next_offset' => 0", $source);
        self::assertStringContainsString('UpgradeApplyState::transact($sf', $source);
        self::assertStringContainsString('UpgradeApplyState::resolveOffset($state, $requestedOffset)', $source);
        self::assertStringContainsString('UpgradeApplyState::isComplete($state)', $source);
        self::assertStringNotContainsString("(int) (\$_POST['offset'] ?? 0)", $source);
    }

    public function testFinalizeGuardsAndFailureSemanticsAreRetained(): void
    {
        $source = file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        self::assertNotFalse($source);

        // finalize：新 state 未走完全部条目时拒绝提前收尾。
        self::assertStringContainsString("__('upgrade_apply_incomplete')", $source);
        // 单文件写失败只累计 errors、不中断批次（避免一个不可写文件卡死升级）。
        self::assertStringContainsString('写入失败: $rel', $source);
        // 失败清单在删状态文件前落持久日志，供事后补文件。
        self::assertStringContainsString('upgrade-failures.log', $source);
        // finalize 成功路径删除状态文件——重放批次由 invalid_state 兜住。
        self::assertStringContainsString('uo_unlink_if_exists($sf)', $source);
    }

    public function testOnlineUpgradeRejectsAReleaseAboveTheCurrentPhpVersion(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/upgrade_online.php');
        self::assertNotFalse($source);

        self::assertStringContainsString("\$data['data']['min_php']", $source);
        self::assertStringContainsString("version_compare(PHP_VERSION, \$minPhp, '<')", $source);
        self::assertStringContainsString("'error_code' => 'php_version_too_low'", $source);
        self::assertStringContainsString("__('upgrade_php_version_required'", $source);
    }
}
