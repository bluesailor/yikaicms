<?php
/** Blox 模板 JSON v1 导入安全契约。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BloxTemplateImporterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testValidTemplateIsNormalizedAndEveryImportGetsFreshIds(): void
    {
        $json = $this->packageJson();
        $first = \BloxTemplateImporter::prepare($json);
        $second = \BloxTemplateImporter::prepare($json);

        $this->assertSame('page', $first['type']);
        $this->assertSame('企业标准页', $first['name']);
        $this->assertSame(['heading'], $first['requirements']['elements']);
        $this->assertSame([], $first['requirements']['plugins']);
        $this->assertNotSame($first['sections'][0]['id'], $second['sections'][0]['id']);
        $this->assertNotSame(
            $first['sections'][0]['columns'][0]['elements'][0]['id'],
            $second['sections'][0]['columns'][0]['elements'][0]['id']
        );
        $this->assertStringNotContainsString('"old-section"', $first['draft_json']);
    }

    public function testUnknownOrInactivePluginElementIsRejectedWithItsType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox-example/notice');
        \BloxTemplateImporter::prepare($this->packageJson('blox-example/notice'));
    }

    public function testCodeElementIsRejectedForFreeEditionImport(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_tpl_code_locked');
        \BloxTemplateImporter::prepare($this->packageJson('code'));
    }

    public function testReusableLibraryReferenceIsRejected(): void
    {
        $package = $this->package();
        $package['document'][0] = ['id' => 'ref', 'library_id' => 9];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_tpl_cross_site_ref');
        \BloxTemplateImporter::prepare($this->encode($package));
    }

    public function testExecutableEnvelopeFieldIsRejected(): void
    {
        $package = $this->package();
        $package['php'] = '<?php echo 1;';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('php');
        \BloxTemplateImporter::prepare($this->encode($package));
    }

    public function testDeclaredMissingPluginIsRejected(): void
    {
        $package = $this->package();
        $package['requires']['plugins'] = ['not-active'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not-active');
        \BloxTemplateImporter::prepare($this->encode($package));
    }

    public function testExportedTemplatePackageCanBePreparedAgain(): void
    {
        $json = \BloxTemplateImporter::exportJson([
            'id' => 42,
            'type' => 'section',
            'name' => 'Export Card',
            'source' => 'user',
            'source_ref' => '',
            'schema_version' => 1,
            'draft_data' => '[{"id":"draft","type":"section","settings":[],"columns":[{"id":"c1","elements":[{"id":"e1","type":"heading","data":{"text":"Draft","level":"h2"}}]}]}]',
            'published_data' => '[{"id":"pub","type":"section","settings":[],"columns":[{"id":"c2","elements":[{"id":"e2","type":"heading","data":{"text":"Published","level":"h2"}}]}]}]',
            'requirements' => '{"elements":[],"plugins":[]}',
            'thumbnail' => '/uploads/templates/card.jpg',
        ]);

        $package = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('yikaicms-blox-template', $package['format']);
        $this->assertSame('section', $package['type']);
        $this->assertSame(['heading'], $package['requires']['elements']);
        $this->assertSame('pub', $package['document'][0]['id']);
        $this->assertSame('blox-template-42.json', \BloxTemplateImporter::exportFilename(['id' => 42, 'name' => '模板 名']));

        $prepared = \BloxTemplateImporter::prepare($json);
        $this->assertSame('section', $prepared['type']);
        $this->assertSame('Export Card', $prepared['name']);
        $this->assertStringNotContainsString('"pub"', $prepared['draft_json']);
    }
    public function testExportMergesStoredRequirementsWithDocumentRequirements(): void
    {
        $package = \BloxTemplateImporter::exportPackage([
            'id' => 43,
            'type' => 'section',
            'name' => 'Requirement merge',
            'draft_data' => $this->encode($this->package()['document']),
            'published_data' => '',
            'requirements' => '{"elements":["text"],"plugins":["explicit-provider"]}',
        ]);

        $this->assertSame(['heading', 'text'], $package['requires']['elements']);
        $this->assertSame(['explicit-provider'], $package['requires']['plugins']);
    }

    public function testExportInfersNestedPluginDependencies(): void
    {
        $document = $this->package()['document'];
        $document[0]['columns'][0]['elements'] = [[
            'id' => 'container',
            'type' => 'container',
            'data' => [
                'children' => [[
                    'id' => 'plugin-element',
                    'type' => 'blox-example/notice',
                    'data' => [],
                ]],
            ],
        ]];

        $package = \BloxTemplateImporter::exportPackage([
            'type' => 'section',
            'name' => 'Plugin requirement',
            'draft_data' => $this->encode($document),
            'published_data' => '',
            'requirements' => '',
        ]);

        $this->assertSame(['blox-example/notice', 'container'], $package['requires']['elements']);
        $this->assertSame(['blox-example'], $package['requires']['plugins']);
    }

    public function testRemoteThumbnailIsRemovedButLocalThumbnailIsKept(): void
    {
        $package = $this->package();
        $package['thumbnail'] = 'https://example.com/a.jpg';
        $this->assertSame('', \BloxTemplateImporter::prepare($this->encode($package))['thumbnail']);

        $package['thumbnail'] = '/uploads/templates/a.jpg';
        $this->assertSame(
            '/uploads/templates/a.jpg',
            \BloxTemplateImporter::prepare($this->encode($package))['thumbnail']
        );
    }

    private function packageJson(string $elementType = 'heading'): string
    {
        return $this->encode($this->package($elementType));
    }

    /** @return array<string,mixed> */
    private function package(string $elementType = 'heading'): array
    {
        return [
            'format' => 'yikaicms-blox-template',
            'version' => 1,
            'type' => 'page',
            'name' => '企业标准页',
            'requires' => ['elements' => [], 'plugins' => []],
            'document' => [[
                'id' => 'old-section',
                'type' => 'section',
                'settings' => [],
                'columns' => [[
                    'id' => 'old-column',
                    'elements' => [[
                        'id' => 'old-element',
                        'type' => $elementType,
                        'data' => $elementType === 'heading'
                            ? ['text' => '标题', 'level' => 'h2']
                            : [],
                    ]],
                ]],
            ]],
        ];
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
