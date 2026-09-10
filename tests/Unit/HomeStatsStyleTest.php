<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeStatsStyleTest extends TestCase
{
    public function testColorsAndDividerSurviveNormalizationAndReachRuntime(): void
    {
        $controls = array_column(HomeBloxBlockSchema::controls(), null, 'key');
        $input = ['block_type' => 'stats', 'stats_divider' => 'hide'];
        foreach (['number', 'icon', 'label', 'divider'] as $part) {
            $key = 'stats_' . $part . '_color';
            self::assertSame('color', $controls[$key]['type']);
            $input[$key] = 'rgba(30,58,138,0.8)';
        }
        $runtime = HomeBloxBlockSchema::runtimeConfigOverrides(HomeBloxBlockSchema::normalize($input));
        foreach (['number', 'icon', 'label', 'divider'] as $part) {
            self::assertSame('rgba(30,58,138,0.8)', $runtime['home_stat_' . $part . '_color']);
        }
        self::assertSame('hide', $runtime['home_stat_divider']);
    }

    public function testLegacyAndInvalidValuesFallBackWithoutCssInjection(): void
    {
        $runtime = HomeBloxBlockSchema::runtimeConfigOverrides(HomeBloxBlockSchema::normalize([
            'block_type' => 'stats', 'stats_divider' => 'invalid',
            'stats_number_color' => 'red;display:none',
        ]));
        self::assertSame('inherit', $runtime['home_stat_divider']);
        foreach (['number', 'icon', 'label', 'divider'] as $part) {
            self::assertSame('', $runtime['home_stat_' . $part . '_color']);
        }
    }
}
