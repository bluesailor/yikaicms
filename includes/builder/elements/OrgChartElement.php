<?php
/** 结构化组织架构：Blox 管理节点数据，前台运行时负责缩放与展开。 */

declare(strict_types=1);

final class OrgChartElement extends AbstractElement
{
    private const MAX_NODES = 100;

    public function type(): string { return 'org-chart'; }
    public function label(): string { return __('blox_el_org_chart'); }
    public function icon(): string { return 'hierarchy-3'; }
    public function category(): string { return 'advanced'; }
    public function treeLabelField(): ?string { return 'label'; }

    public function controls(): array
    {
        return [
            [
                'key' => 'nodes', 'type' => 'org_repeater', 'label' => __('blox_org_nodes'),
                'max' => self::MAX_NODES, 'default' => self::seedNodes(),
            ],
            [
                'key' => 'style', 'type' => 'select', 'label' => __('page_org_style'), 'tab' => 'style',
                'options' => [
                    'default' => __('page_org_style_default'),
                    'teal' => __('page_org_style_teal'),
                    'dark' => __('page_org_style_dark'),
                    'purple' => __('page_org_style_purple'),
                    'amber' => __('page_org_style_amber'),
                    'minimal' => __('page_org_style_minimal'),
                ],
                'default' => 'default',
            ],
            [
                'key' => 'layout', 'type' => 'select', 'label' => __('blox_org_layout'), 'tab' => 'style',
                'options' => [
                    'top' => __('blox_org_layout_top'),
                    'left' => __('blox_org_layout_left'),
                ],
                'default' => 'top',
            ],
            ['key' => 'compact', 'type' => 'checkbox', 'label' => __('blox_org_compact'), 'tab' => 'style', 'default' => false],
            [
                'key' => 'initial_depth', 'type' => 'number', 'label' => __('blox_org_initial_depth'),
                'tab' => 'style', 'min' => 1, 'max' => 8, 'default' => 4,
            ],
        ];
    }

    public function scripts(): array
    {
        return [
            '/assets/d3/d3.min.js',
            '/assets/d3-flextree/d3-flextree.min.js',
            '/assets/d3-org-chart/d3-org-chart.min.js',
            '/assets/js/blox-org-chart.js',
        ];
    }

    public function styles(): array
    {
        return ['/assets/css/blox-org-chart.css'];
    }

    /** @return list<array{id:string,parent_id:string,name:string,title:string}> */
    public static function seedNodes(): array
    {
        return [
            ['id' => 'org_root', 'parent_id' => '', 'name' => __('org_demo_ceo_name'), 'title' => __('org_demo_ceo_title')],
            ['id' => 'org_tech', 'parent_id' => 'org_root', 'name' => __('org_demo_vp1_name'), 'title' => __('org_demo_vp1_title')],
            ['id' => 'org_rd', 'parent_id' => 'org_tech', 'name' => __('org_demo_dept_rd'), 'title' => ''],
            ['id' => 'org_qa', 'parent_id' => 'org_tech', 'name' => __('org_demo_dept_qa'), 'title' => ''],
            ['id' => 'org_ops', 'parent_id' => 'org_tech', 'name' => __('org_demo_dept_ops'), 'title' => ''],
            ['id' => 'org_marketing', 'parent_id' => 'org_root', 'name' => __('org_demo_vp2_name'), 'title' => __('org_demo_vp2_title')],
            ['id' => 'org_mkt', 'parent_id' => 'org_marketing', 'name' => __('org_demo_dept_mkt'), 'title' => ''],
            ['id' => 'org_sales', 'parent_id' => 'org_marketing', 'name' => __('org_demo_dept_sales'), 'title' => ''],
            ['id' => 'org_support', 'parent_id' => 'org_marketing', 'name' => __('org_demo_dept_support'), 'title' => ''],
            ['id' => 'org_operation', 'parent_id' => 'org_root', 'name' => __('org_demo_vp3_name'), 'title' => __('org_demo_vp3_title')],
            ['id' => 'org_fin', 'parent_id' => 'org_operation', 'name' => __('org_demo_dept_fin'), 'title' => ''],
            ['id' => 'org_hr', 'parent_id' => 'org_operation', 'name' => __('org_demo_dept_hr'), 'title' => ''],
            ['id' => 'org_admin', 'parent_id' => 'org_operation', 'name' => __('org_demo_dept_admin'), 'title' => ''],
        ];
    }

    /**
     * 严格整理节点，避免重复 id、孤儿、环与超量数据进入前台运行时。
     * 保留输入顺序，因此编辑器中的同级排序就是前台排序。
     *
     * @return list<array{id:string,parent_id:string,name:string,title:string}>
     */
    public static function normalizeNodes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $nodes = [];
        $seen = [];
        foreach (array_slice($value, 0, self::MAX_NODES) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            if ($id === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1 || isset($seen[$id])) {
                $id = 'org_' . ($index + 1);
                while (isset($seen[$id])) {
                    $id .= '_x';
                }
            }
            $seen[$id] = true;
            $nodes[] = [
                'id' => $id,
                'parent_id' => trim((string) ($item['parent_id'] ?? '')),
                'name' => trim((string) ($item['name'] ?? '')),
                'title' => trim((string) ($item['title'] ?? '')),
            ];
        }
        if ($nodes === []) {
            return [];
        }

        $ids = array_fill_keys(array_column($nodes, 'id'), true);
        $accepted = [];
        $acceptedIds = [];
        foreach ($nodes as $index => $node) {
            $parentId = $node['parent_id'];
            if ($index === 0 || $parentId === '' || !isset($ids[$parentId]) || !isset($acceptedIds[$parentId])) {
                $node['parent_id'] = $index === 0 ? '' : $nodes[0]['id'];
            }
            if ($node['parent_id'] === $node['id']) {
                $node['parent_id'] = $index === 0 ? '' : $nodes[0]['id'];
            }
            $accepted[] = $node;
            $acceptedIds[$node['id']] = true;
        }
        return $accepted;
    }

    public function render(array $data, string $children = ''): string
    {
        unset($children);
        $nodes = self::normalizeNodes($data['nodes'] ?? []);
        if ($nodes === []) {
            return '';
        }

        $style = in_array(($data['style'] ?? 'default'), ['default', 'teal', 'dark', 'purple', 'amber', 'minimal'], true)
            ? (string) $data['style'] : 'default';
        $layout = ($data['layout'] ?? 'top') === 'left' ? 'left' : 'top';
        $initialDepth = max(1, min(8, (int) ($data['initial_depth'] ?? 4)));
        $payload = json_encode([
            'nodes' => $nodes,
            'layout' => $layout,
            'compact' => !empty($data['compact']),
            'initial_depth' => $initialDepth,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<div class="yk-org-chart yk-org-style-' . $style . '" data-blox-org-chart>'
            . '<div class="yk-org-chart-toolbar">'
            . '<button type="button" data-org-action="zoom-in" title="' . e(__('blox_org_zoom_in')) . '" aria-label="' . e(__('blox_org_zoom_in')) . '"><i class="ti ti-zoom-in"></i></button>'
            . '<button type="button" data-org-action="zoom-out" title="' . e(__('blox_org_zoom_out')) . '" aria-label="' . e(__('blox_org_zoom_out')) . '"><i class="ti ti-zoom-out"></i></button>'
            . '<button type="button" data-org-action="fit" title="' . e(__('blox_org_fit')) . '" aria-label="' . e(__('blox_org_fit')) . '"><i class="ti ti-focus-2"></i></button>'
            . '</div>'
            . '<div class="yk-org-chart-stage"></div>'
            . self::fallbackTree($nodes)
            . '<script type="application/json" data-org-chart-data>' . ($payload ?: '{}') . '</script>'
            . '</div>';
    }

    /**
     * 从旧编辑器的嵌套 org-chart HTML 中提取节点，并返回未被组织图占用的剩余正文。
     *
     * @return array{nodes:list<array{id:string,parent_id:string,name:string,title:string}>,style:string,remaining_html:string}|null
     */
    public static function extractLegacyHtml(string $html): ?array
    {
        if ($html === '' || !class_exists(DOMDocument::class) || stripos($html, 'org-chart') === false) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="yk-org-import">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $charts = $xpath->query('//*[@id="yk-org-import"]//*[contains(concat(" ", normalize-space(@class), " "), " org-chart ")]');
        $chart = $charts instanceof DOMNodeList ? $charts->item(0) : null;
        if (!$chart instanceof DOMElement) {
            return null;
        }

        $style = 'default';
        foreach (preg_split('/\s+/', trim($chart->getAttribute('class'))) ?: [] as $class) {
            if (preg_match('/^org-style-(teal|dark|purple|amber|minimal)$/', $class, $match) === 1) {
                $style = $match[1];
                break;
            }
        }

        $nodes = [];
        $counter = 0;
        $topList = self::directChild($chart, 'ul');
        if ($topList instanceof DOMElement) {
            foreach (self::directChildren($topList, 'li') as $rootItem) {
                self::readLegacyBranch($rootItem, '', $nodes, $counter);
            }
        }
        if ($nodes === []) {
            return null;
        }

        $chart->parentNode?->removeChild($chart);
        $wrapper = $document->getElementById('yk-org-import');
        $remaining = '';
        if ($wrapper instanceof DOMElement) {
            foreach ($wrapper->childNodes as $child) {
                $remaining .= (string) $document->saveHTML($child);
            }
        }

        return [
            'nodes' => self::normalizeNodes($nodes),
            'style' => $style,
            'remaining_html' => trim($remaining),
        ];
    }

    /** @param list<array{id:string,parent_id:string,name:string,title:string}> $nodes */
    private static function fallbackTree(array $nodes): string
    {
        $children = [];
        foreach ($nodes as $node) {
            $children[$node['parent_id']][] = $node;
        }
        $render = static function (string $parentId) use (&$render, $children): string {
            $items = $children[$parentId] ?? [];
            if ($items === []) {
                return '';
            }
            $html = '<ul>';
            foreach ($items as $node) {
                $html .= '<li><div class="yk-org-fallback-node"><strong>' . e($node['name']) . '</strong>';
                if ($node['title'] !== '') {
                    $html .= '<span>' . e($node['title']) . '</span>';
                }
                $html .= '</div>' . $render($node['id']) . '</li>';
            }
            return $html . '</ul>';
        };
        return '<div class="yk-org-chart-fallback">' . $render('') . '</div>';
    }

    private static function directChild(DOMElement $parent, string $tag): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === $tag) {
                return $child;
            }
        }
        return null;
    }

    /** @return list<DOMElement> */
    private static function directChildren(DOMElement $parent, string $tag): array
    {
        $out = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === $tag) {
                $out[] = $child;
            }
        }
        return $out;
    }

    /** @param list<array{id:string,parent_id:string,name:string,title:string}> $nodes */
    private static function readLegacyBranch(DOMElement $item, string $parentId, array &$nodes, int &$counter): void
    {
        $nodeElement = null;
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement && str_contains(' ' . $child->getAttribute('class') . ' ', ' org-node ')) {
                $nodeElement = $child;
                break;
            }
        }
        if (!$nodeElement instanceof DOMElement) {
            return;
        }

        $title = '';
        foreach ($nodeElement->getElementsByTagName('span') as $span) {
            if ($span instanceof DOMElement && str_contains(' ' . $span->getAttribute('class') . ' ', ' org-title ')) {
                $title = trim($span->textContent);
                break;
            }
        }
        $copy = $nodeElement->cloneNode(true);
        if ($copy instanceof DOMElement) {
            foreach (iterator_to_array($copy->getElementsByTagName('span')) as $span) {
                $span->parentNode?->removeChild($span);
            }
        }
        $name = trim($copy instanceof DOMElement ? $copy->textContent : $nodeElement->textContent);
        $id = 'org_' . (++$counter);
        $nodes[] = ['id' => $id, 'parent_id' => $parentId, 'name' => $name, 'title' => $title];

        $childList = self::directChild($item, 'ul');
        if ($childList instanceof DOMElement) {
            foreach (self::directChildren($childList, 'li') as $childItem) {
                self::readLegacyBranch($childItem, $id, $nodes, $counter);
            }
        }
    }
}
