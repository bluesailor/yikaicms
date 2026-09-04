<?php

declare(strict_types=1);

/**
 * A known URL language that is not enabled must not render the default-language page.
 * Unknown values are left to the normal router, which will return its own 404.
 *
 * @param array<string,string> $available
 * @param list<string> $enabled
 */
function languagePrefixIsDisabled(string $requested, array $available, array $enabled): bool
{
    return $requested !== ''
        && array_key_exists($requested, $available)
        && !in_array($requested, $enabled, true);
}
