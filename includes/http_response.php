<?php

declare(strict_types=1);

/** Application error codes below 400 remain HTTP 200 for legacy AJAX callers. */
function applicationErrorHttpStatus(int $code): int
{
    return $code >= 400 && $code <= 599 ? $code : 200;
}
