<?php
/**
 * 模板规范的单一来源（2.0.3）：行业分类一份配置、多语字段同一套回落、目录多语字段同一份白名单。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use LanguageRegistry;
use PHPUnit\Framework\TestCase;
use SiteTemplateMarket;
use TemplateCategories;

require_once ROOT_PATH . '/includes/TemplateCategories.php';
require_once ROOT_PATH . '/includes/SiteTemplateMarket.php';
require_once ROOT_PATH . '/includes/ThemeContent.php';
require_once ROOT_PATH . '/includes/ThemeValidator.php';

final class TemplateContractsTest extends TestCase
{
    public function testCategoriesComeFromOneConfigWithLegacyAliases(): void
    {
        self::assertSame(['general', 'manufacturing', 'food', 'home', 'service', 'auto', 'energy', 'creative'], TemplateCategories::keys());
        self::assertSame('service', TemplateCategories::normalize('services'));
        self::assertSame('creative', TemplateCategories::normalize(' Tech '));
        self::assertSame('food', TemplateCategories::normalize('food'));
        self::assertSame('', TemplateCategories::normalize('banking'));
        self::assertSame('餐饮与食品', TemplateCategories::label('food', 'zh-CN'));
        self::assertSame('Food & dining', TemplateCategories::label('food', 'ko'), '韩语缺译先显示英文');
        self::assertSame('飲食・食品', TemplateCategories::label('food', 'ja'));
        self::assertSame(['category_name' => '专业服务', 'category_name_en' => 'Professional services', 'category_name_ja' => '専門サービス'],
            TemplateCategories::catalogNameFields('services'));
        // 校验器的兼容常量与配置一致
        self::assertSame(TemplateCategories::keys(), \ThemeValidator::CATEGORIES);
    }

    public function testNestedValuesFollowTheRegistryFallback(): void
    {
        $value = ['zh-CN' => '中文', 'en' => 'English', 'ja' => '', 'de' => 'Deutsch'];
        self::assertSame('Deutsch', LanguageRegistry::localizedValue($value, 'de'));
        self::assertSame('English', LanguageRegistry::localizedValue($value, 'ar'));
        self::assertSame('中文', LanguageRegistry::localizedValue($value, 'ja'), '日语缺译回落中文');
        self::assertSame('中文', LanguageRegistry::localizedValue($value, 'zh-TW'));
        self::assertSame('English', \ThemeContent::localized($value, 'ko'));
    }

    public function testCatalogTextFieldsCoverEveryRegisteredLanguage(): void
    {
        $keys = SiteTemplateMarket::localizedTextKeys();
        foreach (['description', 'category_name', 'name_en', 'name_ko', 'description_ar', 'category_name_pt'] as $key) {
            self::assertContains($key, $keys);
        }
        self::assertNotContains('name_zh-CN', $keys);
        // 目录准备工具与后台市场页都用这一份，不另写白名单
        $prepare = (string) file_get_contents(ROOT_PATH . '/tools/prepare-site-template-market.php');
        self::assertStringContainsString("'languages', 'demo_url'],\n        SiteTemplateMarket::localizedTextKeys());", str_replace("\r\n", "\n", $prepare));
        self::assertStringContainsString('TemplateCategories::normalize(', $prepare);
        self::assertStringContainsString('SiteTemplateMarket::localizedTextKeys()', (string) file_get_contents(ROOT_PATH . '/admin/site_template_market.php'));
    }

    public function testThemeValidatorFlagsUnknownLanguageSuffixesAndLegacyCategories(): void
    {
        $result = \ThemeValidator::validateMeta([
            'schema_version' => 1, 'name' => 'Contract', 'version' => '1.0.0', 'author' => 'Yikai',
            'category' => 'services', 'name_en' => 'C', 'name_ja' => 'C', 'description_en' => 'd', 'description_ja' => 'd',
            'name_ko' => '계약', 'name_kr' => 'typo',
        ], 'contract', false);
        $warnings = implode("
", $result['warnings']);
        self::assertStringContainsString('name_kr', $warnings);
        self::assertStringNotContainsString('name_ko', $warnings);
        self::assertStringContainsString('请改为「service」', $warnings);
        self::assertSame([], $result['errors']);
    }
}
