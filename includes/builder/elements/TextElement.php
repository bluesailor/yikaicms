<?php
/** 文本（富文本）元素。对齐旧 case 'text'。 */

declare(strict_types=1);

final class TextElement extends AbstractElement
{
    public function type(): string { return 'text'; }
    public function label(): string { return __('blox_el_text'); }
    public function icon(): string { return 'align-left'; }

    // richtext 由构建器 wangEditor 弹窗接管（hasCustomUI），此处仅供默认值 / 元数据
    public function controls(): array
    {
        return [
            ['key' => 'html', 'type' => 'richtext', 'label' => __('blox_ctl_body'), 'default' => ''],
            [
                'key' => 'site_field', 'type' => 'select', 'label' => __('blox_dynamic_site_binding'),
                'default' => 'none', 'options' => DynamicSiteData::fieldOptions('text'),
                'outside_loop_only' => true, 'advanced' => true,
            ],
            [
                'key' => 'site_fallback', 'type' => 'text', 'label' => __('blox_dynamic_fallback'),
                'default' => '', 'outside_loop_only' => true, 'advanced' => true,
                'required' => ['site_field', '!=', 'none'],
            ],
            [
                'key' => 'loop_field', 'type' => 'select', 'label' => __('blox_loop_text_binding'),
                'default' => 'summary', 'loop_only' => true,
                'options' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('summary', 'content'),
                'source_options' => [
                    'content' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('summary', 'content'),
                    'product' => ['none' => __('blox_dynamic_field_none')] + DynamicListItemSchema::fieldOptions('summary', 'product'),
                ],
            ],
            [
                'key' => 'loop_length', 'type' => 'number', 'label' => __('blox_loop_text_length'),
                'default' => 80, 'min' => 20, 'max' => 300, 'loop_only' => true,
                'required' => ['loop_field', '!=', 'none'],
            ],
            [
                'key' => 'loop_fallback', 'type' => 'text', 'label' => __('blox_dynamic_fallback'),
                'default' => '', 'loop_only' => true, 'advanced' => true,
                'required' => ['loop_field', '!=', 'none'],
            ],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $html = (string) ($data['html'] ?? '');
        // 渲染层第二道净化：保存管线已对 richtext 过 sanitizeHtml，这里再兜
        // 历史脏数据 / 手工写库 / 插件与导入绕过保存层的情况（存储型 XSS）。
        // 引擎自包含的裸环境（无 functions.php）没有 sanitizeHtml，原样输出。
        if (function_exists('sanitizeHtml')) {
            $html = sanitizeHtml($html);
        }
        $siteField = (string) ($data['site_field'] ?? 'none');
        if ($siteField !== 'none') {
            $value = DynamicSiteData::value($siteField, 'text', (string) ($data['site_fallback'] ?? ''));
            $html = '<p>' . e($value) . '</p>';
        }
        return '<div class="prose prose-lg max-w-none"' . $this->animationAttrs($data) . '>' . $html . '</div>';
    }
}
