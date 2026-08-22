<?php
/**
 * 导航尾部 CTA 按钮（v1.18.5，nav / nav-mega / nav-drawer 共用配置组）：
 *   - 文字/链接留空不渲染（存量文档零变化）
 *   - cta_url 走 safeHref，javascript: 等伪协议整体不出按钮
 *   - 实心/描边两档样式；抽屉为通栏形态
 *   - NavElement 的 TagEngine 模板路径把 CTA 拼在 {yk:nav} 循环之外
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use NavDrawerElement;
use NavElement;
use NavMegaElement;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class NavCtaTest extends TestCase
{
    // ---- ctaHtml 契约 ----

    public function testEmptyTextOrUrlRendersNothing(): void
    {
        $this->assertSame('', NavMegaElement::ctaHtml([]));
        $this->assertSame('', NavMegaElement::ctaHtml(['cta_text' => '联系我们']));
        $this->assertSame('', NavMegaElement::ctaHtml(['cta_url' => '/contact.html']));
    }

    public function testJavascriptUrlIsRejectedEntirely(): void
    {
        $out = NavMegaElement::ctaHtml(['cta_text' => 'Go', 'cta_url' => 'javascript:alert(1)']);
        $this->assertSame('', $out);
    }

    public function testSolidAndOutlineVariants(): void
    {
        $solid = NavMegaElement::ctaHtml(['cta_text' => '联系我们', 'cta_url' => '/contact.html']);
        $this->assertStringContainsString('bg-primary', $solid);
        $this->assertStringContainsString('href="/contact.html"', $solid);
        $this->assertStringStartsWith('<li', $solid);

        $outline = NavMegaElement::ctaHtml([
            'cta_text' => '联系我们', 'cta_url' => '/contact.html', 'cta_style' => 'outline',
        ]);
        $this->assertStringContainsString('border-primary', $outline);
        $this->assertStringNotContainsString('bg-primary text-white', $outline);
    }

    public function testBlockVariantIsFullWidthAnchor(): void
    {
        $out = NavMegaElement::ctaHtml(['cta_text' => 'お問い合わせ', 'cta_url' => '/contact.html'], 'block');
        $this->assertStringStartsWith('<a ', $out);
        $this->assertStringContainsString('justify-center', $out);
        $this->assertStringNotContainsString('<li', $out);
    }

    public function testLabelIsHtmlEscaped(): void
    {
        $out = NavMegaElement::ctaHtml(['cta_text' => '<b>x</b>', 'cta_url' => '/a']);
        $this->assertStringNotContainsString('<b>', $out);
        $this->assertStringContainsString('&lt;b&gt;', $out);
    }

    // ---- 三元素接线 ----

    public function testNavTemplateMarkupAppendsCtaOutsideLoop(): void
    {
        $markup = (new NavElement())->buildMarkup([
            'cta_text' => '联系我们', 'cta_url' => '/contact.html',
        ]);
        $this->assertStringContainsString('{/yk:nav}<li', $markup);
        $this->assertStringContainsString('href="/contact.html"', $markup);
        $this->assertStringEndsWith('</ul>', $markup);
    }

    public function testNavTemplateMarkupUnchangedWithoutCta(): void
    {
        $markup = (new NavElement())->buildMarkup([]);
        $this->assertStringContainsString('{/yk:nav}</ul>', $markup);
    }

    public function testDrawerRendersBlockCtaBeforeUtilities(): void
    {
        $out = (new NavDrawerElement())->render([
            'id' => 't1', 'cta_text' => 'Contact', 'cta_url' => '/contact.html',
        ]);
        $this->assertStringContainsString('href="/contact.html"', $out);
        $this->assertStringContainsString('justify-center', $out);
    }

    public function testDrawerWithoutCtaHasNoCtaBlock(): void
    {
        $out = (new NavDrawerElement())->render(['id' => 't2']);
        $this->assertStringNotContainsString('pt-4', $out);
    }

    // ---- 菜单项图标（v1.18.6：菜单组 _icon → 三端导航渲染）----

    public function testNodeIconHtmlRendersTablerAndBootstrap(): void
    {
        $this->assertSame(
            '<i class="ti ti-home mr-1" aria-hidden="true"></i>',
            NavMegaElement::nodeIconHtml(['_icon' => 'home'], 'mr-1')
        );
        $this->assertStringContainsString('bi bi-house', NavMegaElement::nodeIconHtml(['_icon' => 'bi:house']));
    }

    public function testNodeIconHtmlEmptyForMissingOrNoneIcon(): void
    {
        // 无图标与空值：输出空串——存量渲染逐字节不变
        $this->assertSame('', NavMegaElement::nodeIconHtml([]));
        $this->assertSame('', NavMegaElement::nodeIconHtml(['_icon' => '']));
        $this->assertSame('', NavMegaElement::nodeIconHtml(['_icon' => 'none']));
    }

    public function testNodeIconFallsBackToChannelIconColumn(): void
    {
        // 菜单项级 _icon 优先；空则回退栏目自身 icon 列（channels.icon）
        $this->assertStringContainsString('ti-box', NavMegaElement::nodeIconHtml(['icon' => 'box']));
        $this->assertStringContainsString('ti-star-filled', NavMegaElement::nodeIconHtml(['_icon' => 'star-filled', 'icon' => 'box']));
        // 历史脏值（非法字符）不渲染，不触发 star 兜底
        $this->assertSame('', NavMegaElement::nodeIconHtml(['icon' => 'ho me<script>']));
    }
}
