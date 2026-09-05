<?php
/** Separate-process HTTP wrapper for the official template catalog fixture. */

declare(strict_types=1);

if ((string) ($_GET['mode'] ?? '') === 'unavailable') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'fixture unavailable';
    exit;
}

require __DIR__ . '/template-market-fixture.php';
