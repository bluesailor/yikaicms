<?php
declare(strict_types=1);

/** Empty values preserve the legacy entry point's pagination on upgrades. */
function catalogPageSize(string $type, int $legacy = 12, int $channelId = 0): int
{
    if ($channelId > 0) {
        $override = config('catalog_channel_' . $channelId . '_page_size', '');
        if (is_string($override) && $override !== '' && validCatalogPageSize($override)) return (int) $override;
    }
    $kind = in_array($type, ['product', 'case', 'download', 'job'], true) ? $type : 'article';
    $value = config('catalog_' . $kind . '_page_size', '');
    return is_scalar($value) && preg_match('/^[1-9][0-9]*$/D', (string) $value)
        && (int) $value <= 100 ? (int) $value : $legacy;
}

function validCatalogPageSize(mixed $value): bool
{
    return is_string($value) && ($value === ''
        || (preg_match('/^[1-9][0-9]*$/D', $value) === 1 && (int) $value <= 100));
}
