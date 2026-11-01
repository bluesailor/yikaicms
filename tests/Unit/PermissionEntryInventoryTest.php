<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PermissionEntryInventoryTest extends TestCase
{
    public function testNewEntryFilesOrActionsRequireInventoryReview(): void
    {
        $output=[]; $code=0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__,2).'/tools/check_permission_inventory.php').' 2>&1',$output,$code);
        self::assertSame(0,$code,implode("\n",$output));
    }
}
