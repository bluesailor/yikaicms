<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class BreadcrumbElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    protected function tearDown(): void
    {
        BlockRenderer::$showHidden = false;
    }

    public function testRegisteredOnlyWhereAPageContextExists(): void
    {
        $element = BuilderRegistry::get('breadcrumb');
        $this->assertInstanceOf(BreadcrumbElement::class, $element);
        foreach (['page', 'content-list', 'product', 'contact'] as $context) {
            $this->assertTrue($element->paletteVisible($context), $context);
        }
        foreach (['home', 'header', 'footer'] as $context) {
            $this->assertFalse($element->paletteVisible($context), $context);
        }
    }

    public function testRendersTheCurrentPageTrailWithOptions(): void
    {
        $element = new BreadcrumbElement();
        $page = ['id' => 88, 'parent_id' => 0, 'name' => '会社概要', 'lang' => 'ja'];

        $html = PageTitleElement::withPage($page, static fn(): string => $element->render([]));
        $this->assertStringContainsString('<nav aria-label=', $html);
        $this->assertStringContainsString('aria-current="page">会社概要</li>', $html);
        $this->assertStringContainsString('<li aria-hidden="true">/</li>', $html);
        $this->assertStringContainsString('justify-start', $html);

        $custom = PageTitleElement::withPage($page, static fn(): string => $element->render([
            'home_text' => 'Top', 'separator' => 'chevron', 'show_current' => false, 'align' => 'center', 'size' => 'md',
        ]));
        $this->assertStringContainsString('>Top</a></li>', $custom);
        $this->assertStringNotContainsString('会社概要', $custom);
        $this->assertStringContainsString('text-base justify-center', $custom);

        $noHome = PageTitleElement::withPage($page, static fn(): string => $element->render(['show_home' => false]));
        $this->assertStringNotContainsString('<a ', $noHome);
        $this->assertStringContainsString('会社概要', $noHome);
    }

    public function testWithoutPageContextOnlyTheEditorCanvasShowsASample(): void
    {
        $element = new BreadcrumbElement();
        $this->assertSame('', $element->render([]));
        BlockRenderer::$showHidden = true;
        $this->assertStringContainsString('aria-current="page"', $element->render([]));
    }

    public function testCustomTextAndStylesCannotInjectMarkup(): void
    {
        $page = ['id' => 1, 'parent_id' => 0, 'name' => '<img src=x onerror=alert(1)>'];
        $html = PageTitleElement::withPage($page, static fn(): string => (new BreadcrumbElement())->render([
            'home_text' => '<script>alert(1)</script>', 'separator' => '"><script>', 'align' => 'left" onclick="x',
            'size' => 'xl" onclick="x', 'color' => 'red;position:fixed',
        ]));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('position:fixed', $html);
        $this->assertStringContainsString('<li aria-hidden="true">/</li>', $html);
    }

    public function testPageTitleKeepsItsBreadcrumbMarkupButNewInsertsLeaveItOff(): void
    {
        $page = ['id' => 5, 'parent_id' => 0, 'name' => 'About'];
        $html = PageTitleElement::withPage($page, static fn(): string => (new PageTitleElement())->render([]));
        $this->assertStringContainsString('<ol class="flex flex-wrap items-center gap-2 mb-4 text-sm justify-start">', $html);
        $defaults = (new PageTitleElement())->defaults();
        $this->assertFalse($defaults['show_breadcrumb']);
    }
}
