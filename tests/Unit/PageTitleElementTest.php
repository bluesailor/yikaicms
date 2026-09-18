<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class PageTitleElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testElementIsRegisteredAndKeepsItsEditableData(): void
    {
        $element = BuilderRegistry::get('page-title');
        $this->assertInstanceOf(PageTitleElement::class, $element);
        $this->assertTrue($element->paletteVisible('page'));
        $this->assertFalse($element->paletteVisible('header'));
        $this->assertFalse($element->paletteVisible('home'));
        $json = json_encode([['id' => 's1', 'columns' => [['id' => 'c1', 'width' => 12, 'elements' => [
            ['id' => 'e1', 'type' => 'page-title', 'data' => ['title' => 'Our company', 'show_breadcrumb' => false, 'bg_color' => '#ffffff']],
        ]]]]], JSON_THROW_ON_ERROR);
        $result = BloxDocumentPipeline::process($json, 'page');
        $this->assertStringContainsString('Our company', $result['json']);
        $document = json_decode($result['json'], true, 512, JSON_THROW_ON_ERROR);
        $data = $document['sections'][0]['columns'][0]['elements'][0]['data'];
        $html = PageTitleElement::withPage(['name' => 'Our company'], static fn(): string => $element->render($data));
        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringContainsString('#ffffff', $result['json']);
    }

    public function testPageContextSuppliesLocalizedTextAndBreadcrumbCanBeHidden(): void
    {
        $element = new PageTitleElement();
        $page = ['id' => 88, 'parent_id' => 0, 'name' => '会社概要', 'description' => '品質と信頼', 'lang' => 'ja'];
        $html = PageTitleElement::withPage($page, static fn(): string => $element->render([]));
        $this->assertStringContainsString('会社概要', $html);
        $this->assertStringContainsString('品質と信頼', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $hidden = PageTitleElement::withPage($page, static fn(): string => $element->render(['show_breadcrumb' => false, 'show_description' => false]));
        $this->assertStringNotContainsString('<nav', $hidden);
        $this->assertStringContainsString('会社概要', $hidden);
        $this->assertStringNotContainsString('品質と信頼', $hidden);
        $this->assertStringNotContainsString('会社概要', $element->render([]));
    }

    public function testCustomTextAndStylesCannotInjectMarkup(): void
    {
        $html = (new PageTitleElement())->render([
            'title' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(1)>',
            'level' => 'script', 'align' => 'left" onclick="alert(1)',
            'color' => 'red;position:fixed', 'bg_image' => 'javascript:alert(1)',
        ]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertStringNotContainsString('position:fixed', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<h1', $html);
    }
}
