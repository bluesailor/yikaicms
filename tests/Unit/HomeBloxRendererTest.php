<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeBloxRenderContext;
use HomeBloxRenderer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeBloxRendererTest extends TestCase
{
    public function testDynamicHomeBlocksKeepOrderAndColumnStructure(): void
    {
        $called = [];
        $sections = [[
            'settings' => [],
            'columns' => [
                ['elements' => [
                    ['type' => 'heading', 'data' => ['text' => 'Regular']],
                    ['type' => 'home-block', 'data' => ['block_type' => 'banner', 'enabled' => true]],
                ]],
                ['elements' => [
                    ['type' => 'home-block', 'data' => ['block_type' => 'about', 'enabled' => true]],
                ]],
            ],
        ]];

        $html = HomeBloxRenderer::render($sections, static function (array $element) use (&$called): string {
            $type = (string) ($element['data']['block_type'] ?? '');
            $called[] = $type;
            return '<aside data-test="' . $type . '">' . ucfirst($type) . '</aside>';
        });

        $this->assertSame(['banner', 'about'], $called);
        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-2', $html);
        $this->assertStringContainsString('<aside data-test="banner">Banner</aside>', $html);
        $this->assertStringContainsString('<aside data-test="about">About</aside>', $html);
        $this->assertGreaterThan(strpos($html, 'Regular'), strpos($html, 'Banner'));
    }

    public function testDisabledDynamicHomeBlocksAreNotDelegated(): void
    {
        $called = 0;
        $sections = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'home-block',
                    'data' => ['block_type' => 'banner', 'enabled' => false],
                ]],
            ]],
        ]];

        $html = HomeBloxRenderer::render($sections, static function (array $element) use (&$called): string {
            $called++;
            return 'unexpected';
        });

        $this->assertSame(0, $called);
        $this->assertStringNotContainsString('unexpected', $html);
    }
    public function testSharedContextDispatchesTheSameDynamicTemplateEntryPoint(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-render-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, "<?php echo 'dynamic-fixture';");

        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'fixture', 'enabled' => true]],
                ['fixture' => (string) $fixture],
                [],
                [],
                null,
                [],
                false
            );

            $html = $context->renderLegacyBlock([
                'data' => ['block_type' => 'fixture', 'enabled' => true],
            ]);

            $this->assertSame('dynamic-fixture', $html);
        } finally {
            @unlink((string) $fixture);
        }
    }
    public function testContainerGutterCanBeRemovedWithoutChangingLegacyDefault(): void
    {
        $section = static fn (array $settings): array => [[
            'settings' => $settings,
            'columns' => [[
                'elements' => [[
                    'type' => 'heading',
                    'data' => ['text' => 'Gutter test'],
                ]],
            ]],
        ]];

        $legacy = \BlockRenderer::render(json_encode($section(['max_width' => 'full']), JSON_THROW_ON_ERROR));
        $edgeToEdge = \BlockRenderer::render(json_encode($section([
            'max_width' => 'full',
            'container_gutter' => 'none',
        ]), JSON_THROW_ON_ERROR));

        $this->assertStringContainsString('class="max-w-full mx-auto px-4"', $legacy);
        $this->assertStringContainsString('class="max-w-full mx-auto"', $edgeToEdge);
        $this->assertStringNotContainsString('max-w-full mx-auto px-4', $edgeToEdge);
    }

}
