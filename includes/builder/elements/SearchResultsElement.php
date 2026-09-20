<?php
/**
 * 搜索结果元素（v1.26 Site Builder）：只在 search 模板里有意义。
 *
 * 结果数据来自 search.php 注入的请求级上下文（分类标签/结果列表/分页全套），
 * 标记与原生搜索页共用 includes/partials/search-results.php——单一来源，
 * 模板路径与原生路径永不漂移。无上下文时前台输出空（模板空输出会整体回落原生页），
 * 编辑画布显示占位说明。
 */

declare(strict_types=1);

final class SearchResultsElement extends AbstractElement
{
    /** @var array<string,mixed>|null 请求级上下文；search.php 渲染模板前设置、渲染后清空 */
    private static ?array $runtimeContext = null;

    /**
     * @param array<string,mixed>|null $context
     * @psalm-suppress PossiblyUnusedMethod 调用方 search.php 不在 Psalm 扫描集
     */
    public static function setRuntimeContext(?array $context): void
    {
        self::$runtimeContext = $context;
    }

    public function type(): string { return 'search-results'; }
    public function label(): string { return __('blox_el_search_results'); }
    public function icon(): string { return 'list-search'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [];
    }

    /** @psalm-suppress UnusedVariable $sr* 变量供 require 的共享局部（include 作用域）消费 */
    public function render(array $data, string $children = ''): string
    {
        $context = self::$runtimeContext;
        if ($context === null) {
            // 编辑画布：占位说明；前台（非搜索页误放）：空输出
            if (class_exists('BlockRenderer') && BlockRenderer::$editChannelId > 0) {
                return '<div class="rounded border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">'
                    . '<i class="ti ti-list-search mr-1" aria-hidden="true"></i>'
                    . htmlspecialchars(__('blox_search_results_placeholder'), ENT_QUOTES) . '</div>';
            }
            return '';
        }
        $srKeyword = (string) ($context['keyword'] ?? '');
        $srType = (string) ($context['type'] ?? 'all');
        $srResults = is_array($context['results'] ?? null) ? $context['results'] : [];
        $srTotal = (int) ($context['total'] ?? 0);
        $srPage = max(1, (int) ($context['page'] ?? 1));
        $srPerPage = max(1, (int) ($context['perPage'] ?? 15));
        $srTypeLabels = is_array($context['typeLabels'] ?? null) ? $context['typeLabels'] : [];
        $srTypeCounts = is_array($context['typeCounts'] ?? null) ? $context['typeCounts'] : [];

        ob_start();
        require ROOT_PATH . '/includes/partials/search-results.php';
        return '<div class="yk-search-results">' . (string) ob_get_clean() . '</div>';
    }
}
