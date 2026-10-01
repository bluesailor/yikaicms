<?php
/**
 * 语言域名模式：某些语言用独立域名（en.example.com、example.de），其余语言照旧用 /xx/ 前缀。
 *
 * 设置 language_domains = {"en":"en.example.com","de":"example.de"}（后台「多语言」页维护），
 * 总开关 language_domains_enabled = '1' 时才生效；关掉后域名保留，全站回到 /xx/ 前缀。
 *   - 默认语言永远在主域名（site_url 的主机），不能映射；
 *   - 只收已启用、已注册的非默认语言；域名不能与主域名或彼此重复；
 *   - 访问语言域名 = 该语言，不带前缀：en.example.com/news.html 就是英文新闻页；
 *   - 带前缀或走错主机的请求一律 301 到该语言的规范地址（见 redirectTarget）。
 *
 * 语言由主机名决定，Host 头伪造只会让对方看到另一种语言；跳转目标只取自配置，不取请求头。
 * 各语言域名需自行解析到本站、配证书、在 Web 服务器里绑定到同一目录（后台可逐个检测）。
 * 与「静态 HTML 直出」互斥：服务器按路径直出文件，分不清主机，会把默认语言页面发给语言域名。
 */

declare(strict_types=1);

require_once __DIR__ . '/LanguageRegistry.php';

final class LanguageDomains
{
    /** @var array<string,string>|null 语言 → 主机（含可选端口） */
    private static ?array $map = null;
    private static ?string $mainOrigin = null;
    private static ?string $currentHost = null;

    /**
     * 测试用：固定映射、主站地址与当前主机；传 null 恢复按配置与请求读取。
     * @param array<string,string>|null $map
     */
    public static function setForTests(?array $map, ?string $mainOrigin = null, ?string $currentHost = null): void
    {
        self::$map = $map;
        self::$mainOrigin = $mainOrigin;
        self::$currentHost = $currentHost;
    }

    /**
     * 解析并校验设置值。非法条目静默丢弃（后台保存时另有逐项报错）。
     *
     * @param list<string> $enabled 已启用语言
     * @return array<string,string>
     */
    public static function parse(string $json, string $defaultLang, array $enabled, string $mainHost): array
    {
        $raw = $json !== '' ? json_decode($json, true) : null;
        if (!is_array($raw)) return [];
        $out = [];
        $seen = [strtolower($mainHost) => true, 'www.' . strtolower($mainHost) => true];
        foreach ($raw as $lang => $host) {
            if (!is_string($lang) || !is_string($host) || $lang === $defaultLang
                || !LanguageRegistry::has($lang) || !in_array($lang, $enabled, true)) continue;
            $host = self::normalizeHost($host);
            if ($host === null || isset($seen[$host]) || isset($seen['www.' . $host])) continue;
            $seen[$host] = true;
            $out[$lang] = $host;
        }
        return $out;
    }

    /** 规范化主机名：小写、去协议/路径/末尾点；只收 ASCII 域名（中文域名请填 punycode）与可选端口。 */
    public static function normalizeHost(string $input): ?string
    {
        $h = strtolower(trim($input));
        if (str_contains($h, '://')) $h = (string) substr($h, (int) strpos($h, '://') + 3);
        $h = (string) preg_replace('#[/?\#].*$#s', '', $h);
        $h = rtrim($h, '.');
        if ($h === '' || strlen($h) > 253) return null;
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        return preg_match('/^(?:' . $label . '\.)+[a-z][a-z0-9-]{0,62}(?::\d{1,5})?$/D', $h) === 1
            || preg_match('/^localhost(?::\d{1,5})?$/D', $h) === 1 ? $h : null;
    }

    /** @return array<string,string> */
    public static function map(): array
    {
        if (self::$map !== null) return self::$map;
        if (!function_exists('config')) return self::$map = [];
        // 兼容地址模式（?yk_route=）没有路径前缀可言，本模式只配合漂亮地址使用
        if (function_exists('isDynamicUrlMode') && isDynamicUrlMode()) return self::$map = [];
        $default = (string) config('site_lang', 'zh-CN');
        if ((string) config('language_domains_enabled', '0') !== '1') return self::$map = [];
        $raw = trim((string) config('language_domains', ''));
        if ($raw === '' || self::mainHost() === '') return self::$map = [];
        $enabledRaw = json_decode((string) config('enabled_languages', ''), true);
        $enabled = is_array($enabledRaw) ? array_values(array_filter($enabledRaw, 'is_string')) : [];
        return self::$map = self::parse($raw, $default, $enabled, self::mainHost());
    }

    public static function active(): bool
    {
        return self::map() !== [];
    }

    public static function hostFor(string $lang): ?string
    {
        return self::map()[$lang] ?? null;
    }

    /** 主机名（含端口）对应的语言；www. 变体视为同一域名。 */
    public static function languageForHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '') return null;
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        foreach (self::map() as $lang => $mapped) {
            if ($host === $mapped || $bare === $mapped || $host === 'www.' . $mapped) return $lang;
        }
        return null;
    }

    public static function currentHost(): string
    {
        if (self::$currentHost !== null) return self::$currentHost;
        return strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    }

    /** 当前请求落在哪个语言域名上；在主域名或未启用本模式时为 null。 */
    public static function currentLanguage(): ?string
    {
        return self::active() ? self::languageForHost(self::currentHost()) : null;
    }

    /** 主站 scheme://host[:port]（不含路径），取自 site_url / SITE_URL。 */
    private static function mainSchemeHost(): string
    {
        if (self::$mainOrigin !== null) return rtrim(self::$mainOrigin, '/');
        $site = function_exists('config') ? trim((string) config('site_url', '')) : '';
        if ($site === '' && defined('SITE_URL')) $site = trim((string) SITE_URL);
        $parts = $site !== '' ? parse_url($site) : false;
        if (!is_array($parts) || empty($parts['host'])) return '';
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        return $scheme . '://' . strtolower((string) $parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function mainHost(): string
    {
        $origin = self::mainSchemeHost();
        return $origin === '' ? '' : (string) preg_replace('#^[a-z]+://#', '', $origin);
    }

    private static function scheme(): string
    {
        $origin = self::mainSchemeHost();
        return $origin !== '' ? (string) strstr($origin, '://', true) : 'https';
    }

    private static function mount(): string
    {
        return class_exists('BasePath') ? BasePath::get() : '';
    }

    /** 某语言页面所在的站点根：映射了域名的是该域名，其余在主域名。末尾不带 /。 */
    public static function originFor(string $lang): string
    {
        $host = self::hostFor($lang);
        $origin = $host !== null ? self::scheme() . '://' . $host : self::mainSchemeHost();
        return rtrim($origin . self::mount(), '/');
    }

    /** 站内路径在该语言下的写法：有独立域名或是默认语言时不带前缀，否则 /xx/… */
    public static function pathFor(string $lang, string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $default = function_exists('config') ? (string) config('site_lang', 'zh-CN') : 'zh-CN';
        if ($lang === $default || self::hostFor($lang) !== null) return $path;
        return '/' . $lang . $path;
    }

    /** 当前请求的站点根（scheme://host + 挂载目录）。 */
    public static function currentOrigin(): string
    {
        $lang = self::currentLanguage();
        return $lang !== null ? self::originFor($lang) : rtrim(self::mainSchemeHost() . self::mount(), '/');
    }

    /**
     * 某语言下某路径的链接：与当前请求同一主机时给站内相对地址，跨主机时给完整地址。
     * $path 是不带语言前缀的站内路径（可带查询串）。
     */
    public static function url(string $lang, string $path): string
    {
        $relative = self::pathFor($lang, $path);
        return self::originFor($lang) === self::currentOrigin() ? $relative : self::originFor($lang) . $relative;
    }

    /**
     * 请求应去的规范地址；已经在规范位置时返回 null。
     *
     * @param string $path    去掉挂载目录后的请求路径（含语言前缀，不含查询串）
     * @param string $query   原始查询串（不含 ?）
     */
    public static function redirectTarget(string $path, string $query): ?string
    {
        if (!self::active()) return null;
        $here = self::currentLanguage();
        $prefix = LanguageRegistry::prefixOf($path);
        $default = function_exists('config') ? (string) config('site_lang', 'zh-CN') : 'zh-CN';
        $suffix = $query !== '' ? '?' . $query : '';
        // 语言域名上的后台 / 安装入口：回主域名（登录状态只在主域名）
        if ($here !== null && preg_match('#^/(?:admin|install)(?:/|$)#', $path) === 1) {
            return rtrim(self::mainSchemeHost() . self::mount(), '/') . $path . $suffix;
        }
        if ($prefix !== null) {
            $rest = substr($path, strlen($prefix) + 1);
            $rest = '/' . ltrim($rest === false ? '' : $rest, '/');
            if ($prefix === $default || self::hostFor($prefix) !== null || $here !== null) {
                // 默认语言不带前缀；有独立域名的语言去自己的域名；语言域名上的任何前缀都回到规范位置
                return self::originFor($prefix) . self::pathFor($prefix, $rest) . $suffix;
            }
            return null;   // 主域名上的前缀式语言：本来就在这里
        }
        return null;
    }

    /** 本主机上提供哪些语言（sitemap 用）：语言域名只有自己；主域名是未映射的已启用语言。 */
    public static function languagesServedHere(array $enabled): array
    {
        $here = self::currentLanguage();
        if ($here !== null) return [$here];
        return array_values(array_filter($enabled, static fn($code): bool => is_string($code) && self::hostFor($code) === null));
    }

    // ── 域名检测：后台保存前确认该域名真的解析、配好证书并指向本站同一个安装 ─────────────

    /** 本站对检测随机数的应答：只有同一安装（同一站点编号）算得出来，外站无法冒充。 */
    public static function probeAnswer(string $nonce): string
    {
        if (!class_exists('InstallIdentity')) require_once dirname(__DIR__) . '/InstallIdentity.php';
        return hash_hmac('sha256', $nonce, 'yikai-language-domain|' . InstallIdentity::id());
    }

    /** index.php?yk_lang_domain_probe=<32 位十六进制> 的应答（任何主机上都答，检测的就是主机能否到达本站）。 */
    public static function respondProbe(string $nonce): void
    {
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if (preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['probe' => 'yikai-language-domain', 'answer' => self::probeAnswer($nonce)]);
        exit;
    }

    /**
     * 从服务器回访 scheme://host/…/index.php?yk_lang_domain_probe=…，核对应答。
     * 返回结果码：ok / invalid_host / unreachable / tls / http_<状态码> / not_this_site。
     * 不跟随跳转（跳走了说明没绑到本站目录）；TLS 一律校验。
     */
    public static function probe(string $host, int $timeout = 8): string
    {
        $host = self::normalizeHost($host);
        if ($host === null || !function_exists('curl_init')) return 'invalid_host';
        $nonce = bin2hex(random_bytes(16));
        $url = self::scheme() . '://' . $host . self::mount() . '/index.php?yk_lang_domain_probe=' . $nonce;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'YikaiCMS-LanguageDomainProbe',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errno !== 0) {
            return in_array($errno, [35, 51, 58, 60, 77, 83], true) ? 'tls' : 'unreachable';
        }
        if ($status !== 200) return 'http_' . $status;
        $data = json_decode((string) $body, true);
        return is_array($data) && ($data['probe'] ?? '') === 'yikai-language-domain'
            && is_string($data['answer'] ?? null) && hash_equals(self::probeAnswer($nonce), $data['answer'])
            ? 'ok' : 'not_this_site';
    }

    /** 页面输出兜底：a / area / form / link[rel=canonical|alternate] 的站内地址按 rewriteUrl 改写。 */
    public static function html(string $html): string
    {
        if (!class_exists('HtmlTagRewriter')) require_once dirname(__DIR__) . '/HtmlTagRewriter.php';
        $tags = new HtmlTagRewriter($html);
        while ($tags->nextTag()) {
            $tag = $tags->getTag();
            $attr = match ($tag) { 'A', 'AREA', 'LINK' => 'href', 'FORM' => 'action', default => '' };
            if ($attr === '') continue;
            if ($tag === 'LINK' && !in_array(strtolower((string) $tags->getAttribute('rel')), ['canonical', 'alternate'], true)) continue;
            $value = $tags->getAttribute($attr);
            if (!is_string($value)) continue;
            $converted = self::rewriteUrl($value);
            if ($converted !== $value) $tags->setAttribute($attr, $converted);
        }
        return $tags->getUpdatedHtml();
    }

    /**
     * ob_start 回调（init.php 在本模式启用时注册）。与 CompatibleLinks 一样整体 fail-open：
     * 输出缓冲回调里抛异常会升级为白屏，出任何差错都原样返回。
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function output(string $html): string
    {
        try {
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Disposition:') === 0) return $html;
                if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) return $html;
            }
            if (stripos($html, '<html') === false) return $html;
            return self::html($html);
        } catch (Throwable $e) {
            error_log('[LanguageDomains] output filter skipped: ' . $e->getMessage());
            return $html;
        }
    }

    /**
     * 把页面里写死了语言前缀或指向别的主机的站内链接改成规范地址（存量 Blox 文档里的 /en/xxx 等）。
     * 只动以 / 开头的站内路径与主站完整地址；外站链接、锚点、协议相对地址不动。
     */
    public static function rewriteUrl(string $url): string
    {
        if (!self::active() || $url === '' || $url[0] === '#' || str_starts_with($url, '//')) return $url;
        $mainRoot = rtrim(self::mainSchemeHost() . self::mount(), '/');
        $absolute = false;
        $path = $url;
        if (preg_match('#^https?://#i', $url) === 1) {
            if ($mainRoot === '' || stripos($url, $mainRoot . '/') !== 0) return $url;
            $absolute = true;
            $path = substr($url, strlen($mainRoot));
        } elseif ($url[0] !== '/') {
            return $url;
        }
        $mount = self::mount();
        if (!$absolute && $mount !== '' && str_starts_with($path, $mount . '/')) $path = substr($path, strlen($mount));
        if (preg_match('#^/(?:admin|api|install|plugins|assets|uploads|themes|html|storage)(?:/|$)#', $path) === 1) {
            // 资源与后台：在语言域名上也能取到（同一目录），不改
            return $url;
        }
        $split = strcspn($path, '?#');
        $pathOnly = substr($path, 0, $split);
        $tail = substr($path, $split);
        $prefix = LanguageRegistry::prefixOf($pathOnly);
        if ($prefix !== null && (self::hostFor($prefix) !== null || self::currentLanguage() !== null)) {
            $rest = substr($pathOnly, strlen($prefix) + 1);
            $rest = '/' . ltrim($rest === false ? '' : $rest, '/');
            return self::url($prefix, $rest) . $tail;
        }
        return $url;
    }
}
