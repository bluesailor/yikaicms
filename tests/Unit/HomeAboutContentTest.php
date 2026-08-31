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
}
