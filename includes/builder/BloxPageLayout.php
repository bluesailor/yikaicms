<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/ThemeSettings.php';

/** 单页外框覆盖沿用文档 settings；缺键继承，null 仅表示可清空的背景。 */
final class BloxPageLayout
{
    public static function fields(): array
    {
        $general = ThemeSettings::generalFields();
        return [
            'page_header_hidden' => ['type' => 'bool', 'label' => 'layout_hide_header', 'global' => ['general', 'page_header_hidden']],
            'page_footer_hidden' => ['type' => 'bool', 'label' => 'layout_hide_footer', 'global' => ['general', 'page_footer_hidden']],
            'page_content_max_width' => $general['content_max_width'] + ['global' => ['general', 'content_max_width']],
            'page_content_gutter' => array_replace($general['page_content_gutter'], ['label' => 'layout_page_gutter', 'global' => ['general', 'page_content_gutter']]),
            'page_content_background' => ['type' => 'color', 'clear' => true, 'label' => 'theme_settings_content_background', 'global' => ['general', 'content_background']],
        ];
    }

    /** 新布局字段严格校验；旧 header/footer 的宽容归一继续留在原管线。 */
    public static function normalize(array $settings): array
    {
        $clean = [];
        foreach (self::fields() as $key => $field) {
            if ($field['type'] === 'bool' || !array_key_exists($key, $settings)) continue;
            $value = $settings[$key];
            if ($value === null && ($field['clear'] ?? false)) {
                $clean[$key] = null;
                continue;
            }
            $valid = $field['type'] === 'color'
                ? is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1
                : is_int($value) && $value >= $field['min'] && $value <= $field['max'];
            if (!$valid) throw new RuntimeException(__('layout_invalid'));
            $clean[$key] = is_string($value) ? strtoupper($value) : $value;
        }
        return $clean;
    }

    /** 只接收调用方已选定的文档，不按裸 ID 查库或缓存，避免类型/语言串页。 */
    public static function resolve(array $settings, array $context, ?array $theme = null): array
    {
        if (($context['type'] ?? '') !== 'page' || (int) ($context['id'] ?? 0) <= 0 || !is_string($context['lang'] ?? null) || $context['lang'] === '') {
            throw new InvalidArgumentException('Invalid page layout context');
        }
        $theme = $theme ?? ThemeSettings::read();
        $local = self::normalize($settings);
        $values = $sources = [];
        foreach (self::fields() as $key => $field) {
            $present = array_key_exists($key, $settings);
            $value = $present ? ($field['type'] === 'bool' ? in_array($settings[$key], [true, 1, '1'], true) : $local[$key])
                : $theme[$field['global'][0]][$field['global'][1]];
            if ($field['type'] === 'bool') $value = in_array($value, [true, 1, '1'], true);
            $values[$key] = $value;
            $sources[$key] = !$present ? 'inherit' : ($value === null ? 'clear' : 'set');
        }
        return ['context' => $context, 'values' => $values, 'sources' => $sources];
    }

    public static function activate(array $settings, array $page): array
    {
        $resolved = self::resolve($settings, ['type' => 'page', 'id' => (int) $page['id'], 'lang' => (string) ($page['lang'] ?? siteLang())]);
        $GLOBALS['ykPageLayout'] = $resolved;
        return array_replace($settings, $resolved['values']);
    }

    public static function css(array $resolved): string
    {
        // 值只来自可信字段定义；不接受文档提供选择器、属性名或 CSS 表达式。
        $values = $resolved['values'];
        $sources = $resolved['sources'];
        $css = '';
        if ($sources['page_content_max_width'] !== 'inherit') {
            $css .= '.yk-site-body main{width:100%;max-width:' . (int) $values['page_content_max_width'] . 'px;margin-inline:auto;}';
        }
        if ($sources['page_content_gutter'] !== 'inherit' || $values['page_content_gutter'] > 0) {
            $css .= '.yk-site-body main{padding-inline:' . (int) $values['page_content_gutter'] . 'px;}';
        }
        if ($sources['page_content_background'] !== 'inherit') {
            $css .= '.yk-site-body main{background-color:' . ($values['page_content_background'] === null ? 'transparent' : $values['page_content_background']) . ';}';
        }
        return $css;
    }

    public static function renderHead(): void
    {
        if (isset($GLOBALS['ykPageLayout'])) echo '<style data-yk-page-layout>' . self::css($GLOBALS['ykPageLayout']) . '</style>';
    }
}
