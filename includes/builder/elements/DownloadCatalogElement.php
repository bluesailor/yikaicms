<?php
/**
 * 下载栏目落地页里的下载目录：前台沿用固定列表的同一份视图（搜索、表格、分页、分类侧栏），
 * 数据由 list.php 按当前请求（分类/搜索/分页）备好后经运行时上下文传入；编辑器里没有
 * 请求上下文，取当前语言最新几条做预览。下载记录在独立的 downloads 表、按下载分类组织，
 * 不挂栏目，所以不走 {yk:list} 的内容循环。
 */

declare(strict_types=1);

final class DownloadCatalogElement extends AbstractElement
{
    /** @var array<string,mixed>|null */
    private static ?array $runtimeContext = null;

    public function type(): string { return 'download-catalog'; }
    public function label(): string { return __('blox_download_catalog'); }
    public function icon(): string { return 'download'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'download-list'; }
    public function supportsBoxStyles(): bool { return false; }

    /** @param array<string,mixed>|null $context */
    public static function setRuntimeContext(?array $context): void
    {
        self::$runtimeContext = $context;
    }

    public function controls(): array
    {
        return [
            ['key' => 'show_search', 'type' => 'checkbox', 'label' => __('blox_content_catalog_show_search'), 'default' => true],
            ['key' => 'show_categories', 'type' => 'checkbox', 'label' => __('blox_content_catalog_show_categories'), 'default' => true],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $vars = self::$runtimeContext ?? self::previewContext();
        if ($vars === null) {
            return '';
        }
        $vars['downloadShowSearch'] = !array_key_exists('show_search', $data) || !empty($data['show_search']);
        if (array_key_exists('show_categories', $data) && empty($data['show_categories'])) {
            $vars['rightSidebarItems'] = null;
            $vars['rightSidebarChannels'] = [];
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require ROOT_PATH . '/views/list/download.php';
        return (string) ob_get_clean();
    }

    /** 编辑器画布：当前栏目语言的最新 6 条，分类侧栏与前台同构。 */
    private static function previewContext(): ?array
    {
        $channelId = class_exists('BlockRenderer') ? (int) BlockRenderer::$editChannelId : 0;
        $channel = $channelId > 0 ? channelModel()->find($channelId) : null;
        if (!is_array($channel)) {
            return null;
        }
        $lang = (string) ($channel['lang'] ?? siteLang());
        $result = downloadModel()->getList(0, ['status' => '1', 'lang' => $lang], 6, 0);
        return [
            'channel' => $channel,
            'downloads' => $result['items'] ?? [],
            'total' => (int) ($result['total'] ?? 0),
            'page' => 1,
            'perPage' => 6,
            'keyword' => '',
            'dlCatId' => 0,
            'rightSidebarChannels' => [],
            'rightSidebarItems' => self::categoryItems($channel, 0),
            'rightSidebarTitle' => __('label_category'),
            'rightSidebarActiveId' => null,
        ];
    }

    /**
     * 下载分类侧栏（与 list.php 固定列表同一套：全部 + 各 download_categories）。
     *
     * @param array<string,mixed> $channel
     * @return list<array{label:string,url:string,active:bool}>|null
     */
    public static function categoryItems(array $channel, int $activeId): ?array
    {
        $categories = downloadCategoryModel()->getActive();
        if ($categories === []) {
            return null;
        }
        $items = [['label' => __('all'), 'url' => channelUrl($channel), 'active' => $activeId === 0]];
        foreach ($categories as $category) {
            $items[] = [
                'label' => (string) $category['name'],
                'url' => downloadCategoryUrl($category),
                'active' => $activeId === (int) $category['id'],
            ];
        }
        return $items;
    }
}
