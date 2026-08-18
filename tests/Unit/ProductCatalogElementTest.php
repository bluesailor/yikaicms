<?php
/** Blox product-page catalog contract. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductCatalogElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testCatalogIsOnlyOfferedInsideProductPageEditor(): void
    {
        $productMeta = \BuilderRegistry::meta('product');
        $pageMeta = \BuilderRegistry::meta('page');

        self::assertTrue($productMeta['product-catalog']['paletteVisible']);
        self::assertFalse($pageMeta['product-catalog']['paletteVisible']);
        self::assertTrue($productMeta['product-catalog']['dynamic']);
        self::assertSame('4', $productMeta['product-catalog']['defaults']['columns']);
    }

    public function testCatalogPreviewKeepsProductsDynamic(): void
    {
        $source = (string) file_get_contents(
            ROOT_PATH . '/includes/builder/elements/ProductCatalogElement.php'
        );

        self::assertStringContainsString('new ListDynamicElement()', $source);
        self::assertStringContainsString("'query_source' => 'type:product'", $source);
        self::assertStringContainsString('self::$runtimeContext', $source);
    }

    public function testProductPageFlowUsesPublishedBlocksWithoutReplacingClassicPageEarly(): void
    {
        $document = (string) file_get_contents(ROOT_PATH . '/includes/builder/PageBloxDocument.php');
        $frontend = (string) file_get_contents(ROOT_PATH . '/list.php');
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        $admin = (string) file_get_contents(ROOT_PATH . '/admin/product.php');

        self::assertStringContainsString("in_array(\$type, ['page', 'product'], true)", $document);
        self::assertStringContainsString("'type' => 'product-catalog'", $document);
        self::assertStringContainsString('$hasPublishedProductBlox', $frontend);
        self::assertStringContainsString('ProductCatalogElement::setRuntimeContext', $frontend);
        self::assertStringContainsString("\$isProductBlox ? 'product'", $editor);
        self::assertStringContainsString('/admin/blox_editor.php?id=', $admin);
    }
}
