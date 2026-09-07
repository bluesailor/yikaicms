<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeAboutContentTest extends TestCase
{
    private mixed $previous;

    protected function setUp(): void
    {
        $this->previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        $GLOBALS['yikai_config_runtime_overrides'] = [
            'site_lang' => 'zh-CN', 'home_about_title' => 'Company',
            'home_about_content' => 'Original body', 'home_about_link' => '/company.html',
        ];
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            unset($GLOBALS['yikai_config_runtime_overrides']);
        } else {
            $GLOBALS['yikai_config_runtime_overrides'] = $this->previous;
        }
    }

    public function testLocalizedValuesAreReadOnlyAndUseExistingLanguageResolvers(): void
    {
        foreach (['zh-CN', 'en', 'ja'] as $language) {
            $GLOBALS['yikai_config_runtime_overrides']['site_lang'] = $language;
            $GLOBALS['yikai_config_runtime_overrides']['home_about_title_' . $language] = 'Title ' . $language;
            $GLOBALS['yikai_config_runtime_overrides']['home_about_content_' . $language] = 'Body ' . $language;
            $before = $GLOBALS['yikai_config_runtime_overrides'];
            $values = HomeAboutContent::resolve();
            self::assertSame('Title ' . $language, $values['override_title']);
            self::assertSame('Body ' . $language, $values['override_content']);
            self::assertSame($before, $GLOBALS['yikai_config_runtime_overrides']);
        }
    }

    public function testMissingImageUsesDefaultButExplicitEmptyImageStaysEmpty(): void
    {
        self::assertSame('/assets/images/demo/about-office.jpg', HomeAboutContent::resolve()['override_image']);
        $GLOBALS['yikai_config_runtime_overrides']['home_about_image'] = '';
        self::assertSame('', HomeAboutContent::resolve()['override_image']);
        $GLOBALS['yikai_config_runtime_overrides']['home_about_image'] = '/uploads/company.jpg';
        self::assertSame('/uploads/company.jpg', HomeAboutContent::resolve()['override_image']);
    }

    public function testRuntimeOverridesAndEmptyResetUseTheSameSource(): void
    {
        $base = $GLOBALS['yikai_config_runtime_overrides'];
        $override = ['block_type' => 'about', 'override_title' => 'Draft', 'override_content' => 'Draft body'];
        $GLOBALS['yikai_config_runtime_overrides'] = array_merge($base, HomeBloxBlockSchema::runtimeConfigOverrides($override));
        self::assertSame('Draft', HomeAboutContent::resolve()['override_title']);
        self::assertSame('Draft body', HomeAboutContent::resolve()['override_content']);
        $override['override_title'] = " \t\n";
        $GLOBALS['yikai_config_runtime_overrides'] = array_merge($base, HomeBloxBlockSchema::runtimeConfigOverrides($override));
        self::assertSame('Company', HomeAboutContent::resolve()['override_title']);
        self::assertSame('Draft body', HomeAboutContent::resolve()['override_content']);
    }

    public function testImportWithoutChannelKeepsConfiguredLinkVerbatim(): void
    {
        foreach (['', '0', '/company.html?from=home'] as $url) {
            $GLOBALS['yikai_config_runtime_overrides']['home_about_link'] = $url;
            self::assertSame($url, HomeAboutContent::resolve()['override_button_url']);
        }
    }

    public function testStandardSectionPreservesOverridesAndContainsOnlyGenericElements(): void
    {
        $section = HomeAboutContent::toSection([
            'override_title' => 'Independent title', 'override_content' => '<script>alert(1)</script> & text',
            'override_image' => '/uploads/about.jpg', 'override_button_url' => '/en/about.html',
            'override_tag_title' => 'Service', 'override_tag_description' => 'Quality',
            'override_ratio' => '2_1', 'override_layout' => 'image_left', 'override_breakpoint' => 'md',
        ], 'snapshot');
        self::assertSame([4, 8], array_column($section['columns'], 'span'));
        self::assertFalse($section['settings']['tablet_stack']);
        $visual = $section['columns'][0]['elements'][0];
        self::assertSame('div', $visual['type']);
        self::assertSame('overlay', $visual['data']['display']);
        self::assertSame('/uploads/about.jpg', $visual['data']['children'][0]['data']['src']);
        self::assertArrayNotHasKey('style_margin_top', $visual['data']['children'][1]['data']);
        $text = $section['columns'][1]['elements'];
        self::assertSame(['heading', 'divider', 'text', 'button'], array_column($text, 'type'));
        self::assertSame('Independent title', $text[0]['data']['text']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; text', $text[2]['data']['html']);
        self::assertSame('/en/about.html', $text[3]['data']['url']);
        $json = json_encode(['schema' => 1, 'settings' => [], 'sections' => [$section]], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('home-block', $json);
        self::assertStringNotContainsString('override_', $json);
        $prepared = BloxDocumentPipeline::process($json);
        self::assertNotEmpty($prepared);
        $html = BlockRenderer::render($json);
        self::assertStringContainsString('Independent title', $html);
        self::assertStringContainsString('Quality', $html);
        self::assertStringContainsString('yk-div-overlay', $html);
        self::assertContains('/assets/css/blox-overlay.css', BloxAssetCollector::styles());
        self::assertStringNotContainsString('<script>alert', $html);
        $GLOBALS['yikai_config_runtime_overrides']['home_about_title'] = 'Changed site title';
        self::assertSame($html, BlockRenderer::render($json));
    }

    public function testDisabledAndEmptyOverridesRetainTheirMeaning(): void
    {
        $section = HomeAboutContent::toSection(['enabled' => false, 'override_title' => ' ', 'bg_color' => '#112233']);
        self::assertTrue($section['settings']['hidden']);
        self::assertTrue($section['settings']['tablet_stack']);
        self::assertSame('#112233', $section['settings']['bg_color']);
        self::assertSame('Company', $section['columns'][0]['elements'][0]['data']['text']);
        self::assertSame('', BlockRenderer::render(json_encode(['schema' => 1, 'sections' => [$section]], JSON_THROW_ON_ERROR)));
    }

    public function testLegacyImportCreatesStandardAboutSections(): void
    {
        $created = !db()->tableExists('channels');
        if ($created) {
            db()->execute('CREATE TABLE channels (id INTEGER PRIMARY KEY, slug TEXT, status INTEGER)');
        }
        $GLOBALS['yikai_config_runtime_overrides']['home_blocks_config'] = json_encode([
            ['type' => 'about', 'enabled' => true],
        ], JSON_THROW_ON_ERROR);
        try {
            $section = HomeLayoutDocument::legacySectionsForImport()[0];
            self::assertCount(2, $section['columns']);
            self::assertSame('heading', $section['columns'][0]['elements'][0]['type']);
        } finally {
            if ($created) {
                db()->execute('DROP TABLE channels');
            }
        }
    }
}
