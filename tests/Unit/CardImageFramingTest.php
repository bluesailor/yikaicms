<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class CardImageFramingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testDefaultsAreUnchangedAndFramesKeepImageBehaviour(): void
    {
        $element = new CardElement();
        $data = ['image' => '/uploads/images/card.jpg', 'title' => 'Title', 'text' => 'Copy', 'link' => '/contact.html'];
        $original = $element->render($data);
        self::assertStringContainsString('aspect-video overflow-hidden bg-gray-100', $original);
        self::assertStringNotContainsString('yk-card-media-framed', $original);
        foreach (['default', null, [], '100vh;position:fixed'] as $ratio) {
            self::assertSame($original, $element->render($data + ['image_ratio' => $ratio, 'image_fit' => [], 'image_position' => 'bad']));
        }
        foreach (['top', 'side'] as $layout) {
            $html = $element->render($data + ['card_layout' => $layout, 'image_ratio' => 'square',
                'image_fit' => 'contain', 'image_position' => 'bottom-right', 'card_hover' => 'zoom']);
            self::assertStringContainsString('yk-card-media-framed', $html);
            self::assertStringContainsString('aspect-ratio:1 / 1;', $html);
            self::assertStringContainsString('object-fit:contain;object-position:right bottom;', $html);
            self::assertStringContainsString('yk-card-hover-zoom', $html);
            self::assertStringContainsString('href="/contact.html"', $html);
            self::assertStringContainsString('loading="lazy" decoding="async"', $html);
        }
        $natural = $element->render($data + ['image_ratio' => 'auto']);
        self::assertStringContainsString('yk-card-media-original', $natural);
        self::assertStringContainsString('aspect-ratio:auto;', $natural);
        $defaultContain = $element->render($data + ['image_fit' => 'contain']);
        self::assertStringNotContainsString('yk-card-media-framed', $defaultContain);
        self::assertStringContainsString('object-fit:contain;', $defaultContain);
    }

    public function testTextLayoutRetainsFramingWhenSavedAndSwitchedBack(): void
    {
        $data = ['image' => '/uploads/images/card.jpg', 'title' => 'Title', 'text' => '<strong>Copy</strong>',
            'text_format' => 'html', 'card_layout' => 'text', 'image_ratio' => 'portrait',
            'image_fit' => 'cover', 'image_position' => 'left', 'animation' => 'fade-up'];
        $json = json_encode([['columns' => [['elements' => [['type' => 'card', 'data' => $data]]]]]], JSON_THROW_ON_ERROR);
        $result = BloxDocumentPipeline::process($json, 'page');
        $saved = json_decode($result['json'], true, 512, JSON_THROW_ON_ERROR)['sections'][0]['columns'][0]['elements'][0]['data'];
        foreach ($data as $key => $value) self::assertSame($value, $saved[$key]);
        $element = new CardElement();
        self::assertStringNotContainsString('<img ', $element->render($saved));
        $saved['card_layout'] = 'side';
        $html = $element->render($saved);
        self::assertStringContainsString('aspect-ratio:3 / 4;', $html);
        self::assertStringContainsString('object-position:left center;', $html);
        self::assertStringContainsString('<strong>Copy</strong>', $html);
        self::assertStringContainsString('fade-up', $html);
        $controls = array_column(BloxImageFraming::controls(true), null, 'key');
        self::assertSame('default', $controls['image_ratio']['default']);
        foreach ($controls as $control) {
            self::assertContains(['card_layout', '!=', 'text'], $control['visible_when']['terms']);
            self::assertContains(['image', 'not_empty'], $control['visible_when']['terms']);
        }
    }
}
