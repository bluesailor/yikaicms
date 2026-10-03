<?php
declare(strict_types=1);

/**
 * 301/302 跳转（核心免费版，2.0.4；WordPress 迁移时原站没法复现的地址都进这里，不许 404）。
 *
 * 不建新表：旧地址登记在网址登记表 product_routes（entity_type=redirect，按 path_key 索引查找、
 * 与自定义网址共用规范化和冲突检查），目标存在 metas 一行：
 *   owner_type=redirect，owner_id=0，meta_key=随机键（metas 有 owner_type+owner_id+meta_key 唯一索引），
 *   meta_value={"target":目标地址,"code":301|302}，
 *   created_at=保存时间，updated_at=最近一次被访问的时间（0 = 从未访问，按小时节流写入）。
 * 整站模板导出时不带跳转（属于具体站点，见 SiteTemplateData::snapshot）。
 * SEO 插件专业版另有自己的重定向表与 404 记录，两者互不影响；SEO 插件的规则在 init 钩子里先执行。
 */
final class Redirects
{
    public const CODES = [301, 302];
    private const MAX_TARGET = 1000;
    private const MAX_IMPORT_LINES = 5000;

    /** @return array{id:int,target:string,code:int,status:int,lang:string,last_hit:int,saved_at:int}|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !db()->tableExists('metas')) return null;
        $row = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'metas WHERE id = ? AND owner_type = ?', [$id, 'redirect']);
        if (!$row) return null;
        $value = self::decode((string) $row['meta_value']);
        return ['id' => (int) $row['id'], 'target' => $value['target'], 'code' => $value['code'],
            'status' => 1, 'lang' => (string) config('site_lang', 'zh-CN'),
            'last_hit' => (int) ($row['updated_at'] ?? 0), 'saved_at' => (int) ($row['created_at'] ?? 0)];
    }

    /** @return array{target:string,code:int} */
    private static function decode(string $json): array
    {
        $value = json_decode($json, true);
        return ['target' => is_array($value) ? (string) ($value['target'] ?? '') : '',
            'code' => is_array($value) && (int) ($value['code'] ?? 301) === 302 ? 302 : 301];
    }

    private static function encode(string $target, int $code): string
    {
        return json_encode(['target' => $target, 'code' => $code], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** 目标：站内路径（/开头，可带查询串）或 http(s) 完整地址；不收协议相对地址、空白与控制字符。 */
    public static function normalizeTarget(string $input): string
    {
        $target = trim($input);
        if ($target === '' || strlen($target) > self::MAX_TARGET || preg_match('/[\s\x00-\x1F\x7F]/', $target)) {
            throw new InvalidArgumentException('redirect_target_invalid');
        }
        if (preg_match('#^https?://#i', $target)) {
            if (filter_var($target, FILTER_VALIDATE_URL) === false) throw new InvalidArgumentException('redirect_target_invalid');
            return $target;
        }
        if (!str_starts_with($target, '/') || str_starts_with($target, '//') || str_contains($target, '\\')) {
            throw new InvalidArgumentException('redirect_target_invalid');
        }
        return $target;
    }

    /**
     * 新建（$id=0）或修改一条跳转，返回它的 id。旧地址不合法、被内容占用、目标不合法或会绕回旧地址时抛出
     * InvalidArgumentException（消息是语言包键），什么也不改。
     */
    public static function save(int $id, string $source, string $target, int $code = 301): int
    {
        $code = in_array($code, self::CODES, true) ? $code : 301;
        $target = self::normalizeTarget($target);
        $routes = productRouteModel();
        try {
            $path = ProductRouteModel::normalize($source);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('product_url_invalid');
        }
        if ($path === '') throw new InvalidArgumentException('redirect_source_required');
        require_once __DIR__ . '/Dispatcher.php';
        $lang = Dispatcher::languagePrefixFromPath($path) ?? (string) config('site_lang', 'zh-CN');
        if ($id > 0 && self::find($id) === null) throw new InvalidArgumentException('product_url_missing');
        $routes->validate('redirect', $id, $path, $lang);
        self::assertNoLoop($path, $target);

        $isNew = $id < 1;
        if ($isNew) {
            $id = (int) db()->insert('metas', ['owner_type' => 'redirect', 'owner_id' => 0, 'meta_key' => bin2hex(random_bytes(8)),
                'meta_value' => self::encode($target, $code), 'created_at' => time(), 'updated_at' => 0]);
        } else {
            db()->update('metas', ['meta_value' => self::encode($target, $code), 'created_at' => time()],
                'id = ? AND owner_type = ?', [$id, 'redirect']);
        }
        try {
            $routes->assign('redirect', $id, $path, $lang);
        } catch (Throwable $e) {
            if ($isNew) db()->delete('metas', 'id = ? AND owner_type = ?', [$id, 'redirect']);
            throw $e;
        }
        return $id;
    }

    /** 目标指回旧地址本身，或指向一条又跳回旧地址的跳转，都会让浏览器死循环。 */
    private static function assertNoLoop(string $source, string $target): void
    {
        if (!str_starts_with($target, '/')) return;
        $targetPath = (string) parse_url($target, PHP_URL_PATH);
        try {
            $normalized = ProductRouteModel::normalize($targetPath);
        } catch (InvalidArgumentException) {
            return;   // 目标不是能登记的路径（如 /search.html?q=），不会再经过跳转表
        }
        if ($normalized === '') return;
        if (rtrim($normalized, '/') === rtrim($source, '/')) throw new InvalidArgumentException('redirect_loop');
        $next = productRouteModel()->resolve($normalized);
        if ($next !== null && $next['kind'] === 'redirect' && is_array($next['entity'])) {
            $nextPath = (string) parse_url((string) $next['entity']['target'], PHP_URL_PATH);
            try {
                if (rtrim(ProductRouteModel::normalize($nextPath), '/') === rtrim($source, '/')) throw new InvalidArgumentException('redirect_loop');
            } catch (InvalidArgumentException $e) {
                if ($e->getMessage() === 'redirect_loop') throw $e;
            }
        }
    }

    public static function delete(int $id): void
    {
        if ($id < 1) return;
        productRouteModel()->remove('redirect', $id);
        db()->delete('metas', 'id = ? AND owner_type = ?', [$id, 'redirect']);
    }

    /** 旧地址对应的跳转 id；没有（或该地址属于别的内容）时为 0。 */
    public static function idForSource(string $source): int
    {
        $hit = productRouteModel()->resolve($source);
        return $hit !== null && $hit['kind'] === 'redirect' ? (int) $hit['id'] : 0;
    }

    /** @return list<array{id:int,source:string,target:string,code:int,last_hit:int}> */
    public static function list(string $search = '', int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::searchCondition($search);
        $rows = db()->fetchAll('SELECT r.entity_id AS id, r.path AS source, m.meta_value AS value, m.updated_at AS last_hit
            FROM ' . DB_PREFIX . 'product_routes r JOIN ' . DB_PREFIX . "metas m ON m.id = r.entity_id AND m.owner_type = 'redirect'
            WHERE r.entity_type = 'redirect'{$where} ORDER BY r.id DESC LIMIT ? OFFSET ?", array_merge($params, [max(1, $limit), max(0, $offset)]));
        return array_map(static function (array $r): array {
            $value = self::decode((string) $r['value']);
            return ['id' => (int) $r['id'], 'source' => (string) $r['source'], 'target' => $value['target'], 'code' => $value['code'],
                'last_hit' => (int) $r['last_hit']];
        }, $rows);
    }

    public static function count(string $search = ''): int
    {
        [$where, $params] = self::searchCondition($search);
        return (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'product_routes r JOIN ' . DB_PREFIX
            . "metas m ON m.id = r.entity_id AND m.owner_type = 'redirect' WHERE r.entity_type = 'redirect'{$where}", $params);
    }

    /** @return array{0:string,1:list<string>} */
    private static function searchCondition(string $search): array
    {
        $search = trim($search);
        if ($search === '') return ['', []];
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $encoded = '%' . strtr(rawurlencode($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        return [" AND (r.path LIKE ? ESCAPE '!' OR r.path LIKE ? ESCAPE '!' OR m.meta_value LIKE ? ESCAPE '!')", [$like, $encoded, $like]];
    }

    /**
     * 批量导入：每行「旧地址 目标 [301|302]」，用空格、制表符或逗号分隔；# 开头与空行跳过。
     * 旧地址已有跳转的改成新目标。逐行保存，出错的行列出来，不影响其它行。
     * @return array{saved:int,errors:list<array{line:int,text:string,error:string}>}
     */
    public static function import(string $text): array
    {
        $saved = 0;
        $errors = [];
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach (array_slice($lines, 0, self::MAX_IMPORT_LINES) as $index => $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $parts = preg_split('/[\s,]+/', $line) ?: [];
            $code = (int) ($parts[2] ?? 301);
            try {
                if (count($parts) < 2 || count($parts) > 3 || !in_array($code, self::CODES, true)) {
                    throw new InvalidArgumentException('redirect_import_line_invalid');
                }
                self::save(self::idForSource($parts[0]), $parts[0], $parts[1], $code);
                $saved++;
            } catch (InvalidArgumentException $e) {
                $errors[] = ['line' => $index + 1, 'text' => mb_substr($line, 0, 200), 'error' => $e->getMessage()];
            }
        }
        if (count($lines) > self::MAX_IMPORT_LINES) {
            $errors[] = ['line' => self::MAX_IMPORT_LINES + 1, 'text' => '', 'error' => 'redirect_import_too_many'];
        }
        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * 发出跳转并结束请求。目标没带查询串时沿用访客原来的查询串（utm 等）。
     * @param array{id:int,target:string,code:int} $redirect
     * @return never
     */
    public static function send(array $redirect): void
    {
        $target = (string) $redirect['target'];
        $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
        if ($query !== '' && !str_contains($target, '?') && !str_contains($target, '#')) $target .= '?' . $query;
        self::recordHit((int) $redirect['id']);
        header('Cache-Control: no-store');
        header('Location: ' . $target, true, (int) $redirect['code'] === 302 ? 302 : 301);
        exit;
    }

    /** 记最近访问时间（每条每小时最多写一次，爬虫反复访问也不压库）。 */
    private static function recordHit(int $id): void
    {
        try {
            db()->execute('UPDATE ' . DB_PREFIX . "metas SET updated_at = ? WHERE id = ? AND owner_type = 'redirect' AND updated_at < ?",
                [time(), $id, time() - 3600]);
        } catch (Throwable) {
            // 统计失败不影响跳转
        }
    }
}
