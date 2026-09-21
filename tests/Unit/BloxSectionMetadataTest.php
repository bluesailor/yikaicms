<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxSectionMetadataTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    /**
     * 目录分类（E08）。本地模板此前没有分类可填，`category` 恒等于 `type`，
     * 用户自存的区块全挤在"section"一格，分类过滤对它们等于不存在。
     */
    public function testCategoryIsKeptOnlyWhenItIsOneOfTheKnownBuckets(): void
    {
        foreach (BloxSectionMetadata::categories() as $category) {
            self::assertSame($category, BloxSectionMetadata::normalize(['category' => $category])['category']);
        }
        self::assertSame('marketing', BloxSectionMetadata::normalize(['category' => ' Marketing '])['category']);

        // 空/未知/非字符串一律退回未分类：宁可沿用 type，也不要把模板归错格子
        foreach (['', 'section', 'made-up', ' ', 42, null, ['marketing']] as $bad) {
            self::assertSame('', BloxSectionMetadata::normalize(['category' => $bad])['category'], var_export($bad, true));
        }
        self::assertSame('', BloxSectionMetadata::normalize([])['category'], '没填过分类的旧数据保持未分类');
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
            'category' => 'not-a-category',
        ], 'content');

        self::assertSame(1, $metadata['schema']);
        self::assertSame('content', $metadata['purpose']);
        self::assertSame('', $metadata['category'], '未知分类退回未分类，调用方据此沿用 type');
        self::assertSame(['about'], $metadata['page_types']);
        self::assertSame(['manufacturing'], $metadata['industries']);
        self::assertSame(['heading'], $metadata['content_slots']);
        self::assertSame('none', $metadata['cta_type']);
        self::assertSame(['forms'], $metadata['required_plugins']);
        self::assertSame(['zh-CN', 'en'], $metadata['language_coverage']);
        self::assertSame('16:9', $metadata['image_ratio']);
        self::assertSame('1.19.2', $metadata['min_cms_version']);
        self::assertSame('standard', $metadata['variant']);
        self::assertSame('static', $metadata['data_source']);
        self::assertSame([], $metadata['states']);
        self::assertSame(100, $metadata['priority']);
    }

    public function testLegacyTemplatesReceiveGeneralMetadata(): void
    {
        $metadata = BloxSectionMetadata::normalize(null);

        self::assertSame('general', $metadata['purpose']);
        self::assertSame(['general'], $metadata['page_types']);
        self::assertSame(0, $metadata['priority']);
        self::assertSame('standard', $metadata['variant']);
        self::assertSame('static', $metadata['data_source']);
    }

    public function testDynamicVariantMetadataIsBounded(): void
    {
        $metadata = BloxSectionMetadata::normalize([
            'variant' => 'dynamic',
            'data_source' => 'dynamic',
            'states' => ['loading', 'empty', 'error', 'invalid'],
        ]);

        self::assertSame('dynamic', $metadata['variant']);
        self::assertSame('dynamic', $metadata['data_source']);
        self::assertSame(['loading', 'empty', 'error'], $metadata['states']);
    }

    public function testLanguageCoverageUsesTheInstalledLanguageCatalog(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxSectionMetadata.php');

        self::assertStringContainsString("function_exists('availableLanguages')", $source);
        self::assertStringContainsString('isset($available[$language])', $source);
        self::assertStringNotContainsString("preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', \$language)", $source);
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
