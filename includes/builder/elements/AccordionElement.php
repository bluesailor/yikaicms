<?php
/**
 * 折叠面板（FAQ）：问答条目点击展开/收起。
 * 原生 <details>/<summary> 实现——零 JS、语义化、搜索引擎可读；
 * 可选输出 FAQPage 结构化数据（JSON-LD，搜索结果富摘要）。
 * 后台与新安装数据使用 question/answer 数组；同时兼容旧版每行「问题|答案」格式。
 */

declare(strict_types=1);

final class AccordionElement extends AbstractElement
{
    public function type(): string { return 'accordion'; }
    public function label(): string { return __('blox_el_accordion'); }
    public function icon(): string { return 'chevrons-down'; }

    public function controls(): array
    {
        return [
            [
                'key' => 'faq_style', 'type' => 'select', 'label' => __('blox_faq_style'),
                'default' => 'default', 'tab' => 'style',
                'options' => [
                    'default' => __('blox_faq_style_default'),
                    'divided' => __('blox_faq_style_divided'),
                    'soft' => __('blox_faq_style_soft'),
                ],
            ],
            [
                'key' => 'items', 'type' => 'faq_repeater', 'label' => __('blox_faq_items'), 'max' => 30,
                'default' => [
                    ['question' => __('blox_faq_seed_q1'), 'answer' => __('blox_faq_seed_a1')],
                    ['question' => __('blox_faq_seed_q2'), 'answer' => __('blox_faq_seed_a2')],
                ],
            ],
            ['key' => 'open_first', 'type' => 'checkbox', 'label' => __('blox_faq_open_first'), 'default' => true],
            ['key' => 'seo_schema', 'type' => 'checkbox', 'label' => __('blox_faq_schema'), 'default' => true],
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function normalizeItems(array $items): array
    {
        $out = [];
        foreach (array_slice($items, 0, 30) as $item) {
            if (!is_array($item)) continue;
            $item['question'] = is_scalar($item['question'] ?? null) ? (string) $item['question'] : '';
            $item['answer'] = is_scalar($item['answer'] ?? null) ? (string) $item['answer'] : '';
            if (($item['answer_format'] ?? '') === 'html') {
                $item['answer'] = HtmlPolicy::description($item['answer']);
            } else {
                unset($item['answer_format']);
            }
            $out[] = $item;
        }
        return $out;
    }

    /** @return list<array{0:string,1:string,2?:string}> */
    public function items(array $data): array
    {
        $value = $data['items'] ?? [];
        $out = [];
        if (is_array($value)) {
            foreach (self::normalizeItems($value) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $question = trim((string) ($item['question'] ?? ''));
                if ($question !== '') {
                    $answer = trim((string) ($item['answer'] ?? ''));
                    $out[] = ($item['answer_format'] ?? '') === 'html'
                        ? [$question, $answer, 'html']
                        : [$question, $answer];
                }
            }
            return $out;
        }
        foreach (preg_split('/\r\n|\r|\n/', (string) $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$q, $a] = array_pad(explode('|', $line, 2), 2, '');
            $q = trim($q);
            if ($q !== '') {
                $out[] = [$q, trim($a)];
            }
        }
        return $out;
    }

    public function render(array $data, string $children = ''): string
    {
        $items = $this->items($data);
        if (!$items) {
            return '';
        }
        $openFirst = !empty($data['open_first']);
        $style = in_array($data['faq_style'] ?? '', ['divided', 'soft'], true) ? $data['faq_style'] : 'default';
        $wrapperClass = match ($style) {
            'divided' => 'divide-y divide-gray-200',
            'soft' => 'space-y-3',
            default => 'divide-y divide-gray-200 border border-gray-200 rounded-xl bg-white overflow-hidden',
        };
        $itemClass = match ($style) {
            'divided' => 'group px-1',
            'soft' => 'group rounded-lg border border-gray-200 bg-gray-50 px-5 open:bg-white open:border-gray-300 transition-colors motion-reduce:transition-none',
            default => 'group px-5',
        };
        $summaryClass = 'flex items-center justify-between gap-3 py-4 cursor-pointer list-none font-medium text-gray-800 hover:text-primary transition';
        if ($style !== 'default') {
            $summaryClass .= ' [&::-webkit-details-marker]:hidden focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none';
        }
        $icon = $style === 'default'
            ? '<i class="ti ti-chevron-down text-gray-400 flex-shrink-0 transition-transform duration-200 group-open:rotate-180"></i>'
            : '<i aria-hidden="true" class="ti ti-plus shrink-0 text-gray-500 group-open:hidden"></i>'
                . '<i aria-hidden="true" class="ti ti-minus hidden shrink-0 text-gray-500 group-open:block"></i>';
        $html = '<div class="' . $wrapperClass . '"' . ($style !== 'default' ? ' data-blox-faq-style="' . $style . '"' : '') . '>';
        foreach ($items as $i => [$q, $a]) {
            $richAnswer = ($items[$i][2] ?? '') === 'html';
            // summary 设为 flex 后浏览器不再画默认三角 marker；list-none 兜底
            $html .= '<details class="' . $itemClass . '"' . ($openFirst && $i === 0 ? ' open' : '') . '>'
                . '<summary class="' . $summaryClass . '">'
                . '<span' . ($style !== 'default' ? ' class="min-w-0 [overflow-wrap:anywhere]"' : '') . '>' . htmlspecialchars($q) . '</span>'
                . $icon
                . '</summary>'
                . '<div class="pb-4 text-sm text-gray-600 leading-relaxed' . ($richAnswer ? ' yk-description' : '') . ($style !== 'default' ? ' [overflow-wrap:anywhere]' : '') . '">' . ($richAnswer ? $a : nl2br(htmlspecialchars($a))) . '</div>'
                . '</details>';
        }
        $html .= '</div>';

        // FAQPage JSON-LD（JSON_HEX_TAG 防止内容含 </script> 逃逸）
        if (!empty($data['seo_schema'])) {
            $ld = [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => array_map(static fn(array $it) => [
                    '@type'          => 'Question',
                    'name'           => $it[0],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $it[1]],
                ], $items),
            ];
            $html .= '<script type="application/ld+json">'
                . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
                . '</script>';
        }
        return $html;
    }
}
