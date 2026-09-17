<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

/** 宽屏档（w，≥1440px）：继承桌面，显式设置后才输出宽屏规则；旧文档输出不变。 */
final class BloxWidescreenTierTest extends TestCase
{
    public function testWidescreenValuesInheritDesktopUnlessSet(): void
    {
        $allowed = ['sm' => true, 'md' => true, 'lg' => true];
        self::assertSame(['d' => 'md', 't' => 'md', 'm' => 'sm', 'w' => 'md'], BloxResponsiveValue::normalize(['d' => 'md', 'm' => 'sm'], $allowed, 'sm'));
        self::assertSame(['d' => 'md', 't' => 'md', 'm' => 'md', 'w' => 'lg'], BloxResponsiveValue::normalize(['desktop' => 'md', 'wide' => 'lg'], $allowed, 'sm'));
        self::assertSame(['d' => 'md', 'w' => 'lg'], BloxResponsiveValue::normalizeStored(['d' => 'md', 'w' => 'lg', 'x' => 'lg'], $allowed, 'sm'));
    }

    public function testClassMapsAddWideClassesOnlyWhenWidescreenDiffers(): void
    {
        $spacer = new SpacerElement();
        self::assertStringNotContainsString('wide:', $spacer->render(['size' => ['d' => 'lg', 't' => 'md', 'm' => 'sm']]));
        $html = $spacer->render(['size' => ['d' => 'lg', 'w' => 'xl']]);
        self::assertStringContainsString('h-16', $html);
        self::assertStringContainsString('wide:h-24', $html);

        $heading = (new HeadingElement())->render(['text' => 'A', 'level' => 'h2', 'visual_size' => ['d' => '3xl', 'w' => '5xl']]);
        self::assertStringContainsString('wide:text-6xl', $heading);
    }

    public function testDeclarativeCssKeepsExistingOutputAndFallsBackToDesktop(): void
    {
        $controls = [['key' => 'size', 'type' => 'css_length', 'responsive' => true, 'css' => [['property' => 'font-size']]]];
        // 未设宽屏：与此前逐字节一致，不输出 -w 变量
        $existing = BloxCssCompiler::compile($controls, ['size' => ['d' => 40, 'm' => 24]]);
        self::assertSame('--yk-r-font-size-m:24px;--yk-r-font-size-t:40px;--yk-r-font-size-d:40px;', $existing['style']);
        // 设了宽屏：输出 -w 变量
        $wide = BloxCssCompiler::compile($controls, ['size' => ['d' => 40, 'w' => 56]]);
        self::assertStringContainsString('--yk-r-font-size-w:56px;', $wide['style']);
        self::assertSame(['yk-r-font-size'], $wide['classes']);
        // 样式表：≥1440 规则回退到桌面变量，已缓存的旧 HTML 不会丢失样式
        $sheet = BloxCssCompiler::responsiveStylesheet();
        self::assertStringContainsString('@media (min-width:1440px){.yk-r-font-size.yk-r-font-size{font-size:var(--yk-r-font-size-w,var(--yk-r-font-size-d))}', $sheet);
        self::assertSame(['d' => 40, 't' => '', 'm' => '', 'w' => 56], BloxCssCompiler::sanitizeValue($controls[0], ['d' => 40, 'w' => 56]));
        self::assertSame(['d' => 40, 't' => '', 'm' => 24], BloxCssCompiler::sanitizeValue($controls[0], ['d' => 40, 'm' => 24]));
    }

    public function testWidescreenCanBeHiddenSeparatelyWhileDesktopStillCoversIt(): void
    {
        $html = BlockRenderer::render((string) json_encode([
            ['settings' => ['hide_on' => ['w']], 'columns' => [['elements' => [['type' => 'heading', 'data' => ['text' => 'Wide hidden']]]]]],
            ['settings' => ['hide_on' => ['d']], 'columns' => [['elements' => [['type' => 'heading', 'data' => ['text' => 'Desktop hidden']]]]]],
        ]));
        self::assertMatchesRegularExpression('/class="[^"]*\bwide:hidden\b[^"]*"/', $html);
        self::assertMatchesRegularExpression('/class="[^"]*\blg:hidden\b[^"]*"/', $html);
    }
}
