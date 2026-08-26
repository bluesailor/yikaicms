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
        // r10 起 json 输出 v1 信封（schema/settings/sections）
        $this->assertSame(
            ['schema' => 1, 'settings' => [], 'sections' => $sections],
            json_decode($processed['json'], true)
        );
    }

    // ── r10：文档 schema 信封与迁移管道 ──────────────────────────

    /** v0 历史两形态（裸数组 / 无 schema 的 {sections}）→ 同一 v1 信封（惰性迁移） */
    public function testMigrateUpgradesLegacyShapesToEnvelope(): void
    {
        $bare = BloxDocumentPipeline::process('[{"columns":[]}]', 'test');
        $wrapped = BloxDocumentPipeline::process('{"sections":[{"columns":[]}]}', 'test');
        $this->assertSame(1, $bare['schema']);
        $this->assertSame([], $bare['settings']);
        $this->assertSame($bare['json'], $wrapped['json']);
        $this->assertStringStartsWith('{"schema":1,', $bare['json']);
    }

    /** v1 信封 round-trip：settings 保留，重处理输出不变（幂等） */
    public function testEnvelopeRoundTripIsIdempotent(): void
    {
        $first = BloxDocumentPipeline::process('{"schema":1,"settings":{"sticky":true},"sections":[{"columns":[]}]}', 'test');
        $second = BloxDocumentPipeline::process($first['json'], 'test');
        $this->assertSame(['sticky' => true], $first['settings']);
        $this->assertSame($first['json'], $second['json']);
    }

    /** 高于当前版本 fail-closed 拒绝（新版本文档不在旧代码上静默丢数据） */
    public function testNewerSchemaIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_doc_schema_too_new');
        BloxDocumentPipeline::process('{"schema":99,"sections":[]}', 'test');
    }

    /** 文档级 settings 白名单：sticky 布尔化，未知键丢弃不入库 */
    public function testDecodeRejectsMalformedSchemaAndIncompleteEnvelope(): void
    {
        foreach (['0', '-1', '"1"', '1.5', 'null'] as $schema) {
            try {
                BloxDocumentPipeline::decode('{"schema":' . $schema . ',"sections":[]}');
                $this->fail('Malformed schema should be rejected: ' . $schema);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('blox_doc_schema_invalid', $e->getMessage());
            }
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_doc_bad_sections');
        BloxDocumentPipeline::decode('{"schema":1,"settings":{}}');
    }

    public function testDecodeMigratesWithoutRebuildingNodeIds(): void
    {
        $decoded = BloxDocumentPipeline::decode('[{"id":"keep-me","columns":[]}]');

        $this->assertSame(1, $decoded['schema']);
        $this->assertSame('keep-me', $decoded['sections'][0]['id']);
    }

    public function testDocumentFingerprintSupportsOptimisticConcurrency(): void
    {
        $legacy = '[{"id":"stable","columns":[]}]';
        $envelope = '{"schema":1,"settings":{},"sections":[{"id":"stable","columns":[]}]}';
        $revision = BloxDocumentPipeline::fingerprint($legacy);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $revision);
        $this->assertSame($revision, BloxDocumentPipeline::fingerprint($envelope));
        $this->assertTrue(BloxDocumentPipeline::revisionMatches($envelope, strtoupper($revision)));
        $this->assertFalse(BloxDocumentPipeline::revisionMatches('[]', $revision));
        $this->assertFalse(BloxDocumentPipeline::revisionMatches($legacy, 'not-a-revision'));
    }

    public function testRendererRejectsFutureSchemaInsteadOfReadingItsSections(): void
    {
        $this->assertSame('', BlockRenderer::render(
            '{"schema":99,"sections":[{"columns":[{"elements":[{"type":"heading","data":{"text":"must-not-render"}}]}]}]}'
        ));
    }

    public function testDocSettingsWhitelist(): void
    {
        $this->assertSame(['sticky' => true], BloxDocumentPipeline::normalizeDocSettings(['sticky' => 1, 'evil' => 'x']));
        $this->assertSame(['sticky' => false], BloxDocumentPipeline::normalizeDocSettings(['sticky' => 0]));
        $this->assertSame([], BloxDocumentPipeline::normalizeDocSettings(['unknown' => 'y']));
        $this->assertSame([], BloxDocumentPipeline::normalizeDocSettings('junk'));
    }

    public function testSectionAnchorsAreSanitizedAndMadeUnique(): void
    {
        $document = BloxDocumentPipeline::process((string) json_encode([
            ['settings' => ['anchor_id' => '#features'], 'columns' => []],
            ['settings' => ['anchor_id' => 'FEATURES'], 'columns' => []],
            ['settings' => ['anchor_id' => 'bad id\" onclick=\"x'], 'columns' => []],
        ], JSON_THROW_ON_ERROR), 'anchor');

        $this->assertSame('features', $document['sections'][0]['settings']['anchor_id']);
        $this->assertSame('FEATURES-2', $document['sections'][1]['settings']['anchor_id']);
        $this->assertSame('', $document['sections'][2]['settings']['anchor_id']);
        $this->assertSame('', BloxDocumentPipeline::normalizeSectionAnchorId('9-starts-with-number'));
        $this->assertSame('pricing_2026', BloxDocumentPipeline::normalizeSectionAnchorId('pricing_2026'));
    }

    public function testSectionNamesAreSanitizedAndEmptyNamesReturnToAutomaticLabels(): void
    {
        $longName = str_repeat('名', BloxDocumentPipeline::SECTION_NAME_MAX + 10);
        $document = BloxDocumentPipeline::process((string) json_encode([
            ['name' => "  <b>售后</b>\u{200B}\n流程 &amp; 支持  ", 'columns' => []],
            ['name' => "\u{0000}\u{200B}  ", 'columns' => []],
            ['name' => $longName, 'columns' => []],
            ['name' => '价格<1000元', 'columns' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'name');

        $this->assertSame('<b>售后</b> 流程 & 支持', $document['sections'][0]['name']);
        $this->assertArrayNotHasKey('name', $document['sections'][1]);
        $this->assertSame(
            str_repeat('名', BloxDocumentPipeline::SECTION_NAME_MAX),
            $document['sections'][2]['name']
        );
        $this->assertSame('价格<1000元', $document['sections'][3]['name']);
        $this->assertSame('', BloxDocumentPipeline::normalizeSectionName([]));
    }

    /** 渲染黄金对拍：信封输入与裸数组输入输出逐字节一致（settings 不影响 sections 渲染） */
    public function testRendererOutputIdenticalForEnvelopeAndBareArray(): void
    {
        $sections = [[
            'id' => 's1',
            'settings' => [],
            'columns' => [['id' => 'c1', 'elements' => [['id' => 'e1', 'type' => 'heading', 'data' => ['text' => '标题']]]]],
        ]];
        $bareHtml = BlockRenderer::render((string) json_encode($sections, JSON_UNESCAPED_UNICODE));
        $envelopeHtml = BlockRenderer::render((string) json_encode(
            ['schema' => 1, 'settings' => ['sticky' => true], 'sections' => $sections],
            JSON_UNESCAPED_UNICODE
        ));
        $this->assertNotSame('', $bareHtml);
        $this->assertSame($bareHtml, $envelopeHtml);
    }

    public function testDocumentListDetectionSupportsPhp80(): void
    {
        $builderPath = ROOT_PATH . '/includes/builder/';
        $forbiddenCall = 'array_' . 'is_list(';

        foreach (['BloxDocumentPipeline.php', 'HomeBloxDocument.php', 'HomeLayoutDocument.php'] as $file) {
            $source = file_get_contents($builderPath . $file);
            $this->assertIsString($source);
            $this->assertStringNotContainsString($forbiddenCall, $source, $file);
        }

        $this->assertTrue(BloxDocumentPipeline::isList([]));
        $this->assertTrue(BloxDocumentPipeline::isList([['columns' => []]]));
        $this->assertFalse(BloxDocumentPipeline::isList(['sections' => []]));
        $this->assertFalse(BloxDocumentPipeline::isList([
            1 => ['columns' => []],
        ]));
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
