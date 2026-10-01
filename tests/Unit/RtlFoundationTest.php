<?php
/**
 * 从右到左（阿拉伯语）底座：<html dir>、Blox 左右按起始/结束输出、物理方向写法只许减少。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TextDirection;

require_once ROOT_PATH . '/includes/i18n/LanguageRegistry.php';
require_once ROOT_PATH . '/includes/i18n/TextDirection.php';
require_once ROOT_PATH . '/tools/check_rtl.php';

final class RtlFoundationTest extends TestCase
{
    public function testStoredLeftAndRightMeanStartAndEnd(): void
    {
        self::assertSame('start', TextDirection::alignValue('left'));
        self::assertSame('end', TextDirection::alignValue('right'));
        self::assertSame('center', TextDirection::alignValue('center'));
        self::assertSame('text-start', TextDirection::alignClass('left'));
        self::assertSame('text-end', TextDirection::alignClass('right'));
        self::assertSame('', TextDirection::alignClass('bogus'));
        self::assertSame('margin-inline-start', TextDirection::property('margin-left'));
        self::assertSame('padding-inline-end', TextDirection::property('padding-right'));
        self::assertSame('border-inline-start-width', TextDirection::property('border-left-width'));
        self::assertSame('margin-top', TextDirection::property('margin-top'));
        self::assertSame('margin-inline-start:auto;margin-inline-end:0', TextDirection::blockAlignCss('right'));
    }

    public function testEveryPageTemplateCarriesTheDirection(): void
    {
        $read = static fn(string $f): string => (string) file_get_contents(ROOT_PATH . '/' . $f);
        foreach (['includes/header.php', 'themes/default/layouts/header.php', 'admin/includes/header.php'] as $file) {
            self::assertStringContainsString('<html lang="<?php echo getLang(); ?>"<?php echo htmlDirAttr(); ?>', $read($file), $file);
        }
        foreach (['aurora', 'business', 'minimal', 'trade'] as $theme) {
            self::assertStringContainsString("function_exists('htmlDirAttr') ? htmlDirAttr() : ''", $read("marketplace/themes/$theme/layouts/header.php"), $theme);
        }
        // 编辑器外壳跟后台语言，画布跟页面语言
        self::assertStringContainsString('htmlDirAttr(getLang())', $read('admin/blox_editor.php'));
        self::assertStringContainsString("htmlDirAttr(siteLang())", $read('includes/builder/BloxCanvasPreview.php'));
        // LTR 不输出 dir：现有页面逐字节不变
        self::assertStringContainsString("return LanguageRegistry::isRtl(\$lang ?? getLang()) ? ' dir=\"rtl\"' : '';", $read('includes/functions.php'));
    }

    public function testArabicHasItsOwnFontsAndDirectionalIconsMirror(): void
    {
        self::assertSame('ar', \LanguageRegistry::fontGroup('ar'));
        self::assertStringContainsString("'ar' => [", (string) file_get_contents(ROOT_PATH . '/includes/font_presets.php'));
        $css = (string) file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertMatchesRegularExpression('/\[dir=rtl\]\s*:is\([^)]*\.ti-arrow-right/', $css, '方向图标在 RTL 下镜像（编译产物里要有）');
        self::assertStringContainsString('.text-start{text-align:start}', $css);
        self::assertStringContainsString('.text-end{text-align:end}', $css);
    }

    /** 新增写死左右的样式会让阿拉伯语页面错位：数量只许减少（改造完执行 php tools/check_rtl.php --update 收紧）。 */
    public function testNoNewPhysicalDirectionStyles(): void
    {
        $base = json_decode((string) file_get_contents(ROOT_PATH . '/tools/rtl-baseline.json'), true);
        self::assertIsArray($base);
        $worse = [];
        foreach (rtl_counts() as $rel => $n) {
            if ($n > (int) ($base[$rel] ?? 0)) $worse[] = "$rel: $n > " . (int) ($base[$rel] ?? 0);
        }
        self::assertSame([], $worse, "新增了物理方向写法，改用 ms-/me-/ps-/pe-/text-start 等，或加 rtl: 变体；逐处查看：php tools/check_rtl.php --file=<路径>");
    }

    public function testCheckerFindsPhysicalClassesAndSkipsSafeOnes(): void
    {
        $hits = rtl_scan('<div class="ml-4 md:pr-2 text-left left-1/2 ms-4"><p class="pl-2 rtl:pr-2"></p>', 'php');
        self::assertSame(['ml-4', 'pr-2', 'text-left'], array_column($hits, 'text'));
        $css = rtl_scan(".a{margin-left:4px;left:auto;text-align:right}\n.b{inset-inline-start:0}", 'css');
        self::assertSame(['margin-left:', 'text-align:right'], array_column($css, 'text'));
    }
}
