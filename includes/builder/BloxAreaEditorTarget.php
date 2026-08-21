<?php
/** 根据前台实际渲染来源，解析 Header/Footer 的编辑器入口。 */

declare(strict_types=1);

final class BloxAreaEditorTarget
{
    /** @param array<string,mixed> $template */
    public static function isThemeFallbackTemplate(array $template, string $area): bool
    {
        $sourceRef = self::defaultThemeSourceRef($area);
        return $sourceRef !== ''
            && (string) ($template['type'] ?? '') === $area
            && (string) ($template['source'] ?? '') === 'builtin'
            && (string) ($template['source_ref'] ?? '') === $sourceRef;
    }

    /**
     * @param array{home?:bool,channel_id?:int,page_id?:int} $context
     */
    public static function url(string $area, array $context = []): string
    {
        if (!in_array($area, ['header', 'footer'], true)) {
            return '/admin/site_design.php';
        }

        $fallback = '/admin/site_design.php#site-design-area-' . $area;
        try {
            if (!db()->tableExists('blox_templates')) {
                return $fallback;
            }

            if (self::customAreaEnabled($area)) {
                $templates = bloxTemplateModel()->publishedAreaTemplates($area);
                $resolved = $templates === [] ? null : BloxAreaResolver::resolve($templates, [
                    'home' => (bool) ($context['home'] ?? false),
                    'channel_id' => max(0, (int) ($context['channel_id'] ?? 0)),
                    'page_id' => max(0, (int) ($context['page_id'] ?? 0)),
                ]);
                $resolvedId = (int) ($resolved['id'] ?? 0);
                if ($resolvedId > 0) {
                    return self::editorUrl($area, $resolvedId);
                }
            }

            // 自定义区域停用或当前上下文没有命中时，前台实际显示主题布局。
            // default 主题的内置起步模板是该布局的可编辑对应物；不能退回去编辑
            // 一个仍在数据库中、但当前没有参与渲染的已发布模板。
            $sourceRef = self::defaultThemeSourceRef($area);
            if ($sourceRef === '') {
                return $fallback;
            }
            $themeDraft = bloxTemplateModel()->findWhere([
                'source' => 'builtin',
                'source_ref' => $sourceRef,
            ]);
            $themeDraftId = (int) ($themeDraft['id'] ?? 0);
            return $themeDraftId > 0
                ? self::editorUrl($area, $themeDraftId, $area === 'header')
                : $fallback;
        } catch (Throwable $e) {
            error_log('[BloxAreaEditorTarget] ' . $e->getMessage());
            return $fallback;
        }
    }

    private static function customAreaEnabled(string $area): bool
    {
        $key = $area === 'header' ? 'blox_custom_header_enabled' : 'blox_custom_footer_enabled';
        return (string) config($key, '1') === '1';
    }

    private static function defaultThemeSourceRef(string $area): string
    {
        if ((string) config('current_theme', 'default') !== 'default') {
            return '';
        }
        if ($area === 'footer') {
            return 'clean-site-footer';
        }

        // 该记录仅作为保存目标；编辑首屏由 BloxThemeHeaderDocument 按当前主题设置生成，
        // 因此 Logo 右侧与 Logo 下方两种布局都能从真实生效状态开始编辑。
        return 'clean-site-header';
    }

    private static function editorUrl(string $area, int $id, bool $currentThemeHeader = false): string
    {
        $url = '/admin/blox_editor.php?template=' . $id;
        if ($currentThemeHeader) {
            $url .= '&current_header=1';
        }
        return $area === 'header' ? $url . '&open=header-settings' : $url;
    }
}
