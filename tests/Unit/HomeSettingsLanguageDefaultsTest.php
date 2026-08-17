<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeSettingsLanguageDefaults;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/HomeSettingsLanguageDefaults.php';

final class HomeSettingsLanguageDefaultsTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $defaults;

    protected function setUp(): void
    {
        $this->defaults = getDefaults('home');
    }

    public function testEnglishSourceUsesTargetLanguageFallbacks(): void
    {
        self::assertSame(
            'Key Features',
            HomeSettingsLanguageDefaults::localizedValue('home_advantage_title', 'en', $this->defaults)
        );
        self::assertSame(
            'We focus on providing high-quality products and services to our clients, and are committed to being an industry-leading solution provider.',
            HomeSettingsLanguageDefaults::localizedValue('home_about_content', 'en', $this->defaults)
        );
        self::assertSame('Partners', HomeSettingsLanguageDefaults::localizedValue('home_links_title', 'en', $this->defaults));
        self::assertSame('Years of Experience', HomeSettingsLanguageDefaults::localizedValue('home_stat_1_text', 'en', $this->defaults));
        self::assertSame('', HomeSettingsLanguageDefaults::localizedValue('home_about_tag_title', 'en', $this->defaults));
        self::assertSame('[]', HomeSettingsLanguageDefaults::localizedValue('home_testimonials', 'en', $this->defaults));
    }

    public function testJapaneseSourceDoesNotFallBackToChineseFactoryCopy(): void
    {
        self::assertSame(
            'ご相談はお気軽に',
            HomeSettingsLanguageDefaults::localizedValue('home_cta_title', 'ja', $this->defaults)
        );
        self::assertNotSame(
            $this->defaults['home_cta_title']['value'],
            HomeSettingsLanguageDefaults::localizedValue('home_cta_title', 'ja', $this->defaults)
        );
    }

    public function testTraditionalChineseKeepsFactoryDataForS2tRendering(): void
    {
        self::assertSame(
            $this->defaults['home_advantage_title']['value'],
            HomeSettingsLanguageDefaults::localizedValue('home_advantage_title', 'zh-TW', $this->defaults)
        );
        self::assertSame([], HomeSettingsLanguageDefaults::pollutedFactoryRows('zh-TW', $this->defaults));
    }

    public function testUntouchedSyntheticValuesAreNotPersisted(): void
    {
        self::assertTrue(HomeSettingsLanguageDefaults::shouldSkipSyntheticWrite(
            'home_advantage_title',
            'Key Features',
            'en',
            false,
            null,
            $this->defaults
        ));
        self::assertTrue(HomeSettingsLanguageDefaults::shouldSkipSyntheticWrite(
            'home_advantage_title',
            '我们的优势',
            'en',
            false,
            null,
            $this->defaults
        ));
        self::assertFalse(HomeSettingsLanguageDefaults::shouldSkipSyntheticWrite(
            'home_advantage_title',
            'Why choose us',
            'en',
            false,
            null,
            $this->defaults
        ));
    }

    public function testCustomizedOrChineseSourceRowsRemainWritable(): void
    {
        self::assertFalse(HomeSettingsLanguageDefaults::shouldSkipSyntheticWrite(
            'home_advantage_title',
            'Key Features',
            'en',
            true,
            'Our strengths',
            $this->defaults
        ));
        self::assertFalse(HomeSettingsLanguageDefaults::shouldSkipSyntheticWrite(
            'home_advantage_title',
            '我们的优势',
            'zh-CN',
            false,
            null,
            $this->defaults
        ));
        self::assertFalse(HomeSettingsLanguageDefaults::shouldSkipSyntheticWrite(
            'home_blocks_config',
            '[]',
            'en',
            false,
            null,
            $this->defaults
        ));
    }

    public function testExactFactoryLeakIsDetectedButCustomCopyIsNot(): void
    {
        self::assertTrue(HomeSettingsLanguageDefaults::isLeakedFactoryValue(
            'home_advantage_title',
            '我们的优势',
            'en',
            $this->defaults
        ));
        self::assertFalse(HomeSettingsLanguageDefaults::isLeakedFactoryValue(
            'home_advantage_title',
            'Our strengths',
            'en',
            $this->defaults
        ));

        $polluted = HomeSettingsLanguageDefaults::pollutedFactoryRows('en', $this->defaults);
        self::assertSame('我们的优势', $polluted['home_advantage_title']);
        self::assertArrayNotHasKey('home_about_title', $polluted);
    }
}
