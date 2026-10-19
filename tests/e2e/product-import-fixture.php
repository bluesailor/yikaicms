<?php
/**
 * 产品导入 2.0 浏览器测试夹具（只在一次性测试站里跑）。
 *   package  启用插件；在 incoming/ 放一个仿 FHZN Washer 的导入包（第 3、5 款主图误用了大类展会拼图），另出一份 zip，打印 zip 路径
 *   state    打印导入后的数据库状态（JSON）
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')
    || !str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')) {
    throw new RuntimeException('Disposable site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}

$action = $argv[1] ?? '';
if ($action === 'package') {
    $row = pluginModel()->findWhere(['slug' => 'product-import']);
    if ((int) ($row['status'] ?? 0) !== 1) {
        pluginModel()->activate('product-import');
    }
    $dir = ROOT_PATH . '/storage/product-import/incoming/e2e-washer';
    if (!is_dir($dir . '/images')) {
        mkdir($dir . '/images', 0755, true);
    }
    $colors = ['collage' => [200, 40, 40], 'flat' => [40, 160, 60], 'spring' => [40, 60, 200], 'lock' => [220, 180, 30], 'spec' => [90, 90, 90]];
    foreach ($colors as $name => [$r, $g, $b]) {
        $img = imagecreatetruecolor(480, 480);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        imagepng($img, "{$dir}/images/{$name}.png");
        imagedestroy($img);
    }
    copy("{$dir}/images/collage.png", "{$dir}/images/washer-collage-copy.png");
    file_put_contents($dir . '/products.json', json_encode([
        'format' => 'yikai-product-package', 'version' => 1, 'lang' => siteLang(),
        'categories' => [
            ['key' => 'e2e-washer', 'name' => 'E2E Washer', 'image' => 'images/collage.png'],
            ['key' => 'e2e-washer-flat', 'name' => 'E2E Flat Washer', 'parent' => 'e2e-washer'],
        ],
        'products' => [
            ['key' => 'e2e-1', 'title' => 'E2E Flat washer DIN125', 'category' => 'e2e-washer-flat', 'cover' => 'images/flat.png', 'images' => ['images/flat.png'], 'param_images' => ['images/spec.png'], 'specs' => ['Material' => 'Steel']],
            ['key' => 'e2e-2', 'title' => 'E2E Spring washer', 'category' => 'e2e-washer-flat', 'cover' => 'images/spring.png', 'param_images' => ['images/spec.png']],
            ['key' => 'e2e-3', 'title' => 'E2E Washer with collage cover', 'category' => 'e2e-washer-flat', 'cover' => 'images/washer-collage-copy.png'],
            ['key' => 'e2e-4', 'title' => 'E2E Lock washer', 'category' => 'e2e-washer-flat', 'cover' => 'images/lock.png', 'param_images' => ['images/spec.png']],
            ['key' => 'e2e-5', 'title' => 'E2E Washer with collage too', 'category' => 'e2e-washer-flat', 'cover' => 'images/collage.png'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zipPath = ROOT_PATH . '/storage/e2e-washer-package.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    // 连文件夹一起压：向导要能找到下一层的清单
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $zip->addFile($file->getPathname(), 'e2e-washer/' . str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1)));
    }
    $zip->close();
    echo $zipPath;
    exit;
}
if ($action === 'state') {
    $p = DB_PREFIX;
    $products = db()->fetchAll("SELECT id, title, cover, content, category_id FROM {$p}products WHERE title LIKE 'E2E %' AND deleted_at IS NULL ORDER BY id");
    echo json_encode([
        'products' => array_map(static fn (array $row): array => [
            'title' => $row['title'], 'cover_exists' => is_file(ROOT_PATH . $row['cover']),
            'param_block' => str_contains((string) $row['content'], 'yk-product-param-images'),
            'import_key' => getMeta('product', (int) $row['id'], 'import_key'),
        ], $products),
        'categories' => array_column(db()->fetchAll("SELECT slug FROM {$p}product_categories WHERE slug LIKE 'e2e-washer%' ORDER BY slug"), 'slug'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
throw new InvalidArgumentException('unknown action');
