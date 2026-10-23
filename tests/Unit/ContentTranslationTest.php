<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/i18n/LanguageRegistry.php';
require_once dirname(__DIR__, 2) . '/includes/content_translation.php';

final class ContentTranslationTest extends TestCase
{
    public function testHtmlAndExtraFieldsArePreserved(): void
    {
        $source = ['title' => '标题', 'summary' => '摘要', 'seo_keywords' => '关键词', 'content' => '<h2>标题</h2><p><a href="/about">正文 &amp; 内容</a><img src="/image.png"></p>'];
        $reply = static function (string $prompt): array {
            $input = json_decode(explode('Input: ', $prompt, 2)[1], true);
            return ['success' => true, 'content' => json_encode(array_map(static fn(string $value): string => 'English text', $input))];
        };
        $result = translateContentFields($source, 'en', $reply);
        self::assertSame('English text', $result['title']);
        self::assertSame('English text', $result['seo_keywords']);
        self::assertStringContainsString('href="/about"', $result['content']);
        self::assertStringContainsString('src="/image.png"', $result['content']);
        self::assertStringContainsString('<h2>English text</h2>', $result['content']);
    }

    public function testInvalidOrMissingOutputCannotBecomeSuccessfulTranslation(): void
    {
        $this->expectException(RuntimeException::class);
        translateContentFields(['title' => '标题'], 'en', static fn(): array => ['success' => true, 'content' => '{}']);
    }

    public function testSourceLanguageResponseIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        translateContentFields(['title' => '标题'], 'en', static fn(): array => ['success' => true, 'content' => '{"title":"标题","summary":""}']);
    }

    public function testProviderFailureIsNotHidden(): void
    {
        $this->expectExceptionMessage('Provider unavailable');
        translateContentFields(['title' => '标题'], 'en', static fn(): array => ['success' => false, 'error' => 'Provider unavailable']);
    }

    public function testBuilderLayoutKeepsIdsUrlsAndStyles(): void
    {
        $source = ['title' => '标题', 'content' => json_encode(['sections' => [['id' => 'section-1', 'data' => ['html' => '<p>正文</p>', 'url' => '/中文地址', 'color' => '#fff']]]])];
        $result = translateContentFields($source, 'en', static function (string $prompt): array {
            $input = json_decode(explode('Input: ', $prompt, 2)[1], true);
            return ['success' => true, 'content' => json_encode(array_map(static fn(string $text): string => $text === '' ? '' : 'Translated text', $input))];
        });
        $layout = json_decode($result['content'], true);
        self::assertSame('section-1', $layout['sections'][0]['id']);
        self::assertSame('/中文地址', $layout['sections'][0]['data']['url']);
        self::assertSame('#fff', $layout['sections'][0]['data']['color']);
        self::assertSame('<p>Translated text</p>', $layout['sections'][0]['data']['html']);
    }

    public function testSpecificationsTranslateTextAndKeepMeasurementsAndGroups(): void
    {
        $source = ['title' => '产品', 'specs' => json_encode(['机械参数' => ['材质' => '铝合金', '重量' => 25, '尺寸' => '100 mm'], 'model' => 'GW-100'])];
        $dictionary = ['产品' => 'Product', '机械参数' => 'Mechanical', '材质' => 'Material', '铝合金' => 'Aluminium', '重量' => 'Weight', '尺寸' => 'Dimensions'];
        $result = translateContentFields($source, 'en', static function (string $prompt) use ($dictionary): array {
            $values = json_decode(explode('Input: ', $prompt, 2)[1], true);
            return ['success' => true, 'content' => json_encode(array_map(static fn(string $value): string => $dictionary[$value] ?? $value, $values))];
        });
        $specs = json_decode($result['specs'], true);
        self::assertSame('Aluminium', $specs['Mechanical']['Material']);
        self::assertSame(25, $specs['Mechanical']['Weight']);
        self::assertSame('100 mm', $specs['Mechanical']['Dimensions']);
        self::assertSame('GW-100', $specs['model']);
    }

    public function testUntranslatedProductNameIsRetriedOnce(): void
    {
        $calls = 0;
        $result = translateContentFields(['title' => '智能网关'], 'de', static function () use (&$calls): array {
            $calls++;
            return ['success' => true, 'content' => $calls === 1 ? '{"title":"智能网关","summary":""}' : '{"title":"Intelligentes Gateway"}'];
        });
        self::assertSame(2, $calls);
        self::assertSame('Intelligentes Gateway', $result['title']);
    }

    public function testJobSummaryAndRequirementsAreIndependentFields(): void
    {
        $result = translateContentFields(['title' => '岗位', 'summary' => '任职要求', 'requirements' => '任职要求', 'secondary_summary' => '负责开发'], 'en', static function (string $prompt): array {
            $input = json_decode(explode('Input: ', $prompt, 2)[1], true);
            $dictionary = ['岗位' => 'Engineer', '任职要求' => 'Requirements', '负责开发' => 'Responsible for development'];
            return ['success' => true, 'content' => json_encode(array_map(static fn(string $value): string => $dictionary[$value] ?? $value, $input))];
        });
        self::assertSame('Requirements', $result['requirements']);
        self::assertSame('Responsible for development', $result['secondary_summary']);
    }
}
