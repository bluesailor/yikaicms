<?php
/** 价格方案：多档套餐卡片，可选按月/按年切换；数据全部在文档里，任何页面都能渲染。 */

declare(strict_types=1);

final class PricingTableElement extends AbstractElement
{
    public const MAX_PLANS = 6;
    private const MAX_FEATURES = 20;
    private const TEXT_LIMITS = [
        'name' => 60, 'badge' => 30, 'price' => 30, 'price_yearly' => 30, 'period' => 20, 'period_yearly' => 20,
        'description' => 200, 'button_text' => 40,
    ];

    public function type(): string { return 'pricing-table'; }
    public function label(): string { return __('blox_el_pricing_table'); }
    public function icon(): string { return 'currency-yen'; }
    public function category(): string { return 'advanced'; }

    public function controls(): array
    {
        return [
            ['key' => 'plans', 'type' => 'pricing_plans', 'label' => __('blox_pricing_plans'), 'max' => self::MAX_PLANS,
                'default' => self::seedPlans()],
            ['key' => 'currency', 'type' => 'text', 'label' => __('blox_pricing_currency'), 'default' => '¥'],
            ['key' => 'billing_toggle', 'type' => 'checkbox', 'label' => __('blox_pricing_billing_toggle'), 'default' => false],
            ['key' => 'monthly_label', 'type' => 'text', 'label' => __('blox_pricing_monthly_label'),
                'default' => __('blox_pricing_monthly'), 'required' => ['billing_toggle', '=', true]],
            ['key' => 'yearly_label', 'type' => 'text', 'label' => __('blox_pricing_yearly_label'),
                'default' => __('blox_pricing_yearly'), 'required' => ['billing_toggle', '=', true]],
            ['key' => 'yearly_note', 'type' => 'text', 'label' => __('blox_pricing_yearly_note'),
                'default' => __('blox_pricing_yearly_note_default'), 'required' => ['billing_toggle', '=', true]],
            ['key' => 'variant', 'type' => 'select', 'label' => __('blox_pricing_variant'), 'tab' => 'style', 'default' => 'cards',
                'options' => [
                    'cards' => __('blox_pricing_variant_cards'),
                    'accent' => __('blox_pricing_variant_accent'),
                    'minimal' => __('blox_pricing_variant_minimal'),
                ]],
            ['key' => 'columns', 'type' => 'select', 'label' => __('blox_dynamic_columns'), 'tab' => 'style', 'default' => 'auto',
                'options' => ['auto' => __('blox_pricing_columns_auto'), '2' => '2', '3' => '3', '4' => '4']],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'tab' => 'style', 'default' => 'left',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center')]],
        ];
    }

    public function scriptsFor(array $data): array
    {
        return self::enabled($data, 'billing_toggle', false) ? ['/assets/js/blox-pricing.js'] : [];
    }

    public function render(array $data, string $children = ''): string
    {
        $plans = self::normalizePlans($data['plans'] ?? null);
        if ($plans === []) {
            return '';
        }
        $currency = self::clip((string) ($data['currency'] ?? '¥'), 8);
        $toggle = self::enabled($data, 'billing_toggle', false);
        $variant = in_array($data['variant'] ?? '', ['cards', 'accent', 'minimal'], true) ? (string) $data['variant'] : 'cards';
        $center = ($data['align'] ?? 'left') === 'center';
        $count = count($plans);
        $columns = in_array((string) ($data['columns'] ?? 'auto'), ['2', '3', '4'], true) ? (int) $data['columns'] : min($count, 4);

        $html = '<div class="yk-pricing" data-yk-pricing data-yk-pricing-cycle="monthly">';
        if ($toggle) {
            $html .= self::toggleHtml(
                self::clip((string) ($data['monthly_label'] ?? __('blox_pricing_monthly')), 20),
                self::clip((string) ($data['yearly_label'] ?? __('blox_pricing_yearly')), 20),
                self::clip((string) ($data['yearly_note'] ?? ''), 30)
            );
        }
        $html .= '<div class="grid gap-6 items-stretch ' . self::gridClasses($columns, 3, true) . '">';
        foreach ($plans as $plan) {
            $html .= self::planHtml($plan, $currency, $variant, $center, $toggle);
        }
        return $html . '</div></div>';
    }

    /**
     * 文档里的套餐数据不可信：逐字段限长、功能行拆分、按钮链接走统一的安全链接规则。
     *
     * @return list<array{name:string,badge:string,price:string,price_yearly:string,period:string,period_yearly:string,description:string,features:list<array{text:string,included:bool}>,button_text:string,button_url:string,featured:bool}>
     */
    public static function normalizePlans(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $plans = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_PLANS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $plan = [];
            foreach (self::TEXT_LIMITS as $key => $limit) {
                $plan[$key] = self::clip(is_scalar($item[$key] ?? null) ? (string) $item[$key] : '', $limit);
            }
            if ($plan['name'] === '' && $plan['price'] === '') {
                continue;
            }
            $plan['features'] = self::features($item['features'] ?? '');
            $plan['button_url'] = self::safeHref(is_scalar($item['button_url'] ?? null) ? (string) $item['button_url'] : '');
            $plan['featured'] = !in_array($item['featured'] ?? false, [false, 0, '0', '', null], true);
            $plans[] = $plan;
        }
        return $plans;
    }

    /** 每行一项；以「-」开头表示该档不包含。 @return list<array{text:string,included:bool}> */
    private static function features(mixed $raw): array
    {
        // 不能用 \R：非 /u 模式下它也匹配 0x85，会把「包」「持」这类 UTF-8 汉字从中间切断
        $lines = is_array($raw) ? $raw : preg_split('/\r\n|\r|\n/', is_scalar($raw) ? (string) $raw : '');
        $out = [];
        foreach ((array) $lines as $line) {
            $line = trim(is_scalar($line) ? (string) $line : '');
            if ($line === '') {
                continue;
            }
            $included = !str_starts_with($line, '-');
            $text = self::clip($included ? $line : ltrim(substr($line, 1)), 120);
            if ($text !== '') {
                $out[] = ['text' => $text, 'included' => $included];
            }
            if (count($out) >= self::MAX_FEATURES) {
                break;
            }
        }
        return $out;
    }

    private static function toggleHtml(string $monthly, string $yearly, string $note): string
    {
        $button = 'rounded-full px-5 py-2 text-sm font-medium transition';
        $noteHtml = $note !== ''
            ? ' <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">' . self::h($note) . '</span>'
            : '';
        return '<div class="mb-10 flex justify-center"><div class="inline-flex items-center rounded-full bg-gray-100 p-1" role="group">'
            . '<button type="button" class="' . $button . ' bg-white text-gray-900 shadow-sm" data-yk-pricing-cycle-button="monthly" aria-pressed="true">' . self::h($monthly) . '</button>'
            . '<button type="button" class="' . $button . ' text-gray-500" data-yk-pricing-cycle-button="yearly" aria-pressed="false">' . self::h($yearly) . $noteHtml . '</button>'
            . '</div></div>';
    }

    /** @param array<string,mixed> $plan */
    private static function planHtml(array $plan, string $currency, string $variant, bool $center, bool $toggle): string
    {
        $featured = $plan['featured'];
        $inverse = $featured && $variant === 'accent';
        $card = match ($variant) {
            'minimal' => 'relative flex h-full flex-col px-2 py-6' . ($featured ? ' border-t-4 border-primary' : ' border-t border-gray-200'),
            default => 'relative flex h-full flex-col rounded-2xl border p-8 shadow-sm'
                . ($inverse ? ' border-primary bg-primary text-white' : ' bg-white')
                . ($featured && !$inverse ? ' border-primary ring-2 ring-primary' : '')
                . (!$featured ? ' border-gray-200' : ''),
        };
        // 卡片是自带配色的独立表面：区块「文字色调」会覆盖标题/段落/列表的工具类颜色，
        // 所以卡片内文字用行内颜色（行内优先）。极简风格没有卡片底色，继续跟随区块色调。
        $palette = $variant === 'minimal' ? null : ($inverse
            ? ['title' => '#ffffff', 'body' => 'rgba(255,255,255,.85)', 'muted' => 'rgba(255,255,255,.6)']
            : ['title' => '#111827', 'body' => '#374151', 'muted' => '#6b7280']);
        $color = static fn (string $role): string => $palette === null ? '' : ' style="color:' . $palette[$role] . '"';
        $align = $center ? ' text-center items-center' : '';

        $html = '<div class="' . $card . $align . '"' . ($featured ? ' data-yk-pricing-featured' : '') . '>';
        if ($plan['badge'] !== '') {
            $badge = $inverse ? 'bg-white text-primary' : 'bg-primary text-white';
            $html .= '<span class="absolute -top-3 ' . ($center ? 'left-1/2 -translate-x-1/2' : 'left-8')
                . ' rounded-full px-3 py-1 text-xs font-semibold ' . $badge . '">' . self::h($plan['badge']) . '</span>';
        }
        $html .= '<h3 class="text-lg font-semibold"' . $color('title') . '>' . self::h($plan['name']) . '</h3>';
        if ($plan['description'] !== '') {
            $html .= '<p class="mt-2 text-sm opacity-90"' . $color('muted') . '>' . self::h($plan['description']) . '</p>';
        }

        $html .= '<div class="mt-6 flex items-baseline gap-1' . ($center ? ' justify-center' : '') . '">';
        $html .= self::priceHtml($currency, $plan['price'], $plan['period'], 'monthly', $palette, false);
        if ($toggle) {
            $yearlyPrice = $plan['price_yearly'] !== '' ? $plan['price_yearly'] : $plan['price'];
            $yearlyPeriod = $plan['period_yearly'] !== '' ? $plan['period_yearly'] : $plan['period'];
            $html .= self::priceHtml($currency, $yearlyPrice, $yearlyPeriod, 'yearly', $palette, true);
        }
        $html .= '</div>';

        if ($plan['features'] !== []) {
            $html .= '<ul class="mt-6 space-y-3 text-sm' . ($center ? ' text-left' : '') . '">';
            foreach ($plan['features'] as $feature) {
                $icon = $feature['included']
                    ? '<i class="ti ti-check mt-0.5 shrink-0 ' . ($inverse ? 'text-white' : 'text-primary') . '" aria-hidden="true"></i>'
                    : '<i class="ti ti-x mt-0.5 shrink-0 ' . ($inverse ? 'text-white/50' : 'text-gray-300') . '" aria-hidden="true"></i>';
                $html .= '<li class="flex items-start gap-2' . ($feature['included'] ? '' : ' line-through opacity-70') . '"'
                    . $color($feature['included'] ? 'body' : 'muted') . '>' . $icon . '<span>' . self::h($feature['text']) . '</span></li>';
            }
            $html .= '</ul>';
        }

        if ($plan['button_text'] !== '') {
            $button = $inverse
                ? 'bg-white text-primary hover:bg-white/90'
                : ($featured ? 'bg-primary text-white hover:opacity-90' : 'border border-gray-300 text-gray-900 hover:border-primary hover:text-primary');
            // 按钮贴底：各档功能行数不同，按钮仍在同一水平线
            $html .= '<div class="mt-auto w-full pt-8"><a href="' . self::h($plan['button_url'] !== '' ? $plan['button_url'] : '#')
                . '" class="inline-flex w-full items-center justify-center rounded-lg px-5 py-3 text-sm font-semibold transition '
                . $button . '">' . self::h($plan['button_text']) . '</a></div>';
        }
        return $html . '</div>';
    }

    /** @param array{title:string,body:string,muted:string}|null $palette */
    private static function priceHtml(string $currency, string $price, string $period, string $cycle, ?array $palette, bool $hidden): string
    {
        $symbol = $currency !== '' && $price !== '' && !preg_match('/^\D/u', $price)
            ? '<span class="text-xl font-semibold">' . self::h($currency) . '</span>'
            : '';
        $periodHtml = $period !== ''
            ? '<span class="text-sm opacity-80">' . self::h($period) . '</span>'
            : '';
        return '<span class="inline-flex items-baseline gap-1" data-yk-pricing-price="' . $cycle . '"' . ($hidden ? ' hidden' : '')
            . ($palette === null ? '' : ' style="color:' . $palette['title'] . '"') . '>'
            . $symbol . '<span class="text-4xl font-bold tracking-tight">' . self::h($price) . '</span>' . $periodHtml . '</span>';
    }

    /** @return list<array<string,mixed>> */
    public static function seedPlans(): array
    {
        return [
            ['name' => __('blox_pricing_seed_basic'), 'badge' => '', 'price' => '99', 'price_yearly' => '990',
                'period' => __('blox_pricing_per_month'), 'period_yearly' => __('blox_pricing_per_year'),
                'description' => __('blox_pricing_seed_basic_desc'),
                'features' => __('blox_pricing_seed_basic_features'),
                'button_text' => __('blox_pricing_seed_button'), 'button_url' => '/contact.html', 'featured' => false],
            ['name' => __('blox_pricing_seed_pro'), 'badge' => __('blox_pricing_seed_badge'), 'price' => '299', 'price_yearly' => '2990',
                'period' => __('blox_pricing_per_month'), 'period_yearly' => __('blox_pricing_per_year'),
                'description' => __('blox_pricing_seed_pro_desc'),
                'features' => __('blox_pricing_seed_pro_features'),
                'button_text' => __('blox_pricing_seed_button'), 'button_url' => '/contact.html', 'featured' => true],
            ['name' => __('blox_pricing_seed_enterprise'), 'badge' => '', 'price' => __('blox_pricing_seed_custom_price'), 'price_yearly' => '',
                'period' => '', 'period_yearly' => '',
                'description' => __('blox_pricing_seed_enterprise_desc'),
                'features' => __('blox_pricing_seed_enterprise_features'),
                'button_text' => __('blox_pricing_seed_contact'), 'button_url' => '/contact.html', 'featured' => false],
        ];
    }

    private static function clip(string $value, int $limit): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        return mb_substr($value, 0, $limit);
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function enabled(array $data, string $key, bool $default): bool
    {
        return array_key_exists($key, $data) ? !in_array($data[$key], [false, 0, '0', '', null], true) : $default;
    }
}
