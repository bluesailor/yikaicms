<?php
/**
 * 文章详情动态字段（article-detail 专用）。
 *
 * 与 ProductFieldElement 同构：委托既有元素承载样式与排版控件，渲染期从
 * ArticleTemplateDocument::currentContent() 取「本篇」，不把示例内容写死进模板。
 *
 * 空值规则（任务书 DS-BLOX-01C）：逐项按组件职责隐藏，不显示假数据——
 * 无摘要/无封面/无上下一篇/无相关文章时该字段输出空串，不渲染占位标题。
 */
declare(strict_types=1);

final class ArticleFieldElement extends AbstractElement
{
    /** 允许的字段 → 委托元素。 */
    private const FIELDS = ['title', 'summary', 'cover', 'meta', 'content', 'prev-next', 'related'];

    /**
     * 字段 → 文案键（写成字面量映射，让 i18n 门禁能静态扫到这些键；
     * 用 'blox_article_' . $field 拼出来的键门禁看不见，漏键不会被发现）。
     */
    private const LABEL_KEYS = [
        'title' => 'blox_article_title',
        'summary' => 'blox_article_summary',
        'cover' => 'blox_article_cover',
        'meta' => 'blox_article_meta',
        'content' => 'blox_article_content',
        'prev-next' => 'blox_article_prev_next',
        'related' => 'blox_article_related',
    ];

    private const NAV_LABEL_KEYS = ['prev' => 'blox_article_prev', 'next' => 'blox_article_next'];

    public function __construct(private string $field) {}

    private function delegate(): AbstractElement
    {
        return match ($this->field) {
            'title' => new HeadingElement(),
            'cover' => new ImageElement(),
            default => new TextElement(),
        };
    }

    public function type(): string { return 'article-' . $this->field; }

    public function label(): string
    {
        return __(self::LABEL_KEYS[$this->field] ?? 'blox_article_content');
    }
    public function icon(): string { return $this->delegate()->icon(); }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'article-detail'; }
    public function backgroundRenderStrategy(): string { return $this->delegate()->backgroundRenderStrategy(); }

    /** 控件白名单：剔除绑定类字段与「本来要改文案」的输入，动态字段只让用户调外观。 */
    public function controls(): array
    {
        $controls = [];
        foreach ($this->delegate()->controls() as $control) {
            $key = (string) ($control['key'] ?? '');
            if (str_starts_with($key, 'site_') || str_starts_with($key, 'loop_')
                || in_array($key, ['html', 'src', 'alt', 'url'], true)
                || $key === 'text') {
                continue;
            }
            if ($key === 'level') $control['default'] = 'h1';
            $controls[] = $control;
        }

        return $controls;
    }

    public function render(array $data, string $children = ''): string
    {
        $content = ArticleTemplateDocument::currentContent();
        if ($content === null) {
            return '';
        }
        // 伪造的绑定字段（含旧导入文档里的）一律忽略
        foreach (array_keys($data) as $key) {
            if (str_starts_with((string) $key, 'site_') || str_starts_with((string) $key, 'loop_')) {
                unset($data[$key]);
            }
        }
        unset($data['_responsive_image_field'], $data['_responsive_image_fallback']);

        switch ($this->field) {
            case 'title':
                $data['text'] = (string) ($content['title'] ?? '');
                $data['level'] ??= 'h1';
                if ($data['text'] === '') return '';
                break;

            case 'summary':
                $summary = trim((string) ($content['summary'] ?? ''));
                if ($summary === '') return '';   // 无摘要不渲染空段落
                // 摘要按纯文本输出（与原生页一致）：先转义再保留换行，避免被当成富文本执行
                $data['html'] = '<p>' . nl2br(e($summary)) . '</p>';
                break;

            case 'cover':
                $cover = (string) ($content['cover'] ?? '');
                if ($cover === '') return '';     // 无封面不显示占位图，避免假内容
                $data['src'] = $cover;
                $data['alt'] = (string) ($content['title'] ?? '');
                break;

            case 'meta':
                $html = $this->metaHtml($content);
                if ($html === '') return '';
                $data['html'] = $html;
                break;

            case 'content':
                $data['html'] = function_exists('sanitizeHtml') ? sanitizeHtml((string) ($content['content'] ?? '')) : '';
                if (trim($data['html']) === '') return '';
                break;

            case 'prev-next':
                $html = $this->prevNextHtml($content);
                if ($html === '') return '';
                $data['html'] = $html;
                break;

            case 'related':
                $html = $this->relatedHtml($content);
                if ($html === '') return '';
                $data['html'] = $html;
                break;
        }

        return $this->delegate()->render($data);
    }

    /** 栏目 / 作者 / 日期 / 浏览量：只输出确实存在的项，逐项隐藏。 */
    private function metaHtml(array $content): string
    {
        $parts = [];
        $channelName = trim((string) ($content['channel_name'] ?? ''));
        if ($channelName !== '') {
            $parts[] = e($channelName);
        }
        $author = trim((string) ($content['author'] ?? ''));
        if ($author !== '') {
            $parts[] = e($author);
        }
        $timestamp = (int) (($content['publish_time'] ?? 0) ?: ($content['created_at'] ?? 0));
        if ($timestamp > 0) {
            $parts[] = date('Y-m-d', $timestamp);
        }

        return $parts === [] ? '' : '<span class="yk-article-meta">' . implode(' · ', $parts) . '</span>';
    }

    /** 上/下一篇：只渲染存在的一侧；两侧都没有则整块隐藏。 */
    private function prevNextHtml(array $content): string
    {
        $links = [];
        foreach (['prev', 'next'] as $key) {
            $row = $content[$key] ?? null;
            if (!is_array($row) || trim((string) ($row['title'] ?? '')) === '') {
                continue;
            }
            $labelKey = self::NAV_LABEL_KEYS[$key];
            $links[] = '<a href="' . e(contentUrl($row)) . '">' . e(__($labelKey)) . '：' . e((string) $row['title']) . '</a>';
        }

        return $links === [] ? '' : '<nav class="yk-article-prev-next">' . implode('', $links) . '</nav>';
    }

    private function relatedHtml(array $content): string
    {
        $items = [];
        foreach (is_array($content['related'] ?? null) ? $content['related'] : [] as $row) {
            if (!is_array($row) || trim((string) ($row['title'] ?? '')) === '') {
                continue;
            }
            $items[] = '<li><a href="' . e(contentUrl($row)) . '">' . e((string) $row['title']) . '</a></li>';
        }

        return $items === [] ? '' : '<ul class="yk-article-related">' . implode('', $items) . '</ul>';
    }
}
