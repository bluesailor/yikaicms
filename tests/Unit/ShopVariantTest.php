<?php
/** 商城有限 SKU 变体契约。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShopVariantTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/variants.php';
        require_once ROOT_PATH . '/plugins/shop/lib/cart.php';
    }

    protected function setUp(): void
    {
        $_SESSION['shop_cart'] = [];
    }

    public function testVariantLinesNormalizePriceStockAndStableIds(): void
    {
        $result = shopNormalizeVariantConfig("重量:1kg | PET-1 | 39.90 | 5\n重量:5kg | PET-5 | | 2");
        self::assertTrue($result['ok']);
        self::assertSame(7, $result['stock']);
        self::assertCount(2, $result['variants']);
        self::assertSame('39.90', $result['variants'][0]['price']);
        self::assertNull($result['variants'][1]['price']);
        self::assertSame(
            $result['variants'],
            shopProductVariantsFromJson($result['value'])
        );
        self::assertSame($result['variants'][0]['id'], shopVariantId('重量:1kg', 'PET-1'));
    }

    public function testMalformedDuplicateAndUnsafeVariantLinesFailClosed(): void
    {
        self::assertFalse(shopNormalizeVariantConfig('only three | fields | 1')['ok']);
        self::assertFalse(shopNormalizeVariantConfig("1kg | A | 10 | 1\n1kg | B | 20 | 2")['ok']);
        self::assertFalse(shopNormalizeVariantConfig("1kg\x01 | A | 10 | 1")['ok']);
        self::assertFalse(shopNormalizeVariantConfig('1kg | A | 0 | 1')['ok']);
        self::assertFalse(shopNormalizeVariantConfig('1kg | A | 10 | -1')['ok']);
        self::assertFalse(shopProductVariantsJsonValid('{broken'));
        self::assertFalse(shopProductVariantsJsonValid('[{"id":"wrong","label":"1kg","sku":"A","price":"10.00","stock":1}]'));
        self::assertTrue(shopProductVariantsJsonValid('[]'));
    }

    public function testCartSeparatesVariantsAndRequiresSelection(): void
    {
        $one = shopVariantId('1kg', 'PET-1');
        $five = shopVariantId('5kg', 'PET-5');
        $lookup = static function (int $id, string $variant) use ($one, $five): ?array {
            $base = ['product_id' => $id, 'status' => 1, 'has_variants' => true];
            return match ($variant) {
                $one => $base + ['stock' => 3, 'variant_id' => $one],
                $five => $base + ['stock' => 2, 'variant_id' => $five],
                '' => $base + ['stock' => 5],
                default => null,
            };
        };

        self::assertSame('shop_err_variant_required', shopCartAdd(7, 1, $lookup)['error']);
        self::assertTrue(shopCartAdd(7, 2, $lookup, $one)['ok']);
        self::assertTrue(shopCartAdd(7, 1, $lookup, $five)['ok']);
        self::assertSame([
            ['id' => 7, 'variant' => $one, 'qty' => 2],
            ['id' => 7, 'variant' => $five, 'qty' => 1],
        ], shopCartLines());
    }
}
