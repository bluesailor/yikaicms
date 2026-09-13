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

    /**
     * TASK-002-R02 针对性用例 ①：v2 文档的"恢复系统默认/恢复自定义"必须写进 v2。
     *
     * 此前 changeSource() 只写 v1，而渲染侧 v2 优先 → 切换根本没生效。
     */
    public function testChangeSourceTargetsTheV2ContractWhenPresent(): void
    {
        $document = [
            'schema' => 1,
            'settings' => [
                'detail_template' => [
                    'version' => 2, 'content_type' => 'product', 'lang' => 'zh-CN', 'source' => 'custom',
                    'priority' => 0, 'include' => [['kind' => 'item', 'ids' => [6, 1], 'include_children' => false]],
                    'exclude' => [], 'legacy' => false,
                ],
            ],
            'sections' => [],
        ];
        $json = json_encode($document, JSON_THROW_ON_ERROR);

        $native = BloxDocumentPipeline::decode(ProductTemplateDocument::changeSource($json, 'native'));
        $this->assertSame('native', $native['settings']['detail_template']['source'], 'v2 source 必须变成 native');
        $this->assertSame(
            [['kind' => 'item', 'ids' => [6, 1], 'include_children' => false]],
            $native['settings']['detail_template']['include'],
            '切默认不能顺手改掉范围'
        );
        $this->assertArrayNotHasKey('product_template', $native['settings'], 'v2 文档不应凭空长出 v1 字段');
        $this->assertTrue(ProductTemplateDocument::usesNative(['published_data' => json_encode($native, JSON_THROW_ON_ERROR)]));

        $custom = BloxDocumentPipeline::decode(
            ProductTemplateDocument::changeSource(json_encode($native, JSON_THROW_ON_ERROR), 'custom')
        );
        $this->assertSame('custom', $custom['settings']['detail_template']['source']);
        $this->assertFalse(ProductTemplateDocument::usesNative(['published_data' => json_encode($custom, JSON_THROW_ON_ERROR)]));
    }

    /** TASK-002-R02 针对性用例 ②：v2 改范围要落到 v2，且保留后台表单表达不了的 category 规则。 */
    public function testUiScopeEditsLandInV2AndKeepCategoryRules(): void
    {
        $document = [
            'schema' => 1,
            'settings' => [
                'detail_template' => [
                    'version' => 2, 'content_type' => 'product', 'lang' => 'zh-CN', 'source' => 'custom',
                    'priority' => 0,
                    'include' => [
                        ['kind' => 'category', 'ids' => [5], 'include_children' => true],
                        ['kind' => 'item', 'ids' => [1], 'include_children' => false],
                    ],
                    'exclude' => [], 'legacy' => false,
                ],
            ],
            'sections' => [],
        ];

        $all = ProductTemplateDocument::applyUiScope($document, ['mode' => 'all', 'ids' => [], 'lang' => 'zh-CN']);
        $kinds = array_column($all['settings']['detail_template']['include'], 'kind');
        $this->assertContains('all', $kinds, '改成"全部产品"要落到 v2');
        $this->assertContains('category', $kinds, 'category 规则不能被后台表单吞掉');
        $this->assertSame('all', ProductTemplateDocument::authoritativeScope($all)['mode']);

        $selected = ProductTemplateDocument::applyUiScope($document, ['mode' => 'selected', 'ids' => [7, 9], 'lang' => 'zh-CN']);
        $scope = ProductTemplateDocument::authoritativeScope($selected);
        $this->assertSame('selected', $scope['mode']);
        $this->assertSame([7, 9], $scope['ids'], '后台勾选的产品要写进 v2 的 item 规则');
        $this->assertContains('category', array_column($selected['settings']['detail_template']['include'], 'kind'));

        // 已存在 v1 镜像的文档（v2 + v1 同时在场）：镜像同步，权威仍读 v2
        $mirrored = $document;
        $mirrored['settings']['product_template'] = ['mode' => 'selected', 'ids' => [1], 'lang' => 'zh-CN'];
        $mirrored = ProductTemplateDocument::applyUiScope($mirrored, ['mode' => 'selected', 'ids' => [7], 'lang' => 'zh-CN']);
        $this->assertArrayHasKey('detail_template', $mirrored['settings'], '夹具必须同时含 v2，否则测不到同步');
        $this->assertSame([7], ProductTemplateDocument::authoritativeScope($mirrored)['ids'], '权威值来自 v2');
        $this->assertSame([7], ProductTemplateDocument::normalizeScope($mirrored['settings']['product_template'])['ids'], 'v1 镜像同步更新');
    }

    /** TASK-002-R02 针对性用例 ③：v1-only 历史模板行为不变（不主动创建 v2）。 */
    public function testLegacyV1DocumentsKeepTheirBehaviour(): void
    {
        $document = [
            'schema' => 1,
            'settings' => ['product_template' => ['mode' => 'selected', 'ids' => [3], 'lang' => 'en']],
            'sections' => [],
        ];
        $json = json_encode($document, JSON_THROW_ON_ERROR);

        $native = BloxDocumentPipeline::decode(ProductTemplateDocument::changeSource($json, 'native'));
        $this->assertSame('native', $native['settings']['product_template']['source']);
        $this->assertArrayNotHasKey('detail_template', $native['settings'], 'v1 模板不应被自动改写成 v2');
        $this->assertSame([3], ProductTemplateDocument::normalizeScope($native['settings']['product_template'])['ids']);

        $edited = ProductTemplateDocument::applyUiScope($document, ['mode' => 'all', 'ids' => [], 'lang' => 'en']);
        $this->assertSame('all', $edited['settings']['product_template']['mode']);
        $this->assertArrayNotHasKey('detail_template', $edited['settings']);
        $this->assertSame('all', ProductTemplateDocument::authoritativeScope($edited)['mode']);
    }
}
