<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxSectionMetadataTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testNormalizeRejectsUnknownValuesAndBoundsListsAndPriority(): void
    {
        $metadata = BloxSectionMetadata::normalize([
            'purpose' => '<script>',
            'page_types' => ['about', 'about', 'unknown', '<img>'],
            'industries' => ['manufacturing', '../escape'],
            'content_slots' => ['heading', 'bad slot'],
            'cta_type' => 'javascript',
            'required_plugins' => ['forms', '../../config'],
            'language_coverage' => ['zh-CN', 'en', 'bad_language'],
            'image_ratio' => '16:9',
            'min_cms_version' => '1.19.2',
            'priority' => 999,
        ], 'content');

        self::assertSame(1, $metadata['schema']);
        self::assertSame('content', $metadata['purpose']);
        self::assertSame(['about'], $metadata['page_types']);
        self::assertSame(['manufacturing'], $metadata['industries']);
        self::assertSame(['heading'], $metadata['content_slots']);
        self::assertSame('none', $metadata['cta_type']);
        self::assertSame(['forms'], $metadata['required_plugins']);
        self::assertSame(['zh-CN', 'en'], $metadata['language_coverage']);
        self::assertSame('16:9', $metadata['image_ratio']);
        self::assertSame('1.19.2', $metadata['min_cms_version']);
        self::assertSame(100, $metadata['priority']);
    }

    public function testLegacyTemplatesReceiveGeneralMetadata(): void
    {
        $metadata = BloxSectionMetadata::normalize(null);

        self::assertSame('general', $metadata['purpose']);
        self::assertSame(['general'], $metadata['page_types']);
        self::assertSame(0, $metadata['priority']);
    }

    /** @dataProvider pageIntentProvider */
    public function testPageIntentInference(array $flags, array $page, string $expected): void
    {
        self::assertSame($expected, BloxSectionMetadata::inferPageType(
            $flags['home'],
            $flags['template'],
            $flags['contact'],
            $flags['product'],
            $flags['content'],
            $page
        ));
    }

    /** @return iterable<string,array{array<string,bool>,array<string,string>,string}> */
    public static function pageIntentProvider(): iterable
    {
        $none = ['home' => false, 'template' => false, 'contact' => false, 'product' => false, 'content' => false];
        yield 'home wins' => [array_replace($none, ['home' => true]), [], 'home'];
        yield 'template stays neutral' => [array_replace($none, ['template' => true]), ['slug' => 'about'], 'general'];
        yield 'contact flag' => [array_replace($none, ['contact' => true]), [], 'contact'];
        yield 'product root' => [array_replace($none, ['product' => true]), [], 'product-list'];
        yield 'content root' => [array_replace($none, ['content' => true]), [], 'content-list'];
        yield 'english service slug' => [$none, ['slug' => 'service-process', 'name' => 'Process'], 'service'];
        yield 'localized about name' => [$none, ['slug' => 'company', 'name' => '关于我们'], 'about'];
        yield 'unknown page' => [$none, ['slug' => 'custom', 'name' => 'Custom'], 'general'];
    }
}
