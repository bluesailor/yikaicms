<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlockRenderer;
use BuilderRegistry;
use HomeBannerItemElement;
use HomeBloxRenderContext;
use HomeBloxRenderer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeBannerItemElementTest extends TestCase
{
    public function testNormalizeSanitizesTextUrlsAndTarget(): void
    {
        $item = HomeBannerItemElement::normalize([
            'title' => '<b>Launch</b>',
            'subtitle' => '<script>alert(1)</script>Welcome',
            'image' => '/uploads/banner.jpg',
            'btn1_url' => 'javascript:alert(1)',
            'btn2_url' => 'mailto:sales@example.com',
            'link_target' => 'popup',
        ]);

        $this->assertSame('Launch', $item['title']);
        $this->assertSame('alert(1)Welcome', $item['subtitle']);
        $this->assertSame('/uploads/banner.jpg', $item['image']);
        $this->assertSame('', $item['btn1_url']);
        $this->assertSame('mailto:sales@example.com', $item['btn2_url']);
        $this->assertSame('_self', $item['link_target']);
    }

    public function testCustomChildrenReplaceLiveBannersInDocumentOrder(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-banner-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, '<?php echo json_encode($banners, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);');

        $children = [
            ['type' => 'home-banner-item', 'data' => ['title' => 'Second', 'image' => '/second.jpg']],
            ['type' => 'heading', 'data' => ['text' => 'Ignored']],
            ['type' => 'home-banner-item', 'data' => ['title' => 'First', 'image' => '/first.jpg']],
        ];

        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'banner', 'enabled' => true]],
                ['banner' => (string) $fixture],
                [],
                [['title' => 'Live banner']],
                null,
                [],
                false
            );
            $html = $context->renderLegacyBlock([
                'data' => [
                    'block_type' => 'banner',
                    'enabled' => true,
                    'items_mode' => 'custom',
                    'children' => $children,
                    '_blox_path' => '2.1.3',
                ],
            ]);
            $items = json_decode($html, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(['Second', 'First'], array_column($items, 'title'));
            $this->assertArrayNotHasKey('_blox_path', $items[0]);

            $editContext = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'banner', 'enabled' => true]],
                ['banner' => (string) $fixture],
                [],
                [],
                null,
                [],
                true
            );
            $editItems = json_decode($editContext->renderLegacyBlock([
                'data' => [
                    'block_type' => 'banner',
                    'enabled' => true,
                    'items_mode' => 'custom',
                    'children' => $children,
                    '_blox_path' => '2.1.3',
                ],
            ]), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('2.1.3.0', $editItems[0]['_blox_path']);
            $this->assertSame('2.1.3.2', $editItems[1]['_blox_path']);
        } finally {
            @unlink((string) $fixture);
        }
    }

    public function testHomeRendererCarriesOriginalElementPathToDynamicCallback(): void
    {
        $capturedPath = '';
        HomeBloxRenderer::render([[
            'columns' => [[
                'elements' => [
                    ['type' => 'home-block', 'data' => ['block_type' => 'about', 'enabled' => false]],
                    ['type' => 'home-block', 'data' => ['block_type' => 'banner', 'enabled' => true]],
                ],
            ]],
        ]], static function (array $element) use (&$capturedPath): string {
            $capturedPath = (string) ($element['data']['_blox_path'] ?? '');
            return '<section>Banner</section>';
        });

        $this->assertSame('0.0.1', $capturedPath);
    }

    public function testRegistryMarksHomeBlockAsSelfRenderingContainer(): void
    {
        $homeBlock = BuilderRegistry::get('home-block');

        $this->assertNotNull(BuilderRegistry::get('home-banner-item'));
        $this->assertNotNull($homeBlock);
        $this->assertTrue($homeBlock->isContainer());
        $this->assertTrue($homeBlock->rendersOwnChildren());

        $html = BlockRenderer::renderElementNode(
            ['type' => 'code', 'data' => ['html' => '<span>Banner</span>', '_blox_path' => '0.0.4']],
            0,
            true,
            [0, 0, 0]
        );
        $this->assertStringContainsString('data-yk-el="0.0.4"', $html);
    }
}
