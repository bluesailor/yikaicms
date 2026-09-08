<?php

declare(strict_types=1);

final class HomeAboutContent
{
    /**
     * Snapshot a legacy reference as ordinary, independently editable Blox nodes.
     * Empty overrides retain their existing meaning: inherit the site value.
     * @param array<string,mixed> $block
     * @param array<string,mixed>|null $aboutChannel
     * @return array<string,mixed>
     */
    public static function toSection(array $block = [], string $id = 'about', ?array $aboutChannel = null): array
    {
        $values = self::resolve($aboutChannel);
        $inherited = $values;
        foreach ($values as $key => $fallback) {
            $override = trim((string) ($block[$key] ?? ''));
            $values[$key] = $override !== '' ? $override : $fallback;
        }
        $option = static function (string $key, string $fallback) use ($block): string {
            $override = trim((string) ($block['override_' . $key] ?? ''));
            return $override !== '' ? $override : (string) config('home_about_' . $key, $fallback);
        };
        $spans = match ($option('ratio', '1_1')) {
            '5_7' => [5, 7], '7_5' => [7, 5], '1_2' => [4, 8], '2_1' => [8, 4],
            default => [6, 6],
        };
        $node = static fn(string $suffix, string $type, array $data): array => [
            'id' => $id . '_' . $suffix, 'type' => $type, 'data' => $data,
        ];
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $light = !empty($block['text_light']);
        $text = [
            $node('title', 'heading', ['text' => $values['override_title'], 'level' => 'h2',
                'visual_size' => ['d' => '3xl', 'm' => '2xl'], 'align' => 'left', 'color' => $light ? '#ffffff' : '']),
            $node('divider', 'divider', ['style' => 'solid', 'width' => 3, 'color' => '#2563eb',
                'spacing' => 'sm', 'style_margin_right' => '88%']),
            $node('body', 'text', ['html' => '<p class="text-lg leading-relaxed' . ($light ? ' text-white' : '') . '">'
                . $escape($values['override_content']) . '</p>', 'style_margin_top' => '24px', 'style_margin_bottom' => '24px']),
        ];
        if ($values['override_button_url'] !== '') {
            $text[] = $node('button', 'button', ['text' => $values['override_button_text'],
                'url' => $values['override_button_url'], 'variant' => 'primary', 'shape' => 'pill', 'align' => 'left']);
        }
        $image = [$node('image', 'image', ['src' => $values['override_image'], 'alt' => $values['override_title']])];
        if ($values['override_tag_title'] !== '' || $values['override_tag_description'] !== '') {
            // Keep the badge in a single ordinary rich-text child: the editor supports one child level.
            $badgeHtml = '<div class="bg-primary text-white rounded-lg p-6">';
            if ($values['override_tag_title'] !== '') {
                $badgeHtml .= '<h3 class="text-xl font-bold text-white m-0">' . $escape($values['override_tag_title']) . '</h3>';
            }
            if ($values['override_tag_description'] !== '') {
                $badgeHtml .= '<p class="text-white m-0">' . $escape($values['override_tag_description']) . '</p>';
            }
            $badge = $node('caption', 'text', ['html' => $badgeHtml . '</div>']);
            $image = [$node('image_badge', 'div', ['display' => 'overlay', 'radius' => 'xl',
                'children' => [$image[0], $badge]])];
        }
        $columns = [
            ['id' => $id . '_text', 'span' => $spans[0], 'elements' => $text],
            ['id' => $id . '_visual', 'span' => $spans[1], 'elements' => $image],
        ];
        if ($option('layout', 'text_left') === 'image_left') {
            $columns = array_reverse($columns);
        }
        $settings = ['padding' => ['d' => 'xl', 'm' => 'lg'], 'max_width' => ($block['layout'] ?? '') === 'full' ? 'full' : 'wide',
            'gap' => 'xl', 'container_gutter' => 'default', 'align_items' => 'center', 'tablet_stack' => $option('breakpoint', 'lg') !== 'md'];
        foreach (['bg_color', 'bg_image', 'bg_opacity', 'bg_overlay_color', 'bg_overlay_opacity'] as $key) {
            if (array_key_exists($key, $block)) {
                $settings[$key] = $block[$key];
            }
        }
        if (isset($block['enabled']) && !$block['enabled']) {
            $settings['hidden'] = true;
        }
        return HomeAboutLocalization::bind(['id' => $id, 'type' => 'section', 'name' => __('blox_hb_about'),
            'settings' => $settings, 'columns' => $columns], $inherited);
    }

    /**
     * Resolve the same values for the theme, editor hints and legacy import.
     * Runtime block overrides are already applied by HomeBloxRenderContext.
     * @param array<string, mixed>|null $aboutChannel
     * @return array<string, string>
     */
    public static function resolve(?array $aboutChannel = null): array
    {
        $title = trim((string) (configJsonLang('home_about_title') ?: config('home_about_title', '')));
        $link = (string) config('home_about_link_' . siteLang(), '');
        $link = $link !== '' ? $link : (string) config('home_about_link', '');
        $button = (string) config('home_about_button_' . siteLang(), '');
        $button = $button !== '' ? $button : (string) config('home_about_button', '');
        return [
            'override_title' => $title !== '' ? $title : homeAboutDefaultTitle(),
            'override_content' => configLang('home_about_content', 'home_about_default'),
            'override_image' => (string) config('home_about_image', '/assets/images/demo/about-office.jpg'),
            'override_tag_title' => (string) (configJsonLang('home_about_tag_title') ?: config('home_about_tag_title', '')),
            'override_tag_description' => (string) (configJsonLang('home_about_tag_desc') ?: config('home_about_tag_desc', '')),
            'override_button_text' => $button !== '' ? $button : __('home_learn_more'),
            'override_button_url' => $aboutChannel ? ($link ?: channelUrl($aboutChannel)) : $link,
        ];
    }
}
