<?php
/**
 * Yikai CMS - 动态 Sitemap XML 生成器
 *
 * 自动收录所有已发布的栏目、文章、产品、案例、招聘等页面
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

HtmlCache::start(86400);

// 检查是否启用
if (config('seo_sitemap_enabled', '1') !== '1') {
    header('HTTP/1.1 404 Not Found');
    exit;
}

header('Content-Type: application/xml; charset=utf-8');

// 缓存（使用后台配置的缓存时间）
$sitemapTtl = (int)config('seo_sitemap_ttl', 600);
$cached = cacheGet('sitemap_xml');
if ($cached !== null) {
    echo $cached;
    exit;
}

$siteUrl = siteBaseUrl();

$urls = [];

// 首页
$urls[] = [
    'loc'        => $siteUrl . '/',
    'changefreq' => 'daily',
    'priority'   => '1.0',
];

// 栏目页
$channels = channelModel()->getTree();
foreach ($channels as $channel) {
    if ($channel['type'] === 'link') continue;
    $urls[] = [
        'loc'        => $siteUrl . channelUrl($channel),
        'lastmod'    => date('Y-m-d', (int)($channel['updated_at'] ?? $channel['created_at'] ?? time())),
        'changefreq' => 'weekly',
        'priority'   => '0.8',
    ];
    // 子栏目
    if (!empty($channel['children'])) {
        foreach ($channel['children'] as $child) {
            if ($child['type'] === 'link') continue;
            $urls[] = [
                'loc'        => $siteUrl . channelUrl($child),
                'lastmod'    => date('Y-m-d', (int)($child['updated_at'] ?? $child['created_at'] ?? time())),
                'changefreq' => 'weekly',
                'priority'   => '0.7',
            ];
        }
    }
}

// 文章/案例/下载/招聘等内容
$contents = db()->fetchAll(
    "SELECT c.id, c.title, c.slug, c.cover, c.publish_time, c.updated_at,
            ch.slug as channel_slug, ch.type as channel_type
     FROM " . DB_PREFIX . "contents c
     LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
     WHERE c.status = 1
     ORDER BY c.publish_time DESC
     LIMIT 5000"
);

foreach ($contents as $content) {
    $url = [
        'loc'        => $siteUrl . contentUrl($content),
        'lastmod'    => date('Y-m-d', (int)($content['updated_at'] ?: (($content['publish_time'] ?? 0) ?: ($content['created_at'] ?? 0)))),
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
    "SELECT p.id, p.title, p.slug, p.cover, p.created_at, p.updated_at,
            pc.slug as category_slug
     FROM " . DB_PREFIX . "products p
     LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
     WHERE p.status = 1
     ORDER BY p.updated_at DESC, p.id DESC
     LIMIT 5000"
);

foreach ($products as $product) {
    $url = [
        'loc'        => $siteUrl . productUrl($product),
        'lastmod'    => date('Y-m-d', (int)($product['updated_at'] ?: $product['created_at'])),
        'changefreq' => 'monthly',
        'priority'   => '0.6',
    ];
    if (!empty($product['cover'])) {
        $url['image'] = $siteUrl . $product['cover'];
    }
    $urls[] = $url;
}

// 过滤器：允许插件增删 sitemap URL
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
cacheSet('sitemap_xml', $xml, $sitemapTtl);

echo $xml;
