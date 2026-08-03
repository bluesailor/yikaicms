<?php
/** Home Blox P0 document and legacy migration contracts. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeBloxDocument;
use HomeBlockElement;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeBloxDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_test_config'] = [];
    }

    public function testLegacyHomeBlocksBecomeSortableBloxSections(): void
    {
        $GLOBALS['_test_config']['home_blocks_config'] = json_encode([
            ['type' => 'banner', 'enabled' => true],
            ['type' => 'channel:7', 'enabled' => false],
        ], JSON_UNESCAPED_UNICODE);

        $document = HomeBloxDocument::load();

        $this->assertSame('legacy', $document['source']);
        $this->assertCount(2, $document['sections']);
        $this->assertSame('home-block', $document['sections'][0]['columns'][0]['elements'][0]['type']);
        $this->assertSame('banner', $document['sections'][0]['columns'][0]['elements'][0]['data']['block_type']);
        $this->assertFalse($document['sections'][1]['columns'][0]['elements'][0]['data']['enabled']);
        $this->assertSame('none', $document['sections'][0]['settings']['container_gutter']);
    }

    public function testSavedEmptyDocumentDoesNotFallbackToLegacy(): void
    {
        $GLOBALS['_test_config']['home_blox_data'] = json_encode([
            'version' => 1,
            'source' => 'blox',
            'sections' => [],
        ], JSON_UNESCAPED_UNICODE);

        $document = HomeBloxDocument::load();

        $this->assertSame('blox', $document['source']);
        $this->assertSame([], $document['sections']);
    }

    public function testHomeBlockPreviewEscapesLegacyTypeAndShowsDraftState(): void
    {
        $html = (new HomeBlockElement())->render([
            'block_type' => 'custom:<script>',
            'label' => '首页 <区块>',
            'enabled' => false,
        ]);

        $this->assertStringContainsString('data-home-block="custom:&lt;script&gt;"', $html);
        $this->assertStringContainsString('首页 &lt;区块&gt;', $html);
        $this->assertStringContainsString('已停用', $html);
    }
}