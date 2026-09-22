<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class TemplatePresetMotionTest extends TestCase
{
    public function testLocalizedPackagesImportWithEditableNodesAndFreshIds(): void
    {
        foreach (['heading-card-pair', 'product-showcase', 'story-split'] as $slug) {
            foreach (['zh-CN', 'en', 'ja'] as $lang) {
                $path = BloxBuiltinTemplateProvider::localizedPackagePath('section', $slug . '.json', $lang);
                $raw = (string) file_get_contents($path);
                $first = BloxTemplateImporter::prepare($raw);
                $second = BloxTemplateImporter::prepare($raw);
                self::assertCount(1, $first['sections']);
                self::assertNotSame($first['sections'][0]['id'], $second['sections'][0]['id']);
                $json = (string) json_encode($first['sections']);
                self::assertStringContainsString('animation_stagger', $json);
                self::assertStringNotContainsString('list-dynamic', $json);
                self::assertStringNotContainsString('/uploads/', $raw);
                if ($slug === 'product-showcase') {
                    $loop = $first['sections'][0]['columns'][0]['elements'][2];
                    self::assertSame('type:product', $loop['data']['_query']['source']);
                    self::assertSame(3, $loop['data']['_query']['limit']);
                    self::assertSame(['d' => '3', 't' => '2', 'm' => '1'], $loop['data']['grid_cols']);
                    self::assertSame('{{loop.title}}', $loop['data']['children'][0]['data']['title']);
                } else {
                    $html = BlockRenderer::render(json_encode(['sections' => $first['sections']], JSON_THROW_ON_ERROR));
                    self::assertStringContainsString('data-stagger', $html);
                    self::assertStringContainsString('data-animate-speed="fast"', $html);
                    self::assertStringNotContainsString('opacity:0', $html);
                }
                preg_match_all('#"(/(?:assets/)?images/[A-Za-z0-9._/-]+)"#', $raw, $assets);
                foreach (array_unique($assets[1]) as $asset) {
                    self::assertFileExists(ROOT_PATH . $asset);
                }
            }
        }
    }

    public function testDynamicPresetFollowsExistingAuthoringPolicy(): void
    {
        $provider = new BloxBuiltinTemplateProvider();
        $keys = array_column($provider->items('home'), 'key');
        self::assertContains('builtin:heading-card-pair', $keys);
        self::assertContains('builtin:story-split', $keys);
        self::assertSame(BloxFeaturePolicy::allows('query_loop'), in_array('builtin:product-showcase', $keys, true));
        if (!BloxFeaturePolicy::allows('query_loop')) {
            $this->expectException(RuntimeException::class);
        }
        $provider->resolve('product-showcase', 'home');
    }

    public function testCardBindingsRemainEscapedAndUrlPolicyIsNotRelaxed(): void
    {
        $previous = $GLOBALS['_test_config'] ?? [];
        try {
            $GLOBALS['_test_config']['site_name'] = '<img src=x onerror=alert(1)>';
            $GLOBALS['_test_config']['site_url'] = 'javascript:alert(1)';
            $html = BuilderRegistry::get('card')->render([
                'title' => '{{site.name}}', 'text' => '<p>{{site.name}}</p>', 'text_format' => 'html',
                'link' => '{{site.url}}', 'image' => '{{site.url}}',
            ]);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString('<a ', $html);
            self::assertStringContainsString('&lt;img', $html);
            foreach (['url' => '{{loop.url}}', 'image' => '{{loop.cover}}'] as $type => $tag) {
                $control = ['type' => $type, 'dynamic_placeholder' => $tag];
                self::assertSame($tag, BloxValueSanitizer::sanitize($control, $tag));
                self::assertSame('', BloxValueSanitizer::sanitize(['type' => $type], $tag));
                foreach (['javascript:alert(1)', '{{loop.url}}&quot; onclick=alert(1)', '{{loop.cover|javascript:alert(1)}}'] as $bad) {
                    self::assertSame('', BloxValueSanitizer::sanitize($control, $bad));
                }
            }
        } finally {
            $GLOBALS['_test_config'] = $previous;
        }
    }
}
