<?php
/** Clean published-page preview requested by an explicit query parameter. */

declare(strict_types=1);

function isCleanFrontendPreview(): bool
{
    return array_key_exists('preview', $_GET);
}
