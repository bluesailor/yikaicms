<?php

declare(strict_types=1);

/** Resolve identities inside one kind's trusted server catalog before eligibility filtering. */
final class MarketCatalogItems
{
    /** @param array<array-key,mixed> $items @return list<array<string,mixed>> */
    public static function select(array $items): array
    {
        $official = [];
        $community = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['slug'] ?? null)
                || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $item['slug']) !== 1) {
                continue;
            }
            $slug = $item['slug'];
            // Legacy official catalogs omit source. This is not a client-supplied trust claim.
            $source = array_key_exists('source', $item) ? $item['source'] : 'official';
            if ($source === 'official') {
                $official[$slug] ??= $item;
            } elseif ($source === 'community') {
                if (isset($community[$slug]) && $community[$slug] !== $item) {
                    // Ambiguous community identity must never choose an arbitrary update package.
                    $community[$slug]['locked_reason'] = 'catalog_conflict';
                    $community[$slug]['entitled'] = false;
                    $community[$slug]['download_url'] = '';
                    unset($community[$slug]['hash'], $community[$slug]['sig'], $community[$slug]['size_kb']);
                } else {
                    $community[$slug] = $item;
                }
            }
        }
        // Reserve official identities even when their metadata/version is later rejected.
        return array_values($official + $community);
    }
}
