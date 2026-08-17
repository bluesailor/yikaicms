<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BundledMediaLibraryTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT_PATH . '/includes/BundledMediaLibrary.php';
    }

    public function testDefaultThemeCtaImagesAreExposedAsReadOnlyMedia(): void
    {
        $items = BundledMediaLibrary::search('image');

        self::assertCount(4, $items);
        foreach ($items as $item) {
            self::assertStringStartsWith('builtin-cta-', (string) $item['id']);
            self::assertStringStartsWith('/themes/default/assets/images/cta/', (string) $item['url']);
            self::assertFileExists(ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, (string) $item['url']));
            self::assertSame('image', $item['type']);
            self::assertSame('png', $item['ext']);
            self::assertTrue($item['builtin']);
            self::assertGreaterThan(0, $item['width']);
            self::assertGreaterThan(0, $item['height']);
        }
    }

    public function testBundledMediaSupportsEnglishChineseAndJapaneseSearchTerms(): void
    {
        self::assertSame('builtin-cta-technology-services', BundledMediaLibrary::search('image', 'technology')[0]['id']);
        self::assertSame('builtin-cta-smart-manufacturing', BundledMediaLibrary::search('image', '智能')[0]['id']);
        self::assertSame('builtin-cta-business-collaboration', BundledMediaLibrary::search('image', 'ビジネス')[0]['id']);
    }

    public function testBundledImagesDoNotLeakIntoFilePickerResults(): void
    {
        self::assertSame([], BundledMediaLibrary::search('file'));
    }
}
