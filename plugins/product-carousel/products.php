<?php
declare(strict_types=1);

/** Resolve selected products without changing stored IDs, order or missing-translation fallback. */
function productCarouselProducts(array $ids, string $lang): array
{
    $model = productModel();
    $products = [];
    foreach ($ids as $id) {
        $source = $model->getPublished((int) $id);
        if ($source === null) continue;
        $product = $source;
        if (($source['lang'] ?? '') !== $lang) {
            $group = (int) ($source['translation_group_id'] ?? 0) ?: (int) $source['id'];
            $translated = $model->findWhere(['translation_group_id' => $group, 'lang' => $lang, 'status' => 1]);
            if ($translated !== null) {
                $product = $model->getPublished((int) $translated['id']) ?? $source;
            } else {
                // Older source rows may not have their own group ID backfilled.
                $root = $model->getPublished($group);
                if ($root !== null && ($root['lang'] ?? '') === $lang
                    && ((int) ($root['translation_group_id'] ?? 0) ?: (int) $root['id']) === $group) {
                    $product = $root;
                }
            }
        }
        $products[] = $product;
    }
    return $products;
}
