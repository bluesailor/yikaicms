<?php
/**
 * Yikai CMS — list.php controller for `type=product` channels.
 *
 * Carved from list.php's most complex inline branch (≈30 lines). Adds:
 *   - resolves productCategoryId from channel parent_id and ?cat= slug
 *   - validates ?sort= against ProductModel::SORT_MAP whitelist
 *   - exposes enabledSorts so the view can render the sort dropdown
 */

declare(strict_types=1);

require_once __DIR__ . '/ListController.php';

final class ProductController extends ListController
{
    public function prepare(array $channel, array $request): array
    {
        $channelId = (int) ($channel['id'] ?? $request['channelId']);
        $page      = (int) $request['page'];
        $perPage   = (int) $request['perPage'];
        $offset    = ($page - 1) * $perPage;
        $keyword   = (string) $request['keyword'];
        $catSlug   = (string) $request['cat'];

        // Top-level product channel → list all products.
        // Sub-channel → use that channel's id as the product category id.
        $productCategoryId = ((int) ($channel['parent_id'] ?? 0) === 0) ? 0 : $channelId;

        // ?cat=<slug> overrides the channel-derived category.
        $productCategory = null;
        if ($catSlug !== '') {
            $productCategory = getProductCategoryBySlug($catSlug);
            if ($productCategory) {
                $productCategoryId = (int) $productCategory['id'];
            }
        }

        // Sort param — validated against the ProductModel whitelist so a
        // crafted query string can't sneak unsupported SQL through.
        $currentSort = $request['sort'] !== ''
            ? (string) $request['sort']
            : (string) config('product_default_sort', 'default');
        if (!isset(ProductModel::SORT_MAP[$currentSort])) {
            $currentSort = 'default';
        }

        $where = [];
        if ($keyword !== '') {
            $where['keyword'] = $keyword;
        }
        $where['sort'] = $currentSort;

        // 多条件筛选参数（?brand=1,3  ?tag=5,8  ?pmin=  ?pmax=）——对标 PbootCMS 多条件筛选。
        // 直接读 $_GET（与筛选面板 partial 一致，且不依赖 functions.php 的 get() 便于单测）。
        $brandIds  = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['brand'] ?? '')))));
        $selTagIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['tag'] ?? '')))));
        $priceMin  = trim((string) ($_GET['pmin'] ?? ''));
        $priceMax  = trim((string) ($_GET['pmax'] ?? ''));

        if ($brandIds) {
            $where['brand_ids'] = $brandIds;
        }
        if ($priceMin !== '' && is_numeric($priceMin)) {
            $where['price_min'] = $priceMin;
        }
        if ($priceMax !== '' && is_numeric($priceMax)) {
            $where['price_max'] = $priceMax;
        }
        // 选中的标签按 group_name 分组（组间 AND、组内 OR）
        $tagGroups = [];
        if ($selTagIds) {
            $ph   = implode(',', array_fill(0, count($selTagIds), '?'));
            $rows = db()->fetchAll('SELECT id, group_name FROM ' . DB_PREFIX . "product_tags WHERE id IN ({$ph})", $selTagIds);
            $byGroup = [];
            foreach ($rows as $r) {
                $byGroup[$r['group_name']][] = (int) $r['id'];
            }
            $tagGroups = array_values($byGroup);
            if ($tagGroups) {
                $where['tag_groups'] = $tagGroups;
            }
        }

        // 筛选面板数据（限当前分类，避免出现零结果的筛选项）
        $model          = productModel();
        $facetBrands    = $model->facetBrands($productCategoryId);
        $facetTagGroups = $model->facetTagGroups($productCategoryId);
        $facetPrice     = $model->facetPriceRange($productCategoryId);
        $filterActive   = (bool) ($brandIds || $selTagIds || $priceMin !== '' || $priceMax !== '');

        // Sort options enabled in the admin settings, used by the view.
        $sortOptionsJson = (string) config('product_sort_options', '["default","newest","views"]');
        $enabledSorts    = json_decode($sortOptionsJson, true) ?: ['default', 'newest', 'views'];

        return [
            'facetBrands'     => $facetBrands,
            'facetTagGroups'  => $facetTagGroups,
            'facetPrice'      => $facetPrice,
            'filterActive'    => $filterActive,
            'selBrandIds'     => $brandIds,
            'selTagIds'       => $selTagIds,
            'filterPriceMin'  => $priceMin,
            'filterPriceMax'  => $priceMax,
            'channel'           => $channel,
            'channelId'         => $channelId,
            'page'              => $page,
            'perPage'           => $perPage,
            'keyword'           => $keyword,
            'productCategoryId' => $productCategoryId,
            'productCategory'   => $productCategory,
            'currentSort'       => $currentSort,
            'enabledSorts'      => $enabledSorts,
            'whereConditions'   => $where,           // legacy var name preserved
            'contents'          => getProducts($productCategoryId, $perPage, $offset, $where),
            'total'             => getProductsCount($productCategoryId, $where),
        ];
    }
}
