<?php
/** Builder 元素 Schema 与 Blox 文档结构契约。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AbstractElement;
use BloxDocumentValidator;
use BuilderRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BuilderSchemaContractTest extends TestCase
{
    public function testPluginElementRegistrationAutomaticallyExportsEditorSchema(): void
    {
        BuilderRegistry::register(new class extends AbstractElement {
            public function type(): string { return 'test-plugin-badge'; }
            public function label(): string { return '测试徽章'; }
            public function icon(): string { return 'badge'; }
            public function category(): string { return 'basic'; }
            public function treeLabelField(): ?string { return 'title'; }
            public function scripts(): array { return ['/plugins/test/badge.js']; }
            public function controls(): array
            {
                return [['key' => 'title', 'type' => 'text', 'label' => '标题', 'default' => '徽章']];
            }
            public function render(array $data, string $children = ''): string
            {
                return '<span>' . htmlspecialchars((string) ($data['title'] ?? ''), ENT_QUOTES) . '</span>';
            }
        });

        $meta = BuilderRegistry::meta()['test-plugin-badge'];

        $this->assertTrue($meta['paletteVisible']);
        $this->assertFalse($meta['container']);
        $this->assertSame([], $meta['allowedChildren']);
        $this->assertSame('title', $meta['treeLabelField']);
        $this->assertSame(['/plugins/test/badge.js'], $meta['scripts']);
        $this->assertSame('徽章', $meta['defaults']['title']);

        BloxDocumentValidator::assertValidSections($this->sections([
            ['id' => 'plugin-1', 'type' => 'test-plugin-badge', 'data' => ['title' => '可用']],
        ]));
    }

    public function testContainerContractsExportDefaultChildrenAndConditionalRules(): void
    {
        $meta = BuilderRegistry::meta('home');

        $this->assertSame(['*'], $meta['container']['allowedChildren']);
        $this->assertSame(['heading', 'text', 'image', 'button', 'div'], $meta['list-dynamic']['allowedChildren']);
        $this->assertSame(['stat-item'], $meta['stats-group']['allowedChildren']);
        $this->assertCount(4, $meta['stats-group']['defaults']['children']);
        $this->assertFalse($meta['stat-item']['paletteVisible']);
        $this->assertSame('label', $meta['stat-item']['treeLabelField']);
        $this->assertSame([], $meta['home-block']['allowedChildren']);
        $this->assertSame('banner', $meta['home-block']['childRules'][0]['value']);
        $this->assertSame(['home-banner-item'], $meta['home-block']['childRules'][0]['allowedChildren']);
    }

    public function testUnknownElementIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_doc_unknown_type');

        BloxDocumentValidator::assertValidSections($this->sections([
            ['id' => 'missing-1', 'type' => 'plugin-removed', 'data' => []],
        ]));
    }

    public function testNonContainerChildrenAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_doc_leaf_has_children');

        BloxDocumentValidator::assertValidSections($this->sections([
            [
                'id' => 'heading-1',
                'type' => 'heading',
                'data' => [
                    'text' => '标题',
                    'children' => [['id' => 'text-1', 'type' => 'text', 'data' => ['html' => '非法']]],
                ],
            ],
        ]));
    }

    public function testIllegalDirectChildIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[parent=stats-group]');

        BloxDocumentValidator::assertValidSections($this->sections([
            [
                'id' => 'stats-1',
                'type' => 'stats-group',
                'data' => [
                    'children' => [['id' => 'text-1', 'type' => 'text', 'data' => ['html' => '非法']]],
                ],
            ],
        ]));
    }

    public function testConditionalHomeBannerChildrenAreValidated(): void
    {
        $valid = $this->sections([[
            'id' => 'home-1',
            'type' => 'home-block',
            'data' => [
                'block_type' => 'banner',
                'children' => [['id' => 'banner-1', 'type' => 'home-banner-item', 'data' => ['title' => 'Banner']]],
            ],
        ]]);
        BloxDocumentValidator::assertValidSections($valid);

        $valid[0]['columns'][0]['elements'][0]['data']['block_type'] = 'about';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[parent=home-block]');
        BloxDocumentValidator::assertValidSections($valid);
    }

    public function testDuplicateNodeIdsAreRejectedAcrossTheDocument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate');

        BloxDocumentValidator::assertValidSections($this->sections([
            ['id' => 'duplicate', 'type' => 'heading', 'data' => ['text' => '一']],
            ['id' => 'duplicate', 'type' => 'text', 'data' => ['html' => '二']],
        ]));
    }

    /**
     * @param list<array<string,mixed>> $elements
     * @return list<array<string,mixed>>
     */
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
