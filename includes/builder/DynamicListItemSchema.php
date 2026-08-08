<?php

declare(strict_types=1);

/** 动态列表条目的受控字段槽位与本地视图预设。 */
final class DynamicListItemSchema
{
    private const PRESETS = ['card', 'media', 'minimal'];

    /** @var array<string, array<string, list<string>>> */
    private const FIELDS_BY_SOURCE = [
        'content' => [
            'image' => ['cover', 'none'],
            'title' => ['title', 'subtitle'],
            'summary' => ['summary', 'subtitle'],
            'date' => ['date', 'publish_time', 'created_at', 'updated_at'],
            'link' => ['url', 'none'],
        ],
        'product' => [
            'image' => ['cover', 'none'],
            'title' => ['title', 'model'],
            'summary' => ['summary', 'subtitle'],
            'date' => ['date', 'created_at', 'updated_at'],
            'link' => ['url', 'none'],
            'meta' => ['model', 'price', 'market_price', 'none'],
        ],
    ];

    /** @return array<string,string> */
    public static function presetOptions(): array
    {
        return [
            'card' => __('blox_dynamic_preset_card'),
            'media' => __('blox_dynamic_preset_media'),
            'minimal' => __('blox_dynamic_preset_minimal'),
        ];
    }

    /** @return array<string,string> */
    public static function fieldOptions(string $slot, string $source = 'content'): array
    {
        $labels = [
            'cover' => __('blox_dynamic_field_cover'),
            'title' => __('blox_dynamic_field_title'),
            'subtitle' => __('blox_dynamic_field_subtitle'),
            'summary' => __('blox_dynamic_field_summary'),
            'date' => __('blox_dynamic_field_date'),
            'publish_time' => __('blox_dynamic_field_publish_time'),
            'created_at' => __('blox_dynamic_field_created_at'),
            'updated_at' => __('blox_dynamic_field_updated_at'),
            'url' => __('blox_dynamic_field_url'),
            'model' => __('blox_dynamic_field_model'),
            'price' => __('blox_dynamic_field_price'),
            'market_price' => __('blox_dynamic_field_market_price'),
            'none' => __('blox_dynamic_field_none'),
        ];

        $source = $source === 'product' ? 'product' : 'content';
        $out = [];
        foreach (self::FIELDS_BY_SOURCE[$source][$slot] ?? [] as $field) {
            $out[$field] = $labels[$field];
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function sourceKind(array $data): string
    {
        $querySource = trim((string) ($data['query_source'] ?? ''));
        if ($querySource === 'type:product') {
            return 'product';
        }
        if ($querySource !== '') {
            return 'content';
        }
        return strtolower(trim((string) ($data['source_type'] ?? ''))) === 'product' ? 'product' : 'content';
    }

    /** @param array<string,mixed> $data */
    public static function render(array $data): string
    {
        $source = self::sourceKind($data);
        $preset = self::allowed((string) ($data['item_preset'] ?? 'card'), self::PRESETS, 'card');
        $imageField = self::field($data, $source, 'image', 'cover');
        $titleField = self::field($data, $source, 'title', 'title');
        $summaryField = self::field($data, $source, 'summary', 'summary');
        $dateField = self::field($data, $source, 'date', 'date');
        $linkField = self::field($data, $source, 'link', 'url');
        $metaField = self::field($data, $source, 'meta', 'model');
        $showImage = (bool) ($data['show_image'] ?? true) && $imageField !== 'none';
        $showTitle = (bool) ($data['show_title'] ?? true);
        $showSummary = (bool) ($data['show_summary'] ?? true);
        $showDate = (bool) ($data['show_date'] ?? false);
        $showMeta = $source === 'product' && (bool) ($data['show_meta'] ?? false) && $metaField !== 'none';
        $summaryLen = max(20, min(300, (int) ($data['summary_len'] ?? 80)));
        $ratio = [
            'wide' => 'aspect-video',
            'landscape' => 'aspect-[4/3]',
            'square' => 'aspect-square',
        ][(string) ($data['image_ratio'] ?? 'wide')] ?? 'aspect-video';

        $titleTag = self::tag($titleField);
        $image = $showImage
            ? '<img src="' . self::tag($imageField) . '" alt="' . $titleTag . '" loading="lazy" class="w-full h-full object-cover">'
            : '';
        $title = $showTitle
            ? '<h3 class="text-lg font-semibold mb-2 group-hover:text-primary transition">' . $titleTag . '</h3>'
            : '';
        $date = $showDate
            ? '<div class="text-xs text-gray-400 mb-2">' . self::tag($dateField, ' dateformat="Y-m-d"') . '</div>'
            : '';
        $meta = $showMeta ? self::meta($metaField) : '';
        $summary = $showSummary
            ? '<p class="text-sm text-gray-500">' . self::tag($summaryField, ' len=' . $summaryLen) . '</p>'
            : '';

        return match ($preset) {
            'media' => self::media($image, $title, $date, $meta, $summary, $ratio, $linkField),
            'minimal' => self::minimal($image, $title, $date, $meta, $summary, $ratio, $linkField),
            default => self::card($image, $title, $date, $meta, $summary, $ratio, $linkField),
        };
    }

    private static function card(string $image, string $title, string $date, string $meta, string $summary, string $ratio, string $linkField): string
    {
        $inner = $image !== '' ? '<div class="' . $ratio . ' overflow-hidden bg-gray-100">' . $image . '</div>' : '';
        $body = $title . $date . $meta . $summary;
        if ($body !== '') {
            $inner .= '<div class="p-4">' . $body . '</div>';
        }
        return self::wrapper($inner, 'group block bg-white rounded-lg border border-gray-100 shadow-sm hover:shadow-md transition overflow-hidden no-underline', $linkField);
    }

    private static function media(string $image, string $title, string $date, string $meta, string $summary, string $ratio, string $linkField): string
    {
        $inner = $image !== ''
            ? '<div class="sm:w-36 shrink-0 ' . $ratio . ' sm:aspect-auto overflow-hidden bg-gray-100">' . $image . '</div>'
            : '';
        $body = $date . $title . $meta . $summary;
        if ($body !== '') {
            $inner .= '<div class="p-4 flex-1 min-w-0">' . $body . '</div>';
        }
        return self::wrapper($inner, 'group flex flex-col sm:flex-row bg-white rounded-lg border border-gray-100 shadow-sm hover:shadow-md transition overflow-hidden no-underline', $linkField);
    }

    private static function minimal(string $image, string $title, string $date, string $meta, string $summary, string $ratio, string $linkField): string
    {
        $inner = $image !== ''
            ? '<div class="w-24 shrink-0 ' . $ratio . ' overflow-hidden rounded-md bg-gray-100">' . $image . '</div>'
            : '';
        $body = $date . $title . $meta . $summary;
        if ($body !== '') {
            $inner .= '<div class="flex-1 min-w-0">' . $body . '</div>';
        }
        return self::wrapper($inner, 'group flex items-center gap-4 py-4 border-b border-gray-200 no-underline', $linkField);
    }

    private static function meta(string $field): string
    {
        if ($field === 'price' || $field === 'market_price') {
            return '{yk:if field=' . $field . ' op=gt value=0}'
                . '<div class="text-sm font-semibold text-primary mb-2">' . e(__('currency_symbol')) . self::tag($field) . '</div>'
                . '{/yk:if}';
        }
        return '{yk:if field=model op=notempty}'
            . '<div class="text-xs text-gray-400 mb-2">' . self::tag('model') . '</div>'
            . '{/yk:if}';
    }

    private static function wrapper(string $inner, string $classes, string $linkField): string
    {
        if ($linkField === 'none') {
            return '<div class="' . $classes . '">' . $inner . '</div>';
        }
        return '<a href="' . self::tag($linkField) . '" class="' . $classes . '">' . $inner . '</a>';
    }

    /** @param array<string,mixed> $data */
    private static function field(array $data, string $source, string $slot, string $default): string
    {
        $value = (string) ($data[$slot . '_field'] ?? $default);
        return self::allowed($value, self::FIELDS_BY_SOURCE[$source][$slot] ?? [], $default);
    }

    /** @param list<string> $allowed */
    private static function allowed(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private static function tag(string $field, string $attrs = ''): string
    {
        return '{yk:field name=' . $field . $attrs . ' /}';
    }
}