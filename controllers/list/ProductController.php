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

        // Sort options enabled in the admin settings, used by the view.
        $sortOptionsJson = (string) config('product_sort_options', '["default","newest","views"]');
        $enabledSorts    = json_decode($sortOptionsJson, true) ?: ['default', 'newest', 'views'];

        return [
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
