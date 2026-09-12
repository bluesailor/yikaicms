<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class CardIconVariantsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testLegacyOutputIsUnchanged(): void
    {
        $card = new CardElement();
        $data = ['title' => 'Title', 'text' => 'Copy'];
        $inner = '<div class="p-4"><h3 class="text-lg font-semibold mb-2">Title</h3><p class="text-sm text-gray-500">Copy</p></div>';
        $classes = 'block bg-white rounded-lg border border-gray-100 shadow-sm overflow-hidden';
        self::assertSame('<div class="' . $classes . '">' . $inner . '</div>', $card->render($data));
        self::assertSame('<a href="/contact.html" class="' . $classes . ' hover:shadow-md transition no-underline">' . $inner . '</a>', $card->render($data + ['link' => '/contact.html']));
        $icon = new IconBoxElement();
        self::assertSame(
            '<div class="yk-icon-interactive text-center px-4 py-6"><i aria-hidden="true" class="' . BloxIcon::classes('star') . ' inline-block text-primary" style="font-size:40px;line-height:1"></i><h3 class="text-lg font-semibold mt-3 mb-1">Title</h3><p class="text-sm text-gray-500">Copy</p></div>',
            $icon->render($data)
        );
        foreach ([null, [], ['top'], 'untrusted" onclick="x', 5] as $invalid) {
            self::assertSame($card->render($data), $card->render($data + ['card_layout' => $invalid, 'card_surface' => $invalid, 'card_hover' => $invalid]));
            self::assertSame($icon->render($data), $icon->render($data + ['icon_layout' => $invalid, 'icon_surface' => $invalid]));
        }
        self::assertSame($card->render($data), $card->render($data + ['card_layout' => 'top', 'card_surface' => 'shadow', 'card_hover' => 'default']));
        self::assertSame($icon->render($data), $icon->render($data + ['icon_layout' => 'top', 'icon_surface' => 'none']));
    }

    public function testVisualOptionsAreValidatedSelectControls(): void
    {
        foreach ([new CardElement(), new IconBoxElement()] as $element) {
            foreach ($element->controls() as $control) {
                if (!isset($control['option_preview'])) {
                    continue;
                }
                self::assertSame('select', $control['type']);
                self::assertSame('style', $control['tab']);
                self::assertArrayHasKey($control['default'], $control['options']);
                foreach (array_merge(array_keys($control['options']), ['bad', ['top']]) as $value) {
                    $payload = ['title' => 'Original title', 'text' => 'Original copy', 'link' => '/contact.html',
                        'image' => '/uploads/images/example.jpg', 'icon' => 'star', $control['key'] => $value];
                    $saved = $this->roundTrip($element->type(), $payload);
                    self::assertSame(is_string($value) && isset($control['options'][$value]) ? $value : $control['default'], $saved[$control['key']]);
                    self::assertSame('Original title', $saved['title']);
                    self::assertSame('Original copy', $saved['text']);
                    if ($element->type() === 'card') {
                        self::assertSame('/uploads/images/example.jpg', $saved['image']);
                        self::assertSame('/contact.html', $saved['link']);
                    } else {
                        self::assertSame('star', $saved['icon']);
                    }
                    self::assertSame($saved, $this->roundTrip($element->type(), $saved));
                }
            }
        }
    }

    public function testCardLayoutsAndSurfacesStayIndependent(): void
    {
        $element = new CardElement();
        foreach (['top', 'side', 'text'] as $layout) {
            foreach (['plain', 'border', 'shadow'] as $surface) {
                $data = ['card_layout' => $layout, 'card_surface' => $surface,
                    'image' => '/uploads/images/example.jpg', 'title' => '<Title>', 'text' => 'Copy & more', 'link' => '/contact.html'];
                $html = $element->render($data);
                self::assertStringStartsWith('<a href="/contact.html"', $html);
                self::assertStringContainsString('&lt;Title&gt;', $html);
                self::assertStringContainsString('Copy &amp; more', $html);
                self::assertSame($layout !== 'text', str_contains($html, '<img '));
                self::assertSame($layout === 'side', str_contains($html, 'yk-card-side'));
                self::assertSame($surface === 'shadow', str_contains($html, 'shadow-sm'));
                self::assertSame($surface !== 'plain', str_contains($html, ' border '));
                if ($layout !== 'text') {
                    self::assertStringContainsString('loading="lazy" decoding="async"', $html);
                }
            }
        }
    }

    public function testTextLayoutKeepsImageForSwitchingBack(): void
    {
        $data = ['title' => 'Title', 'image' => '/uploads/images/retained.jpg', 'card_layout' => 'text', 'card_hover' => 'zoom'];
        $saved = $this->roundTrip('card', $data);
        self::assertSame($data['image'], $saved['image']);
        self::assertStringNotContainsString('<img ', (new CardElement())->render($saved));
        $saved['card_layout'] = 'side';
        self::assertStringContainsString('retained.jpg', (new CardElement())->render($this->roundTrip('card', $saved)));
    }

    public function testHoverOnlyAppliesToValidLinkedCards(): void
    {
        $element = new CardElement();
        foreach (['default', 'lift', 'zoom', 'none'] as $hover) {
            $data = ['title' => 'Title', 'card_hover' => $hover, 'image' => '/uploads/images/example.jpg'];
            foreach (['', 'javascript:alert(1)'] as $link) {
                $html = $element->render($data + ['link' => $link]);
                self::assertStringStartsWith('<div ', $html);
                self::assertStringNotContainsString('yk-card-hover-', $html);
                self::assertStringNotContainsString('hover:shadow-md', $html);
            }
            $linked = $element->render($data + ['link' => '/contact.html']);
            self::assertSame($hover === 'lift', str_contains($linked, 'yk-card-hover-lift'));
            self::assertSame($hover === 'zoom', str_contains($linked, 'yk-card-hover-zoom'));
            self::assertSame($hover === 'default', str_contains($linked, 'hover:shadow-md'));
        }
        foreach ([['image' => ''], ['image' => '/uploads/images/example.jpg', 'card_layout' => 'text']] as $data) {
            $html = $element->render($data + ['title' => 'Title', 'link' => '/contact.html', 'card_hover' => 'zoom']);
            self::assertStringNotContainsString('yk-card-hover-zoom', $html);
        }
    }

    public function testEmptyImageOrBodyDoesNotReserveAnEmptySideColumn(): void
    {
        foreach ([['title' => 'Title'], ['image' => '/uploads/images/example.jpg']] as $data) {
            self::assertStringNotContainsString('yk-card-side', (new CardElement())->render($data + ['card_layout' => 'side']));
        }
    }

    public function testIconVariantsKeepContentAndIconMotion(): void
    {
        $element = new IconBoxElement();
        foreach (['top', 'side', 'compact'] as $layout) {
            foreach (['none', 'circle', 'square'] as $surface) {
                $data = ['icon_layout' => $layout, 'icon_surface' => $surface, 'icon' => 'shield', 'title' => '<Title>', 'text' => 'Copy & more', 'icon_motion' => 'pulse'];
                $html = $element->render($data);
                self::assertStringContainsString('&lt;Title&gt;', $html);
                self::assertStringContainsString('Copy &amp; more', $html);
                self::assertStringContainsString(BloxIcon::classes('shield'), $html);
                self::assertStringContainsString(BloxIcon::motionClass('pulse'), $html);
                self::assertStringContainsString('font-size:' . ($layout === 'compact' ? '24' : '40') . 'px', $html);
                self::assertSame($element->stylesFor(['icon' => 'shield']), $element->stylesFor($data));
                if ($layout !== 'top' || $surface !== 'none') {
                    self::assertStringContainsString('yk-icon-box-' . $layout, $html);
                    self::assertStringContainsString('yk-icon-box-symbol-' . $surface, $html);
                }
            }
        }
    }

    public function testSharedBackgroundAndAnimationRemainOnRoot(): void
    {
        foreach (['card' => ['card_layout' => 'side', 'link' => '/contact.html'], 'icon-box' => ['icon_layout' => 'side']] as $type => $data) {
            $data += ['title' => 'Title', 'bg_color' => '#fafafa', 'animation' => 'fade-up'];
            $json = json_encode([['columns' => [['elements' => [['type' => $type, 'data' => $data]]]]]], JSON_THROW_ON_ERROR);
            $html = BlockRenderer::render($json);
            self::assertStringContainsString('background-color:#fafafa;', $html);
            self::assertStringContainsString('fade-up', $html);
        }
    }

    private function roundTrip(string $type, array $data): array
    {
        $json = json_encode([['columns' => [['width' => 12, 'elements' => [['type' => $type, 'data' => $data]]]]]], JSON_THROW_ON_ERROR);
        $result = BloxDocumentPipeline::process($json, 'page');
        $document = json_decode($result['json'], true, 512, JSON_THROW_ON_ERROR);
        return $document['sections'][0]['columns'][0]['elements'][0]['data'];
    }
}
