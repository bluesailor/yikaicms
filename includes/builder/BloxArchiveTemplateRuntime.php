<?php
/** Resolve and render the active Blox archive (channel list) template (v1.26). */

declare(strict_types=1);

final class BloxArchiveTemplateRuntime
{
    /**
     * 当前栏目命中的已发布 archive 模板行；无候选/未命中/异常返回 null。
     *
     * 激活条件与页头页尾同一 Resolver：any / channel（ids 空=全部栏目，**不展开子栏目**，
     * 子栏目需单独勾选或留空）/ 语言。上下文用**请求的真实栏目**（可能是子栏目），
     * 让"只套某子栏目"成为可表达的条件。
     *
     * @param array<string,mixed> $channel 当前请求的栏目行
     * @return array<string,mixed>|null
     */
    public static function resolve(array $channel): ?array
    {
        try {
            if (!function_exists('db') || !db()->tableExists('blox_templates')) {
                return null;
            }
            $templates = bloxTemplateModel()->publishedAreaTemplates('archive');
            if ($templates === []) {
                return null;
            }
            return BloxAreaResolver::resolve($templates, [
                'home' => false,
                'channel_id' => (int) ($channel['id'] ?? 0),
                'page_id' => 0,
                'lang' => function_exists('siteLang') ? siteLang() : '',
            ]);
        } catch (Throwable $e) {
            error_log('[BloxArchiveTemplateRuntime] resolve: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 渲染模板正文。调用方须先设置好运行时上下文（ContentCatalogElement +
     * BloxLoopQuery::setCurrentContext）——容器 Loop 的 current 源与目录元素
     * 在渲染期读它们。空输出守门与详情模板同款：空白必须回落原生列表。
     *
     * @param array<string,mixed> $row 已发布模板行
     */
    public static function renderBody(array $row): string
    {
        try {
            $html = BlockRenderer::render((string) ($row['published_data'] ?? ''));
            if (trim(strip_tags($html)) === '' && !preg_match('/<(?:img|video|iframe)\b/i', $html)) {
                return '';
            }
            return '<div class="yk-blox-archive" data-template-id="' . (int) ($row['id'] ?? 0) . '">' . $html . '</div>';
        } catch (Throwable $e) {
            error_log('[BloxArchiveTemplateRuntime] render: ' . $e->getMessage());
            return '';
        }
    }
}
