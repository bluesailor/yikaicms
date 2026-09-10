<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeAboutBadgeStyleTest extends TestCase
{
    public function testBadgeColorsAreEditableAndSurviveNormalization(): void
    {
        $controls = array_column(HomeBloxBlockSchema::controls(), null, 'key');
        foreach (['override_tag_background', 'override_tag_color'] as $key) {
            self::assertSame('color', $controls[$key]['type']);
            self::assertSame('style', $controls[$key]['tab']);
        }
        $data = HomeBloxBlockSchema::normalize([
            'block_type' => 'about',
            'override_tag_background' => 'rgba(239,246,255,0.94)',
            'override_tag_color' => '#1e3a8a',
        ]);
        $overrides = HomeBloxBlockSchema::runtimeConfigOverrides($data);
        self::assertSame('rgba(239,246,255,0.94)', $overrides['home_about_tag_background']);
        self::assertSame('#1e3a8a', $overrides['home_about_tag_color']);
    }

    public function testInvalidColorCannotBecomeInlineCss(): void
    {
        $data = HomeBloxBlockSchema::normalize([
            'block_type' => 'about',
            'override_tag_background' => 'red;background-image:url(https://invalid.example/x)',
            'override_tag_color' => '"><script>alert(1)</script>',
        ]);
        self::assertSame('', $data['override_tag_background']);
        self::assertSame('', $data['override_tag_color']);
    }
}
