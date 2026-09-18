<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

/** 客户评价轮播与合作伙伴 Logo 墙：数据在文档里，输出安全、带下方圆点导航。 */
final class TestimonialLogoWallElementsTest extends TestCase
{
    public function testTestimonialCarouselRendersAvatarsRatingsAndDotNavigation(): void
    {
        $element = BuilderRegistry::get('testimonial-carousel');
        self::assertInstanceOf(TestimonialCarouselElement::class, $element);
        self::assertSame(['/assets/js/blox-carousel.js'], $element->scriptsFor([]));
        self::assertSame(['/assets/css/blox-carousel.css'], $element->stylesFor([]));

        $html = $element->render([
            'items' => [
                ['avatar' => '/assets/images/blox-templates/avatar-1.svg', 'name' => 'Ann <b>', 'role' => 'CTO', 'content' => "Great\nteam", 'rating' => '5'],
                ['avatar' => 'javascript:alert(1)', 'name' => 'Bob', 'content' => 'Fast', 'rating' => '9'],
                ['name' => '', 'content' => ''],
                'not-an-item',
            ],
            'per_view' => '1',
            'interval' => '3',
        ]);
        self::assertStringContainsString('data-yk-carousel style="--yk-carousel-per-view:1" data-yk-carousel-autoplay="3000"', $html);
        self::assertSame(2, substr_count($html, 'class="yk-carousel-slide"'));
        self::assertStringContainsString('<div class="yk-carousel-dots" data-yk-carousel-dots></div>', $html);
        self::assertStringContainsString('src="/assets/images/blox-templates/avatar-1.svg" alt="Ann &lt;b&gt;"', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('yk-testimonial-initial', $html, 'invalid avatar falls back to initials');
        self::assertSame(10, substr_count($html, '★'), 'ratings clamp to five stars');
        self::assertStringContainsString("Great<br />\nteam", $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testCarouselSkipsAutoplayWhenEverythingFitsAndCanHideDots(): void
    {
        $html = (new TestimonialCarouselElement())->render([
            'items' => [['name' => 'A', 'content' => 'x'], ['name' => 'B', 'content' => 'y']],
            'per_view' => '3',
            'show_dots' => false,
        ]);
        self::assertStringNotContainsString('data-yk-carousel-autoplay', $html);
        self::assertStringNotContainsString('data-yk-carousel-dots', $html);
        self::assertSame('', (new TestimonialCarouselElement())->render(['items' => []]));
    }

    public function testLogoWallRendersSafeLinksAsGridOrMarquee(): void
    {
        $element = BuilderRegistry::get('logo-wall');
        self::assertInstanceOf(LogoWallElement::class, $element);
        $items = [
            ['logo' => '/assets/images/blox-templates/partner-1.svg', 'name' => 'Lumina', 'url' => 'https://lumina.test'],
            ['logo' => '', 'name' => 'Text only', 'url' => 'javascript:alert(1)'],
            ['logo' => '', 'name' => ''],
        ];
        $grid = $element->render(['items' => $items, 'columns' => '4', 'logo_height' => 'lg']);
        self::assertStringContainsString('style="--yk-logo-cols:4;--yk-logo-h:72px"', $grid);
        self::assertSame(2, substr_count($grid, 'class="yk-logo-item"'));
        self::assertStringContainsString('href="https://lumina.test" target="_blank" rel="noopener"', $grid);
        self::assertStringNotContainsString('javascript:', $grid);
        self::assertStringContainsString('<span class="yk-logo-name">Text only</span>', $grid);

        $marquee = $element->render(['items' => $items, 'layout' => 'marquee', 'grayscale' => false]);
        self::assertSame(4, substr_count($marquee, 'class="yk-logo-item"'), 'marquee duplicates the list once');
        self::assertStringContainsString('<ul class="yk-logo-track" aria-hidden="true">', $marquee);
        self::assertStringContainsString('<a tabindex="-1" class="yk-logo-link"', $marquee);
        self::assertStringNotContainsString('yk-logo-wall--grayscale', $marquee);
    }

    public function testBothElementsUseTheSharedItemsRepeaterControl(): void
    {
        foreach (['testimonial-carousel', 'logo-wall'] as $type) {
            $control = BuilderRegistry::get($type)->controls()[0];
            self::assertSame('items_repeater', $control['type']);
            self::assertNotEmpty($control['fields']);
            self::assertNotEmpty($control['default']);
        }
        $workspace = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/workspace.php');
        self::assertStringContainsString("require __DIR__ . '/items-repeater-control.php';", $workspace);
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        self::assertStringContainsString('...window.BloxItemsControl.methods,', $editor);
    }
}
