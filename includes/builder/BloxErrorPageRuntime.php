<?php
/** Resolve and render the active Blox 404 template inside the theme shell (v1.26). */

declare(strict_types=1);

final class BloxErrorPageRuntime
{
    /**
     * 已发布 404 模板的正文 HTML；无模板/未命中/渲染失败一律返回 ''（回落主题原生 404 局部）。
     * 激活条件与页头页尾同一 Resolver：实际有意义的维度是 any + 语言（404 上下文无栏目/单页）。
     */
    public static function render(): string
    {
        try {
            if (!function_exists('db') || !db()->tableExists('blox_templates')) {
                return '';
            }
            $templates = bloxTemplateModel()->publishedAreaTemplates('error404');
            if ($templates === []) {
                return '';
            }
            $row = BloxAreaResolver::resolve($templates, [
                'home' => false,
                'channel_id' => 0,
                'page_id' => 0,
                'lang' => function_exists('siteLang') ? siteLang() : '',
            ]);
            if ($row === null) {
                return '';
            }
            $html = BlockRenderer::render((string) ($row['published_data'] ?? ''));
            // 空输出守门：与详情模板同款——"看起来成功其实空白"必须回落原生
            if (!BlockRenderer::hasMeaningfulOutput($html)) {
                return '';
            }
            return '<div class="yk-blox-error404" data-template-id="' . (int) ($row['id'] ?? 0) . '">' . $html . '</div>';
        } catch (Throwable $e) {
            error_log('[BloxErrorPageRuntime] ' . $e->getMessage());
            return '';
        }
    }
}
