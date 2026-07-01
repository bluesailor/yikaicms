<?php
/**
 * Yikai CMS - 静态 HTML 生成器
 *
 * 主动把全站公开页面渲染成静态 .html 文件存入 web 根下的 html/ 目录，
 * 由 Web 服务器直接返回（不经过 PHP），获得最高性能、抗压与最小攻击面。
 *
 * 与 HtmlCache 的区别：
 *   - HtmlCache：按需 TTL 缓存，命中仍走 PHP（readfile）。
 *   - StaticHtml：主动全量生成，命中由 .htaccess/nginx 直出，不进 PHP。
 *   两者互补：自爬生成时带 X-Static-Gen 头，HtmlCache 自动跳过、互不污染。
 *
 * 生成方式：HTTP 自爬（cURL 回环抓本站页面），真实渲染、自动处理多语言/控制器副作用。
 * 失效策略：内容变更（data_changed 钩子）即清空静态文件，回落到实时 PHP，
 *           等下次手动「生成」或定时任务重建。
 *
 * 服务规则（需在 .htaccess / nginx.conf 配合，仅 GET + 空查询串 + 文件存在时直出）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit('Access Denied');

final class StaticHtml
{
    /**
     * 抑制失效钩子。静态生成器自身的 DB 记账写入（如 last_gen、设置保存）
     * 会触发 data_changed/setting_saved，若不抑制会把刚生成的文件清掉（自毁）。
     * static_html.php 在处理自身请求时置 true。
     */
    public static bool $mute = false;

    /** 静态文件输出目录（必须在 web 根下、可被直接访问） */
    public static function dir(): string
    {
        return ROOT_PATH . '/html';
    }

    /** 总开关 */
    public static function enabled(): bool
    {
        return (string) config('static_html_enabled', '0') === '1';
    }

    /** 启用的语言列表（默认语言在首位） */
    public static function langs(): array
    {
        $default = (string) config('site_lang', 'zh-CN');
        $raw = trim((string) config('enabled_languages', ''));
        $langs = $raw ? (json_decode($raw, true) ?: []) : [];
        if (!$langs) {
            $langs = [$default];
        }
        // 默认语言排首位、去重
        $langs = array_values(array_unique($langs));
        usort($langs, fn($a, $b) => ($a === $default ? -1 : ($b === $default ? 1 : 0)));
        return $langs;
    }

    /**
     * 枚举全站需生成的 URL 路径（不含域名，形如 /about/company.html、/en/news.html）。
     * 复用 channelUrl/contentUrl/productUrl，按启用语言分别枚举各语言自己的行。
     *
     * @return array<int,array{path:string,group:string}>
     */
    public static function enumerate(): array
    {
        $default = (string) config('site_lang', 'zh-CN');
        $langs   = self::langs();
        $out     = [];
        $seen    = [];

        $add = function (string $path, string $group) use (&$out, &$seen): void {
            $path = self::normalizePath($path);
            if ($path === '' || isset($seen[$path])) return;
            $seen[$path] = true;
            $out[] = ['path' => $path, 'group' => $group];
        };

        foreach ($langs as $lang) {
            $prefix = ($lang === $default) ? '' : '/' . $lang;

            // 首页
            $add($prefix . '/', 'home');

            // 栏目（page / list / product / case / download / job / album，排除外链）
            $channels = db()->fetchAll(
                "SELECT * FROM " . DB_PREFIX . "channels WHERE lang = ? AND status = 1 AND type <> 'link'",
                [$lang]
            );
            foreach ($channels as $ch) {
                $add($prefix . self::strip(channelUrl($ch)), 'channel');
            }

            // 内容详情（文章/案例/下载/招聘）
            $contents = db()->fetchAll(
                "SELECT c.id, c.slug, c.channel_id, ch.slug AS channel_slug, ch.type AS channel_type
                 FROM " . DB_PREFIX . "contents c
                 LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
                 WHERE c.status = 1 AND c.lang = ?
                 LIMIT 20000",
                [$lang]
            );
            foreach ($contents as $c) {
                $add($prefix . self::strip(contentUrl($c)), 'content');
            }

            // 产品详情
            $products = db()->fetchAll(
                "SELECT p.id, p.slug, p.category_id, pc.slug AS category_slug
                 FROM " . DB_PREFIX . "products p
                 LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
                 WHERE p.status = 1 AND p.lang = ?
                 LIMIT 20000",
                [$lang]
            );
            foreach ($products as $p) {
                $add($prefix . self::strip(productUrl($p)), 'product');
            }
        }

        return $out;
    }

    /** 单个 channel/列表页最多生成多少个分页文件（安全上限，防异常死循环） */
    private const MAX_PAGINATION = 500;

    /**
     * 生成一批：抓取并写入静态文件。
     *
     * 对栏目/列表页会顺带解析其分页链接（/.../page/N.html）并一并生成，
     * 用 BFS 跟随翻页，覆盖窗口式分页（1 2 3 … 下一页）的全部页。
     *
     * 分类：
     *   - 200 + 正文 → ok（生成静态文件）
     *   - 3xx 重定向 → skip（如父栏目跳转首子页，本就无独立内容，保持动态）
     *   - 其它（404/5xx/超时） → fail
     *
     * @param array<int,array{path:string,group:string}> $items
     * @param string $baseUrl 形如 http://127.0.0.1 或 https://example.com（无尾斜杠）
     * @return array{ok:int,skip:int,fail:int,extra:int,failed:array<int,string>}
     */
    public static function generateBatch(array $items, string $baseUrl): array
    {
        $ok = 0; $skip = 0; $fail = 0; $extra = 0; $failed = [];

        foreach ($items as $item) {
            $path = $item['path'];
            [$code, $body] = self::crawl($baseUrl . $path);

            if ($code >= 300 && $code < 400) {
                $skip++;
                continue;
            }
            if ($code !== 200 || $body === null || self::writeFile($path, $body) === false) {
                $fail++;
                $failed[] = $path;
                continue;
            }
            $ok++;

            // 列表/栏目页：解析并生成分页页
            if (in_array($item['group'], ['channel', 'home'], true)) {
                $extra += self::expandPagination($path, $body, $baseUrl);
            }
        }

        return ['ok' => $ok, 'skip' => $skip, 'fail' => $fail, 'extra' => $extra, 'failed' => $failed];
    }

    /**
     * 从 page1 的 HTML 出发，BFS 跟随分页链接，生成全部分页静态文件。
     * @return int 生成的分页文件数
     */
    private static function expandPagination(string $firstPath, string $firstHtml, string $baseUrl): int
    {
        $seen  = [$firstPath => true];
        $queue = self::findPaginationPaths($firstHtml);
        $made  = 0;

        while ($queue && $made < self::MAX_PAGINATION) {
            $p = array_shift($queue);
            if (isset($seen[$p])) continue;
            $seen[$p] = true;

            [$code, $body] = self::crawl($baseUrl . $p);
            if ($code !== 200 || $body === null) continue;
            if (self::writeFile($p, $body) === false) continue;
            $made++;

            // 该分页里可能又出现更后面的页码（窗口式分页）
            foreach (self::findPaginationPaths($body) as $np) {
                if (!isset($seen[$np])) $queue[] = $np;
            }
        }
        return $made;
    }

    /** 从 HTML 中提取所有形如 /.../page/N.html 的干净分页路径（去重） */
    private static function findPaginationPaths(string $html): array
    {
        if (!preg_match_all('#href=["\'](/[a-z0-9_\-/]*?/page/\d+\.html)["\']#i', $html, $m)) {
            return [];
        }
        return array_values(array_unique($m[1]));
    }

    /**
     * cURL 抓取单个 URL（带 X-Static-Gen 头让 HtmlCache 跳过）。
     * @return array{0:int,1:?string} [HTTP状态码, 正文或null]
     */
    public static function crawl(string $url): array
    {
        if (!function_exists('curl_init')) return [0, null];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => ['X-Static-Gen: 1'],
            CURLOPT_USERAGENT      => 'YikaiCMS-StaticGen',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && is_string($body) && trim($body) !== '') {
            return [200, $body];
        }
        return [$code, null];
    }

    /** 把 URL 路径写成静态文件（/ → index.html，目录式补 index.html） */
    public static function writeFile(string $path, string $html): bool
    {
        $rel = ltrim($path, '/');
        if ($rel === '' || substr($rel, -1) === '/') {
            $rel .= 'index.html';
        }
        // 安全：禁止路径穿越
        if (str_contains($rel, '..')) return false;

        $file = self::dir() . '/' . $rel;
        $dir  = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        return @file_put_contents($file, $html, LOCK_EX) !== false;
    }

    /** 清空所有已生成的静态文件，返回删除数量 */
    public static function clearAll(): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) return 0;
        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } elseif (@unlink($f->getPathname())) {
                $count++;
            }
        }
        return $count;
    }

    /** 统计：已生成文件数、目录总大小、最后生成时间 */
    public static function stats(): array
    {
        $dir = self::dir();
        $files = 0; $size = 0;
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) { $files++; $size += $f->getSize(); }
            }
        }
        return [
            'files'    => $files,
            'size'     => $size,
            'last_gen' => (int) config('static_html_last_gen', 0),
        ];
    }

    /** 规范化路径：确保以 / 开头，去掉查询串/锚点 */
    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') return '';
        // 去掉协议域名（万一 helper 返回了绝对地址）
        if (preg_match('#^https?://[^/]+(/.*)$#i', $path, $m)) {
            $path = $m[1];
        }
        $path = strtok($path, '#');
        $path = strtok($path, '?');
        if ($path === false) return '';
        if ($path[0] !== '/') $path = '/' . $path;
        return $path;
    }

    /** 去掉 helper 可能带上的语言前缀（/en /ja /zh-CN ...），由枚举侧统一加回 */
    private static function strip(string $url): string
    {
        $langs = self::langs();
        $alt = implode('|', array_map('preg_quote', $langs));
        return preg_replace('#^/(' . $alt . ')(/|$)#', '/', $url) ?? $url;
    }
}

// ============================================================
// 失效钩子：内容/产品/设置变更后清空静态文件，回落到实时 PHP
// ============================================================
add_action('data_changed', function (string $table = '', $id = null): void {
    if (StaticHtml::$mute || !StaticHtml::enabled()) return;
    static $skip = ['admin_logs', 'ai_logs', 'login_throttle', 'form_throttle', 'visits'];
    if (in_array($table, $skip, true)) return;
    StaticHtml::clearAll();
});
add_action('setting_saved', function (): void {
    if (StaticHtml::$mute || !StaticHtml::enabled()) return;
    StaticHtml::clearAll();
});
