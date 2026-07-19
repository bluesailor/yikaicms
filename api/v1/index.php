<?php
/**
 * 公开内容 API v1 — 分发器。
 *
 * 访问：/api/v1/?resource=products&...  （或经重写规则 /api/v1/products）
 * 资源：channels / contents / content / products / product / search
 * 全部只读、只出已发布内容、字段白名单。
 */

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// ---- 字段白名单（杜绝泄露 admin_id / deleted_at / 草稿等内部字段）----

function apiPublicChannel(array $c): array
{
    return [
        'id'        => (int) ($c['id'] ?? 0),
        'parent_id' => (int) ($c['parent_id'] ?? 0),
        'name'      => (string) ($c['name'] ?? ''),
        'slug'      => (string) ($c['slug'] ?? ''),
        'type'      => (string) ($c['type'] ?? ''),
        'url'       => function_exists('channelUrl') ? channelUrl($c) : '',
    ];
}

function apiPublicContent(array $r, bool $full = false): array
{
    $out = [
        'id'           => (int) ($r['id'] ?? 0),
        'channel_id'   => (int) ($r['channel_id'] ?? 0),
        'type'         => (string) ($r['type'] ?? ''),
        'title'        => (string) ($r['title'] ?? ''),
        'subtitle'     => (string) ($r['subtitle'] ?? ''),
        'slug'         => (string) ($r['slug'] ?? ''),
        'summary'      => (string) ($r['summary'] ?? ''),
        'cover'        => (string) ($r['cover'] ?? ''),
        'is_hot'       => (int) ($r['is_hot'] ?? 0),
        'is_recommend' => (int) ($r['is_recommend'] ?? 0),
        'views'        => (int) ($r['views'] ?? 0),
        'publish_time' => (int) ($r['publish_time'] ?? 0),
        'url'          => function_exists('contentUrl') ? contentUrl($r) : '',
    ];
    if ($full) {
        $out['content'] = (string) ($r['content'] ?? '');
        $out['images']  = (string) ($r['images'] ?? '');
        $out['tags']    = (string) ($r['tags'] ?? '');
    }
    return $out;
}

function apiPublicProduct(array $r, bool $full = false): array
{
    $out = [
        'id'           => (int) ($r['id'] ?? 0),
        'category_id'  => (int) ($r['category_id'] ?? 0),
        'brand_id'     => (int) ($r['brand_id'] ?? 0),
        'title'        => (string) ($r['title'] ?? ''),
        'slug'         => (string) ($r['slug'] ?? ''),
        'summary'      => (string) ($r['summary'] ?? ''),
        'cover'        => (string) ($r['cover'] ?? ''),
        'model'        => (string) ($r['model'] ?? ''),
        'price'        => (float) ($r['price'] ?? 0),
        'market_price' => (float) ($r['market_price'] ?? 0),
        'is_hot'       => (int) ($r['is_hot'] ?? 0),
        'is_new'       => (int) ($r['is_new'] ?? 0),
        'views'        => (int) ($r['views'] ?? 0),
        'url'          => function_exists('productUrl') ? productUrl($r) : '',
    ];
    if ($full) {
        $out['content'] = (string) ($r['content'] ?? '');
        $out['images']  = (string) ($r['images'] ?? '');
        $out['specs']   = (string) ($r['specs'] ?? '');
    }
    return $out;
}

// ---- 参数 ----

$resource = preg_replace('/[^a-z_]/', '', strtolower((string) ($_GET['resource'] ?? '')));
$page     = max(1, (int) ($_GET['page'] ?? 1));
$limit    = min(50, max(1, (int) ($_GET['limit'] ?? 10)));
$offset   = ($page - 1) * $limit;

/** 把 channel 参数（id 或 slug）解析成 channelId；未指定=0（全部）。 */
$resolveChannelId = static function (): int {
    $c = trim((string) ($_GET['channel'] ?? ''));
    if ($c === '') {
        return 0;
    }
    if (ctype_digit($c)) {
        return (int) $c;
    }
    $ch = getChannelBySlug($c, true);
    return $ch ? (int) $ch['id'] : -1; // slug 未命中 → -1（空结果）
};

$flagWhere = static function (): array {
    $w = [];
    foreach (['recommend' => 'is_recommend', 'hot' => 'is_hot', 'top' => 'is_top', 'new' => 'is_new'] as $q => $f) {
        if (($_GET[$q] ?? '') === '1') {
            $w[$f] = 1;
        }
    }
    if (($kw = trim((string) ($_GET['keyword'] ?? ''))) !== '') {
        $w['keyword'] = $kw;
    }
    return $w;
};

// ---- 分发 ----

switch ($resource) {
    case 'channels':
        $parent  = (int) ($_GET['parent'] ?? 0);
        $navOnly = ($_GET['nav'] ?? '') === '1';
        $items   = array_map('apiPublicChannel', getChannels($parent, $navOnly));
        apiOk($items);

    case 'contents':
        $cid = $resolveChannelId();
        if ($cid < 0) {
            apiOk(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }
        $where = $flagWhere();
        $rows  = getContents($cid, $limit, $offset, $where);
        apiOk([
            'items' => array_map(static fn ($r) => apiPublicContent($r), $rows),
            'total' => getContentsCount($cid, $where),
            'page'  => $page,
            'limit' => $limit,
        ]);

    case 'content':
        $row = getContent((int) ($_GET['id'] ?? 0));
        if (!$row) {
            apiError('内容不存在', 404);
        }
        apiOk(apiPublicContent($row, true));

    case 'products':
        // 分类：id 或 slug
        $catId = 0;
        $cat   = trim((string) ($_GET['category'] ?? ''));
        if ($cat !== '') {
            if (ctype_digit($cat)) {
                $catId = (int) $cat;
            } else {
                $pc    = getProductCategoryBySlug($cat);
                $catId = $pc ? (int) $pc['id'] : -1;
            }
        }
        if ($catId < 0) {
            apiOk(['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit]);
        }
        $where = $flagWhere();
        // 复用 #1 的多条件筛选：品牌 / 价格 / 标签（每个 tag 独立成组 → 跨 tag AND）
        if (($brands = array_filter(array_map('intval', explode(',', (string) ($_GET['brand'] ?? '')))))) {
            $where['brand_ids'] = array_values($brands);
        }
        if (($pmin = trim((string) ($_GET['pmin'] ?? ''))) !== '' && is_numeric($pmin)) {
            $where['price_min'] = $pmin;
        }
        if (($pmax = trim((string) ($_GET['pmax'] ?? ''))) !== '' && is_numeric($pmax)) {
            $where['price_max'] = $pmax;
        }
        if (($tags = array_filter(array_map('intval', explode(',', (string) ($_GET['tag'] ?? '')))))) {
            $where['tag_groups'] = array_map(static fn ($id) => [$id], array_values($tags));
        }
        if (class_exists('ProductModel') && isset(ProductModel::SORT_MAP[(string) ($_GET['sort'] ?? '')])) {
            $where['sort'] = (string) $_GET['sort'];
        }
        $rows = getProducts($catId, $limit, $offset, $where);
        apiOk([
            'items' => array_map(static fn ($r) => apiPublicProduct($r), $rows),
            'total' => getProductsCount($catId, $where),
            'page'  => $page,
            'limit' => $limit,
        ]);

    case 'product':
        $row = getProduct((int) ($_GET['id'] ?? 0));
        if (!$row) {
            apiError('产品不存在', 404);
        }
        apiOk(apiPublicProduct($row, true));

    case 'search':
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') {
            apiError('缺少查询词 q', 400);
        }
        $kind  = (string) ($_GET['type'] ?? 'all');
        $items = [];
        if ($kind === 'content' || $kind === 'all') {
            foreach (getContents(0, $limit, $offset, ['keyword' => $q]) as $r) {
                $items[] = ['kind' => 'content'] + apiPublicContent($r);
            }
        }
        if ($kind === 'product' || $kind === 'all') {
            foreach (getProducts(0, $limit, $offset, ['keyword' => $q]) as $r) {
                $items[] = ['kind' => 'product'] + apiPublicProduct($r);
            }
        }
        apiOk(['items' => $items, 'q' => $q, 'page' => $page, 'limit' => $limit]);

    default:
        apiError('未知资源：' . $resource . '（可用 channels/contents/content/products/product/search）', 404);
}
