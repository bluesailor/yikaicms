<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** 价格方案元素：套餐数据清洗、渲染结构与按月/按年切换。 */
final class PricingTableElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testElementIsRegisteredWithAPlanRepeaterAndSeedPlans(): void
    {
        $meta = BuilderRegistry::meta()['pricing-table'];
        $controls = array_column($meta['controls'], null, 'key');
        self::assertSame('pricing_plans', $controls['plans']['type']);
        self::assertSame(PricingTableElement::MAX_PLANS, $controls['plans']['max']);
        self::assertCount(3, $meta['defaults']['plans']);
        self::assertCount(1, array_filter($meta['defaults']['plans'], static fn (array $plan): bool => $plan['featured']));
    }

    public function testFeatureLinesKeepEveryChineseLineAndMarkExcludedItems(): void
    {
        // 「包」「持」的 UTF-8 编码含 0x85 字节：按行切分不能把它们切断
        $plans = PricingTableElement::normalizePlans([[
            'name' => '专业版', 'price' => '299',
            'features' => "包含基础版全部功能\r\n不限页面模板\n-专属客户经理\n\n优先技术支持",
        ]]);
        self::assertSame(
            [['包含基础版全部功能', true], ['不限页面模板', true], ['专属客户经理', false], ['优先技术支持', true]],
            array_map(static fn (array $f): array => [$f['text'], $f['included']], $plans[0]['features'])
        );
    }

    public function testUntrustedPlanDataIsClippedEscapedAndLinkChecked(): void
    {
        $plans = PricingTableElement::normalizePlans([
            ['name' => str_repeat('名', 80), 'price' => '99', 'button_url' => 'javascript:alert(1)', 'featured' => '1'],
            ['name' => '', 'price' => ''],
            'not a plan',
        ]);
        self::assertCount(1, $plans);
        self::assertSame(60, mb_strlen($plans[0]['name']));
        self::assertSame('', $plans[0]['button_url']);
        self::assertTrue($plans[0]['featured']);

        $html = (new PricingTableElement())->render(['plans' => [
            ['name' => '<script>x</script>', 'price' => '9', 'button_text' => 'Go', 'button_url' => '/contact.html'],
        ]]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('href="/contact.html"', $html);
    }

    public function testRenderHighlightsTheFeaturedPlanAndKeepsCardColorsInline(): void
    {
        $element = new PricingTableElement();
        $data = ['plans' => PricingTableElement::seedPlans(), 'variant' => 'accent'];
        $html = $element->render($data);

        self::assertSame(3, substr_count($html, '<h3 '));
        self::assertSame(1, substr_count($html, 'data-yk-pricing-featured'));
        self::assertStringContainsString('grid-cols-1 md:grid-cols-3', $html);
        // 区块文字色调会覆盖工具类颜色：卡片内文字必须用行内颜色
        self::assertStringContainsString('style="color:#ffffff"', $html);
        self::assertStringContainsString('style="color:#111827"', $html);
        self::assertStringNotContainsString('data-yk-pricing-cycle-button', $html);
        self::assertSame([], $element->scriptsFor($data));

        $minimal = $element->render(['plans' => PricingTableElement::seedPlans(), 'variant' => 'minimal']);
        self::assertStringNotContainsString('style="color:', $minimal, '极简风格跟随区块文字色调');
        self::assertSame('', $element->render(['plans' => []]));
    }

    public function testBillingToggleRendersBothCyclesWithYearlyHiddenUntilSwitched(): void
    {
        $element = new PricingTableElement();
        $data = [
            'billing_toggle' => true, 'monthly_label' => '按月', 'yearly_label' => '按年', 'yearly_note' => '省 17%',
            'plans' => [['name' => '成长', 'price' => '199', 'period' => '/ 月', 'price_yearly' => '1990', 'period_yearly' => '/ 年']],
        ];
        $html = $element->render($data);

        self::assertSame(['/assets/js/blox-pricing.js'], $element->scriptsFor($data));
        self::assertStringContainsString('data-yk-pricing-cycle-button="yearly" aria-pressed="false"', $html);
        self::assertStringContainsString('省 17%', $html);
        self::assertMatchesRegularExpression('/data-yk-pricing-price="monthly"(?![^>]*hidden)[^>]*>.*199/s', $html);
        self::assertMatchesRegularExpression('/data-yk-pricing-price="yearly" hidden[^>]*>.*1990/s', $html);
    }

    public function testBundledBasicSectionUsesThePricingElement(): void
    {
        $section = (new BloxBuiltinTemplateProvider())->resolve('pricing-plans', 'page')['sections'][0];
        $types = array_column($section['columns'][0]['elements'], 'type');
        self::assertContains('pricing-table', $types);
        $html = BlockRenderer::render(json_encode([$section], JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('data-yk-pricing', $html);
        self::assertStringContainsString('优先技术支持', $html);
    }
}
