<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
define('DB_PATH', ':memory:');
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/SiteTemplateData.php';

db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
if (SiteTemplateData::schema() !== SiteTemplateData::contractSchema()) {
    throw new RuntimeException('Portable schema contract differs from a fresh install');
}
echo "Site template schema contract passed\n";
