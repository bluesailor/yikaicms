<?php
declare(strict_types=1);

require_once __DIR__ . '/WordPressSource.php';
require_once __DIR__ . '/WordPressPermalinks.php';
require_once __DIR__ . '/WordPressContent.php';
require_once __DIR__ . '/WordPressForms.php';
require_once __DIR__ . '/WordPressAcf.php';
require_once dirname(__DIR__) . '/LegacyUrls.php';

/**
 * WordPress → YikaiCMS 导入（2.0.4，WordPress 迁移底座；读 WordPress 数据库）。
 *
 * 对应关系：
 *   文章 post            → 文章（contents.type=article），挂在主分类对应的栏目下
 *   文章分类 category     → 新闻栏目下的列表栏目（channels.type=list），保留父子
 *   文章标签 post_tag     → 文章标签页（tag.php）+ 文章的 tags 文字
 *   单页 page            → 单页栏目（channels.type=page），保留父子
 *   商品 product         → 产品（参数、图集、SKU→型号；只做询盘，不导价格）
 *   商品分类 / 标签       → 产品分类 / 产品标签
 *   Yoast 标题、描述、焦点词 → SEO 标题 / 描述 / 关键词（产品存在 metas）；没有 Yoast 时依次读 Rank Math、
 *                            All in One SEO、Easy WP Meta Description、Betheme 自带 SEO 字段（2.0.5）
 *   自定义内容类型（如 Betheme 作品集 portfolio）→ 按 type_map 映射成案例或文章，它的分类法 → 案例分类（2.0.5）
 *   WPML 翻译组           → translation_group_id；非默认语言网址加 /xx 前缀（语言子域名由「语言域名」设置接管）
 *   Contact Form 7 表单   → 表单模板（各语言字段），正文里的 [contact-form-7] 换成 [form-别名]（2.0.4）
 *   导航菜单 nav_menu     → 菜单组（最多三级），各语言菜单记成默认语言组的语言版本（2.0.4）
 * 每个条目都按原站固定链接登记网址（网址登记表），网址不变；导入后打开 LegacyUrls 兜底
 * （?p=123、?s=、/feed/、/author/、附件页等成批旧地址）。
 *
 * 可重复运行：WordPress id → CMS id 记在 metas（owner_type=wp_import），再跑一次是更新不是重复插入。
 * 试运行（dryRun）在事务里完整跑一遍再回滚，报告与正式导入完全一致。
 *
 * @psalm-suppress UnusedClass 调用方是命令行 tools/wp-import.php（不在 Psalm projectFiles 内）与测试
 */
final class WordPressImporter
{
    private const MAP = 'wp_import';

    private WordPressSource $src;
    private WordPressPermalinks $links;
    private WordPressContent $content;
    private string $defaultLang;
    /** @var array<string,string> WPML 语言代码 → CMS 语言代码 */
    private array $langMap;
    /** @var array<int,string> */
    private array $attachments = [];
    /** @var array<string,array{trid:int,lang:string,source:?string}> */
    private array $translations = [];
    /** @var array<int,list<int>> */
    private array $relationships = [];
    /** @var array<int,array<string,mixed>> 所有分类，以 term_taxonomy_id 为键 */
    private array $terms = [];
    /** @var array<string,int> 「分类法:term_id」→ term_taxonomy_id */
    private array $ttByTerm = [];
    /** @var array<int,string> */
    private array $users = [];
    /** @var array<string,array<int,int>> 种类 → WordPress id → CMS id（本次运行） */
    private array $ids = [];
    /** @var array<string,array<int,int>> 种类 → trid → 翻译组 id */
    private array $groups = [];
    /** @var array<string,int> 各语言新闻栏目（语言 → 栏目 id）的缓存 */
    private array $newsChannels = [];
    /** @var array<string,string> CF7 表单 id / hash 前 7 位 → 本站表单别名 */
    private array $formSlugs = [];
    /** @var array<string,list<array{key:string,acf:array<string,mixed>}>> ACF 字段（按本站归属），写值时用 */
    private array $acfFields = [];

    /**
     * levels：每条正文的迁移程度——complete 完整、partial 部分（去掉或没认出短代码）、degraded 降级（从构建器数据抽出，排版丢失）；
     * skipped 跳过的条目数；unsupported 没迁移的内容类型 → 条数。review 列出需要人工看的条目（降级、部分）。
     * @var array{created:array<string,int>,updated:array<string,int>,skipped:array<string,int>,urls:int,warnings:list<string>,dropped:array<string,int>,unknown:array<string,int>,languages:list<string>,menus:list<array<string,mixed>>,forms:list<array{name:string,slug:string,lang:string,translations:list<string>}>,acf:list<array{group:string,owner:string,fields:int}>,levels:array{complete:int,partial:int,degraded:int},unsupported:array<string,int>,review:list<array{level:string,kind:string,title:string,source:string}>}
     */
    private array $report = ['created' => [], 'updated' => [], 'skipped' => [], 'urls' => 0, 'warnings' => [], 'dropped' => [], 'unknown' => [], 'languages' => [], 'menus' => [], 'forms' => [], 'acf' => [],
        'levels' => ['complete' => 0, 'partial' => 0, 'degraded' => 0], 'unsupported' => [], 'review' => []];
    /** @var list<string> 本次登记的全部网址（覆盖检查用） */
    private array $paths = [];
    /** @var array<string,string> 自定义内容类型 → 本站类型（case / article），见构造参数 type_map */
    private array $typeMap = [];
    /** @var array<string,int> 各语言默认案例栏目的缓存 */
    private array $caseChannels = [];
    /** WordPress 内部类型：不算「没迁移的内容」 */
    private const INTERNAL_TYPES = ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wpcf7_contact_form',
        'acf-field-group', 'acf-field', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'scheduled-action', 'post', 'page', 'product'];

    /**
     * @param array{default_lang?:string,lang_map?:array<string,string>,type_map?:array<string,string>} $options
     *        type_map：自定义内容类型 → case（案例）或 article（文章），如 ['portfolio' => 'case']
     */
    public function __construct(WordPressSource $src, array $options = [])
    {
        if (!$src->looksLikeWordPress()) throw new InvalidArgumentException('wp_not_wordpress');
        $this->src = $src;
        $this->langMap = ($options['lang_map'] ?? []) + ['pt-br' => 'pt', 'pt-pt' => 'pt', 'zh-hans' => 'zh-CN', 'zh-hant' => 'zh-TW', 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW'];
        $wpDefault = (string) ($options['default_lang'] ?? $src->defaultLanguage() ?? config('site_lang', 'zh-CN'));
        $this->defaultLang = $this->cmsLang($wpDefault) ?? $wpDefault;
        foreach ($options['type_map'] ?? [] as $type => $target) {
            $type = strtolower(trim((string) $type));
            if (!in_array($target, ['case', 'article'], true) || preg_match('/^[a-z0-9_-]{1,20}$/', $type) !== 1 || in_array($type, self::INTERNAL_TYPES, true)) {
                throw new InvalidArgumentException("type_map 只支持「自定义类型:case」或「自定义类型:article」：{$type}:{$target}");
            }
            $this->typeMap[$type] = $target;
        }
        $this->links = new WordPressPermalinks((string) $src->option('permalink_structure'), (string) $src->option('category_base'),
            (string) $src->option('tag_base'), $src->optionArray('woocommerce_permalinks'));
    }

    private function cmsLang(string $wpLang): ?string
    {
        $wpLang = strtolower(trim($wpLang));
        $code = $this->langMap[$wpLang] ?? $wpLang;
        foreach (array_keys(LanguageRegistry::all()) as $known) {
            if (strtolower($known) === strtolower($code)) return $known;
        }
        return null;
    }

    /** @return array<string,mixed> 报告 */
    public function run(bool $dryRun = false): array
    {
        $siteLang = (string) config('site_lang', 'zh-CN');
        if ($siteLang !== $this->defaultLang) {
            throw new RuntimeException("站点默认语言是 {$siteLang}，WordPress 默认语言是 {$this->defaultLang}；请先把站点默认语言改成 {$this->defaultLang} 再导入。");
        }
        if (!productRouteModel()->available()) throw new RuntimeException('product_url_upgrade');
        $this->load();

        db()->beginTransaction();
        try {
            // 表单先导：正文里的 [contact-form-7 id=…] 才能换成本站的 [form-别名]
            $this->importForms();
            $this->content->forms = $this->formSlugs;
            $this->importCategories();
            $this->importPostTags();
            $this->importProductCategories();
            $this->importProductTags();
            // ACF 字段定义在分类之后建（挂载位置要用到分类的新 id），值等全部条目导完再写（关联字段要对上新 id）
            $this->importAcfFields();
            $this->importPages();
            $this->importPosts();
            $this->importProducts();
            $this->importCustomTypes();
            $this->importAcfValues();
            // 菜单最后导：菜单项指向的页面、分类、产品都已经有了
            $this->importMenus();
            // 打开旧链接兜底：?p=123、/feed/、/author/… 等（LegacyUrls）
            settingModel()->saveBatch([LegacyUrls::SETTING => '1']);
            if ($dryRun) db()->rollback(); else db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }
        productRouteModel()->clearCache();
        $this->report['dropped'] = $this->content->dropped;
        $this->report['unknown'] = $this->content->unknown;
        $this->report['languages'] = array_values(array_unique(array_merge([$this->defaultLang], $this->report['languages'])));
        foreach ($this->src->postTypeCounts() as $type => $n) {
            if (!in_array($type, self::INTERNAL_TYPES, true) && !isset($this->typeMap[$type])) $this->report['unsupported'][$type] = $n;
        }
        return $this->report + ['dry_run' => $dryRun, 'default_lang' => $this->defaultLang, 'language_domains' => $this->languageDomains()];
    }

    /** 本次登记的网址（覆盖检查用）。 @return list<string> */
    public function registeredPaths(): array
    {
        return $this->paths;
    }

    /** WPML 语言子域名（换成 CMS 语言代码），供「语言域名」设置参考。 @return array<string,string> */
    public function languageDomains(): array
    {
        $out = [];
        foreach ($this->src->languageDomains() as $lang => $host) {
            $code = $this->cmsLang($lang);
            if ($code !== null) $out[$code] = $host;
        }
        return $out;
    }

    private function load(): void
    {
        $this->attachments = $this->src->attachments();
        $this->translations = $this->src->translations();
        $this->relationships = $this->src->relationships();
        $taxonomies = ['category', 'post_tag', 'product_cat', 'product_tag'];
        foreach (array_keys($this->typeMap) as $type) $taxonomies = array_merge($taxonomies, $this->src->taxonomiesOf($type));
        $this->terms = $this->src->terms(array_values(array_unique($taxonomies)));
        foreach ($this->terms as $tt => $term) $this->ttByTerm[$term['taxonomy'] . ':' . $term['term_id']] = $tt;
        $this->users = $this->src->users();
        $languageHosts = [];
        foreach ($this->languageDomains() as $lang => $host) $languageHosts[$host] = $lang;
        $this->content = new WordPressContent($this->attachments, $this->src->hosts(), $languageHosts);
    }

    // ── 公共小件 ──────────────────────────────────────────────────────────

    private function count(string $bucket, string $kind): void
    {
        $this->report[$bucket][$kind] = ($this->report[$bucket][$kind] ?? 0) + 1;
    }

    /** 记下刚清理完的这条正文的迁移程度（WordPressContent 的 lastSource / lastIssues）。 */
    private function level(string $kind, string $title): void
    {
        $level = $this->content->lastSource !== 'html' ? 'degraded' : ($this->content->lastIssues > 0 ? 'partial' : 'complete');
        $this->report['levels'][$level]++;
        if ($level !== 'complete' && count($this->report['review']) < 300) {
            $this->report['review'][] = ['level' => $level, 'kind' => $kind, 'title' => $title, 'source' => $this->content->lastSource];
        }
    }

    private function warn(string $message): void
    {
        if (count($this->report['warnings']) < 500) $this->report['warnings'][] = $message;
    }

    /** 条目的语言（WPML 没记录的当作默认语言）；不支持的语言返回 null。 */
    private function langOf(string $elementType, int $elementId): ?string
    {
        $wp = $this->translations[$elementType . ':' . $elementId]['lang'] ?? null;
        if ($wp === null) return $this->defaultLang;
        $code = $this->cmsLang($wp);
        if ($code === null) {
            $this->warn("语言 {$wp} 不在 YikaiCMS 语言注册表里，跳过 {$elementType} #{$elementId}");
            return null;
        }
        if ($code !== $this->defaultLang) $this->report['languages'][] = $code;
        return $code;
    }

    private function trid(string $elementType, int $elementId): int
    {
        return $this->translations[$elementType . ':' . $elementId]['trid'] ?? 0;
    }

    /** 默认语言排前面：翻译组 id 取默认语言那一条。 @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function defaultLanguageFirst(array $items, string $elementType, string $idKey): array
    {
        usort($items, function (array $a, array $b) use ($elementType, $idKey): int {
            $la = ($this->translations[$elementType . ':' . (int) $a[$idKey]]['source'] ?? null) === null ? 0 : 1;
            $lb = ($this->translations[$elementType . ':' . (int) $b[$idKey]]['source'] ?? null) === null ? 0 : 1;
            return $la <=> $lb;
        });
        return $items;
    }

    private function langPrefix(string $lang): string
    {
        return $lang === $this->defaultLang ? '' : '/' . $lang;
    }

    /** 上次导入时这个 WordPress 条目对应的 CMS id（条目已被删掉则为 0）。 */
    private function mapped(string $kind, int $wpId, string $table): int
    {
        $id = (int) (getMeta(self::MAP, $wpId, $kind) ?? 0);
        if ($id > 0 && !db()->fetchOne('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE id = ?', [$id])) return 0;
        return $id;
    }

    /** 新建或更新一行，记下映射，返回 CMS id。 @param array<string,mixed> $data */
    private function upsert(string $kind, int $wpId, string $table, array $data): int
    {
        $id = $this->mapped($kind, $wpId, $table);
        if ($id > 0) {
            db()->update($table, $data, 'id = ?', [$id]);
            $this->count('updated', $kind);
        } else {
            $id = (int) db()->insert($table, $data);
            setMeta(self::MAP, $wpId, $kind, (string) $id);
            $this->count('created', $kind);
        }
        $this->ids[$kind][$wpId] = $id;
        return $id;
    }

    /** 翻译组：默认语言那条的 id；同组的后续语言指向它。 */
    private function group(string $kind, string $table, int $trid, int $id): void
    {
        $groupId = $trid > 0 ? ($this->groups[$kind][$trid] ??= $id) : $id;
        db()->update($table, ['translation_group_id' => $groupId], 'id = ?', [$id]);
    }

    /** 在表内唯一的别名（重复时加 -2、-3…；不像 resolveSlug 那样加时间戳，同一秒建几百条也不撞）。 */
    private function slug(string $wpSlug, string $title, string $table, int $excludeId): string
    {
        $base = normalizeSlugInput(rawurldecode($wpSlug)) ?: generateSlug($title) ?: 'item';
        if (LanguageRegistry::isReservedSlug($base)) $base .= '-page';
        for ($n = 1; ; $n++) {
            $slug = $n === 1 ? $base : $base . '-' . $n;
            $hit = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE slug = ? AND id <> ?', [$slug, $excludeId]);
            if (!$hit) return $slug;
        }
    }

    private function register(string $kind, int $id, string $path, string $lang, string $label): void
    {
        try {
            $registered = productRouteModel()->assign($kind, $id, $path, $lang);
            $this->paths[] = $registered;
            $this->report['urls']++;
        } catch (InvalidArgumentException $e) {
            $this->warn("网址没登记上（{$e->getMessage()}）：{$path} ← {$label}");
        }
    }

    /** 父级在前的分类 / 页面路径（slug 链）。 @param callable(int):?array{slug:string,parent:int} $lookup */
    private static function slugPath(int $id, callable $lookup): string
    {
        $parts = [];
        $seen = [];
        while ($id > 0 && !isset($seen[$id]) && ($node = $lookup($id)) !== null) {
            $seen[$id] = true;
            array_unshift($parts, $node['slug']);
            $id = $node['parent'];
        }
        return implode('/', $parts);
    }

    /** taxonomy 内按 term_id 找分类。 @return array<string,mixed>|null */
    private function term(string $taxonomy, int $termId): ?array
    {
        $tt = $this->ttByTerm[$taxonomy . ':' . $termId] ?? null;
        return $tt === null ? null : $this->terms[$tt];
    }

    private function termPath(string $taxonomy, int $termId): string
    {
        return self::slugPath($termId, function (int $id) use ($taxonomy): ?array {
            $t = $this->term($taxonomy, $id);
            return $t === null ? null : ['slug' => (string) $t['slug'], 'parent' => (int) $t['parent']];
        });
    }

    /** @return list<array<string,mixed>> 某条目关联的某分类法的分类 */
    private function termsOf(int $objectId, string $taxonomy): array
    {
        $out = [];
        foreach ($this->relationships[$objectId] ?? [] as $tt) {
            if (($this->terms[$tt]['taxonomy'] ?? '') === $taxonomy) $out[] = $this->terms[$tt];
        }
        return $out;
    }

    /** Yoast 分类 SEO（wpseo_taxonomy_meta）。 @return array{title:string,description:string} */
    private function termSeo(string $taxonomy, int $termId): array
    {
        static $meta = null;
        $meta ??= $this->src->optionArray('wpseo_taxonomy_meta');
        $row = is_array($meta[$taxonomy][$termId] ?? null) ? $meta[$taxonomy][$termId] : [];
        return ['title' => $this->yoast((string) ($row['wpseo_title'] ?? ''), ''), 'description' => $this->yoast((string) ($row['wpseo_desc'] ?? ''), '')];
    }

    /**
     * Yoast 模板变量：%%title%%、%%sitename%%、%%sep%% 等换成实际文字；结尾的「分隔符 站点名」去掉（CMS 页头会自己加）。
     * 只有模板、没有自定义内容的（如默认的「%%title%% %%sep%% %%sitename%%」）返回空，交给 CMS 默认规则。
     */
    public function yoast(string $value, string $title): string
    {
        $value = trim($value);
        if ($value === '') return '';
        $site = html_entity_decode((string) $this->src->option('blogname'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sepKey = (string) ($this->src->optionArray('wpseo_titles')['separator'] ?? 'sc-dash');
        $sep = ['sc-dash' => '-', 'sc-ndash' => '–', 'sc-mdash' => '—', 'sc-middot' => '·', 'sc-bull' => '•', 'sc-star' => '*', 'sc-pipe' => '|', 'sc-tilde' => '~',
            'sc-laquo' => '«', 'sc-raquo' => '»', 'sc-lt' => '<', 'sc-gt' => '>'][$sepKey] ?? '-';
        if (preg_replace('/%%[a-z_]+%%|\s/', '', $value) === '' ) return '';   // 纯模板
        $out = strtr($value, ['%%title%%' => $title, '%%sitename%%' => $site, '%%sep%%' => $sep, '%%page%%' => '', '%%sitedesc%%' => '']);
        $out = trim((string) preg_replace('/%%[a-z_]+%%/', '', $out));
        if ($site !== '') {
            $out = trim((string) preg_replace('/\s*' . preg_quote($sep, '/') . '\s*' . preg_quote($site, '/') . '$/u', '', $out));
        }
        return trim((string) preg_replace('/\s{2,}/', ' ', $out));
    }

    private function newsChannel(string $lang): int
    {
        if (!isset($this->newsChannels[$lang])) {
            $source = db()->fetchOne('SELECT id, translation_group_id FROM ' . DB_PREFIX . "channels WHERE slug = 'news' ORDER BY id LIMIT 1");
            $id = 0;
            if ($source) {
                $group = (int) ($source['translation_group_id'] ?: $source['id']);
                $row = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'channels WHERE (translation_group_id = ? OR id = ?) AND lang = ? ORDER BY id LIMIT 1', [$group, $group, $lang]);
                $id = (int) ($row['id'] ?? 0);
            }
            if ($id === 0) $this->warn("语言 {$lang} 没有新闻栏目，文章分类放在顶层");
            $this->newsChannels[$lang] = $id;
        }
        return $this->newsChannels[$lang];
    }

    // ── 分类与标签 ────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function termsIn(string $taxonomy): array
    {
        $list = array_values(array_filter($this->terms, static fn (array $t): bool => $t['taxonomy'] === $taxonomy));
        return $this->defaultLanguageFirst($list, 'tax_' . $taxonomy, 'tt_id');
    }

    private function importCategories(): void
    {
        $done = [];
        $make = function (array $term) use (&$make, &$done): int {
            $tt = (int) $term['tt_id'];
            if (isset($done[$tt])) return $done[$tt];
            $lang = $this->langOf('tax_category', $tt);
            if ($lang === null) return $done[$tt] = 0;
            $parentTerm = (int) $term['parent'] > 0 ? $this->term('category', (int) $term['parent']) : null;
            $parent = $parentTerm !== null ? $make($parentTerm) : $this->newsChannel($lang);
            $seo = $this->termSeo('category', (int) $term['term_id']);
            $existing = $this->mapped('category', $tt, 'channels');
            $data = ['lang' => $lang, 'parent_id' => $parent, 'name' => $term['name'], 'type' => 'list',
                'slug' => $this->slug((string) $term['slug'], (string) $term['name'], 'channels', $existing),
                'description' => strip_tags((string) $term['description']), 'seo_title' => $seo['title'], 'seo_description' => $seo['description'],
                'status' => 1, 'is_nav' => 0, 'updated_at' => time()];
            if ($existing === 0) $data['created_at'] = time();
            $id = $this->upsert('category', $tt, 'channels', $data);
            $this->group('category', 'channels', $this->trid('tax_category', $tt), $id);
            $this->register('channel', $id, $this->langPrefix($lang) . $this->links->term('category', $this->termPath('category', (int) $term['term_id'])), $lang, "分类 {$term['name']}");
            return $done[$tt] = $id;
        };
        foreach ($this->termsIn('category') as $term) $make($term);
    }

    private function importPostTags(): void
    {
        foreach ($this->termsIn('post_tag') as $term) {
            $tt = (int) $term['tt_id'];
            $lang = $this->langOf('tax_post_tag', $tt);
            if ($lang === null) continue;
            $id = productRouteModel()->contentTagId((string) $term['name'], $lang);
            $this->ids['post_tag'][$tt] = $id;
            $this->count(getMeta(self::MAP, $tt, 'post_tag') === null ? 'created' : 'updated', 'post_tag');
            setMeta(self::MAP, $tt, 'post_tag', (string) $id);
            $this->register('content_tag', $id, $this->langPrefix($lang) . $this->links->term('post_tag', (string) $term['slug']), $lang, "标签 {$term['name']}");
        }
    }

    private function importProductCategories(): void
    {
        $thumbs = [];
        $done = [];
        $make = function (array $term) use (&$make, &$done, &$thumbs): int {
            $tt = (int) $term['tt_id'];
            if (isset($done[$tt])) return $done[$tt];
            $lang = $this->langOf('tax_product_cat', $tt);
            if ($lang === null) return $done[$tt] = 0;
            $parentTerm = (int) $term['parent'] > 0 ? $this->term('product_cat', (int) $term['parent']) : null;
            $parent = $parentTerm !== null ? $make($parentTerm) : 0;
            $seo = $this->termSeo('product_cat', (int) $term['term_id']);
            $existing = $this->mapped('product_cat', $tt, 'product_categories');
            $data = ['lang' => $lang, 'parent_id' => $parent, 'name' => $term['name'],
                'slug' => $this->slug((string) $term['slug'], (string) $term['name'], 'product_categories', $existing),
                'description' => (string) $term['description'], 'image' => $this->termImage((int) $term['term_id']),
                'seo_title' => $seo['title'], 'seo_description' => $seo['description'], 'status' => 1];
            if ($existing === 0) $data['created_at'] = time();
            $id = $this->upsert('product_cat', $tt, 'product_categories', $data);
            $this->group('product_cat', 'product_categories', $this->trid('tax_product_cat', $tt), $id);
            $this->register('category', $id, $this->langPrefix($lang) . $this->links->term('product_cat', $this->termPath('product_cat', (int) $term['term_id'])), $lang, "产品分类 {$term['name']}");
            return $done[$tt] = $id;
        };
        foreach ($this->termsIn('product_cat') as $term) $make($term);
    }

    private function termImage(int $termId): string
    {
        $thumb = (int) ($this->src->termMeta($termId)['thumbnail_id'] ?? 0);
        return $this->attachments[$thumb] ?? '';
    }

    private function importProductTags(): void
    {
        foreach ($this->termsIn('product_tag') as $term) {
            $tt = (int) $term['tt_id'];
            $lang = $this->langOf('tax_product_tag', $tt);
            if ($lang === null) continue;
            $existing = $this->mapped('product_tag', $tt, 'product_tags');
            $id = $this->upsert('product_tag', $tt, 'product_tags', ['lang' => $lang, 'group_name' => 'Tags', 'name' => $term['name'],
                'slug' => $this->slug((string) $term['slug'], (string) $term['name'], 'product_tags', $existing), 'status' => 1]);
            $this->group('product_tag', 'product_tags', $this->trid('tax_product_tag', $tt), $id);
            $this->register('product_tag', $id, $this->langPrefix($lang) . $this->links->term('product_tag', (string) $term['slug']), $lang, "产品标签 {$term['name']}");
        }
    }

    // ── 文章、单页、产品 ──────────────────────────────────────────────────

    /**
     * 条目 SEO：Yoast 优先，其次 Rank Math、All in One SEO、Easy WP Meta Description、Betheme 自带字段（各取第一个非空）。
     * @param array<string,string> $meta @return array{title:string,description:string,keywords:string}
     */
    private function postSeo(array $meta, string $title): array
    {
        $first = function (array $keys) use ($meta, $title): string {
            foreach ($keys as $key) {
                $value = $this->yoast(html_entity_decode((string) ($meta[$key] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $title);
                if ($value !== '') return $value;
            }
            return '';
        };
        return ['title' => $first(['_yoast_wpseo_title', 'rank_math_title', '_aioseo_title', 'mfn-meta-seo-title']),
            'description' => $first(['_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description', '_easy_wp_meta_description', 'mfn-meta-seo-description']),
            'keywords' => trim((string) ($meta['_yoast_wpseo_focuskw'] ?? $meta['rank_math_focus_keyword'] ?? $meta['mfn-meta-seo-keywords'] ?? ''))];
    }

    private function time(string $gmt, string $local): int
    {
        if ($gmt !== '' && !str_starts_with($gmt, '0000')) {
            $t = strtotime($gmt . ' UTC');
            if ($t !== false) return $t;
        }
        return strtotime($local) ?: time();
    }

    private function importPages(): void
    {
        $front = (int) $this->src->option('page_on_front');
        $wooPages = array_filter(array_map(fn (string $o): int => (int) $this->src->option($o),
            ['woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id']));
        $pages = $this->src->posts(['page']);
        $byId = [];
        foreach ($pages as $p) $byId[(int) $p['ID']] = $p;
        $meta = $this->src->meta(array_keys($byId));
        $done = [];
        $make = function (array $page) use (&$make, &$done, $byId, $meta, $front, $wooPages): int {
            $wpId = (int) $page['ID'];
            if (isset($done[$wpId])) return $done[$wpId];
            if ($wpId === $front || in_array($wpId, $wooPages, true)) {
                $this->count('skipped', 'page');
                $this->warn("跳过首页 / 商城页面：{$page['post_title']}（#{$wpId}），首页请在网页构建器里重做");
                return $done[$wpId] = 0;
            }
            $lang = $this->langOf('post_page', $wpId);
            if ($lang === null) return $done[$wpId] = 0;
            $parentWp = (int) $page['post_parent'];
            $parent = isset($byId[$parentWp]) ? $make($byId[$parentWp]) : 0;
            $m = $meta[$wpId] ?? [];
            $title = html_entity_decode((string) $page['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $seo = $this->postSeo($m, $title);
            $existing = $this->mapped('page', $wpId, 'channels');
            $data = ['lang' => $lang, 'parent_id' => $parent, 'name' => $title, 'type' => 'page',
                'slug' => $this->slug((string) $page['post_name'], $title, 'channels', $existing),
                'content' => $this->content->clean((string) $page['post_content'], (string) ($m['_elementor_data'] ?? ''), (string) ($m['mfn-page-items'] ?? '')),
                'image' => $this->attachments[(int) ($m['_thumbnail_id'] ?? 0)] ?? '',
                'seo_title' => $seo['title'], 'seo_keywords' => $seo['keywords'], 'seo_description' => $seo['description'],
                'redirect_type' => 'none', 'is_nav' => 0, 'status' => 1, 'sort_order' => (int) $page['menu_order'], 'updated_at' => time()];
            if ($existing === 0) $data['created_at'] = $this->time((string) $page['post_date_gmt'], (string) $page['post_date']);
            $this->level('page', $title);
            $id = $this->upsert('page', $wpId, 'channels', $data);
            $this->group('page', 'channels', $this->trid('post_page', $wpId), $id);
            $ancestors = [];
            for ($a = $parentWp, $guard = 0; $a > 0 && isset($byId[$a]) && $guard < 20; $a = (int) $byId[$a]['post_parent'], $guard++) {
                array_unshift($ancestors, (string) $byId[$a]['post_name']);
            }
            $this->register('channel', $id, $this->langPrefix($lang) . $this->links->page((string) $page['post_name'], $ancestors), $lang, "单页 {$title}");
            return $done[$wpId] = $id;
        };
        foreach ($this->defaultLanguageFirst($pages, 'post_page', 'ID') as $page) $make($page);
    }

    // ── Contact Form 7 表单 ───────────────────────────────────────────────

    /**
     * CF7 表单 → 表单模板。默认语言那份建模板；WPML 翻译进同一模板的各语言字段
     * （en / ja 有现成的列，其它语言存 metas：owner_type=form_template_lang，meta_key=fields_<语言> / success_<语言>）。
     */
    private function importForms(): void
    {
        $forms = $this->src->posts(['wpcf7_contact_form']);
        if ($forms === []) return;
        $meta = $this->src->meta(array_map(static fn (array $f): int => (int) $f['ID'], $forms));
        $converter = new WordPressForms();
        $base = [];
        $reportIndex = [];
        foreach ($this->defaultLanguageFirst($forms, 'post_wpcf7_contact_form', 'ID') as $form) {
            $wpId = (int) $form['ID'];
            $lang = $this->langOf('post_wpcf7_contact_form', $wpId);
            if ($lang === null) continue;
            $m = $meta[$wpId] ?? [];
            $fields = trim($converter->convert((string) ($m['_form'] ?? $form['post_content'])));
            $success = WordPressForms::successMessage((string) ($m['_messages'] ?? ''));
            $title = html_entity_decode((string) $form['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?: 'Form ' . $wpId;
            $trid = $this->trid('post_wpcf7_contact_form', $wpId);
            $templateId = $trid > 0 ? ($base[$trid] ?? 0) : 0;
            if ($templateId === 0) {
                $existing = $this->mapped('form', $wpId, 'form_templates');
                $slug = $this->slug((string) $form['post_name'], $title, 'form_templates', $existing);
                if (preg_match('/^[a-zA-Z0-9_-]+$/', $slug) !== 1) $slug = 'wp-form-' . $wpId;
                $data = ['name' => $title, 'slug' => $slug, 'fields' => $fields, 'status' => 1];
                if ($success !== '') $data['success_message'] = $success;
                if ($existing === 0) $data['created_at'] = time();
                $templateId = $this->upsert('form', $wpId, 'form_templates', $data);
                if ($trid > 0) $base[$trid] = $templateId;
                $this->report['forms'][] = ['name' => $title, 'slug' => (string) db()->fetchColumn('SELECT slug FROM ' . DB_PREFIX . 'form_templates WHERE id = ?', [$templateId]), 'lang' => $lang, 'translations' => []];
                $reportIndex[$templateId] = array_key_last($this->report['forms']);
            } elseif (in_array($lang, ['en', 'ja'], true)) {
                db()->update('form_templates', ['fields_' . $lang => $fields, 'name_' . $lang => $title]
                    + ($success !== '' ? ['success_message_' . $lang => $success] : []), 'id = ?', [$templateId]);
            } else {
                setMeta('form_template_lang', $templateId, 'fields_' . $lang, $fields);
                if ($success !== '') setMeta('form_template_lang', $templateId, 'success_' . $lang, $success);
            }
            if (isset($reportIndex[$templateId]) && $lang !== $this->report['forms'][$reportIndex[$templateId]]['lang']) {
                $this->report['forms'][$reportIndex[$templateId]]['translations'][] = $lang;
            }
            $slug = (string) db()->fetchColumn('SELECT slug FROM ' . DB_PREFIX . 'form_templates WHERE id = ?', [$templateId]);
            $this->formSlugs[(string) $wpId] = $slug;
            $hash = (string) ($m['_hash'] ?? '');
            if ($hash !== '') $this->formSlugs[substr($hash, 0, 7)] = $slug;
        }
        foreach ($converter->dropped as $tag => $n) $this->warn("表单里去掉了不支持的标签 [{$tag}]（{$n} 处），请在表单设计里检查");
    }

    // ── 导航菜单 ────────────────────────────────────────────────────────────

    /**
     * WordPress 菜单 → 菜单组（最多三级，更深的并到第三级）。每种语言的菜单各建一组，
     * 非默认语言的组记成默认语言组的「语言版本」（metas owner_type=nav_menu_lang），
     * 页头导航元素选默认语言那组即可，各语言自动换成自己的菜单（NavMenuModel::treeFor）。
     */
    private function importMenus(): void
    {
        $menus = $this->src->terms(['nav_menu']);
        if ($menus === []) return;
        $items = $this->src->posts(['nav_menu_item']);
        $meta = $this->src->meta(array_map(static fn (array $i): int => (int) $i['ID'], $items));
        $byMenu = [];
        foreach ($items as $item) {
            foreach ($this->relationships[(int) $item['ID']] ?? [] as $tt) {
                if (isset($menus[$tt])) $byMenu[$tt][] = $item;
            }
        }
        $locations = $this->menuLocations();
        $base = [];
        $model = new NavMenuModel();
        foreach ($this->defaultLanguageFirst(array_values($menus), 'tax_nav_menu', 'tt_id') as $menu) {
            $tt = (int) $menu['tt_id'];
            $lang = $this->langOf('tax_nav_menu', $tt);
            if ($lang === null) continue;
            $tree = $this->menuTree($byMenu[$tt] ?? [], $meta);
            $count = 0;
            $clean = $model->sanitizeItems($tree, 1, $count);
            $name = mb_substr($menu['name'] . ($lang !== $this->defaultLang ? " ({$lang})" : ''), 0, 100);
            $existing = $this->mapped('nav_menu', $tt, 'nav_menus');
            $data = ['name' => $name, 'items' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => time()];
            if ($existing === 0) $data += ['sort_order' => 0, 'created_at' => time()];
            $id = $this->upsert('nav_menu', $tt, 'nav_menus', $data);
            $trid = $this->trid('tax_nav_menu', $tt);
            if ($lang === $this->defaultLang || !isset($base[$trid]) || $trid === 0) {
                if ($trid > 0) $base[$trid] = $id;
            } else {
                setMeta('nav_menu_lang', $base[$trid], $lang, (string) $id);
            }
            $this->report['menus'][] = ['id' => $id, 'name' => $name, 'lang' => $lang, 'items' => $count,
                'locations' => $locations[(int) $menu['term_id']] ?? []];
        }
    }

    /** 主题菜单位置：term_id → 位置名（primary、footer…）。 @return array<int,list<string>> */
    private function menuLocations(): array
    {
        $mods = $this->src->optionArray('theme_mods_' . (string) $this->src->option('stylesheet'));
        $out = [];
        foreach (is_array($mods['nav_menu_locations'] ?? null) ? $mods['nav_menu_locations'] : [] as $location => $termId) {
            if ((int) $termId > 0) $out[(int) $termId][] = (string) $location;
        }
        return $out;
    }

    /**
     * 菜单项 → 菜单组的项树。页面 / 文章分类引用栏目（名称跟随栏目），文章 / 产品 / 产品分类 / 标签用登记的网址，
     * 自定义链接把原站域名换成站内路径。没有链接的父项（# 占位）用第一个子项的链接。
     *
     * @param list<array<string,mixed>> $items
     * @param array<int,array<string,string>> $meta
     * @return list<array<string,mixed>>
     */
    private function menuTree(array $items, array $meta): array
    {
        usort($items, static fn (array $a, array $b): int => (int) $a['menu_order'] <=> (int) $b['menu_order']);
        $nodes = [];
        $parents = [];
        foreach ($items as $item) {
            $id = (int) $item['ID'];
            $nodes[$id] = $this->menuNode($item, $meta[$id] ?? []);
            $parents[$id] = (int) ($meta[$id]['_menu_item_menu_item_parent'] ?? 0);
        }
        $children = [];
        foreach ($parents as $id => $parent) $children[isset($nodes[$parent]) ? $parent : 0][] = $id;
        $build = function (int $parent, int $depth) use (&$build, &$nodes, $children): array {
            $out = [];
            foreach ($children[$parent] ?? [] as $id) {
                $node = $nodes[$id];
                $kids = $build($id, $depth + 1);
                if ($depth >= NavMenuModel::MAX_DEPTH) {
                    // 第三级以下：自己留在第三级，子孙并成它后面的同级项
                    $out[] = ['children' => []] + $node;
                    foreach ($kids as $kid) $out[] = $kid;
                    if ($kids !== []) $this->warn("菜单「{$node['label']}」下超过三级，更深的项已并到第三级");
                    continue;
                }
                $node['children'] = $kids;
                if ($node['channel_id'] === 0 && $node['url'] === '' && $kids !== []) {
                    $node['url'] = $kids[0]['url'] !== '' ? $kids[0]['url'] : '';
                    if ($node['url'] === '' && $kids[0]['channel_id'] > 0) {
                        $node['channel_id'] = 0;
                        $node['url'] = '/';
                    }
                }
                if ($node['channel_id'] === 0 && ($node['url'] === '' || $node['label'] === '')) {
                    $this->warn("菜单项「{$node['label']}」没有可用的链接，已跳过");
                    foreach ($kids as $kid) $out[] = $kid;
                    continue;
                }
                $out[] = $node;
            }
            return $out;
        };
        return $build(0, 1);
    }

    /** @param array<string,mixed> $item @param array<string,string> $m @return array{channel_id:int,label:string,url:string,target:string,icon:string,children:list<mixed>} */
    private function menuNode(array $item, array $m): array
    {
        $label = trim(html_entity_decode((string) $item['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $type = (string) ($m['_menu_item_type'] ?? 'custom');
        $object = (string) ($m['_menu_item_object'] ?? '');
        $objectId = (int) ($m['_menu_item_object_id'] ?? 0);
        $node = ['channel_id' => 0, 'label' => $label, 'url' => '', 'target' => ($m['_menu_item_target'] ?? '') === '_blank' ? '_blank' : '', 'icon' => '', 'children' => []];
        $titleOf = static fn (string $table, string $column, int $id): string => $id > 0 ? (string) (db()->fetchColumn('SELECT ' . $column . ' FROM ' . DB_PREFIX . $table . ' WHERE id = ?', [$id]) ?: '') : '';
        if ($type === 'post_type' && $object === 'page') {
            $node['channel_id'] = $this->ids['page'][$objectId] ?? $this->mapped('page', $objectId, 'channels');
        } elseif ($type === 'post_type' && $object === 'post') {
            $id = $this->ids['post'][$objectId] ?? $this->mapped('post', $objectId, 'contents');
            $node['url'] = productRouteModel()->pathFor('content', $id);
            $node['label'] = $label !== '' ? $label : $titleOf('contents', 'title', $id);
        } elseif ($type === 'post_type' && $object === 'product') {
            $id = $this->ids['product'][$objectId] ?? $this->mapped('product', $objectId, 'products');
            $node['url'] = productRouteModel()->pathFor('product', $id);
            $node['label'] = $label !== '' ? $label : $titleOf('products', 'title', $id);
        } elseif ($type === 'taxonomy' && in_array($object, ['category', 'product_cat', 'post_tag', 'product_tag'], true)) {
            $tt = $this->ttByTerm[$object . ':' . $objectId] ?? 0;
            $kind = $object;
            $route = LegacyUrls::KIND_ROUTE[$object];
            $id = $this->ids[$kind][$tt] ?? (int) (getMeta(self::MAP, $tt, $kind) ?? 0);
            if ($object === 'category') {
                $node['channel_id'] = $id;
            } else {
                $node['url'] = productRouteModel()->pathFor($route, $id);
                if ($node['label'] === '') $node['label'] = (string) ($this->terms[$tt]['name'] ?? '');
            }
        } elseif ($type === 'custom') {
            $url = trim((string) ($m['_menu_item_url'] ?? ''));
            if (preg_match('~^(https?:)?//([^/]+)(/.*)?$~i', $url, $u) === 1) {
                $url = $this->content->localPath(strtolower($u[2]), $u[3] ?? '/') ?? $url;
            }
            $node['url'] = ($url === '' || str_starts_with($url, '#')) ? '' : $url;
        } else {
            $this->warn("菜单项「{$label}」的类型 {$type}/{$object} 不支持，请导入后手动补");
        }
        return $node;
    }

    /** 主分类：Yoast 主分类优先，其次第一个分类。 @return array<string,mixed>|null */
    private function primaryTerm(int $objectId, string $taxonomy, array $meta): ?array
    {
        $primary = (int) ($meta['_yoast_wpseo_primary_' . $taxonomy] ?? 0);
        $terms = $this->termsOf($objectId, $taxonomy);
        foreach ($terms as $t) if ((int) $t['term_id'] === $primary) return $t;
        return $terms[0] ?? null;
    }

    private function importPosts(): void
    {
        $posts = $this->src->posts(['post']);
        $meta = $this->src->meta(array_map(static fn (array $p): int => (int) $p['ID'], $posts));
        foreach ($this->defaultLanguageFirst($posts, 'post_post', 'ID') as $post) {
            $wpId = (int) $post['ID'];
            $lang = $this->langOf('post_post', $wpId);
            if ($lang === null) continue;
            $m = $meta[$wpId] ?? [];
            $title = html_entity_decode((string) $post['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $primary = $this->primaryTerm($wpId, 'category', $m);
            $channel = $primary !== null ? (int) ($this->ids['category'][(int) $primary['tt_id']] ?? 0) : 0;
            if ($channel === 0) $channel = $this->newsChannel($lang);
            $tags = array_map(static fn (array $t): string => (string) $t['name'], $this->termsOf($wpId, 'post_tag'));
            $seo = $this->postSeo($m, $title);
            $existing = $this->mapped('post', $wpId, 'contents');
            $time = $this->time((string) $post['post_date_gmt'], (string) $post['post_date']);
            $data = ['lang' => $lang, 'channel_id' => $channel, 'type' => 'article', 'title' => $title,
                'slug' => $this->slug((string) $post['post_name'], $title, 'contents', $existing),
                'cover' => $this->attachments[(int) ($m['_thumbnail_id'] ?? 0)] ?? '',
                'summary' => trim(strip_tags((string) $post['post_excerpt'])),
                'content' => $this->content->clean((string) $post['post_content'], (string) ($m['_elementor_data'] ?? ''), (string) ($m['mfn-page-items'] ?? '')),
                'content_type' => 'html', 'tags' => implode(', ', $tags),
                'seo_title' => $seo['title'], 'seo_keywords' => $seo['keywords'], 'seo_description' => $seo['description'],
                'status' => 1, 'publish_time' => $time, 'updated_at' => $this->time((string) $post['post_modified_gmt'], '')];
            if ($existing === 0) $data['created_at'] = $time;
            $this->level('post', $title);
            $id = $this->upsert('post', $wpId, 'contents', $data);
            $this->group('post', 'contents', $this->trid('post_post', $wpId), $id);
            $categoryPath = $primary !== null ? $this->termPath('category', (int) $primary['term_id']) : '';
            $this->register('content', $id, $this->langPrefix($lang) . $this->links->post($post, $categoryPath, $this->users[(int) $post['post_author']] ?? ''), $lang, "文章 {$title}");
        }
    }

    // ── 自定义内容类型（2.0.5）────────────────────────────────────────────

    /**
     * type_map 里的自定义类型：映射成案例时，它的分类法变成案例分类（type=case 的栏目，保留父子），
     * 没分类的条目放进本语言的第一个顶层案例栏目（没有就建一个「案例」）；映射成文章时放进新闻栏目。
     * 网址按原站重写规则里的前缀登记（如 /portfolio-item/别名/、/portfolio-types/别名/）。
     */
    private function importCustomTypes(): void
    {
        foreach ($this->typeMap as $type => $target) {
            $base = $this->src->rewriteBase($type) ?? $type;
            $termChannels = [];
            if ($target === 'case') {
                foreach ($this->src->taxonomiesOf($type) as $taxonomy) {
                    $taxBase = $this->src->rewriteBase($taxonomy) ?? $taxonomy;
                    $done = [];
                    $make = function (array $term) use (&$make, &$done, $taxonomy, $taxBase): int {
                        $tt = (int) $term['tt_id'];
                        if (isset($done[$tt])) return $done[$tt];
                        $lang = $this->langOf('tax_' . $taxonomy, $tt);
                        if ($lang === null) return $done[$tt] = 0;
                        $parentTerm = (int) $term['parent'] > 0 ? $this->term($taxonomy, (int) $term['parent']) : null;
                        $existing = $this->mapped('tax_' . $taxonomy, $tt, 'channels');
                        $data = ['lang' => $lang, 'parent_id' => $parentTerm !== null ? $make($parentTerm) : 0, 'name' => $term['name'], 'type' => 'case',
                            'slug' => $this->slug((string) $term['slug'], (string) $term['name'], 'channels', $existing),
                            'description' => strip_tags((string) $term['description']), 'status' => 1, 'is_nav' => 0, 'updated_at' => time()];
                        if ($existing === 0) $data['created_at'] = time();
                        $id = $this->upsert('tax_' . $taxonomy, $tt, 'channels', $data);
                        $this->group('tax_' . $taxonomy, 'channels', $this->trid('tax_' . $taxonomy, $tt), $id);
                        $path = $this->termPath($taxonomy, (int) $term['term_id']);
                        $this->register('channel', $id, $this->langPrefix($lang) . '/' . $taxBase . '/' . $path . '/', $lang, "案例分类 {$term['name']}");
                        return $done[$tt] = $id;
                    };
                    foreach ($this->termsIn($taxonomy) as $term) $termChannels[$taxonomy][(int) $term['tt_id']] = $make($term);
                }
            }
            $items = $this->src->posts([$type]);
            $meta = $this->src->meta(array_map(static fn (array $p): int => (int) $p['ID'], $items));
            foreach ($this->defaultLanguageFirst($items, 'post_' . $type, 'ID') as $item) {
                $wpId = (int) $item['ID'];
                $lang = $this->langOf('post_' . $type, $wpId);
                if ($lang === null) continue;
                $m = $meta[$wpId] ?? [];
                $title = html_entity_decode((string) $item['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $channel = 0;
                if ($target === 'case') {
                    foreach ($termChannels as $taxonomy => $map) {
                        foreach ($this->termsOf($wpId, $taxonomy) as $t) { $channel = (int) ($map[(int) $t['tt_id']] ?? 0); if ($channel > 0) break 2; }
                    }
                    if ($channel === 0) $channel = $this->caseChannel($lang, $base);
                } else {
                    $channel = $this->newsChannel($lang);
                }
                $seo = $this->postSeo($m, $title);
                $existing = $this->mapped('cpt_' . $type, $wpId, 'contents');
                $time = $this->time((string) $item['post_date_gmt'], (string) $item['post_date']);
                $data = ['lang' => $lang, 'channel_id' => $channel, 'type' => $target, 'title' => $title,
                    'slug' => $this->slug((string) $item['post_name'], $title, 'contents', $existing),
                    'cover' => $this->attachments[(int) ($m['_thumbnail_id'] ?? 0)] ?? '',
                    'summary' => trim(strip_tags((string) $item['post_excerpt'])),
                    'content' => $this->content->clean((string) $item['post_content'], (string) ($m['_elementor_data'] ?? ''), (string) ($m['mfn-page-items'] ?? '')),
                    'content_type' => 'html',
                    'seo_title' => $seo['title'], 'seo_keywords' => $seo['keywords'], 'seo_description' => $seo['description'],
                    'status' => 1, 'publish_time' => $time, 'updated_at' => $this->time((string) $item['post_modified_gmt'], '')];
                if ($existing === 0) $data['created_at'] = $time;
                $this->level($type, $title);
                $id = $this->upsert('cpt_' . $type, $wpId, 'contents', $data);
                $this->group('cpt_' . $type, 'contents', $this->trid('post_' . $type, $wpId), $id);
                $this->register('content', $id, $this->langPrefix($lang) . '/' . $base . '/' . rawurldecode((string) $item['post_name']) . '/', $lang, "{$type} {$title}");
            }
        }
    }

    /** 本语言的第一个顶层案例栏目；没有就建一个（名称「案例」，别名取原站前缀）。 */
    private function caseChannel(string $lang, string $base): int
    {
        $cache = &$this->caseChannels;
        if (isset($cache[$lang])) return $cache[$lang];
        $row = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . "channels WHERE type = 'case' AND parent_id = 0 AND lang = ? ORDER BY sort_order, id LIMIT 1", [$lang]);
        if ($row) return $cache[$lang] = (int) $row['id'];
        $id = (int) db()->insert('channels', ['lang' => $lang, 'parent_id' => 0, 'name' => '案例', 'type' => 'case',
            'slug' => $this->slug(basename($base), 'case', 'channels', 0), 'status' => 1, 'is_nav' => 0, 'created_at' => time(), 'updated_at' => time()]);
        $this->count('created', 'case_channel');
        return $cache[$lang] = $id;
    }

    private function importProducts(): void
    {
        $products = $this->src->posts(['product']);
        $meta = $this->src->meta(array_map(static fn (array $p): int => (int) $p['ID'], $products));
        foreach ($this->defaultLanguageFirst($products, 'post_product', 'ID') as $product) {
            $wpId = (int) $product['ID'];
            $lang = $this->langOf('post_product', $wpId);
            if ($lang === null) continue;
            $m = $meta[$wpId] ?? [];
            $title = html_entity_decode((string) $product['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $primary = $this->primaryTerm($wpId, 'product_cat', $m);
            $category = $primary !== null ? (int) ($this->ids['product_cat'][(int) $primary['tt_id']] ?? 0) : 0;
            $tagTerms = $this->termsOf($wpId, 'product_tag');
            $gallery = [];
            foreach (preg_split('/\s*,\s*/', (string) ($m['_product_image_gallery'] ?? '')) ?: [] as $att) {
                if (isset($this->attachments[(int) $att])) $gallery[] = $this->attachments[(int) $att];
            }
            $existing = $this->mapped('product', $wpId, 'products');
            $data = ['lang' => $lang, 'category_id' => $category, 'title' => $title,
                'slug' => $this->slug((string) $product['post_name'], $title, 'products', $existing),
                'cover' => $this->attachments[(int) ($m['_thumbnail_id'] ?? 0)] ?? '',
                'images' => json_encode(array_values(array_unique($gallery)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'summary' => trim(strip_tags((string) $product['post_excerpt'])),
                'content' => $this->content->clean((string) $product['post_content'], (string) ($m['_elementor_data'] ?? ''), (string) ($m['mfn-page-items'] ?? '')),
                'model' => trim((string) ($m['_sku'] ?? '')), 'specs' => $this->specs($wpId, (string) ($m['_product_attributes'] ?? '')),
                'tags' => implode(', ', array_map(static fn (array $t): string => (string) $t['name'], $tagTerms)),
                'status' => 1, 'updated_at' => time()];
            if ($existing === 0) $data['created_at'] = $this->time((string) $product['post_date_gmt'], (string) $product['post_date']);
            $this->level('product', $title);
            $id = $this->upsert('product', $wpId, 'products', $data);
            $this->group('product', 'products', $this->trid('post_product', $wpId), $id);
            db()->delete('product_tag_map', 'product_id = ?', [$id]);
            foreach ($tagTerms as $t) {
                $tagId = (int) ($this->ids['product_tag'][(int) $t['tt_id']] ?? 0);
                if ($tagId > 0) db()->insert('product_tag_map', ['product_id' => $id, 'tag_id' => $tagId]);
            }
            $seo = $this->postSeo($m, $title);
            foreach (['seo_title' => $seo['title'], 'seo_description' => $seo['description'], 'seo_keywords' => $seo['keywords']] as $key => $value) {
                setMeta('product', $id, $key, $value);
            }
            $categoryPath = $primary !== null ? $this->termPath('product_cat', (int) $primary['term_id']) : '';
            $this->register('product', $id, $this->langPrefix($lang) . $this->links->product((string) $product['post_name'], $categoryPath), $lang, "产品 {$title}");
        }
    }

    // ── Advanced Custom Fields ────────────────────────────────────────────

    /** ACF 字段组 → 扩展字段定义（按挂载规则分到产品 / 内容 / 栏目 / 产品分类 / 全站选项）。 */
    private function importAcfFields(): void
    {
        $groupPosts = $this->src->posts(['acf-field-group']);
        if ($groupPosts === []) return;
        $fieldPosts = $this->src->posts(['acf-field']);
        foreach (WordPressAcf::groups($groupPosts, $fieldPosts) as $gi => $group) {
            // WPML 翻译过的字段组只取默认语言那份（字段是共用的，值各语言各存）
            if ($this->langOf('post_acf-field-group', $group['id']) !== $this->defaultLang) continue;
            $unknown = [];
            $owners = WordPressAcf::owners($group['location'], $unknown);
            if ($unknown !== []) $this->warn("ACF 字段组「{$group['title']}」有认不出的挂载规则（" . implode('；', array_unique($unknown)) . '），这部分没有导入');
            if ($owners === []) continue;
            foreach ($owners as $target) {
                $owner = $target['owner'];
                $location = $this->acfLocation($target['terms']);
                $count = 0;
                foreach ($group['fields'] as $fi => $acf) {
                    $skipped = null;
                    $def = WordPressAcf::definition($acf, $skipped);
                    if ($def === null) {
                        if ($skipped !== '' && $skipped !== null) {
                            $this->count('skipped', 'acf_' . $skipped);
                            $this->warn("ACF 字段「{$acf['label']}」是 {$skipped} 类型，本站没有对应类型，没有导入");
                        }
                        continue;
                    }
                    $key = WordPressAcf::key((string) $acf['name']);
                    if (strlen($key) < 2) $key = 'f_' . $key;
                    $kind = 'acf_field:' . $owner;
                    $data = ['owner_type' => $owner, 'field_key' => $key, 'field_name' => mb_substr((string) $acf['label'] ?: $key, 0, 100),
                        'field_type' => $def['field_type'], 'options' => $def['options'], 'placeholder' => $def['placeholder'],
                        'help_text' => $def['help_text'], 'is_required' => $def['is_required'], 'sort_order' => $gi * 100 + $fi, 'status' => 1];
                    $id = $this->mapped($kind, (int) $acf['id'], 'extfields')
                        ?: (int) db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'extfields WHERE owner_type = ? AND field_key = ?', [$owner, $key]);
                    if ($id > 0) {
                        db()->update('extfields', $data, 'id = ?', [$id]);
                        $this->count('updated', 'acf_field');
                    } else {
                        $id = (int) db()->insert('extfields', $data + ['created_at' => time()]);
                        $this->count('created', 'acf_field');
                    }
                    setMeta(self::MAP, (int) $acf['id'], $kind, (string) $id);
                    $config = $def['config'];
                    if ($location !== [] && !ExtFields::isProOwner($owner)) $config['location'] = $location;
                    ExtFields::saveConfig($id, $def['field_type'], $config);
                    $this->acfFields[$owner][] = ['key' => $key, 'acf' => $acf];
                    $count++;
                }
                $this->report['acf'][] = ['group' => $group['title'], 'owner' => $owner, 'fields' => $count];
            }
        }
        ExtFields::flushCache();
    }

    /** 挂载规则里的分类别名 → 本站分类 id（产品分类 / 栏目）。 @param array<string,list<string>> $terms @return list<int> */
    private function acfLocation(array $terms): array
    {
        $ids = [];
        foreach ($terms as $taxonomy => $slugs) {
            foreach ($this->terms as $tt => $term) {
                if ($term['taxonomy'] === $taxonomy && in_array((string) $term['slug'], $slugs, true)) {
                    $id = (int) ($this->ids[$taxonomy][$tt] ?? 0);
                    if ($id > 0) $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** ACF 字段值：产品、文章、单页（栏目）、分类、全站选项。 */
    private function importAcfValues(): void
    {
        if ($this->acfFields === []) return;
        $resolve = function (string $kind, int $wpId): string {
            if ($wpId <= 0) return '';
            return match ($kind) {
                'attachment' => $this->attachments[$wpId] ?? '',
                'product' => (string) ($this->ids['product'][$wpId] ?? $this->mapped('product', $wpId, 'products')),
                default => (string) ($this->ids['post'][$wpId] ?? $this->mapped('post', $wpId, 'contents')),
            };
        };
        $postOwners = ['product' => 'product', 'content' => 'post', 'channel' => 'page'];
        foreach ($postOwners as $owner => $kind) {
            $map = $this->ids[$kind] ?? [];
            if (!isset($this->acfFields[$owner]) || $map === []) continue;
            $meta = $this->src->meta(array_keys($map));
            foreach ($map as $wpId => $cmsId) $this->writeAcf($owner, (int) $cmsId, $meta[$wpId] ?? [], $resolve);
        }
        foreach (['channel' => 'category', 'product_category' => 'product_cat'] as $owner => $taxonomy) {
            if (!isset($this->acfFields[$owner])) continue;
            foreach ($this->ids[$taxonomy] ?? [] as $tt => $cmsId) {
                $termId = (int) ($this->terms[$tt]['term_id'] ?? 0);
                if ($termId > 0) $this->writeAcf($owner, (int) $cmsId, $this->src->termMeta($termId), $resolve);
            }
        }
        if (isset($this->acfFields['site'])) {
            $this->writeAcf('site', 0, $this->src->optionsWithPrefix('options_'), $resolve);
        }
    }

    /** @param array<string,string> $meta @param callable(string,int):string $resolve */
    private function writeAcf(string $owner, int $ownerId, array $meta, callable $resolve): void
    {
        foreach ($this->acfFields[$owner] ?? [] as $item) {
            $field = ExtFields::field($owner, $item['key']);
            if ($field === null) continue;
            $raw = WordPressAcf::value($item['acf'], $meta, '', $resolve);
            if ($field['field_type'] === 'richtext' && is_string($raw) && $raw !== '') $raw = $this->content->clean($raw);
            $value = ExtFields::sanitize($field, $raw);
            $previous = getMeta($owner, $ownerId, $item['key']);
            if ($value === '' && $previous === null) continue;
            setMeta($owner, $ownerId, $item['key'], $value);
            $this->count($previous === null ? 'created' : 'updated', 'acf_value');
        }
    }

    /** WooCommerce 属性 → 产品参数 [{name, value}]（分类属性取分类名，多个值用逗号连）。 */
    private function specs(int $wpId, string $serialized): string
    {
        $out = [];
        foreach (WordPressSource::unserializeArray($serialized) as $key => $attr) {
            if (!is_array($attr) || empty($attr['is_visible'])) continue;
            if (!empty($attr['is_taxonomy'])) {
                $taxonomy = (string) ($attr['name'] ?? $key);
                $names = array_map(static fn (array $t): string => (string) $t['name'], $this->src->objectTerms($wpId, $taxonomy));
                $name = $this->src->attributeLabel($taxonomy);
                $value = implode(', ', $names);
            } else {
                $name = (string) ($attr['name'] ?? $key);
                $value = implode(', ', array_map('trim', explode('|', (string) ($attr['value'] ?? ''))));
            }
            if ($name !== '' && $value !== '') $out[] = ['name' => $name, 'value' => $value];
        }
        return (string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
