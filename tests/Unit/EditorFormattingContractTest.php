<?php
/**
 * 编辑器答应的格式，前台要真的显示出来（2026-09-18 复审 R08 / R09）。
 *
 * 后台 TinyMCE 工具栏一直提供字号、前景/背景色和段落对齐，写出来的是内联 style；
 * 正文净化以前对非 description 分支一律清空 style，于是"编辑器里设好 → 保存 → 前台没了"。
 * 同时 Blox 元素的 richtext 仍必须保持"排版只用 class"的老约定。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HtmlPolicy;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/UrlPolicy.php';
require_once ROOT_PATH . '/includes/HtmlPolicy.php';

final class EditorFormattingContractTest extends TestCase
{
    /** 工具栏提供的格式必须能存活（宽松档，CMS 正文用）。 */
    public function testToolbarFormattingSurvivesTheContentPolicy(): void
    {
        $kept = [
            '<p style="text-align: center;">居中</p>' => 'text-align: center',
            '<span style="color: #ff0000;">红字</span>' => 'color: #ff0000',
            '<span style="background-color: rgb(255,255,0);">高亮</span>' => 'background-color: rgb(255,255,0)',
            '<span style="font-size: 24px;">大字</span>' => 'font-size: 24px',
            '<span style="text-decoration: underline;">下划线</span>' => 'text-decoration: underline',
        ];
        foreach ($kept as $input => $expected) {
            self::assertStringContainsString(
                $expected,
                HtmlPolicy::richText($input, true),
                '工具栏给得出、前台却存不住：' . $input
            );
        }
    }

    /** 放行范围之外的样式一律丢弃，尤其是能拉外部资源或改变布局的那些。 */
    public function testDangerousOrLayoutStylesAreStillDropped(): void
    {
        $dropped = [
            '<p style="position: fixed; top: 0;">覆盖层</p>' => 'position',
            '<p style="background-image: url(javascript:alert(1));">x</p>' => 'background-image',
            '<p style="background: url(https://evil.example/t.png);">x</p>' => 'background',
            '<p style="width: 9999px; height: 9999px;">撑破</p>' => 'width',
            '<p style="font-size: 900px;">超大</p>' => 'font-size',
            '<p style="color: expression(alert(1));">老 IE</p>' => 'color',
            '<p style="display: none;">藏起来</p>' => 'display',
        ];
        foreach ($dropped as $input => $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                HtmlPolicy::richText($input, true),
                '不该放行的样式漏了：' . $input
            );
        }

        // 脚本类攻击面不受本次放宽影响
        $out = HtmlPolicy::richText('<p style="color:red" onclick="alert(1)">x</p><script>alert(1)</script>', true);
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringNotContainsString('<script', $out);
    }

    /** 严格档（默认）保持原样：Blox 元素值里不留内联 style。 */
    public function testStrictModeStillStripsInlineStyles(): void
    {
        $out = HtmlPolicy::richText('<p class="text-center" style="color: red; font-size: 24px;">x</p>');
        self::assertStringNotContainsString('style=', $out);
        self::assertStringContainsString('class="text-center"', $out);

        $sanitizer = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxValueSanitizer.php'));
        self::assertStringContainsString('HtmlPolicy::richText($html)', $sanitizer, 'Blox 必须走严格档');
        self::assertStringNotContainsString('sanitizeHtml($html)', $sanitizer);
    }

    /** 反复保存不该让同一段 HTML 越变越长或丢格式。 */
    public function testSanitisingTwiceIsStable(): void
    {
        $input = '<p style="text-align: center;"><span style="color: #336699; font-size: 18px;">稳定</span></p>';
        $once = HtmlPolicy::richText($input, true);
        self::assertSame($once, HtmlPolicy::richText($once, true));
    }

    /**
     * 英文后台的编辑器不该再被强行映射成中文（复审 R09）。
     * 两个编辑器入口（后台通用页、Blox 富文本弹窗）必须共用同一张语言表。
     */
    public function testEditorLanguageMappingFallsBackToEnglish(): void
    {
        $shared = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/assets/js/rich-editor.js'));
        self::assertStringContainsString("var packs = { 'ja': 'ja', 'zh-cn': 'zh_CN', 'zh': 'zh_CN', 'zh-tw': 'zh_CN' };", $shared);
        self::assertStringContainsString("return packs[String(lang || '').toLowerCase()] || '';", $shared);

        $footer = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/admin/includes/footer.php'));
        $dialog = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/media-editing-methods.php'));
        foreach (['footer.php' => $footer, 'Blox 富文本弹窗' => $dialog] as $label => $source) {
            self::assertStringNotContainsString("=== 'ja' ? 'ja' : 'zh_CN'", $source, "{$label} 还在「不是 ja 就当中文」");
            self::assertStringNotContainsString('=== "ja" ? "ja" : "zh_CN"', $source, "{$label} 还在「不是 ja 就当中文」");
            self::assertStringContainsString('window.editorLanguage', $source, "{$label} 没走共用语言表");
        }

        // 随包只有这两个语言包，映射表不能指向不存在的文件
        foreach (['ja', 'zh_CN'] as $pack) {
            self::assertFileExists(ROOT_PATH . '/assets/hugerte/langs/' . $pack . '.js');
        }
    }
}
