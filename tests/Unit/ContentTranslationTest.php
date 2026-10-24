<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/i18n/LanguageRegistry.php';
require_once dirname(__DIR__, 2) . '/includes/content_translation.php';

final class ContentTranslationTest extends TestCase
{
    public function testBuilderPlainTextKeepsLiteralSymbols(): void
    {
        $result = translateContentFields(['title' => '标题', 'content' => json_encode(['sections' => [['data' => ['text' => '研发与创新']]]])], 'en', static function (string $prompt): array {
            $input = json_decode(explode('Input: ', $prompt, 2)[1], true);
            return ['success' => true, 'content' => json_encode(array_map(static fn(string $text): string => $text === '' ? '' : 'Research & Development <2026>', $input))];
        });
        $layout = json_decode($result['content'], true);
        self::assertSame('Research & Development <2026>', $layout['sections'][0]['data']['text']);
    }
    public function testBlocksBodyIsSelectedInsteadOfStaleHtmlFallback(): void
    {
        self::assertSame('blocks_data', translationBodyField(['content_type' => 'blocks', 'blocks_data' => '{"sections":[]}', 'content' => '<p>Old fallback</p>']));
        self::assertSame('blocks_data', translationBodyField(['content_type' => 'blox', 'blocks_data' => '{}']));
        self::assertSame('content', translationBodyField(['content_type' => 'html', 'blocks_data' => '{}']));
    }

    public function testBuilderButtonsAndImageDescriptionsAreTranslatedWithoutChangingResources(): void
    {
        $source = ['title' => '标题', 'content' => json_encode(['sections' => [['id' => 's1', 'elements' => [
            ['type' => 'cta', 'data' => ['btn_text' => '咨询', 'btn_url' => '/contact.html']],
            ['type' => 'image', 'data' => ['alt' => '团队', 'src' => '/team.webp']],
        ]]]])];
        $result = translateContentFields($source, 'fr', static function (string $prompt): array {
            $input = json_decode(explode('Input: ', $prompt, 2)[1], true);
            return ['success' => true, 'content' => json_encode(array_map(static fn(string $text): string => $text === '' ? '' : 'Texte traduit', $input))];
        });
        $layout = json_decode($result['content'], true);
        self::assertSame('s1', $layout['sections'][0]['id']);
        self::assertSame('Texte traduit', $layout['sections'][0]['elements'][0]['data']['btn_text']);
        self::assertSame('/contact.html', $layout['sections'][0]['elements'][0]['data']['btn_url']);
        self::assertSame('Texte traduit', $layout['sections'][0]['elements'][1]['data']['alt']);
        self::assertSame('/team.webp', $layout['sections'][0]['elements'][1]['data']['src']);
    }

    public function testHtmlAlternativeTextAndLinkTitlesAreTranslated(): void
    {
        $result = translateContentFields(['title' => '标题', 'content' => '<a href="/about.html" title="介绍"><img src="/team.webp" alt="团队"></a>'], 'en', static function (string $prompt): array {
            $input = json_decode(explode('Input: ', $prompt, 2)[1], true);
            return ['success' => true, 'content' => json_encode(array_map(static fn(string $text): string => $text === '' ? '' : 'Translated', $input))];
        });
        self::assertStringContainsString('alt="Translated"', $result['content']);
        self::assertStringContainsString('title="Translated"', $result['content']);
        self::assertStringContainsString('href="/about.html"', $result['content']);
    }

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

    public function testIndividualRetryRepairsSourceLanguageLeftInStructuredReplies(): void
    {
        $calls = 0;
        $result = translateContentFields(['title' => 'PHP高级工程师'], 'es', static function () use (&$calls): array {
            $calls++;
            return ['success' => true, 'content' => $calls < 3 ? '{"title":"PHP高级工程师","summary":""}' : 'Ingeniero sénior de PHP'];
        });
        self::assertSame('Ingeniero sénior de PHP', $result['title']);
        self::assertSame('', $result['summary']);
    }

    public function testEnglishBridgeRepairsRepeatedSourceLanguageReplies(): void
    {
        $replies = ['{"title":"PHP高级工程师","summary":""}', '{"title":"PHP高级工程师"}', 'PHP高级工程师', 'Senior PHP engineer', 'Ingeniero sénior de PHP'];
        $result = translateContentFields(['title' => 'PHP高级工程师'], 'es', static function () use (&$replies): array {
            return ['success' => true, 'content' => array_shift($replies)];
        });
        self::assertSame('Ingeniero sénior de PHP', $result['title']);
        self::assertSame('', $result['summary']);
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

    public function testMissingSeoAndEmptyTitleAreRepairedWithoutReplacingValidFields(): void
    {
        $calls = 0;
        $result = translateContentFields(['title' => '标题', 'summary' => '摘要', 'seo_description' => '完整描述'], 'es', static function (string $prompt) use (&$calls): array {
            $calls++;
            if ($calls === 1) return ['success' => true, 'content' => '{"title":"","summary":"Resumen completo"}'];
            $input = json_decode(explode('Input: ', $prompt, 2)[1], true);
            self::assertSame(['title', 'seo_description'], array_keys($input));
            return ['success' => true, 'content' => '{"title":"Título completo","seo_description":"Descripción completa"}'];
        });
        self::assertSame(2, $calls);
        self::assertSame('Título completo', $result['title']);
        self::assertSame('Resumen completo', $result['summary']);
        self::assertSame('Descripción completa', $result['seo_description']);
    }

    public function testReviewedTerminologyCannotBeReplacedWithPreciousComponents(): void
    {
        $calls = 0;
        $result = translateContentFields(['title' => '精密零部件', 'content' => '<p><a href="/parts.html">精密零部件</a></p>'], 'es', static function (string $prompt) use (&$calls): array {
            $calls++;
            $input = json_decode(explode('Input: ', $prompt, 2)[1], true);
            if ($calls === 1) return ['success' => true, 'content' => json_encode(array_map(static fn(string $value): string => $value === '' ? '' : 'componentes preciosos', $input))];
            return ['success' => true, 'content' => json_encode(array_map(static fn(string $value): string => str_replace('精密零部件', 'componentes de precisión', $value), $input))];
        });
        self::assertSame(2, $calls);
        self::assertSame('componentes de precisión', $result['title']);
        self::assertStringContainsString('href="/parts.html"', $result['content']);
        self::assertStringContainsString('componentes de precisión</a>', $result['content']);
        self::assertStringNotContainsString('preciosos', $result['content']);
    }

    public function testLosingReviewedTerminologyIsRejectedEvenWithoutChineseCharacters(): void
    {
        $this->expectException(RuntimeException::class);
        translateContentFields(['title' => '精密零部件'], 'es', static fn(): array => ['success' => true, 'content' => '{"title":"componentes preciosos","summary":""}']);
    }

    public function testMalformedInitialResponseIsRepairedBeforeReturningAnyFields(): void
    {
        $calls = 0;
        $result = translateContentFields(['title' => '标题', 'seo_description' => '描述'], 'es', static function () use (&$calls): array {
            $calls++;
            return ['success' => true, 'content' => $calls === 1 ? '{broken JSON' : 'Translation: {"title":"Título completo","seo_description":"Descripción completa"}'];
        });
        self::assertSame(2, $calls);
        self::assertSame('Título completo', $result['title']);
        self::assertSame('Descripción completa', $result['seo_description']);
    }

    public function testLiteralTokenInSourceIsNotReplacedWithAnUnrelatedGlossaryTerm(): void
    {
        $result = translateContentFields(['title' => 'YKTERM0X 精密零部件'], 'es', static function (string $prompt): array {
            $input = json_decode(explode('Input: ', $prompt, 2)[1], true);
            return ['success' => true, 'content' => json_encode(array_map(static fn(string $value): string => str_replace('精密零部件', 'componentes de precisión', $value), $input))];
        });
        self::assertSame('YKTERM0X componentes de precisión', $result['title']);
    }

    public function testTerminologyAcceptsCorrectSpanishVariantsButRejectsWrongMeaning(): void
    {
        self::assertFalse(translationNeedsRepair('华东地区的精密零部件，询盘量提升', 'Piezas de precisión de China oriental; aumentó el número de solicitudes de cotización.', 'es'));
        self::assertTrue(translationNeedsRepair('精密零部件', 'componentes preciosos', 'es'));
        self::assertTrue(translationNeedsRepair('华东地区', 'noreste de China', 'es'));
    }

    public function testStructuredTranslationRequestsJsonModeFromTheProvider(): void
    {
        $result = translateContentFields(['title' => '标题'], 'es', static function (string $prompt, string $system, float $temperature, bool $jsonObject): array {
            self::assertTrue($jsonObject);
            return ['success' => true, 'content' => '{"title":"Título completo","summary":""}'];
        });
        self::assertSame('Título completo', $result['title']);
    }

    public function testSourceReviewCorrectsRolesBeforeReturningTranslation(): void
    {
        $calls = 0;
        $result = translateContentFields(['title' => '销售人员收到询价后整理报价单'], 'es', static function () use (&$calls): array {
            $calls++;
            return ['success' => true, 'content' => $calls === 1 ? '{"title":"Los vendedores solicitaban presupuestos.","summary":""}' : '{"title":"Los vendedores preparaban presupuestos tras recibir las consultas.","summary":""}'];
        }, true);
        self::assertSame(2, $calls);
        self::assertSame('Los vendedores preparaban presupuestos tras recibir las consultas.', $result['title']);
    }

    public function testIncompleteReviewCannotReplaceTheValidatedTranslation(): void
    {
        $calls = 0;
        $this->expectException(RuntimeException::class);
        translateContentFields(['title' => '标题', 'seo_description' => '描述'], 'es', static function () use (&$calls): array {
            $calls++;
            return ['success' => true, 'content' => $calls === 1 ? '{"title":"Título","summary":"","seo_description":"Descripción"}' : '{"title":"Título"}'];
        }, true);
    }

    public function testReviewWrapperIsUnwrappedWithoutMixingSourceAndTranslation(): void
    {
        $calls = 0;
        $result = translateContentFields(['title' => '标题'], 'es', static function () use (&$calls): array {
            $calls++;
            return ['success' => true, 'content' => $calls === 1 ? '{"title":"Título","summary":""}' : '{"source":{"title":"标题"},"translation":{"title":"Título revisado","summary":""}}'];
        }, true);
        self::assertSame('Título revisado', $result['title']);
        self::assertArrayNotHasKey('source', $result);
    }
}
