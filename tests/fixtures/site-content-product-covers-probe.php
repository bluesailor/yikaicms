<?php
/**
 * 内容自检的产品主图检查（独立进程、完整表结构、临时站点目录）：
 * 主图是别的分类 / 上级分类的图（同网址或同内容）要报；产品所在分类自己的图不报；多款共用一张主图要报。
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
define('DB_PATH', ':memory:');
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);
function config(string $key, mixed $default = ''): mixed { return settingModel()->get($key, $default); }
function __(string $key, array $params = []): string { return $key; }
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/SiteContentChecks.php';
db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
foreach (['settings', 'channels', 'contents', 'products', 'product_categories', 'blox_templates', 'blox_page_drafts'] as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
settingModel()->clearCache();
function coversAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$root = sys_get_temp_dir() . '/sc-covers-' . bin2hex(random_bytes(4));
mkdir($root . '/uploads/images', 0777, true);
foreach (['collage' => 'A', 'collage-copy' => 'A', 'spring' => 'B', 'own' => 'C', 'plain' => 'D', 'shared' => 'E'] as $name => $body) {
    file_put_contents("{$root}/uploads/images/{$name}.png", str_repeat($body, 64));
}
$washer = (int) productCategoryModel()->create(['name' => 'Washer', 'slug' => 'washer', 'lang' => 'en', 'image' => '/uploads/images/collage.png']);
$spring = (int) productCategoryModel()->create(['name' => 'Spring washer', 'slug' => 'spring', 'parent_id' => $washer, 'lang' => 'en', 'image' => '/uploads/images/spring.png']);
$flat = (int) productCategoryModel()->create(['name' => 'Flat washer', 'slug' => 'flat', 'parent_id' => $washer, 'lang' => 'en', 'image' => '/uploads/images/own.png']);
$product = static fn (string $title, int $category, string $cover): int => (int) productModel()->create(
    ['title' => $title, 'slug' => strtolower(str_replace(' ', '-', $title)), 'lang' => 'en', 'status' => 1, 'category_id' => $category, 'cover' => "/uploads/images/{$cover}.png"]);
$collage = $product('Collage copy', $flat, 'collage-copy');      // 与大类图内容相同、文件名不同
$sibling = $product('Sibling image', $flat, 'spring');            // 用了兄弟小类的图
$own = $product('Own image', $flat, 'own');                       // 本小类的图取自本产品：正常
$product('Plain', $spring, 'plain');
$product('Shared one', $spring, 'shared');
$product('Shared two', $flat, 'shared');

$report = SiteContentChecks::run($root);
$byKind = [];
foreach ($report['issues'] as $issue) $byKind[$issue['kind']][] = $issue;
$wrong = array_column($byKind['sc_cover_category_image'] ?? [], 'detail', 'label');
coversAssert(array_keys($wrong) === ['Collage copy', 'Sibling image'], 'Wrong cover set: ' . json_encode(array_keys($wrong)));
coversAssert(str_starts_with($wrong['Collage copy'], 'Washer · '), 'Parent category not named');
coversAssert(str_starts_with($wrong['Sibling image'], 'Spring washer · '), 'Sibling category not named');
coversAssert(($byKind['sc_cover_category_image'][0]['url'] ?? '') === '/admin/product_edit.php?id=' . $collage, 'Wrong edit link');
coversAssert(($byKind['sc_cover_category_image'][0]['level'] ?? '') === 'review', 'Should be a review item');
$shared = $byKind['sc_cover_shared'] ?? [];
coversAssert(count($shared) === 1 && str_starts_with($shared[0]['detail'], '×2 · '), 'Shared cover not reported once');

array_map('unlink', glob($root . '/uploads/images/*'));
rmdir($root . '/uploads/images');
rmdir($root . '/uploads');
rmdir($root);
echo "Product cover checks passed\n";
