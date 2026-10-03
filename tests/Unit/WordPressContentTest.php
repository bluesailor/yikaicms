<?php
/**
 * WordPress 导入的正文清理：区块注释、构建器短代码、图片短代码、表单、Elementor、段落、原站网址。
 */
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressContent;

require_once ROOT_PATH . '/includes/migrate/WordPressContent.php';

final class WordPressContentTest extends TestCase
{
    private function cleaner(): WordPressContent
    {
        return new WordPressContent(
            [12 => '/wp-content/uploads/2023/05/gear.jpg', 13 => '/wp-content/uploads/2023/05/ring.png'],
            ['www.slewing-bearing.com', 'slewing-bearing.com'],
            ['ja.slewing-bearing.com' => 'ja']
        );
    }

    public function testBlockCommentsAreRemovedAndMarkupKept(): void
    {
        $html = "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->\n<!-- wp:image {\"id\":12} -->\n<figure><img src=\"/a.jpg\"></figure>\n<!-- /wp:image -->";
        self::assertSame("<p>Hello</p>\n<figure><img src=\"/a.jpg\"></figure>", $this->cleaner()->clean($html));
    }

    public function testWpBakeryRowsAreUnwrappedAndImagesResolved(): void
    {
        $html = '[vc_row][vc_column width="1/2"][vc_column_text]<p>Slewing bearings carry axial loads.</p>[/vc_column_text][/vc_column]'
            . '[vc_column][vc_single_image image="12" img_size="full"][/vc_column][/vc_row]';
        $out = $this->cleaner()->clean($html);
        self::assertStringNotContainsString('[vc_', $out);
        self::assertStringContainsString('<p>Slewing bearings carry axial loads.</p>', $out);
        self::assertStringContainsString('<img src="/wp-content/uploads/2023/05/gear.jpg" alt="">', $out);
    }

    public function testCaptionGalleryAndEmbed(): void
    {
        $c = $this->cleaner();
        self::assertSame("<figure><img src=\"/x.jpg\" alt=\"x\">
<figcaption>Our factory</figcaption>
</figure>",
            $c->clean('[caption id="attachment_5" align="aligncenter"]<img src="/x.jpg" alt="x"> Our factory[/caption]'));
        self::assertSame("<p><img src=\"/wp-content/uploads/2023/05/gear.jpg\" alt=\"\"></p>\n<p><img src=\"/wp-content/uploads/2023/05/ring.png\" alt=\"\"></p>",
            $c->clean('[gallery ids="12, 13" columns="2"]'));
        self::assertSame('<p><a href="https://www.youtube.com/watch?v=x">https://www.youtube.com/watch?v=x</a></p>',
            $c->clean('[embed]https://www.youtube.com/watch?v=x[/embed]'));
    }

    public function testFormsAndSlidersAreDroppedAndReported(): void
    {
        $c = $this->cleaner();
        self::assertSame('<p>Contact us:</p>', $c->clean("Contact us:\n\n[contact-form-7 id=\"5\" title=\"Inquiry\"]\n\n[rev_slider alias=\"home\"][/rev_slider]"));
        self::assertSame(['contact-form-7' => 1, 'rev_slider' => 1], $c->dropped);
    }

    public function testUnknownSingleBracketsInProseAreKept(): void
    {
        $c = $this->cleaner();
        self::assertSame('<p>The torque curve [see figure 2] and footnote [1] stay.</p>', $c->clean('The torque curve [see figure 2] and footnote [1] stay.'));
        self::assertSame(['see' => 1], $c->unknown);
        self::assertSame('<p>Inner text</p>', $c->clean('[custom_box style="a"]Inner text[/custom_box]'), '成对的短代码去标签留内容');
    }

    public function testElementorDataIsUsedWhenContentIsEmpty(): void
    {
        $json = json_encode([['elType' => 'section', 'elements' => [['elType' => 'column', 'elements' => [
            ['elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => 'Why us', 'header_size' => 'h1']],
            ['elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['editor' => '<p>Since 1998.</p>']],
            ['elType' => 'widget', 'widgetType' => 'image', 'settings' => ['image' => ['id' => 13, 'url' => 'https://www.slewing-bearing.com/old.png']]],
            ['elType' => 'widget', 'widgetType' => 'button', 'settings' => ['text' => 'Buy']],
        ]]]]]);
        $out = $this->cleaner()->clean('', (string) $json);
        self::assertSame("<h2>Why us</h2>\n<p>Since 1998.</p>\n<p><img src=\"/wp-content/uploads/2023/05/ring.png\" alt=\"\"></p>", $out);
        self::assertSame('<p>Kept</p>', $this->cleaner()->clean('<p>Kept</p>', (string) $json), '正文不空时不用 Elementor 数据');
    }

    public function testAutopForClassicEditorText(): void
    {
        self::assertSame("<p>Line one<br>\nline two</p>\n<p>Second paragraph</p>\n<ul>\n<li>a</li>\n</ul>",
            WordPressContent::autop("Line one\nline two\n\nSecond paragraph\n<ul><li>a</li></ul>"));
    }

    public function testSiteUrlsBecomeLocalPathsAndLanguageHostsGetAPrefix(): void
    {
        $html = '<a href="https://www.slewing-bearing.com/product/gear/">a</a>'
            . '<a href="http://slewing-bearing.com">b</a>'
            . '<a href="https://ja.slewing-bearing.com/about/">c</a>'
            . '<img src="https://ja.slewing-bearing.com/wp-content/uploads/2023/05/gear.jpg">'
            . '<a href="https://example.org/x">d</a>';
        $out = $this->cleaner()->localizeUrls($html);
        self::assertSame('<a href="/product/gear/">a</a><a href="/">b</a><a href="/ja/about/">c</a>'
            . '<img src="/wp-content/uploads/2023/05/gear.jpg"><a href="https://example.org/x">d</a>', $out);
    }
}
