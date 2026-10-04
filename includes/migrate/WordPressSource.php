<?php
declare(strict_types=1);

/**
 * 读 WordPress 数据库（WordPress 导入用）：只读、只用通用 SQL，MySQL 正式库与 SQLite 测试库都能跑。
 * 覆盖 WordPress 核心、WooCommerce（商品与商品分类 / 标签）、Yoast SEO（文章 meta 与分类 option）、WPML（icl_translations）。
 */
final class WordPressSource
{
    private PDO $pdo;
    private string $p;
    /** @var array<string,?string> */
    private array $options = [];

    /** @psalm-suppress PossiblyUnusedMethod 调用方是命令行 tools/wp-import.php 与测试 */
    public function __construct(PDO $pdo, string $prefix = 'wp_')
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,32}$/', $prefix)) throw new InvalidArgumentException('wp_prefix_invalid');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;
        $this->p = $prefix;
    }

    /** @param list<scalar> $params @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$this->p}{$table} LIMIT 1");
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** 当前连接是不是一个 WordPress 库（有 posts 与 options 表）。 */
    public function looksLikeWordPress(): bool
    {
        return $this->tableExists('posts') && $this->tableExists('options');
    }

    public function option(string $name): ?string
    {
        if (!array_key_exists($name, $this->options)) {
            $row = $this->rows("SELECT option_value FROM {$this->p}options WHERE option_name = ?", [$name])[0] ?? null;
            $this->options[$name] = $row === null ? null : (string) $row['option_value'];
        }
        return $this->options[$name];
    }

    /** 序列化的 option（WordPress 存 PHP serialize），不认识或损坏时返回 []。 @return array<array-key,mixed> */
    public function optionArray(string $name): array
    {
        return self::unserializeArray((string) $this->option($name));
    }

    /** @return array<array-key,mixed> */
    /**
     * 名字带某前缀的全部选项（ACF 选项页存为 options_字段名），返回 [去掉前缀的名字 => 值]。
     * @return array<string,string>
     */
    public function optionsWithPrefix(string $prefix): array
    {
        $out = [];
        try {
            // 转义符用 !：MySQL 与 SQLite 对反斜杠的处理不同，! 两边一致
            foreach ($this->rows("SELECT option_name, option_value FROM {$this->p}options WHERE option_name LIKE ? ESCAPE '!'", [str_replace(['!', '_', '%'], ['!!', '!_', '!%'], $prefix) . '%']) as $r) {
                $out[substr((string) $r['option_name'], strlen($prefix))] = (string) $r['option_value'];
            }
        } catch (Throwable) {
            return [];
        }
        return $out;
    }

    public static function unserializeArray(string $value): array
    {
        if ($value === '' || !preg_match('/^a:\d+:\{/', $value)) return [];
        $data = @unserialize($value, ['allowed_classes' => false]);
        return is_array($data) ? $data : [];
    }

    /**
     * @param list<string> $types
     * @return list<array<string,mixed>>
     */
    public function posts(array $types, array $statuses = ['publish']): array
    {
        $tp = implode(',', array_fill(0, count($types), '?'));
        $sp = implode(',', array_fill(0, count($statuses), '?'));
        return $this->rows("SELECT ID, post_author, post_date, post_date_gmt, post_modified_gmt, post_content, post_title, post_excerpt,
                post_status, post_name, post_parent, menu_order, post_type, guid
            FROM {$this->p}posts WHERE post_type IN ({$tp}) AND post_status IN ({$sp}) ORDER BY ID", array_merge($types, $statuses));
    }

    /** @param list<int> $ids @return array<int,array<string,string>> 每篇的 meta（同名取第一条） */
    public function meta(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach ($this->rows("SELECT post_id, meta_key, meta_value FROM {$this->p}postmeta WHERE post_id IN ({$ph}) ORDER BY meta_id", $chunk) as $r) {
                $out[(int) $r['post_id']][(string) $r['meta_key']] ??= (string) $r['meta_value'];
            }
        }
        return $out;
    }

    /** 附件 id → 站内路径（/wp-content/uploads/年/月/文件，保留原路径）。 @return array<int,string> */
    public function attachments(): array
    {
        $out = [];
        $files = [];
        foreach ($this->rows("SELECT post_id, meta_value FROM {$this->p}postmeta WHERE meta_key = '_wp_attached_file'") as $r) {
            $files[(int) $r['post_id']] = ltrim((string) $r['meta_value'], '/');
        }
        foreach ($this->rows("SELECT ID, guid FROM {$this->p}posts WHERE post_type = 'attachment'") as $r) {
            $id = (int) $r['ID'];
            if (isset($files[$id]) && $files[$id] !== '') {
                $out[$id] = '/wp-content/uploads/' . $files[$id];
            } elseif (preg_match('#(/wp-content/uploads/[^?\#]+)#', (string) $r['guid'], $m)) {
                $out[$id] = $m[1];
            }
        }
        return $out;
    }

    /**
     * @param list<string> $taxonomies
     * @return array<int,array{term_id:int,tt_id:int,name:string,slug:string,taxonomy:string,parent:int,description:string}> 以 term_taxonomy_id 为键
     */
    public function terms(array $taxonomies): array
    {
        $ph = implode(',', array_fill(0, count($taxonomies), '?'));
        $out = [];
        foreach ($this->rows("SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.parent, tt.description
                FROM {$this->p}terms t JOIN {$this->p}term_taxonomy tt ON tt.term_id = t.term_id
                WHERE tt.taxonomy IN ({$ph}) ORDER BY tt.parent, t.term_id", $taxonomies) as $r) {
            $out[(int) $r['term_taxonomy_id']] = ['term_id' => (int) $r['term_id'], 'tt_id' => (int) $r['term_taxonomy_id'],
                'name' => html_entity_decode((string) $r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'slug' => (string) $r['slug'],
                'taxonomy' => (string) $r['taxonomy'], 'parent' => (int) $r['parent'], 'description' => (string) $r['description']];
        }
        return $out;
    }

    /** 条目 → 关联的 term_taxonomy_id（按 term_order）。 @return array<int,list<int>> */
    public function relationships(): array
    {
        $out = [];
        foreach ($this->rows("SELECT object_id, term_taxonomy_id FROM {$this->p}term_relationships ORDER BY object_id, term_order, term_taxonomy_id") as $r) {
            $out[(int) $r['object_id']][] = (int) $r['term_taxonomy_id'];
        }
        return $out;
    }

    /** @return array<string,string> 分类的 termmeta（同名取第一条） */
    public function termMeta(int $termId): array
    {
        if (!$this->tableExists('termmeta')) return [];
        $out = [];
        foreach ($this->rows("SELECT meta_key, meta_value FROM {$this->p}termmeta WHERE term_id = ? ORDER BY meta_id", [$termId]) as $r) {
            $out[(string) $r['meta_key']] ??= (string) $r['meta_value'];
        }
        return $out;
    }

    /** 某条目在某分类法下的分类（WooCommerce 分类属性 pa_* 用）。 @return list<array{name:string,slug:string}> */
    public function objectTerms(int $objectId, string $taxonomy): array
    {
        return array_map(static fn (array $r): array => ['name' => html_entity_decode((string) $r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'slug' => (string) $r['slug']],
            $this->rows("SELECT t.name, t.slug FROM {$this->p}terms t JOIN {$this->p}term_taxonomy tt ON tt.term_id = t.term_id
                JOIN {$this->p}term_relationships r ON r.term_taxonomy_id = tt.term_taxonomy_id
                WHERE r.object_id = ? AND tt.taxonomy = ? ORDER BY r.term_order, t.term_id", [$objectId, $taxonomy]));
    }

    /** WooCommerce 分类属性（pa_xxx）的显示名；查不到时用去掉 pa_ 的名字。 */
    public function attributeLabel(string $taxonomy): string
    {
        $name = str_starts_with($taxonomy, 'pa_') ? substr($taxonomy, 3) : $taxonomy;
        if ($this->tableExists('woocommerce_attribute_taxonomies')) {
            $row = $this->rows("SELECT attribute_label FROM {$this->p}woocommerce_attribute_taxonomies WHERE attribute_name = ?", [$name])[0] ?? null;
            if ($row && (string) $row['attribute_label'] !== '') return (string) $row['attribute_label'];
        }
        return ucfirst(str_replace(['-', '_'], ' ', $name));
    }

    /** @return array<int,string> 用户 id → user_nicename（%author% 固定链接用） */
    public function users(): array
    {
        if (!$this->tableExists('users')) return [];
        $out = [];
        foreach ($this->rows("SELECT ID, user_nicename FROM {$this->p}users") as $r) $out[(int) $r['ID']] = (string) $r['user_nicename'];
        return $out;
    }

    /** WPML 是否在用（有 icl_translations 表）。 */
    public function hasWpml(): bool
    {
        return $this->tableExists('icl_translations');
    }

    /**
     * WPML 翻译关系：「元素类型:元素 id」→ [trid, 语言, 源语言]。
     * 元素类型如 post_post、post_page、post_product、tax_category、tax_post_tag、tax_product_cat、tax_product_tag；
     * 文章类元素 id 是 posts.ID，分类类是 term_taxonomy_id。
     * @return array<string,array{trid:int,lang:string,source:?string}>
     */
    public function translations(): array
    {
        if (!$this->hasWpml()) return [];
        $out = [];
        foreach ($this->rows("SELECT element_type, element_id, trid, language_code, source_language_code FROM {$this->p}icl_translations WHERE element_id IS NOT NULL") as $r) {
            $out[$r['element_type'] . ':' . (int) $r['element_id']] = ['trid' => (int) $r['trid'], 'lang' => (string) $r['language_code'],
                'source' => $r['source_language_code'] !== null && $r['source_language_code'] !== '' ? (string) $r['source_language_code'] : null];
        }
        return $out;
    }

    /** 默认语言（WPML 设置；没装 WPML 时为 null，由调用方指定）。 */
    public function defaultLanguage(): ?string
    {
        $lang = (string) ($this->optionArray('icl_sitepress_settings')['default_language'] ?? '');
        return $lang !== '' ? $lang : null;
    }

    /** WPML 语言子域名：语言 → 主机（只在「每种语言一个域名」模式下有）。 @return array<string,string> */
    public function languageDomains(): array
    {
        $settings = $this->optionArray('icl_sitepress_settings');
        if ((int) ($settings['language_negotiation_type'] ?? 0) !== 2 || !is_array($settings['language_domains'] ?? null)) return [];
        $out = [];
        foreach ($settings['language_domains'] as $lang => $domain) {
            $host = strtolower((string) preg_replace('#^https?://|/.*$#i', '', (string) $domain));
            if ($host !== '') $out[(string) $lang] = $host;
        }
        return $out;
    }

    /** 站点主机名（home / siteurl，含 www 与不带 www 两种写法）。 @return list<string> */
    public function hosts(): array
    {
        $hosts = [];
        foreach (['home', 'siteurl'] as $name) {
            $host = strtolower((string) parse_url((string) $this->option($name), PHP_URL_HOST));
            if ($host === '') continue;
            $hosts[] = $host;
            $hosts[] = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
        }
        return array_values(array_unique($hosts));
    }
}
