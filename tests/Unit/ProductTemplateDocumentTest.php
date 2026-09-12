<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class ProductTemplateDocumentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testScopeIsFailClosedAndSpecificTemplateWins(): void
    {
        $product = ['id' => 12, 'lang' => 'ja'];
        $this->assertFalse(ProductTemplateDocument::matches([], $product));
        $this->assertFalse(ProductTemplateDocument::matches(['mode' => 'all', 'lang' => 'en'], $product));
        $this->assertFalse(ProductTemplateDocument::matches(['mode' => 'oops', 'lang' => 'ja'], $product));
        $row = static fn(int $id, array $scope): array => ['id' => $id, 'type' => 'product-detail', 'status' => 1,
            'published_data' => json_encode(['schema' => 1, 'settings' => ['product_template' => $scope], 'sections' => []], JSON_THROW_ON_ERROR)];
        $global = $row(9, ['mode' => 'all', 'lang' => 'ja']);
        $specific = $row(2, ['mode' => 'selected', 'ids' => ['12'], 'lang' => 'ja']);
        $this->assertSame(2, ProductTemplateDocument::resolve([$global, $specific], $product)['id']);
        $specific['status'] = 0;
        $this->assertSame(9, ProductTemplateDocument::resolve([$global, $specific], $product)['id']);
    }

    public function testBindingsRenderCurrentRecordAndNeverStoreItsValues(): void
    {
        $json = ProductTemplateDocument::seed('ja');
        $this->assertStringContainsString('product-title', $json);
        $first = ['id' => 1, 'lang' => 'ja', 'title' => 'FIRST <script>bad</script>', 'content' => '<p>One</p><script>alert(1)</script>', 'cover' => ''];
        $second = ['id' => 2, 'lang' => 'ja', 'title' => 'SECOND', 'content' => '<p>Two</p>', 'cover' => ''];
        $render = static fn(): string => BlockRenderer::render($json);
        $html = ProductTemplateDocument::withProduct($first, $render);
        $this->assertStringContainsString('FIRST', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('SECOND', $html);
        $this->assertStringContainsString('SECOND', ProductTemplateDocument::withProduct($second, $render));
        $this->assertNull(ProductTemplateDocument::currentProduct());
        $this->assertStringNotContainsString('FIRST', $json);
        $this->assertStringNotContainsString('SECOND', $json);
        $this->assertSame('', (new ProductFieldElement('title'))->render([]));
    }

    public function testNativeSourceRetainsLayoutAndStopsAtWinningRule(): void
    {
        $original = ProductTemplateDocument::seed('en');
        $document = BloxDocumentPipeline::decode($original);
        $document['settings']['product_template']['ids'] = [12];
        $original = json_encode($document, JSON_THROW_ON_ERROR);
        $native = ProductTemplateDocument::changeSource($original, 'native');
        $row = ['id' => 1, 'type' => 'product-detail', 'status' => 1, 'published_data' => $native];
        $globalDocument = $document;
        $globalDocument['settings']['product_template']['mode'] = 'all';
        $global = ['id' => 99, 'type' => 'product-detail', 'status' => 1, 'published_data' => json_encode($globalDocument, JSON_THROW_ON_ERROR)];
        $winner = ProductTemplateDocument::resolve([$global, $row], ['id' => 12, 'lang' => 'en']);
        $this->assertSame(1, $winner['id']);
        $this->assertTrue(ProductTemplateDocument::usesNative($winner));
        $this->assertFalse(ProductTemplateDocument::usesNative(ProductTemplateDocument::resolve([$global, $row], ['id' => 13, 'lang' => 'en'])));
        $this->assertSame($document, BloxDocumentPipeline::decode(ProductTemplateDocument::changeSource($native, 'custom')));
        $normalized = BloxDocumentPipeline::process($native, 'test')['json'];
        $this->assertTrue(ProductTemplateDocument::usesNative(['published_data' => $normalized]));
    }

    public function testNestedContextsAndExceptionsRestoreTheOuterProduct(): void
    {
        ProductTemplateDocument::withProduct(['id' => 1], function (): string {
            try {
                ProductTemplateDocument::withProduct(['id' => 2], static function (): string { throw new RuntimeException('Expected'); });
            } catch (RuntimeException) {
                $this->assertSame(1, ProductTemplateDocument::currentProduct()['id']);
            }
            return '';
        });
        $this->assertNull(ProductTemplateDocument::currentProduct());
    }

    public function testTemplatePackagePreservesBindingsAndScope(): void
    {
        $json = ProductTemplateDocument::seed('en');
        $package = BloxTemplateImporter::exportJson([
            'id' => 1, 'name' => 'Product layout', 'type' => 'product-detail', 'draft_data' => $json,
        ]);
        $prepared = BloxTemplateImporter::prepare($package);
        $this->assertSame('product-detail', $prepared['type']);
        $scope = BloxDocumentPipeline::decode($prepared['draft_json'])['settings']['product_template'];
        $this->assertSame(['mode' => 'selected', 'ids' => [], 'lang' => 'en'], $scope);
        $this->assertContains('product-title', $prepared['requirements']['elements']);
    }
}
