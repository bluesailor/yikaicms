<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/builder/BloxTemplateImporter.php';
require_once ROOT_PATH . '/includes/builder/BloxBuiltinTemplateProvider.php';

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

final class SectionTemplateLibraryTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function presets(): array
    {
        return [
            'hero' => ['hero-intro', 'landing', '以专业与稳健'],
            'image and text' => ['image-text', 'content', '我们是谁'],
            'reverse image and text' => ['image-text-reverse', 'content', '把复杂问题说清楚'],
            'text columns' => ['text-columns', 'content', '定位与目标'],
            'metrics' => ['stats-band', 'business', '成立年份'],
            'features' => ['feature-grid', 'business', '资质齐全'],
            'process' => ['process-steps', 'business', '需求确认'],
            'trust' => ['trust-grid', 'business', '可核验的交付标准'],
            'cards' => ['card-grid', 'marketing', '研发设计'],
            'cases' => ['case-grid', 'marketing', '生产流程数字化'],
            'quote' => ['testimonial-quote', 'content', '客户反馈'],
            'faq' => ['faq-accordion', 'content', '多久能收到回复'],
            'cta' => ['cta-banner', 'marketing', '立即咨询'],
            'contact strip' => ['contact-strip', 'marketing', '让我们讨论您的项目'],
            'team and recruiting' => ['team-recruiting', 'business', '和专业的人一起'],
            'client logo wall' => ['client-logo-wall', 'business', '服务过的客户'],
            'product comparison' => ['product-comparison', 'marketing', '选择适合的方案'],
            'download guide' => ['download-guide', 'content', '资料与下载'],
        ];
    }

    public function testProviderListsSectionPresetsForPageAndHomeEditors(): void
    {
        $provider = new BloxBuiltinTemplateProvider();
        foreach (['page', 'home'] as $context) {
            $items = [];
            foreach ($provider->items($context) as $item) {
                $items[$item['key']] = $item;
            }

            foreach (self::presets() as [$slug, $category]) {
                $key = 'builtin:' . $slug;
                self::assertArrayHasKey($key, $items, "{$slug} missing from {$context} library");
                self::assertSame('section', $items[$key]['type']);
                self::assertSame('builtin', $items[$key]['source']);
                self::assertSame($category, $items[$key]['category']);
                self::assertNotSame('', $items[$key]['name']);
                self::assertNotSame('', $items[$key]['description']);
                if (isset($items[$key]['keywords'])) {
                    self::assertIsString($items[$key]['keywords']);
                }

                $thumbnail = ROOT_PATH . $items[$key]['thumbnail'];
                self::assertFileExists($thumbnail);
                self::assertSame([1200, 525], array_slice((array) getimagesize($thumbnail), 0, 2));
            }
        }
    }

    /** @dataProvider presets */
    public function testPresetImportsRendersAndGetsFreshIds(
        string $slug,
        string $category,
        string $needle
    ): void {
        $file = ROOT_PATH . '/templates/blox/sections/' . $slug . '.json';
        self::assertFileExists($file);

        $prepared = BloxTemplateImporter::prepare((string) file_get_contents($file));
        self::assertSame('section', $prepared['type']);
        self::assertCount(1, $prepared['sections']);

        $provider = new BloxBuiltinTemplateProvider();
        $first = $provider->resolve($slug, 'page');
        $second = $provider->resolve($slug, 'home');
        self::assertCount(1, $first['sections']);
        self::assertCount(1, $second['sections']);
        self::assertNotSame($first['sections'][0]['id'], $second['sections'][0]['id']);

        $html = BlockRenderer::render((string) json_encode(
            ['schema' => 1, 'settings' => [], 'sections' => $prepared['sections']],
            JSON_UNESCAPED_UNICODE
        ));
        self::assertStringContainsString($needle, $html);
    }

    /** @dataProvider presets */
    public function testPresetUsesOnlyShippedAssets(string $slug, string $category, string $needle): void
    {
        $raw = (string) file_get_contents(ROOT_PATH . '/templates/blox/sections/' . $slug . '.json');
        self::assertStringNotContainsString('/uploads/', $raw);

        preg_match_all('#"(/images/[A-Za-z0-9._/-]+)"#', $raw, $matches);
        foreach (array_unique($matches[1]) as $asset) {
            self::assertFileExists(ROOT_PATH . $asset, "{$slug} references missing asset {$asset}");
        }
    }

    public function testLocalLibraryOffersScenarioFiltering(): void
    {
        $overlay = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/overlays.php');
        self::assertStringContainsString(
            'x-show="templateCategoryOptions().length > 1"',
            $overlay
        );
    }

    public function testHighFrequencySectionsCarryPageIntentMetadata(): void
    {
        $provider = new BloxBuiltinTemplateProvider();
        $items = [];
        foreach ($provider->items('page') as $item) {
            $items[$item['key']] = $item;
        }

        $expected = [
            'builtin:team-recruiting' => ['jobs', 'about'],
            'builtin:client-logo-wall' => ['home', 'case'],
            'builtin:product-comparison' => ['product-list', 'product-detail'],
            'builtin:download-guide' => ['product-detail', 'service'],
            'builtin:contact-strip' => ['contact', 'service'],
        ];
        foreach ($expected as $key => $pageTypes) {
            self::assertArrayHasKey($key, $items);
            $metadata = $items[$key]['metadata'];
            self::assertGreaterThanOrEqual(80, $metadata['priority']);
            foreach ($pageTypes as $pageType) {
                self::assertContains($pageType, $metadata['page_types']);
            }
        }
    }
}
