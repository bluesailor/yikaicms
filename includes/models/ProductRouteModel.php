<?php
declare(strict_types=1);

/**
 * 网址登记表：一张表同时管「进来的网址找哪个条目」和「条目的链接输出什么网址」。
 *
 * 起初只管产品与产品分类（类名因此叫 ProductRouteModel，表名 product_routes）。2.0.4 起为
 * WordPress 迁移推广到所有内容：文章、单页 / 栏目、相册、文章标签、产品标签。只增加 entity_type
 * 的取值、不加列不改表名——整站模板按表结构逐列比对，改结构会让所有在售整站模板导入失败。
 *
 * - content      contents 表（文章、案例、下载等）
 * - channel      channels 表（单页、列表栏目）
 * - album        albums 表
 * - content_tag  文章标签：标签只是 contents.tags 里的文字，没有自己的表；每个标签在 metas 存一行
 *                （owner_type=content_tag，meta_key=语言，meta_value=标签文字），entity_id 指这行的 id
 * - product_tag  product_tags 表
 * - product / category  产品与产品分类（原有）
 */
final class ProductRouteModel extends Model
{
    public const KINDS = ['product', 'category', 'content', 'channel', 'album', 'content_tag', 'product_tag'];
    /** 能带 /page/N/ 分页后缀的类型 */
    public const LISTING_KINDS = ['category', 'channel', 'content_tag', 'product_tag'];

    protected string $table = 'product_routes';
    /** @var array<string, array<int,string>> 按类型缓存的 id → 网址 */
    private array $pathsByKind = [];

    public function available(): bool
    {
        return db()->tableExists($this->table);
    }

    public function clearCache(): void { $this->pathsByKind = []; }

    /** 某类条目的 id → 网址（每类每个请求查一次；链接生成每条都会调 pathFor） @return array<int,string> */
    private function pathsFor(string $kind): array
    {
        if (!isset($this->pathsByKind[$kind])) {
            $map = [];
            if ($this->available()) {
                foreach (db()->fetchAll('SELECT entity_id, path FROM ' . DB_PREFIX . $this->table . ' WHERE entity_type = ?', [$kind]) as $row) {
                    $map[(int) $row['entity_id']] = (string) $row['path'];
                }
            }
            $this->pathsByKind[$kind] = $map;
        }
        return $this->pathsByKind[$kind];
    }

    public function pathFor(string $kind, int $id): string
    {
        return $id < 1 ? '' : ($this->pathsFor($kind)[$id] ?? '');
    }

    /**
     * 解码一次再按段统一编码；不收域名、查询、片段与可执行 / 资源文件名。
     * 允许字母、数字、组合符号、_ - . 以及非 ASCII 的标点与符号（日文「・」、全角「？」「（）」等，
     * WordPress 原站的网址里有）；ASCII 标点、空白、% 一律不收。
     */
    public static function normalize(string $input): string
    {
        $path = rawurldecode(trim($input));
        if ($path === '') return '';
        $segment = '(?:[\pL\pM\pN_\-.]|(?![\x00-\x7F])[\p{P}\p{S}])+';
        if (strlen($path) > 500 || !preg_match('//u', $path)
            || !preg_match('~^/(?:' . $segment . '/)*' . $segment . '/?$~uD', $path)) {
            throw new InvalidArgumentException('product_url_invalid');
        }
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '.' || $part === '..' || $part[0] === '.' || preg_match('/\.(?:php\d*|phtml|phar|asp|aspx|cgi|jpg|jpeg|png|gif|ico|css|js|webp|svg|woff|woff2|ttf|eot)$/iD', $part)) {
                throw new InvalidArgumentException('product_url_invalid');
            }
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        if (strlen($encoded) > 1500) throw new InvalidArgumentException('product_url_invalid');
        return $encoded;
    }

    private static function key(string $path): string { return hash('sha256', rtrim($path, '/')); }

    /** 含回收站里的行：进回收站的仍占着网址（resolve 按 deleted_at 给 404），彻底删除才算没了 @return array<string,mixed>|null */
    private function entity(string $kind, int $id): ?array
    {
        return match ($kind) {
            'product' => productModel()->findBy('id', $id),
            'category' => productCategoryModel()->findBy('id', $id),
            'content' => contentModel()->findBy('id', $id),
            'channel' => channelModel()->findBy('id', $id),
            'album' => albumModel()->findBy('id', $id),
            'product_tag' => db()->tableExists('product_tags')
                ? (db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'product_tags WHERE id = ?', [$id]) ?: null) : null,
            'content_tag' => self::contentTag($id),
            default => null,
        };
    }

    /** 文章标签（metas 一行）→ ['id','name','lang','status'] @return array<string,mixed>|null */
    public static function contentTag(int $id): ?array
    {
        if (!db()->tableExists('metas')) return null;
        $row = db()->fetchOne('SELECT id, meta_key, meta_value FROM ' . DB_PREFIX . 'metas WHERE id = ? AND owner_type = ?', [$id, 'content_tag']);
        return $row ? ['id' => (int) $row['id'], 'name' => (string) $row['meta_value'], 'lang' => (string) $row['meta_key'], 'status' => 1] : null;
    }

    /**
     * 登记一个文章标签（同语言同名只登记一次），返回它的 id，供 content_tag 网址指向。
     * @psalm-suppress PossiblyUnusedMethod 调用方是 WordPress 导入（迁移下一步）与 e2e 夹具
     */
    public function contentTagId(string $name, string $lang): int
    {
        $name = trim($name);
        if ($name === '' || !db()->tableExists('metas')) return 0;
        $row = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'metas WHERE owner_type = ? AND owner_id = 0 AND meta_key = ? AND meta_value = ?', ['content_tag', $lang, $name]);
        if ($row) return (int) $row['id'];
        return (int) db()->insert('metas', ['owner_type' => 'content_tag', 'owner_id' => 0, 'meta_key' => $lang, 'meta_value' => $name,
            'created_at' => time(), 'updated_at' => time()]);
    }

    /** 不能被自定义网址占用的第一段：系统目录、后台、接口、安装、站点地图等。内置前台页面名（news、contact…）可以用，登记表优先。 */
    private const RESERVED_STEMS = ['admin', 'api', 'install', 'config', 'includes', 'controllers', 'views',
        'assets', 'uploads', 'storage', 'vendor', 'themes', 'plugins', 'marketplace', 'overrides',
        'tests', 'tools', 'bin', 'html', 'index', 'sitemap', 'robots', 'favicon', 'member', 'migrations', 'lang', 'deploy'];

    public function validate(string $kind, int $id, string $input, string $lang): string
    {
        if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException('product_url_invalid');
        $path = self::normalize($input);
        if ($path === '') return '';
        if (!$this->available()) throw new InvalidArgumentException('product_url_upgrade');
        require_once dirname(__DIR__) . '/Dispatcher.php';
        $prefix = Dispatcher::languagePrefixFromPath($path);
        $default = (string) config('site_lang', 'zh-CN');
        if (($prefix ?? $default) !== $lang) throw new InvalidArgumentException('product_url_language');
        $relative = $prefix !== null ? substr($path, strlen($prefix) + 2) : substr($path, 1);
        $first = strtolower(explode('/', $relative)[0]);
        $stem = explode('.', $first)[0];
        if (in_array($stem, self::RESERVED_STEMS, true)
            || preg_match('~/page/[0-9]+/?$~D', $path)
            || is_file(ROOT_PATH . rawurldecode($path)) || is_dir(ROOT_PATH . rawurldecode($path))) {
            throw new InvalidArgumentException('product_url_reserved');
        }
        // 内置路由与插件经 dispatch_routes 加的路由（商城购物车等）都不能被占用；落到通用单页规则的可以
        // 登记网址带不带结尾斜杠都会命中，两种写法都要查
        foreach (array_unique([$path, rtrim($path, '/')]) as $variant) {
            $builtIn = Dispatcher::match($variant, Dispatcher::routes());
            if ($builtIn !== null && $builtIn['file'] !== 'page.php') throw new InvalidArgumentException('product_url_reserved');
        }
        // .html 通用路由可以自定义，但不能和别的栏目默认网址撞车（栏目给自己设网址时跳过它自己）
        if (db()->tableExists('channels')) {
            foreach (channelModel()->all() as $channel) {
                if ($kind === 'channel' && (int) $channel['id'] === $id) continue;
                if (($channel['type'] ?? '') !== 'link' && ($channel['lang'] ?? $default) === $lang) {
                    $existing = channelDefaultPrettyUrl($channel);
                    $existingPrefix = Dispatcher::languagePrefixFromPath($existing);
                    $existingRelative = $existingPrefix !== null ? substr($existing, strlen($existingPrefix) + 2) : ltrim($existing, '/');
                    if (rtrim($existingRelative, '/') === rtrim($relative, '/')) throw new InvalidArgumentException('product_url_conflict');
                }
            }
        }
        $conflict = db()->fetchOne('SELECT entity_type, entity_id FROM ' . DB_PREFIX . $this->table . ' WHERE path_key = ?', [self::key($path)]);
        if ($conflict && ($conflict['entity_type'] !== $kind || (int) $conflict['entity_id'] !== $id)) {
            // 原主人已被彻底删除（删除路径很多，不逐个挂钩）：网址收回给新条目。进回收站的仍占着，恢复后照常可用。
            if ($this->entity((string) $conflict['entity_type'], (int) $conflict['entity_id']) !== null) {
                throw new InvalidArgumentException('product_url_conflict');
            }
            $this->remove((string) $conflict['entity_type'], (int) $conflict['entity_id']);
        }
        return $path;
    }

    /** 产品与产品分类：内容与网址在一个事务里改；path_key 唯一索引同时裁决并发保存。 */
    public function saveEntity(string $kind, int $id, array $data, string $input): int
    {
        $model = $kind === 'product' ? productModel() : productCategoryModel();
        $old = $id > 0 ? $this->entity($kind, $id) : null;
        if ($id > 0 && $old === null) throw new InvalidArgumentException('product_url_missing');
        $lang = (string) ($old['lang'] ?? $data['lang'] ?? config('site_lang', 'zh-CN'));
        $path = $this->validate($kind, $id, $input, $lang);
        db()->beginTransaction();
        try {
            if ($id > 0) $model->updateById($id, $data);
            else $id = (int) $model->create($data);
            if ($this->available()) $this->write($kind, $id, $path);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            $this->clearCache();
            if ($e instanceof PDOException && in_array((string) $e->getCode(), ['23000', '23505', '19'], true)) {
                throw new InvalidArgumentException('product_url_conflict', 0, $e);
            }
            throw $e;
        }
        $this->clearCache();
        if (function_exists('do_action')) do_action('data_changed', $this->table, $id);
        return $id;
    }

    /**
     * 其余类型：条目已由各自的编辑页保存好，这里只登记（或清除）它的网址。
     * 编辑页先调 validate() 提前报错，再保存条目，最后调本方法。
     * 返回规范化后的网址（WordPress 导入记日志、测试断言用；编辑页不需要）。
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function assign(string $kind, int $id, string $input, string $lang): string
    {
        if ($id < 1) throw new InvalidArgumentException('product_url_missing');
        $path = $this->validate($kind, $id, $input, $lang);
        try {
            $this->write($kind, $id, $path);
        } catch (PDOException $e) {
            $this->clearCache();
            if (in_array((string) $e->getCode(), ['23000', '23505', '19'], true)) {
                throw new InvalidArgumentException('product_url_conflict', 0, $e);
            }
            throw $e;
        }
        $this->clearCache();
        if (function_exists('do_action')) do_action('data_changed', $this->table, $id);
        return $path;
    }

    /**
     * 条目删除或进回收站时调用：网址一起释放，免得留着挡住别的条目
     */
    public function remove(string $kind, int $id): void
    {
        if ($id < 1 || !$this->available()) return;
        db()->delete($this->table, 'entity_type = ? AND entity_id = ?', [$kind, $id]);
        $this->clearCache();
    }

    private function write(string $kind, int $id, string $path): void
    {
        db()->delete($this->table, 'entity_type = ? AND entity_id = ?', [$kind, $id]);
        if ($path !== '') db()->insert($this->table, [
            'entity_type' => $kind, 'entity_id' => $id, 'path' => $path, 'path_key' => self::key($path),
        ]);
    }

    private const TRANSLATED_TABLES = ['product' => 'products', 'category' => 'product_categories', 'content' => 'contents',
        'channel' => 'channels', 'album' => 'albums', 'product_tag' => 'product_tags'];

    /**
     * 条目各语言版本（同 translation_group_id、已发布）的登记网址：语言 → 网址。hreflang 用。
     * 没登记网址的语言版本不在其中；文章标签没有翻译组，只返回自己。
     * @return array<string,string>
     */
    public function translationPaths(string $kind, int $id): array
    {
        $paths = $this->pathsFor($kind);
        $table = self::TRANSLATED_TABLES[$kind] ?? null;
        if ($table === null || !db()->tableExists($table)) {
            $entity = $kind === 'content_tag' ? self::contentTag($id) : null;
            return $entity !== null && isset($paths[$id]) ? [(string) $entity['lang'] => $paths[$id]] : [];
        }
        $t = DB_PREFIX . $table;
        $self = db()->fetchOne("SELECT id, translation_group_id FROM {$t} WHERE id = ?", [$id]);
        if (!$self) return [];
        $group = (int) ($self['translation_group_id'] ?? 0) ?: $id;
        $out = [];
        foreach (db()->fetchAll("SELECT id, lang FROM {$t} WHERE (translation_group_id = ? OR id = ?) AND status = 1", [$group, $group]) as $row) {
            $path = $paths[(int) $row['id']] ?? '';
            if ($path !== '' && !isset($out[(string) $row['lang']])) $out[(string) $row['lang']] = $path;
        }
        return $out;
    }

    /** 命中但未发布 / 已删除的条目仍是 404，绝不落到别的页面 */
    public function resolve(string $input): ?array
    {
        try { $path = self::normalize($input); }
        catch (InvalidArgumentException $e) { return null; }
        if (!$this->available()) return null;
        $base = $path;
        $page = 1;
        if (preg_match('~^(.*)/page/([1-9][0-9]*)/?$~D', $path, $m)) {
            $base = $m[1];
            $page = (int) $m[2];
        }
        $row = db()->fetchOne('SELECT entity_type, entity_id, path FROM ' . DB_PREFIX . $this->table . ' WHERE path_key = ?', [self::key($base)]);
        if (!$row || ($base !== $path && !in_array($row['entity_type'], self::LISTING_KINDS, true))) {
            return null;
        }
        $kind = (string) $row['entity_type'];
        $entity = $this->entity($kind, (int) $row['entity_id']);
        $deleted = $entity !== null && !empty($entity['deleted_at']);
        return ['kind' => $kind, 'id' => (int) $row['entity_id'], 'entity' => $entity,
            'active' => $entity !== null && !$deleted && (int) ($entity['status'] ?? 0) === 1,
            'lang' => (string) ($entity['lang'] ?? config('site_lang', 'zh-CN')),
            'page' => $page, 'canonical' => $page > 1 ? rtrim((string) $row['path'], '/') . '/page/' . $page . '/' : (string) $row['path']];
    }
}
