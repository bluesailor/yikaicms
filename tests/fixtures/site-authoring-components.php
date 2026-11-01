<?php
/** Standalone import-report dependency regression, without editor bootstrap. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/includes/SiteAuthoringChecks.php';
echo json_encode(SiteAuthoringChecks::inspect(json_encode([
    'schema' => 1,
    'settings' => ['component' => ['props' => [], 'variants' => []]],
    'sections' => [],
])));
