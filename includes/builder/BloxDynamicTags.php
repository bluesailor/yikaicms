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
 * URL 参数、当前用户属付费层，后续批次经 BloxFeaturePolicy 收权。
 *
 * 扩展字段（2.0.4 高级字段，取值永远免费）：
 * - `article.meta.键` / `product.meta.键` / `loop.meta.键`：当前文章 / 产品 / 循环行的字段，`.子键` 取字段组的子字段或链接文字（.title）；
 * - `loop.子键`：重复器循环（来源 field:键 / option:键）里当前行的子字段；
 * - `option.键`：全站选项；`term.meta.键`：当前栏目 / 产品分类页的字段。
 */

declare(strict_types=1);

final class BloxDynamicTags
{
    private const TAG_PATTERN = '/\{\{\s*([^{}|]+(?:\|[^{}|]*)*?)\s*\}\}/';
    // 2.0.4 起最多四段：product.meta.键.子键（字段组 / 链接）
    private const SOURCE_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_-]{0,63}){1,3}$/D';
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
    /** loop.* 循环项字段白名单（值来自数据库行，键必须白名单；url/date/index 等是虚拟字段另行处理）。 */
    // cover 为图片路径列（v1.24-③ 结构化属性绑定：Image src 吃 {{loop.cover}}）
    // 2.0.3：补齐各来源常用列（内容/产品/案例/招聘/下载/分类行），全部为展示型文本列
    private const LOOP_FIELDS = ['title', 'subtitle', 'summary', 'model', 'price', 'cover',
        'id', 'slug', 'market_price', 'author', 'source', 'views', 'tags',
        'client_name', 'industry', 'duration', 'result_metric',
        'location', 'salary', 'job_type', 'education', 'experience', 'headcount',
        'file_ext', 'download_count', 'name', 'description', 'image', 'item_count'];
    /** loop.* 虚拟字段：序号/分页类（来自 BloxLoopQuery 装饰的行键）。 */
    private const LOOP_COUNTERS = ['index' => '_index', 'position' => '_position', 'total' => '_total', 'count' => '_count', 'page' => '_page', 'pages' => '_pages'];
    private const LOOP_FLAGS = ['first' => 'loop_first', 'last' => 'loop_last', 'odd' => 'loop_odd', 'even' => 'loop_even'];
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
            ] + self::fieldTagOptions(true);
        }
        return self::staticTagOptions() + self::fieldTagOptions(false);
    }

    /**
     * 已定义扩展字段的候选（2.0.4）：产品 / 内容字段、全站选项、栏目与产品分类字段、重复器子字段。
     * 链接槽只列链接 / 文件 / 图片类字段。
     * @return array<string,string>
     */
    private static function fieldTagOptions(bool $links): array
    {
        if (!class_exists('ExtFields')) {
            return [];
        }
        $linkTypes = ['link', 'file', 'image'];
        $out = [];
        try {
            foreach (ExtFields::owners() as $owner) {
                $prefix = match ($owner) {
                    'product' => '{{product.meta.',
                    'site' => '{{option.',
                    'channel', 'product_category' => '{{term.meta.',
                    default => '{{article.meta.',
                };
                foreach (ExtFields::fields($owner) as $field) {
                    $type = (string) $field['field_type'];
                    $label = ExtFields::ownerLabel($owner) . ' · ' . (string) $field['field_name'];
                    if ($type === 'repeater') {
                        foreach ((array) ($field['config']['sub_fields'] ?? []) as $sub) {
                            if (!$links || in_array($sub['type'], $linkTypes, true)) {
                                $out['{{loop.' . $sub['key'] . '}}'] = $label . ' · ' . $sub['name'];
                            }
                        }
                        continue;
                    }
                    if ($type === 'group') {
                        foreach ((array) ($field['config']['sub_fields'] ?? []) as $sub) {
                            if (!$links || in_array($sub['type'], $linkTypes, true)) {
                                $out[$prefix . $field['field_key'] . '.' . $sub['key'] . '}}'] = $label . ' · ' . $sub['name'];
                            }
                        }
                        continue;
                    }
                    if ($type === 'relationship' || ($links && !in_array($type, $linkTypes, true))) {
                        continue;
                    }
                    $out[$prefix . $field['field_key'] . '}}'] = $label;
                }
            }
        } catch (Throwable) {
            return [];
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function staticTagOptions(): array
    {
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
            '{{loop.category}}' => __('blox_dyn_loop_category'),
            '{{loop.views}}' => __('blox_dyn_loop_views'),
            '{{loop.total}}' => __('blox_dyn_loop_total'),
            '{{loop.page}}' => __('blox_dyn_loop_page'),
            '{{loop.item_count}}' => __('blox_dyn_loop_item_count'),
            '{{parent.title}}' => __('blox_dyn_parent_title'),
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
                if ($field === 'meta' && class_exists('ArticleTemplateDocument')) {
                    $row = ArticleTemplateDocument::currentContent();
                    return is_array($row) ? self::metaValue($row, 'content', $parts[2] ?? '', $parts[3] ?? '') : null;
                }
                if (!in_array($field, self::ARTICLE_FIELDS, true) || !class_exists('ArticleTemplateDocument')) {
                    return null;
                }
                return self::rowValue(ArticleTemplateDocument::currentContent(), $field);

            case 'product':
                if ($field === 'meta' && class_exists('ProductTemplateDocument')) {
                    $row = ProductTemplateDocument::currentProduct();
                    return is_array($row) ? self::metaValue($row, 'product', $parts[2] ?? '', $parts[3] ?? '') : null;
                }
                if (!in_array($field, self::PRODUCT_FIELDS, true) || !class_exists('ProductTemplateDocument')) {
                    return null;
                }
                return self::rowValue(ProductTemplateDocument::currentProduct(), $field);

            case 'option':
                // 全站选项（2.0.4）：option.键[.子键]
                return class_exists('ExtFields') ? self::nonEmpty(ExtFields::textFor('site', 0, $field, $parts[2] ?? '')) : null;

            case 'term':
                // 当前栏目 / 产品分类页的字段（2.0.4）：term.meta.键[.子键]
                if ($field !== 'meta' || !class_exists('ExtFields') || !class_exists('BloxLoopQuery')) {
                    return null;
                }
                [$owner, $termId] = BloxLoopQuery::currentTerm();
                return $termId > 0 ? self::nonEmpty(ExtFields::textFor($owner, $termId, $parts[2] ?? '', $parts[3] ?? '')) : null;

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
                return is_array($context) ? self::loopValue($context, $field, $parts[2] ?? '', $parts[3] ?? '') : null;

            case 'parent':
                // 嵌套循环：外层循环的当前行（2.0.3）
                $parent = class_exists('BloxLoopQuery') ? BloxLoopQuery::parentRow() : null;
                return is_array($parent) ? self::loopValue($parent, $field, $parts[2] ?? '', $parts[3] ?? '') : null;

            case 'lang':
                return $field === 'code' && function_exists('siteLang') ? siteLang() : null;
        }
        return null;
    }

    /**
     * 循环行取值（loop.* / parent.* 共用）。虚拟字段：
     * url（按条目类型取路由）、date/updated（Y-m-d）、index/total/count/page/pages、
     * first/last/odd/even（"1"/空，便于 fallback 管道与显示条件）、category/category_url、
     * file_size（下载，人类可读）、meta.<键>（自定义字段）。
     */
    private static function loopValue(array $row, string $field, string $sub, string $sub2 = ''): ?string
    {
        if (isset(self::LOOP_COUNTERS[$field])) {
            $value = $row[self::LOOP_COUNTERS[$field]] ?? null;
            return is_numeric($value) ? (string) (int) $value : null;
        }
        if (isset(self::LOOP_FLAGS[$field])) {
            return ($row[self::LOOP_FLAGS[$field]] ?? '0') === '1' ? '1' : '';
        }
        $type = (string) ($row['_type'] ?? 'content');
        if ($type === 'row') {
            return self::repeaterRowValue($row, $field, $sub);
        }
        switch ($field) {
            case 'url':
                return self::rowUrl($row, $type);
            case 'date':
                $timestamp = (int) ($row['publish_time'] ?? 0) ?: (int) ($row['created_at'] ?? 0);
                return $timestamp > 0 ? self::date($timestamp) : null;
            case 'updated':
                $timestamp = (int) ($row['updated_at'] ?? 0) ?: (int) ($row['created_at'] ?? 0);
                return $timestamp > 0 ? self::date($timestamp) : null;
            case 'category':
                return self::rowValue($row, $type === 'content' ? 'channel_name' : 'category_name');
            case 'category_url':
                return self::categoryUrl($row, $type);
            case 'file_size':
                $bytes = (int) ($row['file_size'] ?? 0);
                return $bytes > 0 && function_exists('formatFileSize') ? (string) formatFileSize($bytes) : null;
            case 'meta':
                return $sub !== '' ? self::metaValue($row, $type, $sub, $sub2) : null;
        }
        return in_array($field, self::LOOP_FIELDS, true) ? self::rowValue($row, $field) : null;
    }

    /** 给访客看的日期，按访客语言（displayDate）；单测等未加载 functions.php 时退回 Y-m-d */
    private static function date(int $timestamp): string
    {
        return function_exists('displayDate') ? displayDate($timestamp) : date('Y-m-d', $timestamp);
    }

    private static function rowUrl(array $row, string $type): ?string
    {
        switch ($type) {
            case 'product':
                return function_exists('productUrl') ? (string) productUrl($row) : null;
            case 'job':
                return function_exists('jobUrl') ? (string) jobUrl($row) : null;
            case 'download':
                $id = (int) ($row['id'] ?? 0);
                return $id > 0 ? '/download.php?fid=' . $id : null; // 与下载列表同一下载入口
            case 'term':
                return self::termUrl($row, (string) ($row['_taxonomy'] ?? ''));
        }
        return function_exists('contentUrl') ? (string) contentUrl($row) : null;
    }

    private static function termUrl(array $row, string $taxonomy): ?string
    {
        return match ($taxonomy) {
            'content' => function_exists('channelUrl') ? (string) channelUrl($row) : null,
            'product' => function_exists('productCategoryUrl') ? (string) productCategoryUrl($row) : null,
            'download' => function_exists('downloadCategoryUrl') ? (string) downloadCategoryUrl($row) : null,
            default => null,
        };
    }

    /** 条目所属分类的链接（内容=栏目，产品/下载=分类）。 */
    private static function categoryUrl(array $row, string $type): ?string
    {
        if ($type === 'content') {
            $channelId = (int) ($row['channel_id'] ?? 0);
            $channel = $channelId > 0 && function_exists('channelModel') ? channelModel()->find($channelId) : null;
            return is_array($channel) ? self::termUrl($channel, 'content') : null;
        }
        if ($type !== 'product' && $type !== 'download') {
            return null;
        }
        $categoryId = (int) ($row['category_id'] ?? 0);
        if ($categoryId <= 0) {
            return null;
        }
        return self::termUrl([
            'id' => $categoryId,
            'slug' => (string) ($row['category_slug'] ?? ''),
            'name' => (string) ($row['category_name'] ?? ''),
        ], $type);
    }

    /**
     * 自定义字段（metas，owner 与显示条件/循环过滤同源）。2.0.4 起按字段类型出纯文本
     * （链接 = 网址、下拉 = 显示名、字段组 = 子键），并支持分类循环行（栏目 / 产品分类字段）。
     */
    private static function metaValue(array $row, string $type, string $key, string $sub = ''): ?string
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || $key === '' || !function_exists('getMeta')) {
            return null;
        }
        $owner = match ($type) {
            'product' => 'product',
            'content' => function_exists('resolveExtFieldOwner') ? resolveExtFieldOwner((string) ($row['type'] ?? 'article')) : 'content',
            'term' => ['content' => 'channel', 'product' => 'product_category'][(string) ($row['_taxonomy'] ?? '')] ?? '',
            default => '',
        };
        if ($owner === '') {
            return null;
        }
        if (class_exists('ExtFields')) {
            return self::nonEmpty(ExtFields::textFor($owner, $id, $key, $sub));
        }
        $value = getMeta($owner, $id, $key);
        return is_scalar($value) ? trim((string) $value) : null;
    }

    /** 重复器行（来源 field:键 / option:键）：子字段按类型出纯文本，`.title` 取链接文字。 */
    private static function repeaterRowValue(array $row, string $field, string $sub): ?string
    {
        $subs = is_array($row['_subs'] ?? null) ? $row['_subs'] : [];
        if (!is_array($subs[$field] ?? null) || !is_scalar($row[$field] ?? null) || !class_exists('ExtFields')) {
            return null;
        }
        return self::nonEmpty(ExtFields::text($subs[$field], (string) $row[$field], $sub));
    }

    private static function nonEmpty(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
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
