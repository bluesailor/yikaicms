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

        self::assertSame(['/assets/js/yikay-pricing.js'], $element->scriptsFor($data));
        self::assertStringContainsString('data-yk-pricing-cycle-button="yearly" aria-pressed="false"', $html);
        self::assertStringContainsString('省 17%', $html);
        self::assertMatchesRegularExpression('/data-yk-pricing-price="monthly"(?![^>]*hidden)[^>]*>.*199/s', $html);
        self::assertMatchesRegularExpression('/data-yk-pricing-price="yearly" hidden[^>]*>.*1990/s', $html);
    }

    public function testPricingSectionIsOnlyOfferedAsAProSection(): void
    {
        // 价格方案区块只在「区块PRO版」提供；系统区块不再随包附带
        $keys = array_column((new BloxBuiltinTemplateProvider())->items('page'), 'key');
        self::assertNotContains('builtin:pricing-plans', $keys);
        self::assertFileDoesNotExist(ROOT_PATH . '/templates/blox/sections/pricing-plans.json');
    }

    // ── 2.0.3：价格内容、每档主色、角标位置、推荐档突出、按断点网格 ─────────────────────

    public function testNewPlanFieldsAreClippedAndColorsValidated(): void
    {
        $plans = PricingTableElement::normalizePlans([
            ['name' => 'A', 'price' => '99', 'original_price' => str_repeat('9', 40), 'price_prefix' => '限时', 'price_note' => '首年', 'accent' => '#FF6600'],
            ['name' => 'B', 'price' => '9', 'accent' => 'red;background:url(x)'],
        ]);
        self::assertSame(30, strlen($plans[0]['original_price']));
        self::assertSame('#ff6600', $plans[0]['accent']);
        self::assertSame('', $plans[1]['accent'], 'anything but a real colour is dropped');

        $html = (new PricingTableElement())->render(['plans' => $plans]);
        self::assertStringContainsString('style="--color-primary:#ff6600"', $html);
        self::assertStringNotContainsString('url(x)', $html);
    }

    public function testPriceContentShowsPrefixStrikethroughAndNote(): void
    {
        $element = new PricingTableElement();
        $plan = ['name' => '专业版', 'price' => '199', 'original_price' => '299', 'price_prefix' => '限时特价', 'price_note' => '按年付再省 2 个月'];
        $html = $element->render(['plans' => [$plan]]);
        self::assertMatchesRegularExpression('#<s class="[^"]*line-through|<s class="mr-1[^"]*"[^>]*>¥299</s>#', $html);
        self::assertStringContainsString('限时特价', $html);
        self::assertStringContainsString('按年付再省 2 个月', $html);
        self::assertStringNotContainsString('bg-primary/10', $html, 'plain note by default');

        $chip = $element->render(['plans' => [$plan], 'price_note_style' => 'chip']);
        self::assertStringContainsString('bg-primary/10 text-primary', $chip);

        // 按年切换：年价自带原价时用年原价；年价沿用月价时连原价一起沿用
        $toggle = $element->render(['billing_toggle' => true, 'plans' => [
            ['name' => 'X', 'price' => '99', 'original_price' => '129', 'price_yearly' => '990', 'original_price_yearly' => '1290'],
            ['name' => 'Y', 'price' => '59', 'original_price' => '79'],
        ]]);
        self::assertMatchesRegularExpression('/data-yk-pricing-price="yearly" hidden[^>]*><s[^>]*>¥1290<\/s>/', $toggle);
        self::assertSame(2, substr_count($toggle, '>¥79</s>'), 'monthly and yearly both show the original price when yearly reuses monthly');
    }

    public function testBadgePositionsSizesAndColors(): void
    {
        $element = new PricingTableElement();
        $plans = [['name' => 'P', 'price' => '1', 'badge' => '推荐']];
        foreach (['top-left', 'top-center', 'top-right', 'inline', 'corner', 'ribbon-left', 'ribbon-right'] as $position) {
            $html = $element->render(['plans' => $plans, 'badge_position' => $position]);
            self::assertStringContainsString('data-yk-pricing-badge="' . $position . '"', $html, $position);
        }
        // auto 沿用旧版：左对齐顶边靠左，居中对齐顶边居中
        self::assertStringContainsString('data-yk-pricing-badge="top-left"', $element->render(['plans' => $plans]));
        self::assertStringContainsString('data-yk-pricing-badge="top-center"', $element->render(['plans' => $plans, 'align' => 'center']));
        self::assertStringContainsString('data-yk-pricing-badge="top-left"', $element->render(['plans' => $plans, 'badge_position' => 'bogus']));

        $ribbon = $element->render(['plans' => $plans, 'badge_position' => 'ribbon-right']);
        self::assertStringContainsString('overflow-hidden', $ribbon);
        self::assertStringContainsString('rotate-45', $ribbon);

        $custom = $element->render(['plans' => $plans, 'badge_bg' => '#111111', 'badge_color' => '#fafafa', 'badge_size' => 'md']);
        self::assertStringContainsString('style="background-color:#111111;color:#fafafa;"', $custom);
        self::assertStringContainsString('px-4 py-1.5 text-sm', $custom);
        $bad = $element->render(['plans' => $plans, 'badge_bg' => 'expression(alert(1))']);
        self::assertStringNotContainsString('expression', $bad);
    }

    public function testFeaturedPlanCanStandOutAndButtonCanFollowThePrice(): void
    {
        $element = new PricingTableElement();
        $plans = PricingTableElement::seedPlans();
        $plain = $element->render(['plans' => $plans]);
        self::assertStringNotContainsString('md:scale-105', $plain);
        self::assertStringNotContainsString('shadow-xl', $plain);

        $standout = $element->render(['plans' => $plans, 'featured_scale' => 'md', 'featured_shadow' => true]);
        self::assertSame(1, substr_count($standout, 'md:scale-105'), 'only the featured plan is enlarged');
        self::assertSame(1, substr_count($standout, 'shadow-xl'));
        self::assertStringContainsString('md:py-4', $standout, 'room for the enlarged card');

        $top = $element->render(['plans' => [['name' => 'A', 'price' => '1', 'features' => "x\ny", 'button_text' => 'Go']], 'button_position' => 'price']);
        self::assertLessThan(strpos($top, '<ul'), strpos($top, '>Go</a>'), 'button comes before the feature list');
        $bottom = $element->render(['plans' => [['name' => 'A', 'price' => '1', 'features' => "x\ny", 'button_text' => 'Go']]]);
        self::assertGreaterThan(strpos($bottom, '<ul'), strpos($bottom, '>Go</a>'));
        self::assertStringContainsString('mt-auto pt-8', $bottom, 'default keeps buttons aligned at the bottom');
    }

    public function testGridColumnsPerBreakpointGapMaxWidthAndStagger(): void
    {
        $element = new PricingTableElement();
        $plans = PricingTableElement::seedPlans();
        // 旧文档的单值写法照旧
        self::assertStringContainsString('grid-cols-1 md:grid-cols-2', $element->render(['plans' => $plans, 'columns' => '2']));
        // 按断点：手机 1、平板 2、桌面 3
        $responsive = $element->render(['plans' => $plans, 'columns' => ['d' => '3', 't' => '2', 'm' => '1']]);
        self::assertStringContainsString('grid-cols-1', $responsive);
        self::assertStringContainsString('md:grid-cols-2', $responsive);
        self::assertStringContainsString('lg:grid-cols-3', $responsive);
        // auto 取套餐数
        self::assertStringContainsString('md:grid-cols-3', $element->render(['plans' => $plans, 'columns' => ['d' => 'auto']]));

        $wide = $element->render(['plans' => $plans, 'gap' => 'xl', 'max_width' => 'lg', 'grid_align' => 'left']);
        self::assertStringContainsString('gap-10', $wide);
        self::assertStringContainsString('max-w-5xl mr-auto', $wide);
        self::assertStringContainsString('gap-6', $element->render(['plans' => $plans]), 'default gap unchanged');
        self::assertStringNotContainsString('max-w-', $element->render(['plans' => $plans]));

        self::assertStringContainsString(' data-stagger', $element->render(['plans' => $plans, 'animation_stagger' => true]));
        $keys = array_column($element->controls(), 'key');
        foreach (['gap', 'max_width', 'grid_align', 'badge_position', 'badge_bg', 'featured_scale', 'button_position', 'price_note_style', 'animation_stagger'] as $key) {
            self::assertContains($key, $keys);
        }
    }

    /** 2.0.4 功能行：行首 [图标名] 单独换图标、默认图标可改、「不含」行三种样式、按钮可放在名称下方。 */
    public function testFeatureRowIconsExcludedStylesAndTopButton(): void
    {
        $plans = PricingTableElement::normalizePlans([[
            'name' => 'Pro', 'price' => '9',
            'features' => "[star] Priority support\n-[lock] Data export\n[bi:gift] Welcome gift\n[none] Plain row\n[not an icon] Literal\nDefault row",
        ]]);
        self::assertSame([
            ['Priority support', true, 'star'],
            ['Data export', false, 'lock'],
            ['Welcome gift', true, 'bi:gift'],
            ['Plain row', true, 'none'],
            ['[not an icon] Literal', true, ''],
            ['Default row', true, ''],
        ], array_map(static fn (array $f): array => [$f['text'], $f['included'], $f['icon']], $plans[0]['features']));

        $element = new PricingTableElement();
        $plan = ['name' => 'A', 'price' => '1', 'features' => "[star] Starred\nIncluded\n-Excluded", 'button_text' => 'Go'];
        $html = $element->render(['plans' => [$plan]]);
        self::assertStringContainsString('<i class="ti ti-star mt-0.5', $html);
        self::assertStringContainsString('<i class="ti ti-check mt-0.5', $html, '默认图标与改版前相同');
        self::assertStringContainsString('<i class="ti ti-x mt-0.5', $html);
        self::assertStringContainsString('line-through opacity-70" data-yk-pricing-excluded', $html, '默认划线，与改版前相同');
        self::assertStringContainsString('<span class="sr-only">' . __('blox_pricing_feature_excluded_sr') . '</span>Excluded', $html);

        $custom = $element->render(['plans' => [$plan], 'feature_icon' => 'circle-check', 'feature_excluded_icon' => 'none', 'feature_excluded_style' => 'muted']);
        self::assertStringContainsString('ti ti-circle-check', $custom);
        self::assertStringNotContainsString('ti ti-x', $custom, '不含项图标设为无');
        self::assertStringContainsString('opacity-60" data-yk-pricing-excluded', $custom);
        self::assertStringNotContainsString('line-through', $custom);

        $hidden = $element->render(['plans' => [$plan], 'feature_excluded_style' => 'hide']);
        self::assertStringNotContainsString('Excluded', $hidden);
        self::assertStringContainsString('Included', $hidden);

        $top = $element->render(['plans' => [$plan + ['description' => 'Desc']], 'button_position' => 'top']);
        self::assertLessThan(strpos($top, 'data-yk-pricing-price'), strpos($top, '>Go</a>'), '按钮在价格之前');
        self::assertGreaterThan(strpos($top, '>Desc</p>'), strpos($top, '>Go</a>'), '按钮在名称与简介之后');
        self::assertStringNotContainsString('mt-auto pt-8', $top);
    }

    public function testProEditorOffersEveryNewPlanField(): void
    {
        $form = (string) file_get_contents(ROOT_PATH . '/plugins/yikai-builder/editor/pricing-plans.php');
        $methods = (string) file_get_contents(ROOT_PATH . '/plugins/yikai-builder/assets/yikay-pro-pricing.js');
        foreach (['price_prefix', 'original_price', 'original_price_yearly', 'price_note'] as $field) {
            self::assertStringContainsString("['" . $field . "',", $form, $field);
            self::assertStringContainsString('"' . $field . '"', $methods, $field);
        }
        self::assertStringContainsString("setPricingPlan(index, 'accent'", $form);
        self::assertStringContainsString('"accent"', $methods);
        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            foreach (['blox_pricing_plan_price_prefix', 'blox_pricing_plan_original_price', 'blox_pricing_plan_price_note', 'blox_pricing_plan_accent', 'blox_pricing_badge_ribbon_right'] as $key) {
                self::assertNotSame('', trim((string) ($strings[$key] ?? '')), "$lang $key");
            }
        }
    }
}
