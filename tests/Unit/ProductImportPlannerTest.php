<?php
/**
 * 产品导入 2.0：读包与导入前校验（不写库）。图片用 GD 现画的真文件，按 MD5 比对。
 * 场景取自 FHZN 录入：Washer 产品误用大类展会拼图、小类图取自本产品、外部网址与 .. 路径不收。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/plugins/product-import/lib/ProductImportPackage.php';
require_once ROOT_PATH . '/plugins/product-import/lib/ProductImportPlanner.php';

final class ProductImportPlannerTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is required to draw test images');
        }
        $this->tmp = sys_get_temp_dir() . '/pimp-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/package/images', 0777, true);
        mkdir($this->tmp . '/site/uploads/images', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp === '' || !is_dir($this->tmp)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmp);
    }

    private function image(string $path, int $color, int $w = 600, int $h = 600): void
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, $color);
        imagepng($img, $path);
        imagedestroy($img);
    }

    /** @param array<string,mixed> $manifest */
    private function package(array $manifest): ProductImportPackage
    {
        file_put_contents($this->tmp . '/package/products.json', json_encode($manifest + ['format' => 'yikai-product-package', 'version' => 1, 'lang' => 'en']));
        return ProductImportPackage::fromDirectory($this->tmp . '/package', $this->tmp . '/site');
    }

    /** @param list<array{id:int,slug:string,name:string,parent_id:int,image:string}> $categories @param list<array<string,mixed>> $products */
    private function site(array $categories = [], array $products = []): ProductImportSite
    {
        $root = $this->tmp . '/site';
        return new class ($categories, $products, $root) implements ProductImportSite {
            public function __construct(private array $categories, private array $products, private string $root)
            {
            }
            public function categories(): array { return $this->categories; }
            public function productByImportKey(string $key): ?array { return $this->find(fn ($p) => ($p['import_key'] ?? '') === $key); }
            public function productBySlug(string $slug): ?array { return $this->find(fn ($p) => $p['slug'] === $slug); }
            public function productByModelTitle(string $model, string $title): ?array { return $this->find(fn ($p) => ($p['model'] ?? '') === $model && $p['title'] === $title); }
            public function sitePath(string $url): ?string { return is_file($this->root . $url) ? $this->root . $url : null; }
            public function imageMd5(string $url): ?string { $path = $this->sitePath($url); return $path !== null ? (string) md5_file($path) : null; }
            public function product(int $id): ?array { return null; }
            private function find(callable $test): ?array
            {
                foreach ($this->products as $p) {
                    if ($test($p)) {
                        return ['id' => $p['id'], 'title' => $p['title'], 'slug' => $p['slug']];
                    }
                }
                return null;
            }
        };
    }

    /** @param array<string,mixed> $row @return list<string> */
    private static function codes(array $row, string $kind = 'errors'): array
    {
        return array_column($row[$kind], 'code');
    }

    public function testACoverTakenFromAnotherOrParentCategoryIsAnError(): void
    {
        $this->image($this->tmp . '/package/images/collage.png', 0xff0000);   // 大类封面：展会拼图
        copy($this->tmp . '/package/images/collage.png', $this->tmp . '/package/images/washer-a.png');   // 同一张图换了名字当主图
        $this->image($this->tmp . '/package/images/spring.png', 0x00ff00);
        $this->image($this->tmp . '/package/images/plain.png', 0x0000ff);
        $plan = (new ProductImportPlanner($this->package([
            'categories' => [
                ['key' => 'washer', 'name' => 'Washer', 'image' => 'images/collage.png'],
                ['key' => 'washer-spring-washer', 'name' => 'Spring Washer', 'parent' => 'washer', 'image' => 'images/spring.png'],
            ],
            'products' => [
                ['key' => 'a', 'title' => 'Flat washer A', 'category' => 'washer-spring-washer', 'cover' => 'images/washer-a.png'],
                ['key' => 'b', 'title' => 'Spring washer B', 'category' => 'washer-spring-washer', 'cover' => 'spring.png'],
                ['key' => 'c', 'title' => 'Spring washer C', 'category' => 'washer-spring-washer', 'cover' => 'images/plain.png'],
            ],
        ]), $this->site()))->plan();

        self::assertSame(['cover_is_category_image'], self::codes($plan['products'][0]));
        self::assertSame('washer', $plan['products'][0]['errors'][0]['params']['categories'], '指出是哪个分类的图');
        self::assertSame('error', $plan['products'][0]['action'], '这一行不导入');
        self::assertSame([], self::codes($plan['products'][1]), '小类图取自本产品是正常的');
        self::assertSame('create', $plan['products'][2]['action']);
        self::assertSame(2, $plan['summary']['categories_new']);
    }

    public function testASiteCategoryImageReusedAsCoverIsCaughtByContent(): void
    {
        $this->image($this->tmp . '/site/uploads/images/nut.png', 0x123456);
        copy($this->tmp . '/site/uploads/images/nut.png', $this->tmp . '/package/images/looks-new.png');
        $this->image($this->tmp . '/package/images/ok.png', 0x654321);
        $site = $this->site([
            ['id' => 1, 'slug' => 'nuts', 'name' => 'Nuts', 'parent_id' => 0, 'image' => '/uploads/images/nut.png'],
            ['id' => 2, 'slug' => 'washer', 'name' => 'Washer', 'parent_id' => 0, 'image' => ''],
        ]);
        $plan = (new ProductImportPlanner($this->package(['products' => [
            ['title' => 'Washer X', 'category' => 'washer', 'cover' => 'images/looks-new.png'],
            ['title' => 'Washer Y', 'category' => 'washer', 'cover' => 'images/ok.png'],
        ]]), $site))->plan();

        self::assertSame(['cover_is_category_image'], self::codes($plan['products'][0]));
        self::assertSame('nuts', $plan['products'][0]['errors'][0]['params']['categories']);
        self::assertSame([], self::codes($plan['products'][1]));
    }

    public function testMissingExternalAndEscapingImagesAreRefused(): void
    {
        $this->image($this->tmp . '/package/images/ok.png', 0x111111);
        file_put_contents($this->tmp . '/secret.png', 'x');
        $plan = (new ProductImportPlanner($this->package(['products' => [
            ['title' => 'No cover'],
            ['title' => 'Bad refs', 'cover' => 'ok.png', 'images' => ['nope.png', 'https://s.alicdn.com/x.jpg', '../../secret.png'], 'param_images' => '/uploads/images/gone.png'],
        ]]), $this->site()))->plan();

        self::assertSame(['cover_missing'], self::codes($plan['products'][0]));
        self::assertSame(['image_not_found', 'image_not_allowed', 'image_not_allowed', 'image_not_found'], self::codes($plan['products'][1]));
        self::assertSame(2, $plan['summary']['error']);
    }

    public function testDuplicatesAreMatchedByKeyThenSlugThenModelAndTitle(): void
    {
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $name) {
            $this->image($this->tmp . "/package/images/{$name}.png", 0x101010 * ($i + 1));
        }
        $site = $this->site([], [
            ['id' => 7, 'title' => 'Hex bolt', 'slug' => 'hex-bolt', 'import_key' => '1601', 'model' => ''],
            ['id' => 8, 'title' => 'Eye bolt', 'slug' => 'eye-bolt', 'model' => ''],
            ['id' => 9, 'title' => 'U bolt', 'slug' => 'u-bolt-m8', 'model' => 'UB-8'],
        ]);
        $plan = (new ProductImportPlanner($this->package(['products' => [
            ['key' => '1601', 'title' => 'Hex bolt renamed', 'cover' => 'a.png'],
            ['slug' => 'eye-bolt', 'title' => 'Eye bolt', 'cover' => 'b.png'],
            ['model' => 'UB-8', 'title' => 'U bolt', 'cover' => 'c.png'],
            ['key' => 'new', 'slug' => 'brand-new', 'title' => 'New bolt', 'cover' => 'd.png'],
            ['key' => 'new', 'title' => 'Same key twice', 'cover' => 'e.png'],
        ]]), $site))->plan();

        self::assertSame(['update', 7, 'key'], [$plan['products'][0]['action'], $plan['products'][0]['match']['id'], $plan['products'][0]['match']['by']]);
        self::assertSame(['update', 8, 'slug'], [$plan['products'][1]['action'], $plan['products'][1]['match']['id'], $plan['products'][1]['match']['by']]);
        self::assertSame(['update', 9, 'model_title'], [$plan['products'][2]['action'], $plan['products'][2]['match']['id'], $plan['products'][2]['match']['by']]);
        self::assertSame('create', $plan['products'][3]['action']);
        self::assertSame(['duplicate_in_package'], self::codes($plan['products'][4]));
    }

    public function testSharedCoversSmallImagesAndMissingParameterImagesAreWarnings(): void
    {
        $this->image($this->tmp . '/package/images/shared.png', 0xaaaaaa);
        $this->image($this->tmp . '/package/images/tiny.png', 0xbbbbbb, 120, 80);
        $this->image($this->tmp . '/package/images/spec.png', 0xcccccc);
        $plan = (new ProductImportPlanner($this->package(['products' => [
            ['title' => 'A', 'cover' => 'shared.png', 'param_images' => ['spec.png']],
            ['title' => 'B', 'cover' => 'shared.png', 'param_images' => ['spec.png']],
            ['title' => 'C', 'cover' => 'tiny.png'],
        ]]), $this->site()))->plan();

        self::assertSame(['cover_shared'], self::codes($plan['products'][0], 'warnings'));
        self::assertSame('2', $plan['products'][0]['warnings'][0]['params']['count']);
        self::assertSame(['image_small', 'param_image_missing'], self::codes($plan['products'][2], 'warnings'));
        self::assertSame('create', $plan['products'][2]['action'], '警告不拦导入');
        self::assertSame(3, $plan['summary']['warnings']);
    }

    public function testASpreadsheetPackageBuildsTheCategoryTree(): void
    {
        $this->image($this->tmp . '/package/images/m6.png', 0x0a0b0c);
        $this->image($this->tmp . '/package/images/m6-2.png', 0x0c0b0a);
        $csv = "\xEF\xBB\xBF大类,小类,名称,型号,主图,产品图,参数图,规格,来源编号\n"
            . "Threaded rod,Fully Threaded,M6 Galvanized Threaded Rod,TR-M6,m6.png,m6.png|m6-2.png,,\"Material:Carbon steel\nFinish:Galvanized\",1601388182550\n";
        file_put_contents($this->tmp . '/package/products.csv', $csv);
        $package = ProductImportPackage::fromDirectory($this->tmp . '/package', $this->tmp . '/site');

        self::assertSame([], $package->errors);
        self::assertSame(['threaded-rod', 'threaded-rod-fully-threaded'], array_column($package->categories, 'key'));
        self::assertSame('threaded-rod', $package->categories[1]['parent']);
        $product = $package->products[0];
        self::assertSame(['threaded-rod-fully-threaded', '1601388182550', 'TR-M6'], [$product['category'], $product['key'], $product['model']]);
        self::assertSame(['m6.png', 'm6-2.png'], $product['images']);
        self::assertSame(['Material' => 'Carbon steel', 'Finish' => 'Galvanized'], $product['specs']);

        $plan = (new ProductImportPlanner($package, $this->site()))->plan();
        self::assertSame('create', $plan['products'][0]['action']);
        self::assertSame('package', $plan['products'][0]['images']['cover'][0]['kind']);
    }

    public function testACategoryMatchedByNameKeepsItsOwnImageAllowed(): void
    {
        $this->image($this->tmp . '/site/uploads/images/spring.png', 0x00aa00);
        copy($this->tmp . '/site/uploads/images/spring.png', $this->tmp . '/package/images/spring-copy.png');
        $site = $this->site([
            ['id' => 1, 'slug' => 'washer', 'name' => 'Washer', 'parent_id' => 0, 'image' => ''],
            ['id' => 2, 'slug' => 'spring-washer', 'name' => 'Spring washer', 'parent_id' => 1, 'image' => '/uploads/images/spring.png'],
        ]);
        $plan = (new ProductImportPlanner($this->package([
            'categories' => [['key' => 'washer-spring-washer', 'name' => 'Spring Washer', 'parent' => 'washer']],
            'products' => [['title' => 'Spring washer M8', 'category' => 'washer-spring-washer', 'cover' => 'spring-copy.png']],
        ]), $site))->plan();

        self::assertSame(['existing', 2], [$plan['categories'][0]['action'], $plan['categories'][0]['site_id']]);
        self::assertSame(['category_name_differs'], self::codes($plan['categories'][0], 'warnings'));
        self::assertSame([], self::codes($plan['products'][0]), '对上的本站小类自己的图不算错图');
    }

    public function testBrokenManifestsReportACode(): void
    {
        file_put_contents($this->tmp . '/package/products.json', '{"format":"something-else","version":1}');
        self::assertSame('package_bad_format', ProductImportPackage::fromDirectory($this->tmp . '/package', $this->tmp . '/site')->errors[0]['code']);
        unlink($this->tmp . '/package/products.json');
        self::assertSame('package_no_manifest', ProductImportPackage::fromDirectory($this->tmp . '/package', $this->tmp . '/site')->errors[0]['code']);
    }
}
