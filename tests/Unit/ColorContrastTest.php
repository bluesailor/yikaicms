<?php
/**
 * 2.0.5 前台无障碍：时间线日期等「用品牌色写字」的地方，ColorContrast::readableText() 把颜色调暗到浅底上 4.5:1。
 */
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use ColorContrast;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ColorContrast.php';

final class ColorContrastTest extends TestCase
{
    public function testLightBrandColorsAreDarkenedToAaOnLightGray(): void
    {
        // 10-05 axe：时间线日期 #10B981 2.42、#F59E0B 2.05、#06B6D4 2.32 等
        foreach (['#3B82F6', '#10B981', '#8B5CF6', '#F59E0B', '#EF4444', '#06B6D4', '#6366F1', '#FFFF00', '#FFFFFF'] as $color) {
            $text = ColorContrast::readableText($color);
            self::assertGreaterThanOrEqual(4.5, ColorContrast::ratio($text, ColorContrast::LIGHT_BACKGROUND), $color . ' → ' . $text);
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $text);
        }
    }

    public function testDarkColorsAndNonHexValuesAreUnchanged(): void
    {
        self::assertSame('#1f2937', ColorContrast::readableText('#1f2937'));
        self::assertSame('var(--x)', ColorContrast::readableText('var(--x)'));
        self::assertSame('#abc', ColorContrast::readableText('#abc'));
        self::assertEqualsWithDelta(21.0, ColorContrast::ratio('#000000', '#ffffff'), 0.01);
        self::assertSame(0.0, ColorContrast::ratio('red', '#ffffff'));
    }

    public function testTimelineUsesTheReadableColorForTextOnly(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString("'dotStyle'  => 'background-color:' . \$color . ';',", $source, '圆点仍用原色');
        self::assertStringContainsString("'textStyle' => 'color:' . ColorContrast::readableText(\$color) . ';',", $source);
        self::assertStringContainsString("'yellow' => 'text-yellow-700'", $source, '600 档黄色在浅底上不够 4.5:1');
        self::assertContains('includes/ColorContrast.php', (require ROOT_PATH . '/config/release-runtime.php')['required_files']);
    }
}
