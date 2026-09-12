<?php
/** Optional page title and breadcrumb, rendered inside the Blox document. */
declare(strict_types=1);

final class PageTitleElement extends AbstractElement
{
    /** @var array<string,mixed>|null */
    private static ?array $page = null;

    public function type(): string { return 'page-title'; }
    public function label(): string { return __('blox_el_page_title'); }
    public function icon(): string { return 'text-caption'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'page'; }
    public function backgroundRenderStrategy(): string { return 'native'; }
    public function treeLabelField(): ?string { return 'title'; }

    /** @param array<string,mixed> $page @param callable():string $render */
    public static function withPage(array $page, callable $render): string
    {
        $previous = self::$page;
        self::$page = $page;
        try {
            return $render();
        } finally {
            self::$page = $previous;
        }
    }

    public function controls(): array
    {
        return [
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_field_title_short'), 'default' => '', 'placeholder' => __('blox_page_title_follow')],
            ['key' => 'description', 'type' => 'textarea', 'label' => __('blox_ctl_subtext'), 'default' => '', 'placeholder' => __('blox_page_title_follow')],
            ['key' => 'show_description', 'type' => 'checkbox', 'label' => __('blox_page_title_description'), 'default' => true],
            ['key' => 'show_breadcrumb', 'type' => 'checkbox', 'label' => __('blox_page_frame_show_breadcrumb'), 'default' => true],
            ['key' => 'level', 'type' => 'select', 'label' => __('blox_ctl_level'), 'default' => 'h1', 'options' => ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3']],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'default' => 'left', 'tab' => 'style',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')]],
            ['key' => 'size', 'type' => 'select', 'label' => __('blox_font_size'), 'default' => 'md', 'responsive' => true, 'tab' => 'style',
                'options' => ['sm' => '24px', 'md' => '32px', 'lg' => '40px']],
            ['key' => 'color', 'type' => 'color', 'label' => __('blox_text_color'), 'default' => '', 'tab' => 'style'],
            ...$this->backgroundControls(),
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $page = self::$page ?? [];
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') $title = (string) ($page['name'] ?? __('blox_el_page_title'));
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') $description = (string) ($page['description'] ?? '');
        $level = in_array($data['level'] ?? '', ['h1', 'h2', 'h3'], true) ? $data['level'] : 'h1';
        $align = in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'left';
        $alignment = ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right'][$align];
        $justify = ['left' => 'justify-start', 'center' => 'justify-center', 'right' => 'justify-end'][$align];
        $size = $this->resp($data['size'] ?? 'md', [
            'sm' => ['text-2xl', 'md:text-2xl', 'lg:text-2xl'],
            'md' => ['text-[32px]', 'md:text-[32px]', 'lg:text-[32px]'],
            'lg' => ['text-[40px]', 'md:text-[40px]', 'lg:text-[40px]'],
        ], 'md');
        $style = self::backgroundDeclarations($data);
        $color = self::cssColor($data['color'] ?? null);
        if ($color !== null) $style .= ';color:' . $color;
        $html = '<div class="blox-page-title ' . $alignment . ' ' . $size . '" style="' . self::escape($style) . '"' . $this->animationAttrs($data) . '>';
        if (($data['show_breadcrumb'] ?? true) && $page !== []) {
            $home = function_exists('langUrl') ? langUrl('/', (string) ($page['lang'] ?? '')) : '/';
            $html .= '<nav aria-label="' . self::escape(__('breadcrumb_nav')) . '"><ol class="flex flex-wrap items-center gap-2 mb-4 text-sm ' . $justify . '">'
                . '<li><a class="underline hover:no-underline" href="' . self::escape(self::safeHref($home)) . '">' . self::escape(__('breadcrumb_home')) . '</a></li>';
            $parents = [];
            $seen = [(int) ($page['id'] ?? 0) => true];
            $parentId = (int) ($page['parent_id'] ?? 0);
            while ($parentId > 0 && !isset($seen[$parentId]) && count($parents) < 20) {
                $seen[$parentId] = true;
                $parent = channelModel()->find($parentId);
                if (!$parent) break;
                array_unshift($parents, $parent);
                $parentId = (int) ($parent['parent_id'] ?? 0);
            }
            foreach ($parents as $parent) {
                $url = function_exists('channelUrl') ? channelUrl($parent) : '';
                $html .= '<li aria-hidden="true">/</li><li><a class="underline hover:no-underline" href="' . self::escape(self::safeHref($url) ?: '#') . '">' . self::escape((string) $parent['name']) . '</a></li>';
            }
            $html .= '<li aria-hidden="true">/</li><li aria-current="page">' . self::escape((string) ($page['name'] ?? $title)) . '</li></ol></nav>';
        }
        $html .= '<' . $level . ' class="font-bold leading-tight break-words">' . self::escape($title) . '</' . $level . '>';
        if (($data['show_description'] ?? true) && $description !== '') {
            $html .= '<p class="mt-4 text-base leading-relaxed break-words">' . nl2br(self::escape($description)) . '</p>';
        }
        return $html . '</div>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
