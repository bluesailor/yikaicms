<?php
/** 商城订单 CSV 导出安全边界。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShopExportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/export.php';
    }

    public function testSpreadsheetFormulaPrefixesAreNeutralized(): void
    {
        self::assertSame("'=SUM(1,1)", shopCsvSafe('=SUM(1,1)'));
        self::assertSame("'  +cmd", shopCsvSafe('  +cmd'));
        self::assertSame("'-1", shopCsvSafe('-1'));
        self::assertSame("'@A1", shopCsvSafe('@A1'));
        self::assertSame('正常地址', shopCsvSafe('正常地址'));
        self::assertSame('13812345678', shopCsvSafe('13812345678'));
    }

    public function testExportIncludesFulfillmentColumns(): void
    {
        $headers = shopOrderExportHeaders();
        self::assertContains('items', $headers);
        self::assertContains('province', $headers);
        self::assertContains('tracking_company', $headers);
        self::assertContains('tracking_no', $headers);
    }
}
