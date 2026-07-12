<?php
/**
 * Shim for the small set of channel/category helpers list-controllers
 * call into. Production wires these through includes/functions.php which
 * pulls a much heavier graph; here we redefine just enough to stay
 * test-isolated.
 *
 * Each helper is only declared if PHP hasn't already seen it, so a real
 * functions.php may be loaded above this without redeclaration errors.
 */

declare(strict_types=1);

if (!function_exists('getChannel')) {
    function getChannel(int $id): ?array {
        return db()->fetchOne('SELECT * FROM channels WHERE id = ?', [$id]);
    }
}
if (!function_exists('getChannelBySlug')) {
    function getChannelBySlug(string $slug): ?array {
        return db()->fetchOne('SELECT * FROM channels WHERE slug = ? AND status = 1', [$slug]);
    }
}
if (!function_exists('getChannels')) {
    /** Sub-channels under a parent — list-controllers only need the basic shape. */
    function getChannels(int $parentId, bool $navOnly = false): array {
        $sql = 'SELECT * FROM channels WHERE parent_id = ?';
        if ($navOnly) {
            $sql .= ' AND is_nav = 1';
        }
        $sql .= ' ORDER BY id ASC';
        return db()->fetchAll($sql, [$parentId]);
    }
}

// Product helper shims — production wires these to productModel(). The
// real productModel() reads `lang` and pulls in productCategoryModel for
// child-id recursion; the test schema disables both, keeping queries flat.
if (!function_exists('getProducts')) {
    function getProducts(int $categoryId = 0, int $limit = 10, int $offset = 0, array $where = []): array {
        return productModel()->getList($categoryId, $limit, $offset, $where + ['_skip_lang' => 1, 'include_children' => false]);
    }
}
if (!function_exists('getProductsCount')) {
    function getProductsCount(int $categoryId = 0, array $where = []): int {
        return productModel()->getCount($categoryId, $where + ['_skip_lang' => 1, 'include_children' => false]);
    }
}
if (!function_exists('getProductCategoryBySlug')) {
    function getProductCategoryBySlug(string $slug): ?array {
        return db()->fetchOne('SELECT * FROM product_categories WHERE slug = ? AND status = 1', [$slug]);
    }
}
if (!function_exists('getProductCategory')) {
    function getProductCategory(int $id): ?array {
        return db()->fetchOne('SELECT * FROM product_categories WHERE id = ?', [$id]);
    }
}
if (!function_exists('addProductViews')) {
    function addProductViews(int $id): int { return productModel()->incrementViews($id); }
}
