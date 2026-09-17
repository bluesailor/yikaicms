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
    /**
     * 2026-09-16 起本地内置区块缩减为六款（01–06）：站点真实在用的五款 + 补齐的基础「简洁标题」。
     * 其余 22 款移出随包目录，重设计后进远程精品库，详见 CLAUDE-SECTION-LIBRARY-PROGRESS.md。
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function presets(): array
    {
        return [
            '01 简洁标题' => ['basic-heading', 'content', '一句话说清这一段讲什么'],
            '02 图文介绍' => ['image-text', 'content', '我们是谁'],
            '03 服务优势' => ['feature-grid', 'business', '资质齐全'],
            '04 行动引导' => ['cta-banner', 'marketing', '立即咨询'],
            '05 图文咨询引导' => ['cta-split', 'marketing', '准备好开始合作了吗'],
            '06 客户引语' => ['testimonial-quote', 'content', '客户反馈'],
        ];
    }

    /** 基础区块要有稳定编号，界面上按 01–07 呈现；整页模板不参与编号。 */
    public function testBasicSectionsCarryStableNumbers(): void
    {
        $numbers = [];
        foreach ((new BloxBuiltinTemplateProvider())->items('page') as $item) {
            if ($item['type'] === 'section') {
                $numbers[substr($item['key'], strlen('builtin:'))] = $item['number'];
            } else {
                self::assertSame(0, $item['number'], '整页模板不编号');
            }
        }

        self::assertSame([
            'basic-heading' => 1,
            'image-text' => 2,
            'feature-grid' => 3,
            'cta-banner' => 4,
            'cta-split' => 5,
            'testimonial-quote' => 6,
        ], $numbers);
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

        $item = null;
        foreach ($provider->items('page') as $candidate) {
            if ($candidate['key'] === 'builtin:' . $slug) {
                $item = $candidate;
                break;
            }
        }
        if (is_array($item) && ($item['metadata']['data_source'] ?? 'static') === 'dynamic') {
            $serialized = (string) json_encode($prepared['sections'], JSON_UNESCAPED_UNICODE);
            self::assertStringContainsString('list-dynamic', $serialized);
            return;
        }

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

    /**
     * 随包基础区块（六款保留 + 价格方案）都要带可用于场景推荐的元数据。
     *
     * 原先此处还断言 team-recruiting / client-logo-wall / product-comparison / download-guide /
     * contact-strip 以及 hero-split / feature-cards-soft / faq-split / *-grid-dynamic 的
     * variant、data_source、states——那些模板 2026-09-16 已移出随包目录，断言随之移除；
     * 元数据**归一化逻辑本身**的覆盖在 BloxSectionMetadataTest，未受影响。
     */
    public function testShippedSectionsCarryPageIntentMetadata(): void
    {
        $items = [];
        foreach ((new BloxBuiltinTemplateProvider())->items('page') as $item) {
            $items[$item['key']] = $item;
        }

        foreach (array_column(self::presets(), 0) as $slug) {
            $metadata = $items['builtin:' . $slug]['metadata'];
            // 优先级只要求"已声明且在合理区间"——具体高低是编辑取舍，不该由测试钉死
            self::assertGreaterThan(0, $metadata['priority'], $slug . ' 未声明推荐优先级');
            self::assertLessThanOrEqual(100, $metadata['priority']);
            self::assertNotEmpty($metadata['page_types'], $slug . ' 缺少适用页面类型');
            self::assertNotEmpty($metadata['content_slots'], $slug . ' 缺少内容槽位声明');
            self::assertSame('static', $metadata['data_source'] ?? 'static', '随包基础区块不绑定动态数据源');
        }
    }
}
