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
require_once dirname(__DIR__, 2) . '/includes/ProductCatalogRequest.php';

final class ProductController extends ListController
{
    public function prepare(array $channel, array $request): array
    {
        $channelId = (int) ($channel['id'] ?? $request['channelId']);
        // 原始 GET 最后合并，结构化/越界值会由规范化器拒绝，不能被上游 string cast 成 "Array"。
        $catalogQuery = ProductCatalogRequest::normalize(array_merge($request, $_GET));
        $page      = $catalogQuery['page'];
        $perPage   = (int) $request['perPage'];
        $offset    = ($page - 1) * $perPage;
        $keyword   = $catalogQuery['keyword'];
        $catSlug   = $catalogQuery['cat'];

        // Top-level product channel → list all products.
        // Sub-channel → use that channel's id as the product category id.
        $productCategoryId = ((int) ($channel['parent_id'] ?? 0) === 0) ? 0 : $channelId;

        // ?cat=<slug> overrides the channel-derived category.
        $productCategory = null;
        if (!empty($GLOBALS['yk_custom_product_category_id'])) {
            $productCategory = getProductCategory((int) $GLOBALS['yk_custom_product_category_id']);
            if ($productCategory) $productCategoryId = (int) $productCategory['id'];
        }
        if ($catSlug !== '' && $productCategory === null) {
            $productCategory = getProductCategoryBySlug($catSlug);
            if ($productCategory) {
                $productCategoryId = (int) $productCategory['id'];
            }
        }

        // Sort param — validated against the ProductModel whitelist so a
        // crafted query string can't sneak unsupported SQL through.
        $currentSort = $catalogQuery['sort'] !== 'default'
            ? $catalogQuery['sort']
            : (string) config('product_default_sort', 'default');
        if (!isset(ProductModel::SORT_MAP[$currentSort])) {
            $currentSort = 'default';
        }

        $where = [];
        if ($keyword !== '') {
            $where['keyword'] = $keyword;
        }
        $where['sort'] = $currentSort;

        // 多条件筛选参数与视图、缓存键共用同一份规范化结果。
        $brandIds  = $catalogQuery['brand_ids'];
        $selTagIds = $catalogQuery['tag_ids'];
        $priceMin  = $catalogQuery['pmin'];
        $priceMax  = $catalogQuery['pmax'];

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
        $sortCandidates = json_decode($sortOptionsJson, true);
        $sortCandidates = is_array($sortCandidates) ? $sortCandidates : ['default', 'newest', 'views'];
        $enabledSorts = array_values(array_filter($sortCandidates,
            static fn(mixed $sort): bool => is_string($sort) && isset(ProductModel::SORT_MAP[$sort])));
        if ($enabledSorts === []) {
            $enabledSorts = ['default'];
        }

        $catalogQuery['sort'] = $currentSort;

        return [
            'facetBrands'     => $facetBrands,
            'facetTagGroups'  => $facetTagGroups,
            'facetPrice'      => $facetPrice,
            'filterActive'    => $filterActive,
            'selBrandIds'     => $brandIds,
            'selTagIds'       => $selTagIds,
            'filterPriceMin'  => $priceMin,
            'filterPriceMax'  => $priceMax,
            'catalogQuery'    => $catalogQuery,
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
