<?php
/**
 * 招聘栏目落地页里的职位目录：前台沿用固定列表的职位卡片（主题 partials/job-card.php）与分页，
 * 数据由 list.php 按当前请求备好后经运行时上下文传入；编辑器里取当前语言最新几条做预览。
 * 职位在独立的 jobs 表、只按语言归属，不挂栏目，所以不走 {yk:list} 的内容循环。
 */

declare(strict_types=1);

final class JobCatalogElement extends AbstractElement
{
    /** @var array<string,mixed>|null */
    private static ?array $runtimeContext = null;

    public function type(): string { return 'job-catalog'; }
    public function label(): string { return __('blox_job_catalog'); }
    public function icon(): string { return 'briefcase'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'job-list'; }
    public function supportsBoxStyles(): bool { return false; }

    /** @param array<string,mixed>|null $context */
    public static function setRuntimeContext(?array $context): void
    {
        self::$runtimeContext = $context;
    }

    public function controls(): array
    {
        return [
            ['key' => 'show_pagination', 'type' => 'checkbox', 'label' => __('blox_job_catalog_show_pagination'), 'default' => true],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $vars = self::$runtimeContext ?? self::previewContext();
        if ($vars === null) {
            return '';
        }
        $vars['jobShowPagination'] = !array_key_exists('show_pagination', $data) || !empty($data['show_pagination']);
        extract($vars, EXTR_SKIP);
        ob_start();
        require ROOT_PATH . '/views/list/job-catalog.php';
        return (string) ob_get_clean();
    }

    /** 编辑器画布：当前栏目语言的最新 6 个职位。 */
    private static function previewContext(): ?array
    {
        $channelId = class_exists('BlockRenderer') ? (int) BlockRenderer::$editChannelId : 0;
        $channel = $channelId > 0 ? channelModel()->find($channelId) : null;
        if (!is_array($channel)) {
            return null;
        }
        $result = jobModel()->getList(['status' => '1', 'lang' => (string) ($channel['lang'] ?? siteLang())], 6, 0);
        return [
            'channel' => $channel,
            'jobs' => $result['items'] ?? [],
            'total' => (int) ($result['total'] ?? 0),
            'page' => 1,
            'perPage' => 6,
            'keyword' => '',
        ];
    }
}
