<?php
/**
 * SEO 助手 - 失效链接检查 + 索引健康摘要（专业版）
 *
 * 两件事：
 *   1) 失效链接检查：扫描已发布 contents / products 正文里的 <a href>，判定每个站内
 *      链接是否还能落到真实页面/文件。判定不重写路由——用 CMS 自己的 URL 生成器
 *      （seo_all_urls + 各实体的 id/slug 查询别名）构成「有效集」，链接归一化后对
 *     不上集子才判死；命中已有重定向规则的标为「已接管」。外链只计数不探测
 *      （本插件的立身之本是离线可用、不依赖外部服务）。
 *   2) 索引健康摘要：sitemap / robots.txt / llms.txt / 推送密钥的可见状态 +
 *      404 记录、重定向、死链的数量汇总，一屏看懂搜索引擎看站点的状态。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/redirects.php';   // seo_redirect_list()
require_once __DIR__ . '/lib.php';         // seo_all_urls() / seo_indexnow_key_path()

/** 站内语言前缀（含小写形态；默认语言无前缀不产出）。 */
function seo_linkcheck_lang_prefixes(): array
{
    $prefixes = [];
    $default = (string) config('site_lang', 'zh-CN');
    foreach (array_keys(function_exists('enabledLanguages') ? enabledLanguages() : []) as $code) {
        if ($code === $default) {
            continue;
        }
        $prefixes[] = $code;
        $lower = strtolower($code);
        if ($lower !== $code) {
            $prefixes[] = $lower;
        }
    }
    return $prefixes;
}

/**
 * 从 HTML 提取去重 href 列表（保持首次出现顺序）。
 * @return list<string>
 */
function seo_linkcheck_hrefs(string $html): array
{
    if (!preg_match_all('/<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))/i', $html, $matches)) {
        return [];
    }
    $hrefs = [];
    foreach (array_keys($matches[1]) as $i) {
        $href = $matches[1][$i] !== '' ? $matches[1][$i]
            : ($matches[2][$i] !== '' ? $matches[2][$i] : $matches[3][$i]);
        $href = trim($href);
        if ($href !== '' && !in_array($href, $hrefs, true)) {
            $hrefs[] = $href;
        }
    }
    return $hrefs;
}

/**
 * 归一化站内链接；返回 null = 非站内链接（外链 / 锚点 / mailto / tel / 脚本）。
 * 归一规则与「有效集」的生成完全同构：去协议与域名（含 www 差异）、去 #fragment、
 * 去语言前缀（/en/...，跨语言互链不算死链）、尾部斜杠归一（根除外）。
 */
function seo_linkcheck_normalize(string $href, string $siteHost, array $langPrefixes): ?string
{
    $href = trim($href);
    if ($href === '' || $href[0] === '#') {
        return null;
    }
    $lower = strtolower($href);
    foreach (['javascript:', 'mailto:', 'tel:', 'data:'] as $scheme) {
        if (strncmp($lower, $scheme, strlen($scheme)) === 0) {
            return null;
        }
    }
    if (strncmp($href, '//', 2) === 0) {
        return null;   // 协议相对 = 外链
    }
    if (preg_match('#^https?://#i', $href)) {
        $host = strtolower((string) (parse_url($href, PHP_URL_HOST) ?: ''));
        $path = (string) (parse_url($href, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($href, PHP_URL_QUERY) ?? '');
        $site = strtolower($siteHost);
        if ($host !== $site && $host !== 'www.' . $site) {
            return null;
        }
        $href = $path . ($query !== '' ? '?' . $query : '');
    }
    $href = explode('#', $href)[0];
    if ($href === '') {
        return '/';
    }
    if ($href[0] !== '/') {
        return null;   // 无斜杠的相对路径在正文里极罕见且无法离线可靠解析，跳过
    }
    foreach ($langPrefixes as $prefix) {
        $withSlash = '/' . $prefix . '/';
        if (strncmp($href, $withSlash, strlen($withSlash)) === 0) {
            $href = substr($href, strlen($prefix) + 1);
            break;
        }
        if ($href === '/' . $prefix) {
            $href = '/';
            break;
        }
    }
    if ($href !== '/' && substr($href, -1) === '/') {
        $href = rtrim($href, '/');
    }
    return $href;
}

/**
 * 站内静态文件是否存在（/uploads/... 直链等）。
 *
 * href 取自文章正文，可能含 `../` 或其百分号编码形态。直接 is_file(ROOT_PATH . $path)
 * 会穿出站点目录（实测 /uploads/../config/config.php 判定为存在），让扫描报告
 * 变成一个文件存在性探测器。用 realpath 把路径收回站点根内再判。
 */
function seo_linkcheck_file_exists(string $path): bool
{
    $resolved = realpath(ROOT_PATH . urldecode($path));
    if ($resolved === false || !is_file($resolved)) {
        return false;
    }
    $root = realpath(ROOT_PATH);
    return $root !== false && strncmp($resolved, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0;
}

/**
 * 链接分类。判定次序（都是离线判定）：
 *   有效集（CMS 生成器产出的 URL + 旧式 ?id=/slug 查询别名）
 *   → 磁盘上真实存在的文件（/uploads/... 直链等）
 *   → 已有重定向规则接管
 *   → 死链。
 *
 * @param list<string> $hrefs
 * @param array<string,true> $validSet
 * @param array<string,true> $redirectSources
 * @return array{internal:int,external:int,dead:list<string>,redirected:list<string>}
 */
function seo_linkcheck_classify(array $hrefs, array $validSet, array $redirectSources, string $siteHost, array $langPrefixes): array
{
    $result = ['internal' => 0, 'external' => 0, 'dead' => [], 'redirected' => []];
    foreach ($hrefs as $href) {
        $normalized = seo_linkcheck_normalize($href, $siteHost, $langPrefixes);
        if ($normalized === null) {
            $result['external']++;
            continue;
        }
        $result['internal']++;
        $pathOnly = explode('?', $normalized, 2)[0];
        if (isset($validSet[$normalized]) || isset($validSet[strtolower($normalized)])) {
            continue;
        }
        if (seo_linkcheck_file_exists($pathOnly)) {
            continue;
        }
        if (isset($redirectSources[$normalized])) {
            $result['redirected'][] = $normalized;
            continue;
        }
        $result['dead'][] = $normalized;
    }
    return $result;
}

/**
 * 有效 URL 集：seo_all_urls（生成器口径）+ 正文里常见的旧式查询别名。
 * @return array<string,true>
 */
function seo_linkcheck_valid_set(int $limit = 1000): array
{
    $host = (string) (parse_url(siteBaseUrl(), PHP_URL_HOST) ?: '');
    $prefixes = seo_linkcheck_lang_prefixes();
    $valid = [];
    foreach (seo_all_urls($limit) as $url) {
        $normalized = seo_linkcheck_normalize($url, $host, $prefixes);
        if ($normalized !== null) {
            $valid[$normalized] = true;
            $valid[strtolower($normalized)] = true;
        }
    }
    // rewrite 伪静态路径（物理上不存在的文件，由 .htaccess/nginx 映射到入口）
    foreach (['/sitemap.xml', '/index.html', '/index.php'] as $pseudo) {
        $valid[$pseudo] = true;
    }
    // 旧式 ?id= / ?slug= 互链别名（历史正文与外部资料常见形态）
    try {
        foreach (db()->fetchAll(
            'SELECT id, slug FROM ' . DB_PREFIX . 'contents WHERE status = 1 LIMIT ' . (int) $limit
        ) as $row) {
            $valid['/detail.php?id=' . (int) $row['id']] = true;
            if ((string) $row['slug'] !== '') {
                $valid['/detail.php?slug=' . (string) $row['slug']] = true;
            }
        }
        foreach (db()->fetchAll(
            'SELECT id FROM ' . DB_PREFIX . 'products WHERE status = 1 LIMIT ' . (int) $limit
        ) as $row) {
            $valid['/product.php?id=' . (int) $row['id']] = true;
        }
        foreach (db()->fetchAll(
            'SELECT id, slug, type FROM ' . DB_PREFIX . 'channels LIMIT ' . (int) $limit
        ) as $row) {
            if ((string) $row['slug'] !== '') {
                $valid['/list.php?cat=' . (string) $row['slug']] = true;
            }
            $valid['/page.php?id=' . (int) $row['id']] = true;
        }
    } catch (\Throwable $e) {
        // 表缺失（未装相应模块）时别名集为空——生成器主集仍在
    }
    return $valid;
}

/**
 * 全站扫描（专业版入口调用）：返回可 JSON 存档的报告。
 * @return array{at:int,scanned:int,internal:int,external:int,redirected:int,
 *               dead:list<array{url:string,uses:int,where:list<array{type:string,id:int,title:string}>}>}
 */
function seo_linkcheck_scan(int $limit = 1000): array
{
    $host = (string) (parse_url(siteBaseUrl(), PHP_URL_HOST) ?: '');
    $prefixes = seo_linkcheck_lang_prefixes();
    $validSet = seo_linkcheck_valid_set($limit);
    $redirectSources = [];
    try {
        foreach (seo_redirect_list(1000) as $rule) {
            $normalized = seo_linkcheck_normalize((string) $rule['source'], $host, $prefixes);
            if ($normalized !== null) {
                $redirectSources[$normalized] = true;
            }
        }
    } catch (\Throwable $e) {
        // 重定向表未建 = 无规则可接管
    }

    $report = ['at' => time(), 'scanned' => 0, 'internal' => 0, 'external' => 0,
        'redirected' => 0, 'dead' => []];
    $deadByUrl = [];
    $docs = [
        ['type' => '文章', 'table' => 'contents', 'title' => 'title'],
        ['type' => '产品', 'table' => 'products', 'title' => 'title'],
    ];
    foreach ($docs as $doc) {
        try {
            $rows = db()->fetchAll(
                'SELECT id, title, content FROM ' . DB_PREFIX . $doc['table']
                . ' WHERE status = 1 ORDER BY updated_at DESC, id DESC LIMIT ' . (int) $limit
            );
        } catch (\Throwable $e) {
            continue;
        }
        foreach ($rows as $row) {
            $report['scanned']++;
            $verdict = seo_linkcheck_classify(
                seo_linkcheck_hrefs((string) ($row['content'] ?? '')),
                $validSet,
                $redirectSources,
                $host,
                $prefixes
            );
            $report['internal'] += $verdict['internal'];
            $report['external'] += $verdict['external'];
            $report['redirected'] += count($verdict['redirected']);
            foreach ($verdict['dead'] as $url) {
                if (!isset($deadByUrl[$url])) {
                    $deadByUrl[$url] = ['url' => $url, 'uses' => 0, 'where' => []];
                }
                $deadByUrl[$url]['uses']++;
                if (count($deadByUrl[$url]['where']) < 3) {
                    $deadByUrl[$url]['where'][] = [
                        'type' => $doc['type'],
                        'id' => (int) $row['id'],
                        'title' => mb_substr((string) $row['title'], 0, 40),
                    ];
                }
            }
        }
    }
    usort($deadByUrl, static fn (array $a, array $b): int => $b['uses'] <=> $a['uses']);
    $report['dead'] = array_slice(array_values($deadByUrl), 0, 100);
    return $report;
}

/**
 * 索引健康摘要（只读，免费展示；死链计数来自专业版最近一次扫描）。
 * @return array<string,mixed>
 */
function seo_index_health(): array
{
    $sitemapEnabled = (string) config('seo_sitemap_enabled', '1') === '1';
    $robots = ROOT_PATH . '/robots.txt';
    $robotsExists = is_file($robots);
    $robotsHasSitemap = $robotsExists
        && stripos((string) file_get_contents($robots), 'sitemap') !== false;
    $deadCount = 0;
    $lastScan = 0;
    $last = json_decode((string) config('seo_linkcheck_last', ''), true);
    if (is_array($last)) {
        $deadCount = count($last['dead'] ?? []);
        $lastScan = (int) ($last['at'] ?? 0);
    }
    $log404Count = 0;
    $redirectCount = 0;
    try {
        if (db()->tableExists('seo_404log')) {
            $log404Count = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'seo_404log');
        }
        if (db()->tableExists('seo_redirects')) {
            $redirectCount = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'seo_redirects');
        }
    } catch (\Throwable $e) {
    }
    return [
        'sitemap_enabled' => $sitemapEnabled,
        'robots_exists' => $robotsExists,
        'robots_has_sitemap' => $robotsHasSitemap,
        'llms_exists' => is_file(ROOT_PATH . '/llms.txt'),
        'indexnow_ready' => (string) config('seo_indexnow_key', '') !== ''
            && is_file(seo_indexnow_key_path((string) config('seo_indexnow_key', ''))),
        'log404_count' => $log404Count,
        'redirect_count' => $redirectCount,
        'dead_count' => $deadCount,
        'last_scan_at' => $lastScan,
    ];
}
