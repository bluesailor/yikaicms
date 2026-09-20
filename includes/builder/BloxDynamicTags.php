<?php
/**
 * Blox 动态标签 {{provider.field}}（v1.24 Phase 2 第一批）。
 *
 * 语法契约：
 * - `{{ source (| source | 字面量)* }}`，禁嵌套（内容不允许再含花括号）；
 * - source = `provider.field[.sub]`（必须含点）；**首段不是合法 source 的标签整体原样保留**，
 *   旧内容里的 `{{任意文字}}` 不会被吃；
 * - fallback 管道从左到右取第一个非空值；非 source 形态的段视为字面量并终止管道；
 * - 全部为空 → 输出空串（Bricks 同款语义）。
 *
 * 与既有机制的共存（D10）：
 * - 单花括号 `{site_name}` 仍由 DynamicSiteData::interpolate 处理（其环视正则明确
 *   排除 `{{…}}`，本类消费的正是那块被刻意保留的命名空间）；
 * - `{yk:*}` 服务端标签（TagEngine）语法不重叠，互不接触；
 * - site_field 等平铺绑定键只读兼容，不迁移。
 *
 * 转义按落点分双轨（安全边界）：
 * - resolveText：原样代入，由元素既有转义兜底——与用户手输值走同一条路；
 * - resolveHtml：每个解析值先 e() 再代入（值可能来自文章标题等用户内容，
 *   直接进 HTML 上下文就是存储型 XSS）。
 * URL 槽位用 resolveText 后仍过元素既有的 safeHref/UrlPolicy 白名单。
 *
 * 第一批 Provider 全部免费（site/page/article/product/loop/lang）；
 * URL 参数、当前用户、自定义模型字段属付费层，后续批次经 BloxFeaturePolicy 收权。
 */

declare(strict_types=1);

final class BloxDynamicTags
{
    private const TAG_PATTERN = '/\{\{\s*([^{}|]+(?:\|[^{}|]*)*?)\s*\}\}/';
    private const SOURCE_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_-]{0,63}){1,2}$/D';
    /** 单串标签数上限：坏数据兜底，不是产品限制。 */
    private const MAX_TAGS = 20;

    /** site.* → [DynamicSiteData 字段, 槽位]。复用既有白名单与语言回落。 */
    private const SITE_FIELDS = [
        'name' => ['site_name', 'text'],
        'description' => ['site_description', 'text'],
        'phone' => ['contact_phone', 'text'],
        'email' => ['contact_email', 'text'],
        'address' => ['contact_address', 'text'],
        'copyright' => ['copyright', 'text'],
        'url' => ['site_url', 'url'],
        'logo' => ['site_logo', 'image'],
    ];
    /** loop.* 循环项字段白名单（值来自数据库行，键必须白名单；url/date/index 是虚拟字段另行处理）。 */
    // cover 为图片路径列（v1.24-③ 结构化属性绑定：Image src 吃 {{loop.cover}}）
    private const LOOP_FIELDS = ['title', 'subtitle', 'summary', 'model', 'price', 'cover'];
    private const ARTICLE_FIELDS = ['title', 'summary', 'author'];
    private const PRODUCT_FIELDS = ['title', 'model', 'price', 'summary'];

    /**
     * 编辑器插入面板候选（与 DynamicSiteData::tagOptions 合并展示）。
     * 只列上下文类标签——site.* 的等价物已有单花括号候选，不重复。
     * 外审 P2-3：候选按槽位与运行时能力对齐——文本槽列全 loop 文本/虚拟字段，
     * 链接槽列 loop.url；loop.cover（图片路径）暂不进面板：图片控件没有标签
     * 插入入口，查询卡片预设已自动绑好 {{loop.cover}}，等控件长出入口再暴露。
     *
     * @return array<string,string> tag => label
     * @psalm-suppress PossiblyUnusedMethod 编辑器插入面板消费（admin partial 不在 Psalm 扫描集）
     */
    public static function tagOptions(bool $links = false): array
    {
        if ($links) {
            return [
                '{{loop.url}}' => __('blox_dyn_loop_url'),
            ];
        }
        return [
            '{{article.title}}' => __('blox_dyn_article_title'),
            '{{article.summary}}' => __('blox_dyn_article_summary'),
            '{{article.author}}' => __('blox_dyn_article_author'),
            '{{product.title}}' => __('blox_dyn_product_title'),
            '{{product.model}}' => __('blox_dyn_product_model'),
            '{{product.price}}' => __('blox_dyn_product_price'),
            '{{page.title}}' => __('blox_dyn_page_title'),
            '{{loop.title}}' => __('blox_dyn_loop_title'),
            '{{loop.subtitle}}' => __('blox_dyn_loop_subtitle'),
            '{{loop.summary}}' => __('blox_dyn_loop_summary'),
            '{{loop.model}}' => __('blox_dyn_loop_model'),
            '{{loop.price}}' => __('blox_dyn_loop_price'),
            '{{loop.date}}' => __('blox_dyn_loop_date'),
            '{{loop.index}}' => __('blox_dyn_loop_index'),
            '{{lang.code}}' => __('blox_dyn_lang_code'),
        ];
    }

    public static function hasTags(string $value): bool
    {
        return strpos($value, '{{') !== false;
    }

    /** 纯文本落点：原样代入，调用方（元素既有转义）负责转义。 */
    public static function resolveText(string $value): string
    {
        return self::substitute($value, static fn (string $resolved): string => $resolved);
    }

    /** HTML 落点：解析值先 e() 再代入（值不可信，禁止裸进 HTML 上下文）。 */
    public static function resolveHtml(string $value): string
    {
        return self::substitute($value, static fn (string $resolved): string => e($resolved));
    }

    /** @param callable(string):string $escape */
    private static function substitute(string $value, callable $escape): string
    {
        if (!self::hasTags($value)) {
            return $value;
        }
        $count = 0;
        return (string) preg_replace_callback(self::TAG_PATTERN, static function (array $match) use ($escape, &$count): string {
            if (++$count > self::MAX_TAGS) {
                return $match[0];
            }
            $segments = array_map('trim', explode('|', $match[1]));
            // 首段不是合法 source → 不是本协议的标签，整体原样保留（旧内容安全）
            if (!preg_match(self::SOURCE_PATTERN, $segments[0])) {
                return $match[0];
            }
            foreach ($segments as $index => $segment) {
                if ($segment === '') {
                    continue;
                }
                if (preg_match(self::SOURCE_PATTERN, $segment)) {
                    $resolved = self::providerValue($segment);
                    if ($resolved !== null && $resolved !== '') {
                        return $escape($resolved);
                    }
                    continue;
                }
                // 字面量兜底：只允许出现在非首段，命中即终止管道
                return $index === 0 ? $match[0] : $escape($segment);
            }
            return '';
        }, $value);
    }

    /** source → 值；null = 未知 provider/无上下文（与空值同样落入管道下一段）。 */
    public static function providerValue(string $source): ?string
    {
        $parts = explode('.', $source);
        $provider = $parts[0];
        $field = $parts[1] ?? '';

        switch ($provider) {
            case 'site':
                if (!isset(self::SITE_FIELDS[$field])) {
                    return null;
                }
                [$configField, $slot] = self::SITE_FIELDS[$field];
                return DynamicSiteData::value($configField, $slot);

            case 'article':
                if (!in_array($field, self::ARTICLE_FIELDS, true) || !class_exists('ArticleTemplateDocument')) {
                    return null;
                }
                return self::rowValue(ArticleTemplateDocument::currentContent(), $field);

            case 'product':
                if (!in_array($field, self::PRODUCT_FIELDS, true) || !class_exists('ProductTemplateDocument')) {
                    return null;
                }
                return self::rowValue(ProductTemplateDocument::currentProduct(), $field);

            case 'page':
                if ($field !== 'title' || !class_exists('PageTitleElement')) {
                    return null;
                }
                return self::rowValue(PageTitleElement::currentPage(), 'name');

            case 'loop':
                if (!class_exists('TagEngine')) {
                    return null;
                }
                $context = TagEngine::currentContext();
                if (!is_array($context)) {
                    return null;
                }
                if ($field === 'index') {
                    $index = $context['_index'] ?? null;
                    return is_numeric($index) ? (string) (int) $index : null;
                }
                // url/date 是虚拟字段（与 {yk:field} 同语义）：url 按条目类型取路由，date 回退创建时间
                if ($field === 'url') {
                    if (($context['_type'] ?? '') === 'product') {
                        return function_exists('productUrl') ? (string) productUrl($context) : null;
                    }
                    return function_exists('contentUrl') ? (string) contentUrl($context) : null;
                }
                if ($field === 'date') {
                    $timestamp = (int) ($context['publish_time'] ?? 0) ?: (int) ($context['created_at'] ?? 0);
                    return $timestamp > 0 ? date('Y-m-d', $timestamp) : null;
                }
                return in_array($field, self::LOOP_FIELDS, true) ? self::rowValue($context, $field) : null;

            case 'lang':
                return $field === 'code' && function_exists('siteLang') ? siteLang() : null;
        }
        return null;
    }

    /** 数据行取值：仅标量，其他形态一律 null（行内容不可信，形状必须收紧）。 */
    private static function rowValue(mixed $row, string $field): ?string
    {
        if (!is_array($row)) {
            return null;
        }
        $value = $row[$field] ?? null;
        return is_scalar($value) ? trim((string) $value) : null;
    }
}
