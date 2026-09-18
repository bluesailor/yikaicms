<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class ImageFramingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testDefaultAndInvalidFramesKeepOriginalImage(): void
    {
        $element = new ImageElement();
        $data = ['src' => '/uploads/images/test.jpg', 'alt' => '<Photo>'];
        $original = $element->render($data);
        self::assertStringNotContainsString('aspect-ratio', $original);
        self::assertStringContainsString('alt="&lt;Photo&gt;"', $original);
        foreach (['auto', null, [], '1;position:fixed'] as $ratio) {
            self::assertSame($original, $element->render($data + ['image_ratio' => $ratio]));
        }
        $fallback = $element->render($data + ['image_ratio' => 'square', 'image_fit' => [], 'image_position' => 'bad']);
        self::assertStringContainsString('aspect-ratio:1 / 1;object-fit:cover;object-position:center center;', $fallback);
    }

    public function testFramingSurvivesSaveAndKeepsClickActions(): void
    {
        $data = ['src' => '/uploads/images/test.jpg', 'alt' => 'Photo', 'image_ratio' => 'portrait',
            'image_fit' => 'contain', 'image_position' => 'top-right', 'animation' => 'fade-up'];
        $json = json_encode([['columns' => [['width' => 12, 'elements' => [['type' => 'image', 'data' => $data]]]]]], JSON_THROW_ON_ERROR);
        $result = BloxDocumentPipeline::process($json, 'page');
        $saved = json_decode($result['json'], true, 512, JSON_THROW_ON_ERROR)['sections'][0]['columns'][0]['elements'][0]['data'];
        foreach ($data as $key => $value) self::assertSame($value, $saved[$key]);
        $element = new ImageElement();
        $lightbox = $element->render($saved + ['click_action' => 'lightbox']);
        self::assertStringContainsString('data-lightbox', $lightbox);
        self::assertStringContainsString('aspect-ratio:3 / 4;object-fit:contain;object-position:right top;', $lightbox);
        self::assertStringContainsString('fade-up', $lightbox);
        $linked = $element->render($saved + ['click_action' => 'link', 'link_url' => '/contact.html', 'link_new_tab' => true]);
        self::assertStringStartsWith('<a href="/contact.html"', $linked);
        self::assertStringContainsString('target="_blank" rel="noopener"', $linked);
        self::assertStringContainsString('aspect-ratio:3 / 4;', $linked);
        self::assertStringContainsString('loading="lazy" decoding="async"', $linked);
        $dynamic = $element->render($saved + ['_responsive_image_field' => 'cover']);
        self::assertStringContainsString('{yk:image-attrs name=cover', $dynamic);
        self::assertStringContainsString('aspect-ratio:3 / 4;', $dynamic);
    }
}
