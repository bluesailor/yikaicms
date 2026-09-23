<?php
/**
 * 模板里的 HTML 注释会原样发给每个访客（查看源代码可见）。
 * 这里既锁住改写器的边界，也锁住「发行源码里不再有会输出的 HTML 注释」。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HtmlCommentScanner;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/tools/html_comments.php';

final class HtmlCommentScannerTest extends TestCase
{
    private static function render(string $template): string
    {
        ob_start();
        eval('?>' . $template);
        return (string) ob_get_clean();
    }

    public function testCommentsBecomePhpCommentsAndDisappearFromOutput(): void
    {
        $tpl = "<div>\n    <!-- 语言切换（右上角） -->\n    <span>x</span>\n</div>\n";
        $r = HtmlCommentScanner::process($tpl);
        self::assertCount(1, $r['found']);
        self::assertSame('语言切换（右上角）', $r['found'][0]['text']);
        self::assertStringContainsString('<?php /* 语言切换（右上角） */ ?>', $r['source']);
        $out = self::render($r['source']);
        self::assertStringNotContainsString('<!--', $out);
        self::assertStringNotContainsString('语言切换', $out);
    }

    public function testRenderedOutputOnlyLosesTheComment(): void
    {
        $tpl = "<ul>\n  <li>a</li>\n  <!-- b -->\n  <li><?= 'c' ?></li><!-- d -->\n</ul>\n";
        $before = self::render($tpl);
        $after = self::render(HtmlCommentScanner::process($tpl)['source']);
        $strip = static fn(string $s): string => (string) preg_replace('/\s+/', ' ', (string) preg_replace('/<!--.*?-->/s', '', $s));
        self::assertSame($strip($before), $strip($after));
    }

    /** `?>` 吞掉的换行若是两段内容之间唯一的空白，必须补回，否则 inline 元素会粘连 */
    public function testWhitespaceBetweenGluedContentSurvives(): void
    {
        $tpl = "<a>1</a><!-- gap -->\n<a>2</a>";
        $out = self::render(HtmlCommentScanner::process($tpl)['source']);
        self::assertMatchesRegularExpression('~</a>\s+<a>~', $out);
    }

    public function testRawTextElementsConditionalAndPhpStringsAreUntouched(): void
    {
        $tpl = "<script>var s = '<!-- keep -->';</script>\n<style>/* <!-- keep --> */</style>\n"
            . "<pre><!-- keep --></pre>\n<textarea><!-- keep --></textarea>\n"
            . "<!--[if IE]><p>old</p><![endif]-->\n<?php echo '<!-- keep -->'; ?>\n";
        $r = HtmlCommentScanner::process($tpl);
        self::assertSame([], $r['found']);
        self::assertSame($tpl, $r['source']);
    }

    public function testCommentsSpanningPhpAreReportedNotRewritten(): void
    {
        $tpl = "<!-- start <?= \$x ?> end -->\n";
        $r = HtmlCommentScanner::process($tpl);
        self::assertSame([], $r['found']);
        self::assertSame($tpl, $r['source']);
        self::assertCount(1, $r['skipped']);
    }

    public function testCommentTerminatorInsideTextCannotCloseThePhpComment(): void
    {
        $r = HtmlCommentScanner::process("<!-- a */ b -->\n");
        self::assertSame('', self::render($r['source']));
    }

    /** 防回归：发行源码里不允许再出现会原样输出的 HTML 注释 */
    public function testShippedTemplatesEmitNoHtmlComments(): void
    {
        $offenders = [];
        foreach (HtmlCommentScanner::files(ROOT_PATH) as $rel) {
            $source = (string) file_get_contents(ROOT_PATH . '/' . $rel);
            if (!str_contains($source, '<!--')) continue;
            foreach (HtmlCommentScanner::process($source)['found'] as $c) {
                $offenders[] = "{$rel}:{$c['line']}";
            }
        }
        self::assertSame([], $offenders, '模板里的 HTML 注释会发给每个访客；改用 <?php /* … */ ?>（php tools/html_comments.php --fix）');
    }
}
