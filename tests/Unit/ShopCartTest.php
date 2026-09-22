<?php
/**
 * 购物车会话模型测试（M1-b）。
 *
 * 查库通过 $lookup 注入假货——不触库即可覆盖库存/行数/合并/清洗分支。
 * 价格红线（车里只存 id+qty）与翻译组键语义由行为断言锁死。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShopCartTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/money.php';
        require_once ROOT_PATH . '/plugins/shop/lib/sales.php';
        require_once ROOT_PATH . '/plugins/shop/lib/cart.php';
    }

    protected function setUp(): void
    {
        $_SESSION['shop_cart'] = [];
    }

    /** 库存 5、在售的假销售行。 */
    private static function lookup(int $id): ?array
    {
        return $id === 7 ? ['product_id' => 7, 'sku' => 'S7', 'sale_price' => '19.90', 'stock' => 5, 'status' => 1] : null;
    }

    public function testAddStoresOnlyIdAndQty(): void
    {
        $r = shopCartAdd(7, 2, self::lookup(...));
        $this->assertTrue($r['ok'], $r['error']);
        // 红线：session 里只有 [id, qty]——价格绝不落车
        $this->assertSame([['id' => 7, 'variant' => '', 'qty' => 2]], $_SESSION['shop_cart']);
        $this->assertSame(2, shopCartCount());
    }

    public function testAddMergesSameLineAndRejectsOverStock(): void
    {
        shopCartAdd(7, 3, self::lookup(...));
        $r = shopCartAdd(7, 2, self::lookup(...));   // 3+2=5 恰好到库存上限
        $this->assertTrue($r['ok']);
        $this->assertSame([['id' => 7, 'variant' => '', 'qty' => 5]], $_SESSION['shop_cart']);

        $r = shopCartAdd(7, 1, self::lookup(...));   // 6 > 5：拒绝
        $this->assertFalse($r['ok']);
        $this->assertSame('shop_err_out_of_stock', $r['error']);
        $this->assertSame(5, shopCartCount(), '拒绝后原行不动');
    }

    public function testAddRejectsNotOnSaleAndBadQty(): void
    {
        $this->assertSame('shop_err_not_on_sale', shopCartAdd(999, 1, self::lookup(...))['error']);
        $this->assertSame('shop_err_qty', shopCartAdd(7, 0, self::lookup(...))['error']);
        $this->assertSame('shop_err_qty', shopCartAdd(7, 1000, self::lookup(...))['error']);
        $this->assertSame('shop_err_product', shopCartAdd(0, 1, self::lookup(...))['error']);
    }

    public function testLineCountCapAt50(): void
    {
        $many = static fn(int $id): ?array => ['product_id' => $id, 'stock' => 99, 'status' => 1, 'sku' => '', 'sale_price' => '1.00'];
        for ($id = 1; $id <= 50; $id++) {
            $this->assertTrue(shopCartAdd($id, 1, $many)['ok']);
        }
        $this->assertSame('shop_err_too_many_lines', shopCartAdd(51, 1, $many)['error']);
        $this->assertCount(50, shopCartLines());
    }

    public function testSetQtyUpdatesAndZeroRemoves(): void
    {
        shopCartAdd(7, 2, self::lookup(...));
        $this->assertTrue(shopCartSetQty(7, 4)['ok']);
        $this->assertSame([['id' => 7, 'variant' => '', 'qty' => 4]], $_SESSION['shop_cart']);

        shopCartSetQty(7, 0);
        $this->assertSame([], shopCartLines(), 'qty=0 即移除');

        $this->assertSame('shop_err_qty', shopCartSetQty(7, -1)['error']);
        $this->assertSame('shop_err_qty', shopCartSetQty(7, 1000)['error']);
    }

    public function testRemoveKeepsOtherLinesOrder(): void
    {
        $any = static fn(int $id): ?array => ['stock' => 9, 'status' => 1];
        shopCartAdd(1, 1, $any);
        shopCartAdd(2, 2, $any);
        shopCartAdd(3, 3, $any);
        shopCartRemove(2);
        $this->assertSame(
            [['id' => 1, 'variant' => '', 'qty' => 1], ['id' => 3, 'variant' => '', 'qty' => 3]],
            shopCartLines(),
            '移除中间行后其余行序稳定'
        );
    }

    public function testMalformedSessionDataIsSanitized(): void
    {
        $_SESSION['shop_cart'] = [
            ['id' => 7, 'qty' => 2],
            'garbage',
            ['id' => 0, 'qty' => 5],      // 非法 id 丢弃
            ['id' => 8, 'qty' => -3],     // 非法 qty 丢弃
            ['id' => 9],                  // 缺 qty 丢弃
        ];
        $this->assertSame([['id' => 7, 'variant' => '', 'qty' => 2]], shopCartLines());
        $this->assertSame(2, shopCartCount());
    }

    public function testNonArraySessionTreatedAsEmpty(): void
    {
        $_SESSION['shop_cart'] = 'corrupted';
        $this->assertSame([], shopCartLines());
        $this->assertSame(0, shopCartCount());
        shopCartSetQty(7, 1);
        $this->assertSame([['id' => 7, 'variant' => '', 'qty' => 1]], $_SESSION['shop_cart']);
    }

    public function testClear(): void
    {
        shopCartAdd(7, 1, self::lookup(...));
        shopCartClear();
        $this->assertSame([], shopCartLines());
    }
}
