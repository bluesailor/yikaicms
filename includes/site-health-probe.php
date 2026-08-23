<?php
/** YIKAI_SITE_HEALTH_PHP_PROBE: harmless execution-boundary marker. */
declare(strict_types=1);

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
