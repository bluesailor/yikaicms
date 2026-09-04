<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAssetCollector;
use HomeBloxBlockSchema;
use HomeBloxRenderer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeSurfaceContractTest extends TestCase
{
    public function testSurfaceModesAreOptionalAndWhitelisted(): void
    {
        self::assertArrayNotHasKey('home_surface', HomeBloxBlockSchema::normalize(['block_type' => 'about']));
        foreach (['auto', 'light', 'dark', 'custom'] as $mode) {
            $data = HomeBloxBlockSchema::normalize(['block_type' => 'about', 'home_surface' => $mode, 'bg_color' => '#f5f5f5']);
            self::assertSame($mode, $data['home_surface']);
            self::assertSame('#f5f5f5', $data['bg_color']);
        }
        self::assertSame('auto', HomeBloxBlockSchema::normalize(['home_surface' => '"><script>'])['home_surface']);
    }

    public function testRendererPassesNearestBackgroundFirstWithoutChangingInput(): void
    {
        $sections = [[
            'settings' => [
                'bg_color' => '#111111',
                'container_bg' => '#222222',
                'bg_video' => '/uploads/videos/section.mp4',
            ],
            'columns' => [['card_bg' => '#333333', 'elements' => [[
                'type' => 'home-block', 'data' => ['block_type' => 'about', 'enabled' => true],
            ]]]],
        ]];
        $original = $sections;
        $received = [];
        HomeBloxRenderer::render($sections, static function (array $element) use (&$received): string {
            $received = $element['data']['_blox_parent_backgrounds'];
            return '<p>About</p>';
        });
        self::assertSame(['#333333', '#222222', '#111111'], array_column($received, 'bg_color'));
        self::assertSame('/uploads/videos/section.mp4', $received[2]['bg_video']);
        self::assertSame($original, $sections);
    }

    public function testDefaultThemeUsesOnlyTheGenericSectionBackgroundEditor(): void
    {
        $runtimeKey = 'yikai_config_runtime_overrides';
        $hadPrevious = array_key_exists($runtimeKey, $GLOBALS);
        $previous = $GLOBALS[$runtimeKey] ?? null;
        $GLOBALS[$runtimeKey] = array_merge(
            is_array($previous) ? $previous : [],
            ['current_theme' => 'default']
        );

        try {
            $element = new \HomeBlockElement();
            $keys = array_column($element->controls(), 'key');
            foreach (['bg_image', 'bg_color', 'bg_overlay_color', 'bg_overlay_opacity', 'text_light'] as $key) {
                self::assertNotContains($key, $keys);
            }
        } finally {
            if ($hadPrevious) {
                $GLOBALS[$runtimeKey] = $previous;
            } else {
                unset($GLOBALS[$runtimeKey]);
            }
        }
    }

    public function testAssetCollectorStillRejectsRemoteTraversalAndNonAssetThemePaths(): void
    {
        BloxAssetCollector::reset();
        foreach (['https://evil.example/asset.js', '/themes/business/../assets/header.js',
            '/themes/business/layouts/header.php', '/themes/business/header.js',
            '/themes/business/assets/not-installed.js', '/themes/business/assets/../../header.js'] as $path) {
            BloxAssetCollector::addScript($path);
        }
        self::assertSame([], BloxAssetCollector::scripts());
    }
}
