<?php
declare(strict_types=1);

/** One registry governs both incoming routes and outgoing product links. */
final class ProductRouteModel extends Model
{
    protected string $table = 'product_routes';
    private ?array $routes = null;

    public function available(): bool
    {
        return db()->tableExists($this->table);
    }

    public function clearCache(): void { $this->routes = null; }

    private function routes(): array
    {
        if ($this->routes === null) {
            $this->routes = $this->available() ? $this->all() : [];
        }
        return $this->routes;
    }

    public function pathFor(string $kind, int $id): string
    {
        if ($id < 1) return '';
        foreach ($this->routes() as $row) {
            if ($row['entity_type'] === $kind && (int) $row['entity_id'] === $id) return (string) $row['path'];
        }
        return '';
    }

    /** Decode once, then encode segments consistently; never accept URL authority or executable paths. */
    public static function normalize(string $input): string
    {
        $path = rawurldecode(trim($input));
        if ($path === '') return '';
        if (strlen($path) > 500 || !preg_match('//u', $path)
            || !preg_match('~^/(?:[\pL\pN_\-.]+/)*[\pL\pN_\-.]+/?$~uD', $path)) {
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

    private function entity(string $kind, int $id): ?array
    {
        return match ($kind) {
            'product' => productModel()->find($id),
            'category' => productCategoryModel()->find($id),
            default => null,
        };
    }

    public function validate(string $kind, int $id, string $input, string $lang): string
    {
        if (!in_array($kind, ['product', 'category'], true)) throw new InvalidArgumentException('product_url_invalid');
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
        if (in_array($stem, ['admin', 'api', 'install', 'config', 'includes', 'controllers', 'views',
            'assets', 'uploads', 'storage', 'vendor', 'themes', 'plugins', 'marketplace', 'overrides',
            'tests', 'tools', 'bin', 'html', 'index', 'search', 'sitemap', 'robots', 'favicon',
            'member', 'news', 'download', 'job', 'case', 'detail', 'list', 'page', 'contact'], true)
            || preg_match('~/page/[0-9]+/?$~D', $path)
            || is_file(ROOT_PATH . rawurldecode($path)) || is_dir(ROOT_PATH . rawurldecode($path))) {
            throw new InvalidArgumentException('product_url_reserved');
        }
        $builtIn = Dispatcher::match($path);
        if ($builtIn !== null && $builtIn['file'] !== 'page.php') throw new InvalidArgumentException('product_url_reserved');
        // Generic .html routes can be custom, but never shadow an existing channel.
        if (db()->tableExists('channels')) {
            foreach (channelModel()->all() as $channel) {
                if (($channel['type'] ?? '') !== 'link' && ($channel['lang'] ?? $default) === $lang) {
                    $existing = channelPrettyUrl($channel);
                    $existingPrefix = Dispatcher::languagePrefixFromPath($existing);
                    $existingRelative = $existingPrefix !== null ? substr($existing, strlen($existingPrefix) + 2) : ltrim($existing, '/');
                    if (rtrim($existingRelative, '/') === rtrim($relative, '/')) throw new InvalidArgumentException('product_url_conflict');
                }
            }
        }
        $conflict = db()->fetchOne('SELECT entity_type, entity_id FROM ' . DB_PREFIX . 'product_routes WHERE path_key = ?', [self::key($path)]);
        if ($conflict && ($conflict['entity_type'] !== $kind || (int) $conflict['entity_id'] !== $id)) {
            throw new InvalidArgumentException('product_url_conflict');
        }
        return $path;
    }

    /** Content and path change atomically; unique path_key also arbitrates concurrent saves. */
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
            if ($this->available()) {
                db()->delete($this->table, 'entity_type = ? AND entity_id = ?', [$kind, $id]);
                if ($path !== '') db()->insert($this->table, [
                    'entity_type' => $kind, 'entity_id' => $id, 'path' => $path, 'path_key' => self::key($path),
                ]);
            }
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

    /** A matched but unpublished/deleted entity remains a 404, never another page. */
    public function resolve(string $input): ?array
    {
        try { $path = self::normalize($input); }
        catch (InvalidArgumentException $e) { return null; }
        $base = $path;
        $page = 1;
        if (preg_match('~^(.*)/page/([1-9][0-9]*)/?$~D', $path, $m)) {
            $base = $m[1];
            $page = (int) $m[2];
        }
        foreach ($this->routes() as $row) {
            if ($row['path_key'] !== self::key($base) || ($base !== $path && $row['entity_type'] !== 'category')) continue;
            $entity = $this->entity((string) $row['entity_type'], (int) $row['entity_id']);
            return ['kind' => $row['entity_type'], 'id' => (int) $row['entity_id'], 'entity' => $entity,
                'active' => $entity !== null && (int) ($entity['status'] ?? 0) === 1,
                'lang' => (string) ($entity['lang'] ?? config('site_lang', 'zh-CN')),
                'page' => $page, 'canonical' => $page > 1 ? rtrim((string) $row['path'], '/') . '/page/' . $page . '/' : (string) $row['path']];
        }
        return null;
    }
}
