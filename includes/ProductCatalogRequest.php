<?php
declare(strict_types=1);

/** One normalization contract for product queries, generated links and HTML cache identities. */
final class ProductCatalogRequest
{
    private const MAX_PAGE = 10000;
    private const MAX_IDS = 50;
    private const SORTS = ['default', 'recommend_first', 'newest', 'updated', 'views', 'price_asc', 'price_desc'];
    private const FILTER_KEYS = ['keyword', 'cat', 'sort', 'brand', 'tag', 'pmin', 'pmax', 'page'];

    /**
     * @param array<array-key,mixed> $input
     * @return array{page:int,keyword:string,cat:string,sort:string,brand:string,tag:string,pmin:string,pmax:string,brand_ids:list<int>,tag_ids:list<int>}
     */
    public static function normalize(array $input): array
    {
        $page = self::positivePage($input['page'] ?? 1) ?? 1;
        $keyword = self::keyword($input['keyword'] ?? '') ?? '';
        $cat = self::slug($input['cat'] ?? '') ?? '';
        $sort = self::sort($input['sort'] ?? '') ?? 'default';
        $brandIds = self::ids($input['brand'] ?? '') ?? [];
        $tagIds = self::ids($input['tag'] ?? '') ?? [];
        $pmin = self::price($input['pmin'] ?? '') ?? '';
        $pmax = self::price($input['pmax'] ?? '') ?? '';
        return [
            'page' => $page, 'keyword' => $keyword, 'cat' => $cat, 'sort' => $sort,
            'brand' => implode(',', $brandIds), 'tag' => implode(',', $tagIds), 'pmin' => $pmin, 'pmax' => $pmax,
            'brand_ids' => $brandIds, 'tag_ids' => $tagIds,
        ];
    }

    /** @param array<array-key,mixed> $normalized @return array<string,string> */
    public static function filterQuery(array $normalized, bool $withPage = false): array
    {
        $query = [];
        foreach (['keyword', 'brand', 'tag', 'pmin', 'pmax'] as $key) {
            $value = (string) ($normalized[$key] ?? '');
            if ($value !== '') $query[$key] = $value;
        }
        $sort = (string) ($normalized['sort'] ?? 'default');
        if ($sort !== '' && $sort !== 'default') $query['sort'] = $sort;
        if ($withPage && (int) ($normalized['page'] ?? 1) > 1) $query['page'] = (string) (int) $normalized['page'];
        return $query;
    }

    /**
     * Keep only documented route identity plus the normalized catalog query.
     * @param array<array-key,mixed> $source
     * @param array<array-key,mixed> $normalized
     * @return array<string,string>
     */
    public static function urlQuery(array $source, array $normalized, bool $withPage = true): array
    {
        $query = self::routeQuery($source);
        if ((string) ($normalized['cat'] ?? '') !== '') $query['cat'] = (string) $normalized['cat'];
        return array_merge($query, self::filterQuery($normalized, $withPage));
    }

    /**
     * One route builder for canonical URLs and every product-catalog pagination surface.
     * @param array<string,mixed> $channel
     * @param array<string,mixed>|null $category
     * @param array<array-key,mixed> $normalized
     */
    public static function pageUrl(array $channel, ?array $category, int $page, array $normalized): string
    {
        $filters = self::filterQuery($normalized);
        if ($category !== null) return customProductCategoryPageUrl($category, $page, $filters);
        if (isDynamicUrlMode()) {
            return dynamicChannelPageUrl($channel, $page, $filters)
                ?? dynamicUrl('list', ['id' => (int) ($channel['id'] ?? 0), 'page' => $page]);
        }
        $query = $filters !== [] ? '?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986) : '';
        $slug = (string) ($channel['slug'] ?? '');
        $path = $page === 1
            ? ($slug !== '' ? "/{$slug}.html" : "/list/{$channel['id']}.html")
            : ($slug !== '' ? "/{$slug}/page/{$page}.html" : "/list/{$channel['id']}/page/{$page}.html");
        return $path . $query;
    }

    /** @param array<array-key,mixed> $source @return array<string,string> */
    public static function routeQuery(array $source): array
    {
        $query = [];
        foreach (['yk_route', 'id', 'slug', 'parent', 'lang', '_lang'] as $key) {
            $raw = $source[$key] ?? null;
            if (!is_scalar($raw)) continue;
            $value = trim((string) $raw);
            $valid = match ($key) {
                'yk_route' => preg_match('/^[a-z][a-z0-9_]{0,49}$/D', $value) === 1,
                'id' => preg_match('/^[1-9][0-9]{0,9}$/D', $value) === 1 && (int) $value <= 2147483647,
                'slug', 'parent' => self::slug($value) === $value && $value !== '',
                'lang', '_lang' => preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $value) === 1,
                default => false,
            };
            if ($valid) $query[$key] = $value;
        }
        return $query;
    }

    /**
     * Cache normalization is strict: invalid or unknown parameters disable caching instead of aliasing another result.
     * @param array<array-key,mixed> $input
     * @return array<string,string>|null
     */
    public static function normalizeCacheQuery(array $input): ?array
    {
        $allowed = array_merge(self::FILTER_KEYS, ['yk_route', 'id', 'slug', 'parent', 'lang', '_lang', 'q', 's']);
        foreach (array_keys($input) as $key) if (!in_array((string) $key, $allowed, true)) return null;
        $query = [];
        foreach ($input as $key => $raw) {
            if (is_array($raw) || is_object($raw)) return null;
            $value = match ((string) $key) {
                'page' => ($page = self::positivePage($raw)) === null ? null : (string) $page,
                'sort' => self::sort($raw),
                'brand', 'tag' => ($ids = self::ids($raw)) === null ? null : implode(',', $ids),
                'pmin', 'pmax' => self::price($raw),
                'keyword', 'q', 's' => self::keyword($raw),
                'slug', 'parent', 'cat' => self::slug($raw),
                'yk_route' => preg_match('/^[a-z][a-z0-9_]{0,49}$/D', trim((string) $raw)) === 1 ? trim((string) $raw) : null,
                'id' => preg_match('/^[1-9][0-9]{0,9}$/D', trim((string) $raw)) === 1
                    && (int) $raw <= 2147483647 ? trim((string) $raw) : null,
                'lang', '_lang' => preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', trim((string) $raw)) === 1
                    ? trim((string) $raw) : null,
                default => null,
            };
            if ($value === null) return null;
            if ($value === '' || ($key === 'page' && $value === '1') || ($key === 'sort' && $value === 'default')) continue;
            $query[(string) $key] = $value;
        }
        ksort($query);
        return $query;
    }

    /** @param array<array-key,mixed> $normalized HTML attributes shared by every classic/Blox product-catalog root. */
    public static function rootAttributes(array $normalized): string
    {
        $canonical = class_exists('HtmlCache') ? HtmlCache::canonicalRequest() : [
            'path' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
            'query' => self::filterQuery($normalized, true),
        ];
        $attributes = [
            'data-product-catalog' => '',
            'data-catalog-ajax' => '1',
            'data-catalog-request-key' => hash('sha256', serialize($canonical)),
            'data-catalog-loading' => __('catalog_ajax_loading'),
            'data-catalog-updated' => __('catalog_ajax_updated'),
            'data-catalog-error' => __('catalog_ajax_error'),
            'data-catalog-retry' => __('catalog_ajax_retry'),
            'tabindex' => '-1',
        ];
        $html = [];
        foreach ($attributes as $name => $value) $html[] = $name . '="' . e($value) . '"';
        return implode(' ', $html);
    }

    /** @return list<int>|null */
    private static function ids(mixed $value): ?array
    {
        if (!is_scalar($value)) return null;
        $raw = trim((string) $value);
        if ($raw === '') return [];
        if (preg_match('/^[0-9]+(?:,[0-9]+)*$/D', $raw) !== 1) return null;
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int) $part;
            if ($id < 1 || $id > 2147483647) return null;
            $ids[$id] = $id;
            if (count($ids) > self::MAX_IDS) return null;
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private static function positivePage(mixed $value): ?int
    {
        if (!is_scalar($value) || preg_match('/^[0-9]{1,5}$/D', trim((string) $value)) !== 1) return null;
        $page = (int) $value;
        return $page >= 1 && $page <= self::MAX_PAGE ? $page : null;
    }

    private static function keyword(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $keyword = trim((string) $value);
        if (!mb_check_encoding($keyword, 'UTF-8')) return null;
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $keyword) === 1) return null;
        return mb_strlen($keyword) <= 100 ? $keyword : null;
    }

    private static function slug(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $slug = trim((string) $value);
        if ($slug === '') return '';
        return preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $slug) === 1 ? $slug : null;
    }

    private static function sort(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $sort = trim((string) $value);
        if ($sort === '') return 'default';
        return in_array($sort, self::SORTS, true) ? $sort : null;
    }

    private static function price(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $price = trim((string) $value);
        if ($price === '') return '';
        if (preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,4})?$/D', $price) !== 1) return null;
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($fraction, '0');
        return $fraction === '' ? $whole : $whole . '.' . $fraction;
    }
}
