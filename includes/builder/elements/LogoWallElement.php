<?php
/** 合作伙伴 Logo 墙：网格或无缝滚动；Logo 可链接到伙伴官网，默认灰度、悬停恢复彩色。 */

declare(strict_types=1);

final class LogoWallElement extends AbstractElement
{
    public const MAX_ITEMS = 24;

    public function type(): string { return 'logo-wall'; }
    public function label(): string { return __('blox_el_logo_wall'); }
    public function icon(): string { return 'building-community'; }
    public function category(): string { return 'advanced'; }
    public function styles(): array { return ['/assets/css/blox-logo-wall.css']; }

    public function controls(): array
    {
        return [
            [
                'key' => 'items', 'type' => 'items_repeater', 'label' => __('blox_logo_wall_items'),
                'max' => self::MAX_ITEMS, 'title_field' => 'name', 'thumb_field' => 'logo',
                'item_label' => __('blox_logo_wall_item'),
                'fields' => [
                    ['key' => 'logo', 'type' => 'image', 'label' => __('blox_logo_wall_logo')],
                    ['key' => 'name', 'type' => 'text', 'label' => __('blox_logo_wall_name')],
                    ['key' => 'url', 'type' => 'text', 'label' => __('blox_logo_wall_url'), 'placeholder' => 'https://'],
                ],
                'default' => self::seedItems(),
            ],
            ['key' => 'layout', 'type' => 'select', 'label' => __('blox_logo_wall_layout'), 'default' => 'grid',
                'options' => ['grid' => __('blox_logo_wall_layout_grid'), 'marquee' => __('blox_logo_wall_layout_marquee')]],
            ['key' => 'columns', 'type' => 'select', 'label' => __('blox_dynamic_columns'), 'default' => '6',
                'options' => ['3' => '3', '4' => '4', '5' => '5', '6' => '6'], 'required' => ['layout', '=', 'grid']],
            ['key' => 'logo_height', 'type' => 'select', 'label' => __('blox_logo_wall_height'), 'tab' => 'style', 'default' => 'md',
                'options' => ['sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg')]],
            ['key' => 'grayscale', 'type' => 'checkbox', 'label' => __('blox_logo_wall_grayscale'), 'tab' => 'style', 'default' => true],
            ['key' => 'tiles', 'type' => 'checkbox', 'label' => __('blox_logo_wall_tiles'), 'tab' => 'style', 'default' => true],
        ];
    }

    /** @return list<array{logo:string,name:string,url:string}> */
    public static function seedItems(): array
    {
        $items = [];
        foreach ([1, 2, 3, 4, 5, 6] as $n) {
            $items[] = ['logo' => '/assets/images/blox-templates/partner-' . $n . '.svg', 'name' => __('blox_logo_wall_seed_' . $n), 'url' => ''];
        }
        return $items;
    }

    /** @return list<array{logo:string,name:string,url:string}> */
    public static function normalizeItems(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $items = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_ITEMS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = is_scalar($item['name'] ?? null) ? mb_substr(trim((string) $item['name']), 0, 60) : '';
            $logo = UrlPolicy::image($item['logo'] ?? '');
            if ($logo === '' && $name === '') {
                continue;
            }
            $items[] = ['logo' => $logo, 'name' => $name, 'url' => UrlPolicy::href($item['url'] ?? '', false)];
        }
        return $items;
    }

    public function render(array $data, string $children = ''): string
    {
        $items = self::normalizeItems(array_key_exists('items', $data) ? $data['items'] : self::seedItems());
        if ($items === []) {
            return '';
        }
        $marquee = ($data['layout'] ?? 'grid') === 'marquee';
        $columnsValue = (string) ($data['columns'] ?? '6');
        $columns = in_array($columnsValue, ['3', '4', '5', '6'], true) ? (int) $columnsValue : 6;
        $height = ['sm' => 40, 'md' => 56, 'lg' => 72][(string) ($data['logo_height'] ?? 'md')] ?? 56;
        $classes = 'yk-logo-wall yk-logo-wall--' . ($marquee ? 'marquee' : 'grid');
        if (self::enabled($data, 'grayscale', true)) {
            $classes .= ' yk-logo-wall--grayscale';
        }
        if (self::enabled($data, 'tiles', true)) {
            $classes .= ' yk-logo-wall--tiles';
        }
        $html = '<div class="' . $classes . '" style="--yk-logo-cols:' . $columns . ';--yk-logo-h:' . $height . 'px">';
        $cells = '';
        foreach ($items as $item) {
            $cells .= self::cell($item);
        }
        if ($marquee) {
            // 无缝滚动：列表复制一份接在后面（副本对读屏隐藏），动画平移一半宽度后回到起点
            return $html . '<div class="yk-logo-marquee"><ul class="yk-logo-track">' . $cells . '</ul>'
                . '<ul class="yk-logo-track" aria-hidden="true">' . str_replace('<a ', '<a tabindex="-1" ', $cells) . '</ul></div></div>';
        }
        return $html . '<ul class="yk-logo-grid">' . $cells . '</ul></div>';
    }

    /** @param array{logo:string,name:string,url:string} $item */
    private static function cell(array $item): string
    {
        $name = htmlspecialchars($item['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inner = $item['logo'] !== ''
            ? '<img class="yk-logo-img" src="' . htmlspecialchars($item['logo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt="' . $name . '" loading="lazy" decoding="async">'
            : '<span class="yk-logo-name">' . $name . '</span>';
        if ($item['url'] !== '') {
            $external = preg_match('#^https?://#i', $item['url']) === 1;
            $inner = '<a class="yk-logo-link" href="' . htmlspecialchars($item['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ($external ? ' target="_blank" rel="noopener"' : '') . ($item['name'] !== '' ? ' title="' . $name . '"' : '') . '>' . $inner . '</a>';
        }
        return '<li class="yk-logo-item">' . $inner . '</li>';
    }

    private static function enabled(array $data, string $key, bool $default): bool
    {
        return array_key_exists($key, $data) ? !in_array($data[$key], [false, 0, '0', '', null], true) : $default;
    }
}
