<?php
/**
 * 折叠面板（FAQ）：问答条目点击展开/收起。
 * 原生 <details>/<summary> 实现——零 JS、语义化、搜索引擎可读；
 * 可选输出 FAQPage 结构化数据（JSON-LD，搜索结果富摘要）。
 * 条目编辑格式：textarea 每行一条「问题|答案」（schema 表单无 repeater 控件，此为最轻表示）。
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
            ['key' => 'items', 'type' => 'textarea', 'label' => __('blox_faq_items'), 'rows' => 8,
             'default' => __('blox_faq_seed_q1') . '|' . __('blox_faq_seed_a1') . "\n" . __('blox_faq_seed_q2') . '|' . __('blox_faq_seed_a2'),
             'placeholder' => __('blox_faq_ph') . "\n" . __('blox_faq_ph')],
            ['key' => 'open_first', 'type' => 'checkbox', 'label' => __('blox_faq_open_first'), 'default' => true],
            ['key' => 'seo_schema', 'type' => 'checkbox', 'label' => __('blox_faq_schema'), 'default' => true],
        ];
    }

    /** 解析「问题|答案」行 → [[q, a], ...] */
    private function items(array $data): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($data['items'] ?? '')) ?: [] as $line) {
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
        $html = '<div class="divide-y divide-gray-200 border border-gray-200 rounded-xl bg-white overflow-hidden">';
        foreach ($items as $i => [$q, $a]) {
            // summary 设为 flex 后浏览器不再画默认三角 marker；list-none 兜底
            $html .= '<details class="group px-5"' . ($openFirst && $i === 0 ? ' open' : '') . '>'
                . '<summary class="flex items-center justify-between gap-3 py-4 cursor-pointer list-none font-medium text-gray-800 hover:text-primary transition">'
                . '<span>' . htmlspecialchars($q) . '</span>'
                . '<i class="ti ti-chevron-down text-gray-400 flex-shrink-0 transition-transform duration-200 group-open:rotate-180"></i>'
                . '</summary>'
                . '<div class="pb-4 text-sm text-gray-600 leading-relaxed">' . nl2br(htmlspecialchars($a)) . '</div>'
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
