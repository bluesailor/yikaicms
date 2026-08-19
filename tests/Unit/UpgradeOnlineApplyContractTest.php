<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UpgradeOnlineApplyContractTest extends TestCase
{
    public function testOnlineUpgradeUsesPersistentServerCursorForApplyBatches(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/upgrade_online.php');
        self::assertNotFalse($source);

        self::assertStringContainsString("'next_offset' => 0", $source);
        self::assertStringContainsString('UpgradeApplyState::transact($sf', $source);
        self::assertStringContainsString('UpgradeApplyState::resolveOffset($state, $requestedOffset)', $source);
        self::assertStringContainsString('UpgradeApplyState::isComplete($state)', $source);
        self::assertStringNotContainsString("(int) (\$_POST['offset'] ?? 0)", $source);
    }
}
