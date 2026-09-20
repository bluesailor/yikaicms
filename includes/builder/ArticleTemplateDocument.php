<?php
/**
 * 文章详情模板文档（article-detail）。
 *
 * 与 ProductTemplateDocument 同构：渲染期用 withContent() 保护/恢复当前文章上下文
 * （try/finally，嵌套渲染后不泄漏给后续元素），动态字段元素从这里取「本篇」。
 *
 * 判定走统一入口 DetailTemplateProvider → DetailTemplateResolver，
 * 不再另立第二套排序；v1 产品规则由 provider 只读适配进同一路径。
 */

declare(strict_types=1);

final class ArticleTemplateDocument
{
    /** 渲染期「当前文章」上下文（静态单例，withContent 保护）。 */
    private static ?array $content = null;

    public static function currentContent(): ?array
    {
        return self::$content;
    }

    /**
     * 在「当前文章」上下文中执行渲染，无论成功失败都恢复上一个上下文。
     *
     * @param array<string,mixed> $content
     */
    public static function withContent(array $content, callable $render): string
    {
        $previous = self::$content;
        self::$content = $content;
        try {
            return $render();
        } finally {
            self::$content = $previous;
        }
    }

    /**
     * 只切换输出源（native/custom），保留布局与作用域。
     * 与 ProductTemplateDocument::changeSource 同一语义，作用在 v2 的 detail_template 上。
     */
    public static function changeSource(string $json, string $source): string
    {
        if (!in_array($source, [DetailTemplateResolver::SOURCE_NATIVE, DetailTemplateResolver::SOURCE_CUSTOM], true)) {
            throw new InvalidArgumentException(__('blox_bad_request'));
        }
        $document = BloxDocumentPipeline::decode($json);
        $scope = DetailTemplateResolver::normalizeScope($document['settings']['detail_template'] ?? null);
        $scope['source'] = $source === DetailTemplateResolver::SOURCE_NATIVE
            ? DetailTemplateResolver::SOURCE_NATIVE
            : DetailTemplateResolver::SOURCE_CUSTOM;
        // 归一化结果去掉内部标记后再落盘
        unset($scope['legacy']);
        $document['settings']['detail_template'] = $scope;

        return json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 哪些内容类型套用文章详情模板。
     *
     * 这是「按内容真实类型接入」的唯一判定点——`article.php` 与 `detail.php` 共用它，
     * 避免两处各自内联字符串比较而漂移。案例 / 下载 / 招聘 / 产品 / 单页等一律为 false，
     * 保持各自既有原生分支不变（任务书 §3.4.2）。
     *
     * 注意 contents.type 与 channels.type 是两套词表：新闻子栏目的 channel_type 是 'list'，
     * 而内容自身的 type 才是 'article'（见 includes/functions.php 的 contentUrl() 注释）。
     *
     * v1.26 Single 扩自定义模型：**已注册**的自定义内容模型也走 article-detail 管线
     * （模板条件的 content_type 维度区分，见 DetailTemplateResolver::TEMPLATE_TYPES 注释）。
     * 注册核对在此（IO 层）完成——模型删除后其内容页立即回归原生输出，模板成休眠数据。
     */
    public static function supportsContentType(mixed $type): bool
    {
        if (!is_string($type) || $type === '') {
            return false;
        }
        if ($type === 'article') {
            return true;
        }
        try {
            return function_exists('contentModelModel')
                && db()->tableExists('content_models')
                && in_array($type, contentModelModel()->keys(), true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 把 ContentDetailController::prepare() 的输出归一为渲染上下文（单一契约处）。
     * 动态字段元素只认这里产出的键名，入口文件不自行拼装，
     * 避免 article.php / detail.php 各拼一套导致字段漂移。
     *
     * @param array<string,mixed> $vars 控制器返回值
     * @return array<string,mixed>
     */
    public static function contextFrom(array $vars): array
    {
        $content = is_array($vars['content'] ?? null) ? $vars['content'] : [];
        $channel = is_array($vars['channel'] ?? null) ? $vars['channel'] : [];

        return [
            'id' => (int) ($content['id'] ?? 0),
            // 内容真实类型（article / 自定义模型 key）：renderPublished 据此解析对应作用域的模板
            'type' => (string) ($content['type'] ?? 'article'),
            'title' => (string) ($content['title'] ?? ''),
            'subtitle' => (string) ($content['subtitle'] ?? ''),
            'slug' => (string) ($content['slug'] ?? ''),
            'summary' => (string) ($content['summary'] ?? ''),
            'cover' => (string) ($content['cover'] ?? ''),
            'content' => (string) ($content['content'] ?? ''),
            'images' => (string) ($content['images'] ?? ''),
            'author' => (string) ($content['author'] ?? ''),
            'source' => (string) ($content['source'] ?? ''),
            'tags' => (string) ($content['tags'] ?? ''),
            'views' => (int) ($content['views'] ?? 0),
            'lang' => (string) ($content['lang'] ?? ''),
            'publish_time' => (int) ($content['publish_time'] ?? 0),
            'created_at' => (int) ($content['created_at'] ?? 0),
            'updated_at' => (int) ($content['updated_at'] ?? 0),
            'channel_id' => (int) ($vars['channelId'] ?? ($content['channel_id'] ?? 0)),
            'channel_name' => (string) ($content['channel_name'] ?? ($channel['name'] ?? '')),
            'channel_slug' => (string) ($content['channel_slug'] ?? ($channel['slug'] ?? '')),
            'channel_type' => (string) ($content['channel_type'] ?? ($channel['type'] ?? '')),
            'prev' => is_array($vars['prevContent'] ?? null) ? $vars['prevContent'] : null,
            'next' => is_array($vars['nextContent'] ?? null) ? $vars['nextContent'] : null,
            'related' => is_array($vars['relatedContents'] ?? null) ? $vars['relatedContents'] : [],
        ];
    }

    /**
     * 前台渲染：未命中或条件为系统默认时返回 ''（由调用方回退原生模板）。
     *
     * @param array<string,mixed> $content
     */
    public static function renderPublished(array $content): string
    {
        // Published output is independent of editor and download entitlements.
        $contentType = is_string($content['type'] ?? null) && trim((string) $content['type']) !== ''
            ? trim((string) $content['type'])
            : 'article';
        try {
            $resolution = DetailTemplateProvider::resolveFor($contentType, $content);
        } catch (Throwable $e) {
            error_log('[article-template] Resolve failed: ' . $e->getMessage());
            return '';
        }

        // native / 未命中 / 失效绑定 / 冲突兜底到 native：都交回原生输出
        $template = is_array($resolution['template'] ?? null) ? $resolution['template'] : null;
        if ($template === null || $resolution['source'] !== DetailTemplateResolver::SOURCE_CUSTOM) {
            return '';
        }

        try {
            $html = self::withContent($content, static function () use ($template): string {
                return BlockRenderer::render((string) ($template['published_data'] ?? ''));
            });
            // 空输出守门：只有确实没有可渲染内容时才回退，避免"看起来成功其实空白"
            if (trim(strip_tags($html)) === '' && !preg_match('/<(?:img|video|iframe)\b/i', $html)) {
                return '';
            }

            return '<div class="yk-blox-article-detail" data-template-id="' . (int) $template['id'] . '">' . $html . '</div>';
        } catch (Throwable $e) {
            error_log('[article-template] Render failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 「复制为自定义模板」的起始布局：标题 + 封面 + 正文，全部走动态字段绑定。
     * 默认不应用（include 为空 = 未应用），需要用户显式配置范围后才生效。
     */
    public static function seed(string $language): string
    {
        return BloxDocumentPipeline::process(json_encode([
            'schema' => 1,
            'settings' => ['detail_template' => [
                'version' => DetailTemplateResolver::VERSION,
                'content_type' => 'article',
                'lang' => $language,
                'include' => [],
            ]],
            'sections' => [['columns' => [
                ['width' => 12, 'elements' => [
                    ['type' => 'article-title', 'data' => ['level' => 'h1']],
                ]],
                ['width' => 12, 'elements' => [
                    ['type' => 'article-meta', 'data' => []],
                ]],
                ['width' => 12, 'elements' => [
                    ['type' => 'article-cover', 'data' => []],
                ]],
                ['width' => 12, 'elements' => [
                    ['type' => 'article-content', 'data' => []],
                ]],
                ['width' => 12, 'elements' => [
                    ['type' => 'article-prev-next', 'data' => []],
                ]],
            ]]],
        ], JSON_THROW_ON_ERROR), 'article-template')['json'];
    }
}
