<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ThemePalette.php';

final class ThemePaletteTest extends TestCase
{
    public function testMarketplaceThemesExposeDistinctPalettes(): void
    {
        $root = ROOT_PATH . '/marketplace/themes';
        $aurora = ThemePalette::definition($root, 'aurora');
        $business = ThemePalette::definition($root, 'business');
        $minimal = ThemePalette::definition($root, 'minimal');

        self::assertSame('#6366F1', $aurora['colors']['primary']);
        self::assertSame('#3B6CF5', $business['colors']['primary']);
        self::assertSame('#0F766E', $minimal['colors']['primary']);
        self::assertNotSame($aurora['preview'], $business['preview']);
        self::assertNotSame($business['preview'], $minimal['preview']);
        self::assertCount(3, $minimal['palettes']);
    }

    public function testProfilesRejectMalformedSlugsAndColors(): void
    {
        $profiles = ThemePalette::profiles((string) json_encode([
            'minimal' => ['primary' => '#0f766e', 'secondary' => '#256d85'],
            '../bad' => ['primary' => '#112233', 'secondary' => '#445566'],
            'business' => ['primary' => 'red', 'secondary' => '#445566'],
        ]));

        self::assertSame([
            'minimal' => ['primary' => '#0F766E', 'secondary' => '#256D85'],
        ], $profiles);
    }

    public function testProfileEncodingIsStable(): void
    {
        $encoded = ThemePalette::encodeProfiles([
            'minimal' => ['primary' => '#0F766E', 'secondary' => '#256D85'],
            'aurora' => ['primary' => '#6366F1', 'secondary' => '#8B5CF6'],
        ]);

        self::assertSame(
            '{"aurora":{"primary":"#6366F1","secondary":"#8B5CF6"},"minimal":{"primary":"#0F766E","secondary":"#256D85"}}',
            $encoded
        );
    }
}
