<?php
/**
 * Yikai CMS - 动态 Sitemap XML 生成器
 *
 * 自动收录所有已发布的栏目、文章、产品、案例、招聘等页面
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

// 检查是否启用（在页面缓存之前：关掉后不能再从缓存里吐旧文件）
if (config('seo_sitemap_enabled', '1') !== '1') {
    header('HTTP/1.1 404 Not Found');
    exit;
}

// 类型必须在页面缓存之前发：缓存命中会直接输出并 exit，走不到后面的 header()，
// 站点地图就以默认的 text/html 下发——搜索引擎按 HTML 处理、解析失败。
header('Content-Type: application/xml; charset=utf-8');
HtmlCache::start(86400);

/**
 * 取第一个有效（> 0）的时间戳作 lastmod；都没有就返回空串、整行不输出。
 * 由来：栏目的 updated_at 在安装种子数据里是 0，`??` 不跳过 0，开发站 182 个地址里
 * 18 个 lastmod 成了 1970-01-01——搜索引擎据此判定整份 lastmod 不可信。宁缺毋错。
 */
function sitemapLastmod(mixed ...$timestamps): string
{
    foreach ($timestamps as $ts) {
        if (is_numeric($ts) && (int) $ts > 0) {
            return date('Y-m-d', (int) $ts);
        }
    }
    return '';
}

// 缓存（使用后台配置的缓存时间）
$sitemapTtl = (int)config('seo_sitemap_ttl', 600);
// 语言域名模式下每个主机各有一份 sitemap（只列本主机上的语言），缓存按主机分开
$sitemapCacheKey = LanguageDomains::active() ? 'sitemap_xml_' . md5(LanguageDomains::currentOrigin()) : 'sitemap_xml';
// 带上页面缓存代号：内容、网址登记或设置一改代号就换，站点地图随之重建（此前要等 TTL 过期）
$sitemapCacheKey .= '_' . substr(md5(settingModel()->htmlCacheGeneration()), 0, 12);
$cached = cacheGet($sitemapCacheKey);
if ($cached !== null) {
    echo $cached;
    exit;
}

$siteUrl = siteBaseUrl();

// 只提交**已启用语言**的内容。原先三处查询都不带 lang 条件：多语言站会把各语言混在
// 一张 sitemap 里；只开英文的站点更糟——把从未启用、内容为空的中文/日文栏目页
// 一并交给搜索引擎去索引（fhzn 实测 204 条里有 9 条是这种）。
// enabledLanguages() 返回的是 ['en' => 'English'] 这种**映射**，要的是键不是值
$sitemapLangs = isMultiLangEnabled('channels') ? array_keys(enabledLanguages()) : [];
if ($sitemapLangs !== [] && LanguageDomains::active()) {
    // 搜索引擎只认同一主机的 sitemap 条目：语言域名只列自己，主域名不列有独立域名的语言
    $sitemapLangs = LanguageDomains::languagesServedHere($sitemapLangs);
}
$sitemapLangParams = [];
$sitemapLangCond = static fn(string $col): string => '';   // 未启用多语言时不加条件
if ($sitemapLangs !== []) {
    $ph = implode(',', array_fill(0, count($sitemapLangs), '?'));
    $sitemapLangCond = static fn(string $col): string => " AND {$col} IN ({$ph})";
    $sitemapLangParams = $sitemapLangs;
}

$urls = [];
// 各行按自己的语言出网址（2.0.5：LocalizedUrl::urlFor；动态网址带 lang 参数、语言域名给完整地址）
$sitemapLoc = static function (string $kind, array $row, string $fallback) use ($siteUrl): string {
    $lang = (string) ($row['lang'] ?? '');
    $url = $lang !== '' ? LocalizedUrl::urlFor($kind, $row, $lang) : '';
    if ($url === '') $url = $fallback;
    return preg_match('#^https?://#i', $url) === 1 ? $url : $siteUrl . $url;
};

// 首页
$urls[] = [
    'loc'        => $siteUrl . '/',
    'changefreq' => 'daily',
    'priority'   => '1.0',
];

// 栏目页
$channels = channelModel()->getTree();
// 会跳走的单页不是规范网址（2.0.5 网址回归）：设了指定地址跳转的，或默认「自动跳到第一个子栏目」且有子栏目的父单页
$sitemapRedirects = static function (array $channel): bool {
    if (($channel['type'] ?? '') !== 'page') return false;
    $type = (string) ($channel['redirect_type'] ?? 'auto');
    if ($type === 'url') return trim((string) ($channel['redirect_url'] ?? '')) !== '';
    return $type === 'auto' && channelModel()->getByParent((int) $channel['id'], true) !== [];
};
foreach ($channels as $channel) {
    if ($channel['type'] === 'link') continue;
    if ($sitemapLangs !== [] && !in_array((string) ($channel['lang'] ?? ''), $sitemapLangs, true)) continue;
    if (!$sitemapRedirects($channel)) $urls[] = [
        'loc'        => $sitemapLoc('channel', $channel, channelUrl($channel)),
        'lastmod'    => sitemapLastmod($channel['updated_at'] ?? null, $channel['created_at'] ?? null),
        'changefreq' => 'weekly',
        'priority'   => '0.8',
    ];
    // 子栏目
    if (!empty($channel['children'])) {
        foreach ($channel['children'] as $child) {
            if ($child['type'] === 'link' || $sitemapRedirects($child)) continue;
            $urls[] = [
                'loc'        => $sitemapLoc('channel', $child, channelUrl($child)),
                'lastmod'    => sitemapLastmod($child['updated_at'] ?? null, $child['created_at'] ?? null),
                'changefreq' => 'weekly',
                'priority'   => '0.7',
            ];
        }
    }
}

// 文章/案例/下载/招聘等内容
$contents = db()->fetchAll(
    // c.type 必须选出来，否则 contentUrl() 认不出文章，提交给搜索引擎的就是 404 地址
    "SELECT c.id, c.lang, c.title, c.slug, c.type, c.cover, c.publish_time, c.updated_at,
            ch.slug as channel_slug, ch.type as channel_type
     FROM " . DB_PREFIX . "contents c
     LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
     WHERE c.status = 1 AND c.deleted_at IS NULL" . $sitemapLangCond('c.lang') . "
     ORDER BY c.publish_time DESC
     LIMIT 5000",
    $sitemapLangParams
);

foreach ($contents as $content) {
    $url = [
        'loc'        => $sitemapLoc('content', $content, contentUrl($content)),
        'lastmod'    => sitemapLastmod($content['updated_at'] ?? null, $content['publish_time'] ?? null, $content['created_at'] ?? null),
        'changefreq' => 'monthly',
        'priority'   => '0.6',
    ];
    if (!empty($content['cover'])) {
        $url['image'] = $siteUrl . $content['cover'];
    }
    $urls[] = $url;
}

// 产品
$products = db()->fetchAll(
    "SELECT p.id, p.lang, p.title, p.slug, p.cover, p.created_at, p.updated_at,
            pc.slug as category_slug
     FROM " . DB_PREFIX . "products p
     LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
     WHERE p.status = 1 AND p.deleted_at IS NULL" . $sitemapLangCond('p.lang') . "
     ORDER BY p.updated_at DESC, p.id DESC
     LIMIT 5000",
    $sitemapLangParams
);

foreach ($products as $product) {
    $url = [
        'loc'        => $sitemapLoc('product', $product, productUrl($product)),
        'lastmod'    => sitemapLastmod($product['updated_at'] ?? null, $product['created_at'] ?? null),
        'changefreq' => 'monthly',
        'priority'   => '0.6',
    ];
    if (!empty($product['cover'])) {
        $url['image'] = $siteUrl . $product['cover'];
    }
    $urls[] = $url;
}

// 过滤器：允许插件增删 sitemap URL
foreach (productRouteModel()->available() ? productCategoryModel()->where(['status' => 1, 'lang' => siteLang()]) : [] as $category) {
    if (productRouteModel()->pathFor('category', (int) $category['id']) !== '') {
        $urls[] = ['loc' => $siteUrl . productCategoryUrl($category), 'changefreq' => 'weekly', 'priority' => '0.7'];
    }
}

// 只有登记网址才有入口的页面（相册、文章标签、产品标签；WordPress 迁移保留的原站地址）
if (productRouteModel()->available()) {
    $registeredOnly = db()->fetchAll("SELECT path FROM " . DB_PREFIX . "product_routes WHERE entity_type IN ('album', 'content_tag', 'product_tag') LIMIT 20000");
    foreach ($registeredOnly as $route) {
        $hit = productRouteModel()->resolve((string) $route['path']);
        if ($hit !== null && $hit['active'] && $hit['lang'] === siteLang()) {
            $urls[] = ['loc' => $siteUrl . customRoutePublicPath((string) $route['path']), 'changefreq' => 'weekly', 'priority' => '0.5'];
        }
    }
}

$urls = apply_filters('sitemap_urls', $urls);

// 生成 XML
$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
$xml .= '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

foreach ($urls as $url) {
    $xml .= "  <url>\n";
    $xml .= "    <loc>" . htmlspecialchars($url['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
    if (!empty($url['lastmod'])) {
        $xml .= "    <lastmod>" . $url['lastmod'] . "</lastmod>\n";
    }
    $xml .= "    <changefreq>" . $url['changefreq'] . "</changefreq>\n";
    $xml .= "    <priority>" . $url['priority'] . "</priority>\n";
    if (!empty($url['image'])) {
        $xml .= "    <image:image>\n";
        $xml .= "      <image:loc>" . htmlspecialchars($url['image'], ENT_XML1, 'UTF-8') . "</image:loc>\n";
        $xml .= "    </image:image>\n";
    }
    $xml .= "  </url>\n";
}

$xml .= "</urlset>\n";

// 写入缓存
cacheSet($sitemapCacheKey, $xml, $sitemapTtl);

echo $xml;
