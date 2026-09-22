<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/includes/FormSpamGuard.php';

$blocked = FormSpamGuard::isExplicitCrossSite($_SERVER, 'http://127.0.0.1:8196');
http_response_code($blocked ? 403 : 200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['blocked' => $blocked], JSON_THROW_ON_ERROR);
