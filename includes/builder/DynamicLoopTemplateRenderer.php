<?php

declare(strict_types=1);

/** 将受控 Blox 子元素转换为 {yk:field} 循环模板。 */
final class DynamicLoopTemplateRenderer
{
    private const ALLOWED_TYPES = ['heading', 'text', 'image', 'button', 'div'];

    /** @param array<string,mixed> $listData @param array<string,mixed> $context */
    public static function render(array $children, array $listData, array $context = []): string
    {
        $source = DynamicListItemSchema::sourceKind($listData);
        $editMode = ($context['edit_mode'] ?? false) === true;
        $depth = max(0, (int) ($context['depth'] ?? 0)) + 1;
        $parentPath = is_array($context['path'] ?? null) ? $context['path'] : [];
        $out = '';

        foreach (array_values($children) as $index => $child) {
            if (!is_array($child) || !in_array((string) ($child['type'] ?? ''), self::ALLOWED_TYPES, true)) {
                continue;
            }
            $node = self::bindNode($child, $source);
            $path = $parentPath;
            $path[] = $index;
            $out .= BlockRenderer::renderElementNode($node, $depth, $editMode, $path);
        }

        return '<div class="yk-query-item">' . $out . '</div>';
    }

    /** @return list<string> */
    public static function allowedTypes(): array
    {
        return self::ALLOWED_TYPES;
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private static function bindNode(array $node, string $source): array
    {
        $type = (string) ($node['type'] ?? '');
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        unset($data['children']);

        if ($type === 'heading') {
            $field = self::field($data, 'loop_field', 'title', $source);
            if ($field !== 'none') {
                $data['text'] = self::tag($field);
            }
        } elseif ($type === 'text') {
            $field = self::field($data, 'loop_field', 'summary', $source);
            if ($field !== 'none') {
                $length = max(20, min(300, (int) ($data['loop_length'] ?? 80)));
                $data['html'] = '<p>' . self::tag($field, ' len=' . $length) . '</p>';
            }
        } elseif ($type === 'image') {
            $field = self::field($data, 'loop_field', 'image', $source);
            if ($field !== 'none') {
                $data['src'] = self::tag($field);
            }
            $alt = self::field($data, 'loop_alt_field', 'title', $source);
            if ($alt !== 'none') {
                $data['alt'] = self::tag($alt);
            }
            $link = self::field($data, 'loop_link_field', 'link', $source);
            if ($link !== 'none') {
                $data['click_action'] = 'link';
                $data['link_url'] = self::tag($link);
            }
        } elseif ($type === 'button') {
            $text = self::field($data, 'loop_text_field', 'title', $source);
            if ($text !== 'none') {
                $data['text'] = self::tag($text);
            }
            $url = self::field($data, 'loop_url_field', 'link', $source);
            if ($url !== 'none') {
                $data['url'] = self::tag($url);
            }
        }

        $node['data'] = $data;
        return $node;
    }

    /** @param array<string,mixed> $data */
    private static function field(array $data, string $key, string $slot, string $source): string
    {
        $options = DynamicListItemSchema::fieldOptions($slot, $source);
        $value = (string) ($data[$key] ?? 'none');
        return array_key_exists($value, $options) ? $value : 'none';
    }

    private static function tag(string $field, string $attrs = ''): string
    {
        return '{yk:field name=' . $field . $attrs . ' /}';
    }
}