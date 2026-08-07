<?php
/** 第 1.5/2 轮：插件边界、统一文档管线与资源收集契约。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AbstractElement;
use BloxAssetCollector;
use BloxDocumentPipeline;
use BloxDocumentValidator;
use BloxPluginRegistry;
use BlockRenderer;
use BuilderRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/plugins/blox-example/BloxExampleNoticeElement.php';

final class BuilderPluginPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        BloxPluginRegistry::resetForTests();
        BloxAssetCollector::reset();
    }

    protected function tearDown(): void
    {
        BloxPluginRegistry::resetForTests();
        BloxAssetCollector::reset();
    }

    public function testPluginElementRequiresNamespacedTypeAndExportsSchema(): void
    {
        $element = new class extends AbstractElement {
            public function type(): string { return 'demo-addon/badge'; }
            public function label(): string { return '插件徽章'; }
            public function icon(): string { return 'badge'; }
            public function category(): string { return 'basic'; }
            public function controls(): array { return []; }
            public function render(array $data, string $children = ''): string { return '<span>badge</span>'; }
        };

        BloxPluginRegistry::registerElement('demo-addon', $element);
        $meta = BuilderRegistry::meta()['demo-addon/badge'];

        $this->assertFalse($meta['missing']);
        $this->assertSame('demo-addon', $meta['plugin']);
        $this->assertSame('插件徽章', $meta['label']);
    }

    public function testPluginElementRejectsUnnamespacedType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BloxPluginRegistry::registerElement('demo-addon', new class extends AbstractElement {
            public function type(): string { return 'plain-badge'; }
            public function label(): string { return '错误元素'; }
            public function icon(): string { return 'badge'; }
            public function category(): string { return 'basic'; }
            public function controls(): array { return []; }
            public function render(array $data, string $children = ''): string { return ''; }
        });
    }

    public function testInactivePluginManifestPreservesNodeAndExportsMissingMeta(): void
    {
        BloxPluginRegistry::declareManifest('inactive-demo', [
            'blox' => ['elements' => [[
                'type' => 'inactive-demo/widget',
                'label' => '停用组件',
                'container' => false,
            ]]],
        ]);

        $sections = $this->sections([[
            'id' => 'plugin-node',
            'type' => 'inactive-demo/widget',
            'data' => ['value' => 'kept'],
        ]]);
        BloxDocumentValidator::assertValidSections($sections);

        $meta = BuilderRegistry::meta()['inactive-demo/widget'];
        $this->assertTrue($meta['missing']);
        $this->assertFalse($meta['paletteVisible']);

        $html = BlockRenderer::renderElementNode($sections[0]['columns'][0]['elements'][0], 0, true, [0, 0, 0]);
        $this->assertStringContainsString('blox_plugin_missing_front', $html);
        $this->assertSame('', BlockRenderer::renderElementNode(
            $sections[0]['columns'][0]['elements'][0],
            0,
            false,
            [0, 0, 0]
        ));
    }

    public function testPluginTemplateProviderAddsSourceOwnership(): void
    {
        BloxPluginRegistry::registerTemplateProvider('demo-addon', static fn (string $context): array => [[
            'key' => 'hero',
            'name' => 'Hero',
            'context' => $context,
        ]]);

        $template = BloxPluginRegistry::templates('home')[0];
        $this->assertSame('plugin', $template['source']);
        $this->assertSame('demo-addon', $template['plugin']);
        $this->assertSame('home', $template['context']);
    }

    public function testPipelineNormalizesNestedIdsWithoutChangingDocumentShape(): void
    {
        $processed = BloxDocumentPipeline::process(json_encode([[
            'settings' => ['padding' => 'sm'],
            'columns' => [[
                'span' => 12,
                'elements' => [[
                    'type' => 'container',
                    'data' => ['children' => [[
                        'type' => 'heading',
                        'data' => ['text' => '标题'],
                    ]]],
                ]],
            ]],
        ]], JSON_UNESCAPED_UNICODE), 'test');

        $sections = $processed['sections'];
        $this->assertSame('test_s_0', $sections[0]['id']);
        $this->assertSame('test_c_0_0', $sections[0]['columns'][0]['id']);
        $this->assertSame('test_e_0_0_0', $sections[0]['columns'][0]['elements'][0]['id']);
        $this->assertSame(
            'test_e_0_0_0_0',
            $sections[0]['columns'][0]['elements'][0]['data']['children'][0]['id']
        );
        $this->assertSame($sections, json_decode($processed['json'], true));
    }

    public function testPipelineRejectsOversizedDocuments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_doc_too_large');
        BloxDocumentPipeline::process('[] ', 'test', 2);
    }

    public function testAssetCollectorLoadsOnlyUsedElementAssetsAndDeduplicates(): void
    {
        $element = new \BloxExampleNoticeElement();
        BloxAssetCollector::collectElement($element, []);
        BloxAssetCollector::collectElement($element, []);

        $this->assertSame(['/plugins/blox-example/assets/notice.js'], BloxAssetCollector::scripts());
        $this->assertSame(['/plugins/blox-example/assets/notice.css'], BloxAssetCollector::styles());

        BloxAssetCollector::addScript('https://example.com/remote.js');
        BloxAssetCollector::addStyle('/plugins/../unsafe.css');
        $this->assertCount(1, BloxAssetCollector::scripts());
        $this->assertCount(1, BloxAssetCollector::styles());
    }

    /** @param array<int,array<string,mixed>> $elements @return array<int,array<string,mixed>> */
    private function sections(array $elements): array
    {
        return [[
            'id' => 'section-1',
            'type' => 'section',
            'settings' => [],
            'columns' => [[
                'id' => 'column-1',
                'elements' => $elements,
            ]],
        ]];
    }
}
