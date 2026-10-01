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
        // 2.0.3：划线原价（按月/按年各一）、价格上方的小字（如「起」「限时」）、价格下方说明
        'original_price' => 30, 'original_price_yearly' => 30, 'price_prefix' => 20, 'price_note' => 60,
    ];
    /** 角标位置：auto 沿用旧版（居中对齐时顶边居中，否则顶边靠左），其余 7 种 */
    private const BADGE_POSITIONS = ['auto', 'top-left', 'top-center', 'top-right', 'inline', 'corner', 'ribbon-left', 'ribbon-right'];
    private const GAPS = ['sm' => 'gap-4', 'md' => 'gap-6', 'lg' => 'gap-8', 'xl' => 'gap-10'];
    private const MAX_WIDTHS = ['md' => 'max-w-3xl', 'lg' => 'max-w-5xl', 'xl' => 'max-w-6xl', '2xl' => 'max-w-7xl'];
    private const GRID_ALIGN = ['left' => 'mr-auto', 'center' => 'mx-auto', 'right' => 'ml-auto'];
    private const FEATURED_SCALE = ['sm' => 'md:scale-[1.03]', 'md' => 'md:scale-105'];

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
            // 网格：列数按断点、间距、最大宽度与整体对齐
            ['key' => 'columns', 'type' => 'select', 'label' => __('blox_dynamic_columns'), 'tab' => 'style', 'default' => 'auto', 'responsive' => true,
                'options' => ['auto' => __('blox_pricing_columns_auto'), '1' => '1', '2' => '2', '3' => '3', '4' => '4']],
            ['key' => 'gap', 'type' => 'select', 'label' => __('blox_pricing_gap'), 'tab' => 'style', 'default' => 'md',
                'options' => ['sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg'), 'xl' => __('blox_spacing_xl')]],
            ['key' => 'max_width', 'type' => 'select', 'label' => __('blox_pricing_max_width'), 'tab' => 'style', 'default' => '',
                'options' => ['' => __('blox_pricing_max_width_none'), 'md' => '768px', 'lg' => '1024px', 'xl' => '1152px', '2xl' => '1280px']],
            ['key' => 'grid_align', 'type' => 'select', 'label' => __('blox_pricing_grid_align'), 'tab' => 'style', 'default' => 'center',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')]],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'tab' => 'style', 'default' => 'left',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center')]],
            // 价格下方说明的样式
            ['key' => 'price_note_style', 'type' => 'select', 'label' => __('blox_pricing_note_style'), 'tab' => 'style', 'default' => 'text',
                'options' => ['text' => __('blox_pricing_note_style_text'), 'chip' => __('blox_pricing_note_style_chip')]],
            // 角标
            ['key' => 'badge_position', 'type' => 'select', 'label' => __('blox_pricing_badge_position'), 'tab' => 'style', 'default' => 'auto',
                'options' => [
                    'auto' => __('blox_pricing_badge_auto'),
                    'top-left' => __('blox_pricing_badge_top_left'),
                    'top-center' => __('blox_pricing_badge_top_center'),
                    'top-right' => __('blox_pricing_badge_top_right'),
                    'inline' => __('blox_pricing_badge_inline'),
                    'corner' => __('blox_pricing_badge_corner'),
                    'ribbon-left' => __('blox_pricing_badge_ribbon_left'),
                    'ribbon-right' => __('blox_pricing_badge_ribbon_right'),
                ]],
            ['key' => 'badge_size', 'type' => 'select', 'label' => __('blox_pricing_badge_size'), 'tab' => 'style', 'default' => 'sm',
                'options' => ['sm' => __('blox_pricing_badge_size_sm'), 'md' => __('blox_pricing_badge_size_md')]],
            ['key' => 'badge_bg', 'type' => 'color', 'label' => __('blox_pricing_badge_bg'), 'tab' => 'style', 'default' => ''],
            ['key' => 'badge_color', 'type' => 'color', 'label' => __('blox_pricing_badge_color'), 'tab' => 'style', 'default' => ''],
            // 推荐档突出显示与按钮位置
            ['key' => 'featured_scale', 'type' => 'select', 'label' => __('blox_pricing_featured_scale'), 'tab' => 'style', 'default' => '',
                'options' => ['' => __('blox_pricing_featured_scale_none'), 'sm' => __('blox_pricing_featured_scale_sm'), 'md' => __('blox_pricing_featured_scale_md')]],
            ['key' => 'featured_shadow', 'type' => 'checkbox', 'label' => __('blox_pricing_featured_shadow'), 'tab' => 'style', 'default' => false],
            ['key' => 'button_position', 'type' => 'select', 'label' => __('blox_pricing_button_position'), 'tab' => 'style', 'default' => 'bottom',
                'options' => ['bottom' => __('blox_pricing_button_bottom'), 'price' => __('blox_pricing_button_price')]],
            ...$this->staggerControls(),
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
        $options = [
            'variant' => $variant,
            'center' => $center,
            'toggle' => $toggle,
            'currency' => $currency,
            'badge_position' => self::badgePosition($data['badge_position'] ?? 'auto', $center),
            'badge_size' => ($data['badge_size'] ?? 'sm') === 'md' ? 'md' : 'sm',
            'badge_bg' => self::cssColor($data['badge_bg'] ?? null),
            'badge_color' => self::cssColor($data['badge_color'] ?? null),
            'note_chip' => ($data['price_note_style'] ?? 'text') === 'chip',
            'featured_scale' => self::FEATURED_SCALE[(string) ($data['featured_scale'] ?? '')] ?? '',
            'featured_shadow' => self::enabled($data, 'featured_shadow', false),
            'button_at_price' => ($data['button_position'] ?? 'bottom') === 'price',
        ];

        $html = '<div class="yk-pricing" data-yk-pricing data-yk-pricing-cycle="monthly">';
        if ($toggle) {
            $html .= self::toggleHtml(
                self::clip((string) ($data['monthly_label'] ?? __('blox_pricing_monthly')), 20),
                self::clip((string) ($data['yearly_label'] ?? __('blox_pricing_yearly')), 20),
                self::clip((string) ($data['yearly_note'] ?? ''), 30)
            );
        }
        $maxWidth = self::MAX_WIDTHS[(string) ($data['max_width'] ?? '')] ?? '';
        $wrap = 'grid items-stretch ' . self::columnClasses($data['columns'] ?? 'auto', count($plans))
            . ' ' . (self::GAPS[(string) ($data['gap'] ?? 'md')] ?? 'gap-6')
            . ($maxWidth !== '' ? ' w-full ' . $maxWidth . ' ' . (self::GRID_ALIGN[(string) ($data['grid_align'] ?? 'center')] ?? 'mx-auto') : '')
            // 放大的推荐档会越出格子：留出上下余量，免得贴到区块边上
            . ($options['featured_scale'] !== '' ? ' md:py-4' : '');
        $html .= '<div class="' . $wrap . '"' . $this->staggerAttrs($data) . '>';
        foreach ($plans as $plan) {
            $html .= self::planHtml($plan, $options);
        }
        return $html . '</div></div>';
    }

    /**
     * 列数：旧文档是单值（auto/2/3/4，沿用原来的手机单列、md 起分列）；按断点设置时是 {d,t,m,w}，auto 取套餐数（最多 4）。
     */
    private static function columnClasses(mixed $value, int $count): string
    {
        $auto = min(max($count, 1), 4);
        if (!is_array($value)) {
            $columns = in_array((string) $value, ['1', '2', '3', '4'], true) ? (int) $value : $auto;
            return self::gridClasses($columns, 3, true);
        }
        $resolved = [];
        foreach (['d', 't', 'm', 'w'] as $bp) {
            if (!array_key_exists($bp, $value)) {
                continue;
            }
            $candidate = (string) $value[$bp];
            $resolved[$bp] = $candidate === 'auto' ? $auto : (in_array($candidate, ['1', '2', '3', '4'], true) ? (int) $candidate : $auto);
        }
        return self::gridColumnClasses($resolved + ['d' => $auto]);
    }

    private static function badgePosition(mixed $value, bool $center): string
    {
        $value = is_string($value) && in_array($value, self::BADGE_POSITIONS, true) ? $value : 'auto';
        return $value === 'auto' ? ($center ? 'top-center' : 'top-left') : $value;
    }

    /**
     * 文档里的套餐数据不可信：逐字段限长、功能行拆分、按钮链接走统一的安全链接规则、颜色只收合法取值。
     *
     * @return list<array<string,mixed>>
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
            // 每档单独的主色：只收 #hex / rgb() 等合法颜色，其它一律忽略
            $plan['accent'] = self::cssColor($item['accent'] ?? null) ?? '';
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

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $o 元素级选项（见 render()）
     */
    private static function planHtml(array $plan, array $o): string
    {
        $variant = (string) $o['variant'];
        $center = (bool) $o['center'];
        $featured = (bool) $plan['featured'];
        $inverse = $featured && $variant === 'accent';
        $emphasis = $featured ? trim($o['featured_scale'] . ($o['featured_scale'] !== '' ? ' z-10' : '')) : '';
        $shadow = $featured && $o['featured_shadow'] ? ' shadow-xl' : ' shadow-sm';
        $card = match ($variant) {
            'minimal' => 'relative flex h-full flex-col px-2 py-6' . ($featured ? ' border-t-4 border-primary' : ' border-t border-gray-200'),
            default => 'relative flex h-full flex-col rounded-2xl border p-8' . $shadow
                . ($inverse ? ' border-primary bg-primary text-white' : ' bg-white')
                . ($featured && !$inverse ? ' border-primary ring-2 ring-primary' : '')
                . (!$featured ? ' border-gray-200' : ''),
        };
        if ($emphasis !== '') {
            $card .= ' transition-transform ' . $emphasis;
        }
        // 卡片是自带配色的独立表面：区块「文字色调」会覆盖标题/段落/列表的工具类颜色，
        // 所以卡片内文字用行内颜色（行内优先）。极简风格没有卡片底色，继续跟随区块色调。
        $palette = $variant === 'minimal' ? null : ($inverse
            ? ['title' => '#ffffff', 'body' => 'rgba(255,255,255,.85)', 'muted' => 'rgba(255,255,255,.6)']
            : ['title' => '#111827', 'body' => '#374151', 'muted' => '#6b7280']);
        $color = static fn (string $role): string => $palette === null ? '' : ' style="color:' . $palette[$role] . '"';
        $align = $center ? ' text-center items-center' : '';
        // 每档主色：卡片内的 primary 工具类（边框、角标、按钮、勾选图标、强调底色）都读这个变量
        $accentStyle = $plan['accent'] !== '' ? ' style="--color-primary:' . self::h((string) $plan['accent']) . '"' : '';

        $html = '<div class="' . $card . $align . '"' . $accentStyle . ($featured ? ' data-yk-pricing-featured' : '') . '>';
        $badgePos = (string) $o['badge_position'];
        $badge = $plan['badge'] !== '' ? self::badgeHtml((string) $plan['badge'], $badgePos, $o, $inverse, $center) : '';
        if (!in_array($badgePos, ['inline', 'corner'], true)) {
            $html .= $badge;
        }
        if ($badgePos === 'inline') {
            $html .= $badge;
        }
        $title = '<h3 class="text-lg font-semibold"' . $color('title') . '>' . self::h((string) $plan['name']) . '</h3>';
        // 卡片内右上角：与名称同一行，名称再长也不会被角标压住
        $html .= $badgePos === 'corner' && $badge !== ''
            ? '<div class="flex w-full items-start justify-between gap-3">' . $title . $badge . '</div>'
            : $title;
        if ($plan['description'] !== '') {
            $html .= '<p class="mt-2 text-sm opacity-90"' . $color('muted') . '>' . self::h((string) $plan['description']) . '</p>';
        }

        if ($plan['price_prefix'] !== '') {
            $html .= '<p class="mt-6 text-xs font-semibold uppercase tracking-wide opacity-80"' . $color('muted') . '>' . self::h((string) $plan['price_prefix']) . '</p>';
        }
        $html .= '<div class="' . ($plan['price_prefix'] !== '' ? 'mt-1' : 'mt-6') . ' flex items-baseline gap-1' . ($center ? ' justify-center' : '') . '">';
        $html .= self::priceHtml((string) $o['currency'], (string) $plan['price'], (string) $plan['original_price'], (string) $plan['period'], 'monthly', $palette, false);
        if ($o['toggle']) {
            $yearlyPrice = $plan['price_yearly'] !== '' ? (string) $plan['price_yearly'] : (string) $plan['price'];
            $yearlyOriginal = $plan['original_price_yearly'] !== '' ? (string) $plan['original_price_yearly'] : ($plan['price_yearly'] !== '' ? '' : (string) $plan['original_price']);
            $yearlyPeriod = $plan['period_yearly'] !== '' ? (string) $plan['period_yearly'] : (string) $plan['period'];
            $html .= self::priceHtml((string) $o['currency'], $yearlyPrice, $yearlyOriginal, $yearlyPeriod, 'yearly', $palette, true);
        }
        $html .= '</div>';
        if ($plan['price_note'] !== '') {
            $html .= $o['note_chip']
                ? '<p class="mt-3"><span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ' . ($inverse ? 'bg-white/15 text-white' : 'bg-primary/10 text-primary') . '">'
                    . self::h((string) $plan['price_note']) . '</span></p>'
                : '<p class="mt-2 text-sm"' . $color('muted') . '>' . self::h((string) $plan['price_note']) . '</p>';
        }

        $button = self::buttonHtml($plan, $featured, $inverse, (bool) $o['button_at_price']);
        if ($o['button_at_price']) {
            $html .= $button;
        }
        if ($plan['features'] !== []) {
            $html .= '<ul class="mt-6 space-y-3 text-sm' . ($center ? ' text-start' : '') . '">';
            foreach ($plan['features'] as $feature) {
                $icon = $feature['included']
                    ? '<i class="ti ti-check mt-0.5 shrink-0 ' . ($inverse ? 'text-white' : 'text-primary') . '" aria-hidden="true"></i>'
                    : '<i class="ti ti-x mt-0.5 shrink-0 ' . ($inverse ? 'text-white/50' : 'text-gray-300') . '" aria-hidden="true"></i>';
                $html .= '<li class="flex items-start gap-2' . ($feature['included'] ? '' : ' line-through opacity-70') . '"'
                    . $color($feature['included'] ? 'body' : 'muted') . '>' . $icon . '<span>' . self::h($feature['text']) . '</span></li>';
            }
            $html .= '</ul>';
        }
        if (!$o['button_at_price']) {
            $html .= $button;
        }
        return $html . '</div>';
    }

    /** @param array<string,mixed> $o */
    private static function badgeHtml(string $text, string $position, array $o, bool $inverse, bool $center): string
    {
        $custom = '';
        if ($o['badge_bg'] !== null) {
            $custom .= 'background-color:' . $o['badge_bg'] . ';';
        }
        if ($o['badge_color'] !== null) {
            $custom .= 'color:' . $o['badge_color'] . ';';
        }
        $colors = $custom !== '' ? '' : ($inverse ? ' bg-white text-primary' : ' bg-primary text-white');
        $style = $custom !== '' ? ' style="' . self::h($custom) . '"' : '';
        $size = $o['badge_size'] === 'md' ? ' px-4 py-1.5 text-sm' : ' px-3 py-1 text-xs';

        if ($position === 'ribbon-left' || $position === 'ribbon-right') {
            // 斜角绶带：外层裁掉超出卡片圆角的部分，绶带本身旋转 45°
            $left = $position === 'ribbon-left';
            return '<div class="pointer-events-none absolute top-0 h-24 w-24 overflow-hidden ' . ($left ? 'left-0 rounded-tl-2xl' : 'right-0 rounded-tr-2xl') . '" data-yk-pricing-badge="' . $position . '">'
                . '<span class="absolute top-5 block w-36 text-center font-semibold shadow-sm ' . ($left ? '-left-9 -rotate-45' : '-right-9 rotate-45')
                . ($o['badge_size'] === 'md' ? ' py-1.5 text-sm' : ' py-1 text-xs') . $colors . '"' . $style . '>' . self::h($text) . '</span></div>';
        }
        $place = match ($position) {
            'top-center' => 'absolute -top-3 left-1/2 -translate-x-1/2 whitespace-nowrap',
            'top-right' => 'absolute -top-3 right-8 whitespace-nowrap',
            'inline' => 'mb-3 inline-flex self-start' . ($center ? ' self-center' : ''),
            'corner' => 'shrink-0 whitespace-nowrap',
            default => 'absolute -top-3 left-8 whitespace-nowrap',
        };
        return '<span class="' . $place . ' rounded-full font-semibold' . $size . $colors . '"' . $style . ' data-yk-pricing-badge="' . $position . '">' . self::h($text) . '</span>';
    }

    /** @param array<string,mixed> $plan */
    private static function buttonHtml(array $plan, bool $featured, bool $inverse, bool $atPrice): string
    {
        if ($plan['button_text'] === '') {
            return '';
        }
        $button = $inverse
            ? 'bg-white text-primary hover:bg-white/90'
            : ($featured ? 'bg-primary text-white hover:opacity-90' : 'border border-gray-300 text-gray-900 hover:border-primary hover:text-primary');
        // 默认贴底：各档功能行数不同，按钮仍在同一水平线；放在价格下方时紧跟价格
        return '<div class="' . ($atPrice ? 'mt-6' : 'mt-auto pt-8') . ' w-full"><a href="' . self::h($plan['button_url'] !== '' ? (string) $plan['button_url'] : '#')
            . '" class="inline-flex w-full items-center justify-center rounded-lg px-5 py-3 text-sm font-semibold transition '
            . $button . '">' . self::h((string) $plan['button_text']) . '</a></div>';
    }

    /** @param array{title:string,body:string,muted:string}|null $palette */
    private static function priceHtml(string $currency, string $price, string $original, string $period, string $cycle, ?array $palette, bool $hidden): string
    {
        $symbol = static fn (string $amount): string => $currency !== '' && $amount !== '' && !preg_match('/^\D/u', $amount) ? self::h($currency) : '';
        $originalHtml = $original !== ''
            ? '<s class="mr-1 text-base font-medium opacity-60" aria-label="' . self::h(__('blox_pricing_original_price_aria', ['price' => $symbol($original) . $original])) . '">'
                . $symbol($original) . self::h($original) . '</s>'
            : '';
        $symbolHtml = $symbol($price) !== '' ? '<span class="text-xl font-semibold">' . $symbol($price) . '</span>' : '';
        $periodHtml = $period !== ''
            ? '<span class="text-sm opacity-80">' . self::h($period) . '</span>'
            : '';
        return '<span class="inline-flex flex-wrap items-baseline gap-1" data-yk-pricing-price="' . $cycle . '"' . ($hidden ? ' hidden' : '')
            . ($palette === null ? '' : ' style="color:' . $palette['title'] . '"') . '>'
            . $originalHtml . $symbolHtml . '<span class="text-4xl font-bold tracking-tight">' . self::h($price) . '</span>' . $periodHtml . '</span>';
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
