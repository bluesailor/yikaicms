<?php
/** 商城 M3：Blox 商品详情购买组件与原生共用表单契约。 */

declare(strict_types=1);

use Yikai\Tests\TestCase;

final class ShopPurchaseElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        require_once ROOT_PATH . '/plugins/shop/lib/tables.php';
        require_once ROOT_PATH . '/plugins/shop/ShopPurchaseElement.php';
        if (!defined('ENCRYPT_KEY')) {
            define('ENCRYPT_KEY', 'shop-purchase-test-key-32-characters');
        }
        db()->execute(
            'CREATE TABLE IF NOT EXISTS settings ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE, value TEXT, '
            . '`group` TEXT, name TEXT, tip TEXT)'
        );
        settingModel()->clearCache();
        shopEnsureSchema();
    }

    protected function setUp(): void
    {
        db()->delete('shop_products', '1 = 1');
        ProductTemplateDocument::markPreview(false);
    }

    protected function tearDown(): void
    {
        ProductTemplateDocument::markPreview(false);
    }

    public function testElementOnlyAppearsInProductDetailPalette(): void
    {
        $element = new ShopPurchaseElement();

        $this->assertSame('shop/purchase', $element->type());
        $this->assertTrue($element->isDynamic());
        $this->assertTrue($element->paletteVisible('product-detail'));
        $this->assertFalse($element->paletteVisible('page'));
        $this->assertSame(
            ['show_price' => true, 'show_stock' => true, 'layout' => 'stacked', 'radius' => 'md'],
            $element->defaults()
        );
    }

    public function testPreviewUsesTranslationGroupStockAndCannotSubmit(): void
    {
        $this->seedSale(77, '12.34', 8);
        ProductTemplateDocument::markPreview();
        $html = ProductTemplateDocument::withProduct($this->product(), static function (): string {
            return (new ShopPurchaseElement())->render([
                'show_price' => true,
                'show_stock' => true,
                'layout' => 'stacked',
                'radius' => 'md',
            ]);
        });

        $this->assertStringContainsString('data-testid="shop-buy-form"', $html);
        $this->assertStringContainsString('12.34', $html);
        $this->assertStringContainsString('max="8"', $html);
        $this->assertStringContainsString('<fieldset disabled', $html);
        $this->assertStringContainsString('data-yk-preview="1"', $html);
        $this->assertStringNotContainsString('action="/shop/api"', $html);
        $this->assertStringNotContainsString('name="sig"', $html);
    }

    public function testLiveFormPostsLanguageRowIdWithSignedToken(): void
    {
        $this->seedSale(77, '12.34', 8);
        $html = ProductTemplateDocument::withProduct($this->product(), static function (): string {
            return (new ShopPurchaseElement())->render([
                'show_price' => false,
                'show_stock' => false,
                'layout' => 'inline',
                'radius' => 'none',
            ]);
        });

        $this->assertStringContainsString('method="post" action="/shop/api"', $html);
        $this->assertStringContainsString('name="pid" value="81"', $html);
        $this->assertStringContainsString('name="sig"', $html);
        $this->assertStringNotContainsString('data-testid="shop-buy-price"', $html);
        $this->assertStringNotContainsString('text-xs text-gray-400', $html);
    }

    public function testUnavailableProductOnlyGetsAnEditorPlaceholder(): void
    {
        ProductTemplateDocument::markPreview();
        $preview = ProductTemplateDocument::withProduct($this->product(), static function (): string {
            return (new ShopPurchaseElement())->render([]);
        });
        $this->assertStringContainsString('shop-buy-preview-unavailable', $preview);

        ProductTemplateDocument::markPreview(false);
        $live = ProductTemplateDocument::withProduct($this->product(), static function (): string {
            return (new ShopPurchaseElement())->render([]);
        });
        $this->assertSame('', $live);
    }

    /** @return array<string,mixed> */
    private function product(): array
    {
        return [
            'id' => 81,
            'translation_group_id' => 77,
            'title' => 'Translated product',
            'price' => '19.90',
        ];
    }

    private function seedSale(int $canonicalId, string $price, int $stock): void
    {
        db()->insert('shop_products', [
            'product_id' => $canonicalId,
            'sku' => 'SKU-' . $canonicalId,
            'price' => $price,
            'stock' => $stock,
            'status' => 1,
            'sales' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
