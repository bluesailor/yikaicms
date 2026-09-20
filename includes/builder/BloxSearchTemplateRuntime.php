<?php
/** Resolve and render the active Blox search page template (v1.26). */

declare(strict_types=1);

/** @psalm-suppress UnusedClass 唯一调用方 search.php 不在 Psalm 扫描集（根入口只列了部分） */
final class BloxSearchTemplateRuntime
{
    /**
     * 命中的已发布 search 模板行；无候选/未命中/异常返回 null。
     * 与 404 同款语义：搜索页无栏目/单页维度，any + 语言即全部有意义的条件。
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(): ?array
    {
        try {
            if (!function_exists('db') || !db()->tableExists('blox_templates')) {
                return null;
            }
            $templates = bloxTemplateModel()->publishedAreaTemplates('search');
            if ($templates === []) {
                return null;
            }
            return BloxAreaResolver::resolve($templates, [
                'home' => false,
                'channel_id' => 0,
                'page_id' => 0,
                'lang' => function_exists('siteLang') ? siteLang() : '',
            ]);
        } catch (Throwable $e) {
            error_log('[BloxSearchTemplateRuntime] resolve: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 渲染模板正文；调用方须先注入 SearchResultsElement 的运行时上下文。
     * 空输出守门同详情模板：空白必须回落原生搜索页。
     *
     * @param array<string,mixed> $row
     */
    public static function renderBody(array $row): string
    {
        try {
            $html = BlockRenderer::render((string) ($row['published_data'] ?? ''));
            if (trim(strip_tags($html)) === '' && !preg_match('/<(?:img|video|iframe|form)\b/i', $html)) {
                return '';
            }
            return '<div class="yk-blox-search" data-template-id="' . (int) ($row['id'] ?? 0) . '">' . $html . '</div>';
        } catch (Throwable $e) {
            error_log('[BloxSearchTemplateRuntime] render: ' . $e->getMessage());
            return '';
        }
    }
}
