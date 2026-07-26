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
    public function label(): string { return '折叠面板(FAQ)'; }
    public function icon(): string { return 'chevrons-down'; }

    public function controls(): array
    {
        return [
            ['key' => 'items', 'type' => 'textarea', 'label' => '问答条目（每行一条：问题|答案）', 'rows' => 8,
             'default' => "如何购买你们的产品？|您可以通过在线表单留言或直接电话联系我们，客服会在一个工作日内回复。\n是否提供售后服务？|提供。所有产品均含一年质保，终身技术支持。",
             'placeholder' => "问题|答案\n问题|答案"],
            ['key' => 'open_first', 'type' => 'checkbox', 'label' => '默认展开第一条', 'default' => true],
            ['key' => 'seo_schema', 'type' => 'checkbox', 'label' => '输出 FAQ 结构化数据（搜索富摘要）', 'default' => true],
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
