<?php
/**
 * 查询筛选元素（2.0.3，对标 Bricks Query Filters）：指向页面上的一个查询循环，
 * 访客的选择写进该循环命名空间下的 URL 参数（BloxQueryFilters），循环渲染时叠加收窄。
 *
 * 类型：搜索 / 分类 / 自定义字段 / 排序 / 价格区间 / 发布时间 / 重置 / 结果摘要。
 * 渐进增强：本身就是 GET 表单，禁用脚本时提交整页同样得到正确结果；有脚本时
 * blox-query.js 局部刷新目标循环并同步地址栏（可分享、可后退）。
 *
 * 授权：作者端归 query_loop（与容器 Loop 同一能力），已发布内容渲染永远免费。
 */

declare(strict_types=1);

final class QueryFilterElement extends AbstractElement
{
    public const TYPES = ['search', 'terms', 'field', 'sort', 'price', 'date', 'reset', 'summary'];
    private const DISPLAYS = ['select', 'radio', 'checkbox', 'pills', 'pills_multi'];
    private const SORT_SETS = [
        'basic' => ['default', 'newest', 'oldest', 'title', 'views'],
        'product' => ['default', 'newest', 'price_asc', 'price_desc', 'views', 'title'],
        'terms' => ['default', 'name', 'count'],
    ];
    private const DATE_OPTIONS = [7, 30, 90, 365];

    private static int $seq = 0;

    public function type(): string { return 'query-filter'; }
    public function label(): string { return __('blox_el_query_filter'); }
    public function icon(): string { return 'filter'; }
    public function category(): string { return 'dynamic'; }

    public function scripts(): array
    {
        return ['/assets/js/blox-query.js'];
    }

    public function styles(): array
    {
        return ['/assets/css/blox-query.css'];
    }

    public function controls(): array
    {
        $types = [];
        foreach (self::TYPES as $type) {
            $types[$type] = __('blox_qf_type_' . $type);
        }
        $displays = [];
        foreach (self::DISPLAYS as $display) {
            $displays[$display] = __('blox_qf_display_' . $display);
        }
        $choice = ['filter_type', 'in', ['terms', 'field']];
        return [
            ['key' => 'target', 'type' => 'select', 'label' => __('blox_qf_target'), 'default' => '',
                // 候选由编辑器按当前文档实时列出（loopHostOptions）；服务端不设 options，值按 id 规则在渲染端校验
                'options_from' => 'loop_hosts', 'loop_label' => __('blox_qf_loop'), 'none_label' => __('blox_qf_target_none')],
            ['key' => 'filter_type', 'type' => 'select', 'label' => __('blox_qf_type'), 'default' => 'search', 'options' => $types],
            ['key' => 'label', 'type' => 'text', 'label' => __('blox_qf_label'), 'default' => ''],
            ['key' => 'placeholder', 'type' => 'text', 'label' => __('blox_qf_placeholder'), 'default' => '',
                'required' => ['filter_type', 'in', ['search', 'terms', 'field', 'price']]],
            ['key' => 'display', 'type' => 'select', 'label' => __('blox_qf_display'), 'default' => 'select',
                'options' => $displays, 'required' => $choice],
            ['key' => 'taxonomy', 'type' => 'select', 'label' => __('blox_qf_taxonomy'), 'default' => 'content',
                'options' => ['content' => __('blox_query_terms_content'), 'product' => __('blox_query_terms_product'), 'download' => __('blox_query_terms_download')],
                'required' => ['filter_type', '=', 'terms']],
            // 文本而非数字控件：空值表示「全部分类」，数字控件会把空值归一成 0（仅顶级）
            ['key' => 'parent_term', 'type' => 'text', 'label' => __('blox_qf_parent_term'), 'default' => '',
                'placeholder' => __('blox_qf_parent_term_hint'), 'required' => ['filter_type', '=', 'terms']],
            ['key' => 'show_count', 'type' => 'checkbox', 'label' => __('blox_qf_show_count'), 'default' => false,
                'required' => ['filter_type', '=', 'terms']],
            ['key' => 'field_key', 'type' => 'text', 'label' => __('blox_qf_field_key'), 'default' => '',
                'required' => ['filter_type', '=', 'field']],
            ['key' => 'field_options', 'type' => 'textarea', 'label' => __('blox_qf_field_options'), 'default' => '', 'rows' => 4,
                'placeholder' => "red|Red\nblue|Blue", 'required' => ['filter_type', '=', 'field']],
            ['key' => 'all_label', 'type' => 'text', 'label' => __('blox_qf_all_label'), 'default' => '', 'required' => $choice],
            ['key' => 'sort_set', 'type' => 'select', 'label' => __('blox_qf_sort_set'), 'default' => 'basic',
                'options' => ['basic' => __('blox_qf_sort_basic'), 'product' => __('blox_qf_sort_product'), 'terms' => __('blox_qf_sort_terms')],
                'required' => ['filter_type', '=', 'sort']],
            ['key' => 'summary_text', 'type' => 'text', 'label' => __('blox_qf_summary_text'), 'default' => '',
                'placeholder' => __('blox_qf_summary_default'), 'required' => ['filter_type', '=', 'summary']],
            ['key' => 'summary_empty', 'type' => 'text', 'label' => __('blox_qf_summary_empty'), 'default' => '',
                'placeholder' => __('blox_qf_summary_empty_default'), 'required' => ['filter_type', '=', 'summary']],
            ['key' => 'reset_text', 'type' => 'text', 'label' => __('blox_qf_reset_text'), 'default' => '',
                'placeholder' => __('blox_qf_reset_default'), 'required' => ['filter_type', '=', 'reset']],
        ];
    }

    public function renderWithContext(array $data, string $children = '', array $context = []): string
    {
        $editMode = !empty($context['edit_mode']);
        $target = is_string($data['target'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $data['target']) ? $data['target'] : '';
        if ($target === '') {
            return $editMode
                ? '<div class="yk-query-filter rounded border border-dashed border-gray-300 px-3 py-2 text-xs text-gray-500">'
                    . e(__('blox_qf_target_hint')) . '</div>'
                : '';
        }
        $type = in_array($data['filter_type'] ?? '', self::TYPES, true) ? (string) $data['filter_type'] : 'search';
        $prefix = BloxQueryFilters::prefix($target);
        $current = BloxQueryFilters::requestOverrides($target);

        if ($type === 'summary') {
            return $this->renderSummary($data, $target);
        }
        if ($type === 'reset') {
            return $this->renderReset($data, $prefix, $current);
        }

        $id = 'ykqf-' . substr(md5($target . $type), 0, 6) . '-' . (++self::$seq);
        $label = trim((string) ($data['label'] ?? ''));
        $control = match ($type) {
            'search' => $this->searchControl($data, $prefix, $current, $id, $label),
            'terms' => $this->choiceControl($data, $prefix . '_cat', $this->termChoices($data),
                array_map('strval', (array) ($current['cats'] ?? [])), $id, $label),
            'field' => $this->fieldControl($data, $prefix, $current, $id, $label),
            'sort' => $this->choiceControl(['display' => 'select'] + $data, $prefix . '_sort', $this->sortChoices($data),
                isset($current['sort']) ? [(string) $current['sort']] : [], $id, $label, false),
            'price' => $this->priceControl($data, $prefix, $current, $id, $label),
            default => $this->choiceControl(['display' => 'select'] + $data, $prefix . '_days', $this->dateChoices(),
                isset($current['days']) ? [(string) $current['days']] : [], $id, $label),
        };
        return '<form class="yk-query-filter" method="get" action="" data-yk-filter data-yk-filter-target="' . e($target)
            . '" data-yk-filter-prefix="' . e($prefix) . '"' . ($type === 'search' ? ' role="search"' : '') . '>'
            . self::preservedParams($prefix) . $control
            . '<noscript><button type="submit" class="mt-2 rounded border border-gray-300 px-3 py-1 text-sm">'
            . e(__('blox_qf_apply')) . '</button></noscript></form>';
    }

    public function render(array $data, string $children = ''): string
    {
        return $this->renderWithContext($data, $children);
    }

    /** 无脚本提交时保留页面上其余的查询参数（本循环的筛选参数、分页参数与片段参数除外）。 */
    private static function preservedParams(string $prefix): string
    {
        $html = '';
        foreach ($_GET as $key => $value) {
            if (!is_string($key) || !is_scalar($value) || str_starts_with($key, $prefix . '_')
                || str_starts_with($key, 'ykq_') || $key === 'page' || $key === BloxQueryFragment::PARAM
                || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) !== 1) {
                continue;
            }
            $html .= '<input type="hidden" name="' . e($key) . '" value="' . e(mb_substr((string) $value, 0, 200)) . '">';
        }
        return $html;
    }

    private static function labelHtml(string $label, string $for): string
    {
        return $label === '' ? '' : '<label for="' . e($for) . '" class="mb-1.5 block text-sm font-medium text-gray-700">' . e($label) . '</label>';
    }

    private const INPUT = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20';

    /** @param array<string,mixed> $current */
    private function searchControl(array $data, string $prefix, array $current, string $id, string $label): string
    {
        $placeholder = trim((string) ($data['placeholder'] ?? '')) ?: __('blox_qf_search_placeholder');
        return self::labelHtml($label, $id)
            . '<div class="relative"><i class="ti ti-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true"></i>'
            . '<input type="search" id="' . e($id) . '" name="' . e($prefix . '_s') . '" value="' . e((string) ($current['search'] ?? ''))
            . '" placeholder="' . e($placeholder) . '"' . ($label === '' ? ' aria-label="' . e($placeholder) . '"' : '')
            . ' maxlength="100" class="' . self::INPUT . ' pl-9"></div>';
    }

    /**
     * 单选/多选控件（下拉、单选、复选、按钮组）。$choices 为 [值 => [文案, 计数|null]]。
     *
     * @param array<array-key,array{0:string,1:int|null}> $choices @param list<string> $selected
     */
    private function choiceControl(array $data, string $name, array $choices, array $selected, string $id, string $label, bool $withAll = true): string
    {
        $display = in_array($data['display'] ?? '', self::DISPLAYS, true) ? (string) $data['display'] : 'select';
        $allLabel = trim((string) ($data['all_label'] ?? '')) ?: __('blox_qf_all');
        $showCount = !empty($data['show_count']);
        $text = static fn (array $choice): string => $choice[0] . ($showCount && $choice[1] !== null ? ' (' . $choice[1] . ')' : '');
        if ($display === 'select') {
            $html = self::labelHtml($label, $id) . '<select id="' . e($id) . '" name="' . e($name) . '" class="' . self::INPUT . '"'
                . ($label === '' ? ' aria-label="' . e(trim((string) ($data['placeholder'] ?? '')) ?: $allLabel) . '"' : '') . '>';
            if ($withAll) {
                $html .= '<option value="">' . e(trim((string) ($data['placeholder'] ?? '')) ?: $allLabel) . '</option>';
            }
            foreach ($choices as $value => $choice) {
                $html .= '<option value="' . e((string) $value) . '"' . (in_array((string) $value, $selected, true) ? ' selected' : '') . '>'
                    . e($text($choice)) . '</option>';
            }
            return $html . '</select>';
        }
        $multi = $display === 'checkbox' || $display === 'pills_multi';
        $pills = $display === 'pills' || $display === 'pills_multi';
        $inputType = $multi ? 'checkbox' : 'radio';
        $fieldName = $multi ? $name . '[]' : $name;
        $html = '<fieldset class="yk-query-filter-group min-w-0 border-0 p-0 m-0">'
            . ($label !== '' ? '<legend class="mb-1.5 block text-sm font-medium text-gray-700">' . e($label) . '</legend>' : '')
            . '<div class="' . ($pills ? 'flex flex-wrap gap-2' : 'space-y-1.5') . '">';
        $options = $choices;
        if (!$multi && $withAll) {
            $options = ['' => [$allLabel, null]] + $choices;
        }
        foreach ($options as $value => $choice) {
            $checked = (string) $value === '' ? $selected === [] : in_array((string) $value, $selected, true);
            $input = '<input type="' . $inputType . '" name="' . e($fieldName) . '" value="' . e((string) $value) . '"'
                . ($checked ? ' checked' : '') . ($pills ? '' : ' class="h-4 w-4 accent-primary"') . '>';
            if ($pills) {
                $count = $showCount && $choice[1] !== null ? '<span class="yk-filter-count">' . (int) $choice[1] . '</span>' : '';
                $html .= '<label class="yk-filter-pill">' . $input . '<span>' . e($choice[0]) . $count . '</span></label>';
            } else {
                $html .= '<label class="flex items-center gap-2 text-sm text-gray-700">' . $input . '<span>' . e($text($choice)) . '</span></label>';
            }
        }
        return $html . '</div></fieldset>';
    }

    /** @return array<array-key,array{0:string,1:int|null}> */
    private function termChoices(array $data): array
    {
        $taxonomy = in_array($data['taxonomy'] ?? '', ['content', 'product', 'download'], true) ? (string) $data['taxonomy'] : 'content';
        $plan = ['kind' => 'term', 'taxonomy' => $taxonomy, 'limit' => 100, 'offset' => 0, 'order' => 'default'];
        $parent = trim((string) ($data['parent_term'] ?? ''));
        if ($parent !== '' && ctype_digit($parent)) {
            $plan['parent_term'] = max(0, (int) $parent);
        }
        try {
            $rows = BloxQueryRunner::fetch($plan, 1)['rows'];
        } catch (Throwable) {
            return []; // 缺表的老站：没有候选即不输出选项
        }
        // 未限定上级时按树形排列：子分类紧跟父分类、下拉里缩进（按钮组/列表不缩进）
        $byParent = [];
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        foreach ($rows as $row) {
            $parentId = (int) ($row['parent_id'] ?? 0);
            $byParent[in_array($parentId, $ids, true) ? $parentId : 0][] = $row;
        }
        $indent = ($data['display'] ?? 'select') === 'select';
        $choices = [];
        $walk = static function (int $parentId, int $level) use (&$walk, &$choices, $byParent, $taxonomy, $indent): void {
            foreach ($byParent[$parentId] ?? [] as $row) {
                $name = $taxonomy === 'download' ? self::downloadName($row) : (string) ($row['name'] ?? '');
                $choices[(string) (int) $row['id']] = [($indent ? str_repeat('— ', min(4, $level)) : '') . $name,
                    isset($row['item_count']) ? (int) $row['item_count'] : null];
                if ($level < 8) {
                    $walk((int) $row['id'], $level + 1);
                }
            }
        };
        $walk(0, 0);
        return $choices;
    }

    private static function downloadName(array $row): string
    {
        $lang = function_exists('siteLang') ? siteLang() : 'zh-CN';
        $localized = $lang === 'en' ? (string) ($row['name_en'] ?? '') : ($lang === 'ja' ? (string) ($row['name_ja'] ?? '') : '');
        return $localized !== '' ? $localized : (string) ($row['name'] ?? '');
    }

    /** @param array<string,mixed> $current */
    private function fieldControl(array $data, string $prefix, array $current, string $id, string $label): string
    {
        $key = strtolower(trim((string) ($data['field_key'] ?? '')));
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1) {
            return '';
        }
        $choices = [];
        foreach (preg_split('/\R/u', (string) ($data['field_options'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || count($choices) >= 30) {
                continue;
            }
            [$value, $text] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $value = str_replace(',', '', mb_substr($value, 0, 100));
            if ($value !== '') {
                $choices[$value] = [$text !== '' ? mb_substr($text, 0, 60) : $value, null];
            }
        }
        $selected = array_map('strval', (array) (($current['meta'] ?? [])[$key] ?? []));
        return $this->choiceControl($data, $prefix . '_m_' . $key, $choices, $selected, $id, $label);
    }

    /** @return array<array-key,array{0:string,1:int|null}> */
    private function sortChoices(array $data): array
    {
        $set = self::SORT_SETS[(string) ($data['sort_set'] ?? 'basic')] ?? self::SORT_SETS['basic'];
        $labels = [
            'default' => __('blox_qf_sort_default'), 'newest' => __('blox_dynamic_order_newest'), 'oldest' => __('blox_query_order_oldest'),
            'title' => __('blox_query_order_title'), 'views' => __('blox_dynamic_order_views'),
            'price_asc' => __('blox_dynamic_order_price_asc'), 'price_desc' => __('blox_dynamic_order_price_desc'),
            'name' => __('blox_query_order_name'), 'count' => __('blox_query_order_count'),
        ];
        $choices = [];
        foreach ($set as $value) {
            // 默认排序 = 不带参数（地址干净、「清除筛选」按钮不会被它点亮）
            $choices[$value === 'default' ? '' : $value] = [$labels[$value] ?? $value, null];
        }
        return $choices;
    }

    /** @return array<array-key,array{0:string,1:int|null}> */
    private function dateChoices(): array
    {
        $choices = [];
        foreach (self::DATE_OPTIONS as $days) {
            $choices[(string) $days] = [str_replace(':days', (string) $days, __('blox_query_date_within')), null];
        }
        return $choices;
    }

    /** @param array<string,mixed> $current */
    private function priceControl(array $data, string $prefix, array $current, string $id, string $label): string
    {
        $value = static fn (string $key): string => isset($current[$key]) ? rtrim(rtrim(number_format((float) $current[$key], 2, '.', ''), '0'), '.') : '';
        $input = static fn (string $name, string $key, string $placeholder, string $inputId): string =>
            '<input type="number" min="0" step="any" inputmode="decimal" id="' . e($inputId) . '" name="' . e($name) . '" value="' . e($value($key))
            . '" placeholder="' . e($placeholder) . '" aria-label="' . e($placeholder) . '" class="' . self::INPUT . '">';
        return self::labelHtml($label, $id)
            . '<div class="flex items-center gap-2">'
            . $input($prefix . '_min', 'price_min', __('blox_query_price_min'), $id)
            . '<span class="text-gray-400" aria-hidden="true">–</span>'
            . $input($prefix . '_max', 'price_max', __('blox_query_price_max'), $id . '-max')
            . '</div>';
    }

    /** @param array<string,mixed> $current */
    private function renderReset(array $data, string $prefix, array $current): string
    {
        $text = trim((string) ($data['reset_text'] ?? '')) ?: __('blox_qf_reset_default');
        $url = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = [];
        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $query);
        foreach (array_keys($query) as $key) {
            if (str_starts_with((string) $key, $prefix . '_') || str_starts_with((string) $key, 'ykq_') || $key === BloxQueryFragment::PARAM) {
                unset($query[$key]);
            }
        }
        $href = $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        return '<a href="' . e($href) . '" data-yk-filter-reset="' . e($prefix) . '"' . ($current === [] ? ' hidden' : '')
            . ' class="yk-query-filter-reset inline-flex items-center gap-1.5 text-sm text-gray-600 no-underline hover:text-primary">'
            . '<i class="ti ti-x" aria-hidden="true"></i>' . e($text) . '</a>';
    }

    private function renderSummary(array $data, string $target): string
    {
        $template = trim((string) ($data['summary_text'] ?? '')) ?: __('blox_qf_summary_default');
        $empty = trim((string) ($data['summary_empty'] ?? '')) ?: __('blox_qf_summary_empty_default');
        // 循环先于摘要渲染时服务端直接给出数字；否则由脚本从宿主的 data-yk-query-total 填入
        $total = BloxLoopQuery::totalFor($target);
        $text = $total === null ? '' : str_replace(':count', (string) $total, $total > 0 ? $template : $empty);
        return '<p class="yk-query-summary text-sm text-gray-600" data-yk-query-summary="' . e($target) . '" data-template="' . e($template)
            . '" data-template-empty="' . e($empty) . '"' . ($total === null ? ' hidden' : '') . '>' . e($text) . '</p>';
    }
}
