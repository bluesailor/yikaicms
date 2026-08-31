<?php
/** Read-only, bounded detail-entry lookup for a catalog's published records. */
declare(strict_types=1);

final class BloxCatalogItems
{
    public static function read(array $channel, string $keyword, int $page): array
    {
        $type = (string) ($channel['type'] ?? '');
        if (!in_array($type, ['product', 'list'], true) || (int) ($channel['id'] ?? 0) < 1) {
            throw new RuntimeException(__('blox_bad_request'));
        }
        $page = max(1, min(1000, $page));
        $filters = [
            'lang' => (string) ($channel['lang'] ?? siteLang()),
            'keyword' => mb_substr(trim($keyword), 0, 120),
            'include_children' => true,
        ];
        if ($type === 'product') {
            // Match ProductController's channel/category convention and configured ordering.
            $categoryId = (int) ($channel['parent_id'] ?? 0) === 0 ? 0 : (int) $channel['id'];
            $filters['sort'] = (string) config('product_default_sort', 'default');
            $rows = productModel()->getList($categoryId, 7, ($page - 1) * 6, $filters);
        } else {
            // Other content types have separate permissions and edit forms.
            $filters['type'] = 'article';
            $rows = contentModel()->getList((int) $channel['id'], 7, ($page - 1) * 6, $filters);
        }
        $items = [];
        foreach (array_slice($rows, 0, 6) as $row) {
            $sourceName = (string) ($row[$type === 'product' ? 'category_name' : 'channel_name'] ?? '');
            $sourceId = (int) ($row[$type === 'product' ? 'category_id' : 'channel_id'] ?? 0);
            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'cover' => UrlPolicy::image((string) ($row['cover'] ?? '')),
                'source_label' => trim($sourceName) !== '' ? $sourceName
                    : __($sourceId > 0 ? 'blox_catalog_source_unavailable' : 'admin_uncategorized'),
            ];
        }
        return ['items' => $items, 'page' => $page, 'has_more' => $page < 1000 && count($rows) > 6];
    }
}
