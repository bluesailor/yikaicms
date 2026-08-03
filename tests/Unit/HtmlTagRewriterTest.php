<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HtmlTagRewriter;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/HtmlTagRewriter.php';

/**
 * 标签改写器。重点锁三件事：
 *   1. 未被改写的字节原样保留（黄金对拍前提）；
 *   2. 实体解码不再依赖外部实体表——旧实现在 href="…&amp;…" 上会致命错误；
 *   3. script/style/textarea 内的伪标签不被当作真标签改写。
 */
final class HtmlTagRewriterTest extends TestCase
{
    public function testReadsAttributesAcrossQuotingStyles(): void
    {
        $p = new HtmlTagRewriter('<img src="/a.jpg" alt=\'单引号\' width=120 hidden>');
        $this->assertTrue($p->nextTag('IMG'));
        $this->assertSame('IMG', $p->getTag());
        $this->assertSame('/a.jpg', $p->getAttribute('src'));
        $this->assertSame('单引号', $p->getAttribute('alt'));
        $this->assertSame('120', $p->getAttribute('width'));
        $this->assertTrue($p->getAttribute('hidden'), '无值属性返回 true');
        $this->assertNull($p->getAttribute('missing'));
    }

    /** 旧的 vendored 解码器在这里会抛 Error，导致正文渲染白屏 */
    public function testDecodesCharacterReferencesWithoutFatal(): void
    {
        $p = new HtmlTagRewriter('<a href="https://e.com/p?a=1&amp;b=2" title="A &quot;q&quot; &lt;b&gt;">x</a>');
        $this->assertTrue($p->nextTag('A'));
        $this->assertSame('https://e.com/p?a=1&b=2', $p->getAttribute('href'));
        $this->assertSame('A "q" <b>', $p->getAttribute('title'));
    }

    public function testUntouchedBytesArePreserved(): void
    {
        $html = "<div class='x'>\n  <!-- 注释 <img src=/c.jpg> -->\n  <p>文本 &amp; 实体</p>\n</div>";
        $p = new HtmlTagRewriter($html);
        while ($p->nextTag('IMG')) {
            $p->setAttribute('loading', 'lazy');
        }
        $this->assertSame($html, $p->getUpdatedHtml(), '无改写时原样返回');
    }

    public function testSetAttributeReplacesInPlaceAndEscapes(): void
    {
        $p = new HtmlTagRewriter('<img src="/a.jpg" alt="旧">');
        $p->nextTag('IMG');
        $p->setAttribute('alt', 'A & B "q"');
        $this->assertSame(
            '<img src="/a.jpg" alt="A &amp; B &quot;q&quot;">',
            $p->getUpdatedHtml()
        );
    }

    public function testNewAttributesAreInsertedAfterTagName(): void
    {
        $p = new HtmlTagRewriter('<img src="/a.jpg">');
        $p->nextTag('IMG');
        $p->setAttribute('loading', 'lazy');
        $p->setAttribute('decoding', 'async');
        $this->assertSame('<img decoding="async" loading="lazy" src="/a.jpg">', $p->getUpdatedHtml());
    }

    public function testSetAttributeIsReadableWithinSameTag(): void
    {
        $p = new HtmlTagRewriter('<img src="/a.jpg">');
        $p->nextTag('IMG');
        $p->setAttribute('alt', '新值');
        $this->assertSame('新值', $p->getAttribute('alt'), '同一标签内可读回刚写入的值');
    }

    public function testAddClassAppendsAndDeduplicates(): void
    {
        $p = new HtmlTagRewriter('<img class="a b" src="/x.jpg"><img src="/y.jpg">');
        $p->nextTag('IMG');
        $p->addClass('b');
        $this->assertSame('<img class="a b" src="/x.jpg"><img src="/y.jpg">', $p->getUpdatedHtml(), '已存在则不重复');

        $p2 = new HtmlTagRewriter('<img class="a" src="/x.jpg">');
        $p2->nextTag('IMG');
        $p2->addClass('u-img');
        $this->assertSame('<img class="a u-img" src="/x.jpg">', $p2->getUpdatedHtml());
    }

    public function testSkipsRawTextElementContent(): void
    {
        $html = '<script>var s = "<img src=/evil.jpg>";</script><style>b{content:"<img>"}</style><img src="/real.jpg">';
        $p = new HtmlTagRewriter($html);
        $hits = 0;
        while ($p->nextTag('IMG')) {
            $hits++;
            $p->setAttribute('loading', 'lazy');
        }
        $this->assertSame(1, $hits, 'script/style 内的伪标签不参与匹配');
        $this->assertStringContainsString('<img loading="lazy" src="/real.jpg">', $p->getUpdatedHtml());
        $this->assertStringContainsString('"<img src=/evil.jpg>"', $p->getUpdatedHtml(), 'JS 字符串原样保留');
    }

    public function testSkipsCommentsAndClosingTags(): void
    {
        $p = new HtmlTagRewriter('<!-- <img src="/c.jpg"> --></p><img src="/d.jpg">');
        $tags = [];
        while ($p->nextTag()) {
            $tags[] = $p->getTag();
        }
        $this->assertSame(['IMG'], $tags);
    }

    public function testDuplicateAttributeUsesFirstOccurrence(): void
    {
        $p = new HtmlTagRewriter('<img src="/first.jpg" src="/second.jpg">');
        $p->nextTag('IMG');
        $this->assertSame('/first.jpg', $p->getAttribute('src'));
    }

    public function testHandlesSelfClosingAndUppercaseTags(): void
    {
        $p = new HtmlTagRewriter('<IMG SRC="/u.jpg" />');
        $this->assertTrue($p->nextTag('img'));
        $this->assertSame('IMG', $p->getTag());
        $this->assertSame('/u.jpg', $p->getAttribute('src'));
        $p->setAttribute('loading', 'lazy');
        $this->assertSame('<IMG loading="lazy" SRC="/u.jpg" />', $p->getUpdatedHtml());
    }

    public function testMalformedAndEmptyInputDoNotHang(): void
    {
        foreach (['', 'plain &amp; text', '<img src="/x.jpg" <a href="/y">z</a>', '<<>>', '<img'] as $html) {
            $p = new HtmlTagRewriter($html);
            $guard = 0;
            while ($p->nextTag() && $guard < 50) {
                $guard++;
            }
            $this->assertLessThan(50, $guard, '不应出现死循环：' . $html);
            $this->assertIsString($p->getUpdatedHtml());
        }
    }

    public function testGetUpdatedHtmlIsRepeatable(): void
    {
        $p = new HtmlTagRewriter('<img src="/a.jpg">');
        $p->nextTag('IMG');
        $p->setAttribute('loading', 'lazy');
        $this->assertSame($p->getUpdatedHtml(), $p->getUpdatedHtml());
    }
}
