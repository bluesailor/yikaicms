<?php
/**
 * 产品导入 2.0：导入包的存放与解压（上传 zip、单张表、FTP 传到 incoming/ 的文件夹或 zip）。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/plugins/product-import/lib/ProductImportJobs.php';
require_once ROOT_PATH . '/plugins/product-import/lib/ProductImportPackages.php';

final class ProductImportPackagesTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        if (!class_exists('ZipArchive')) {
            self::markTestSkipped('zip extension required');
        }
        $this->tmp = sys_get_temp_dir() . '/pimpk-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmp);
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries): string
    {
        $path = $this->tmp . '/' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();
        return $path;
    }

    public function testAZipWithAWrappingFolderKeepsOnlyPackageFiles(): void
    {
        $packages = new ProductImportPackages($this->tmp . '/store');
        $package = $packages->extractZip($this->zip([
            'washer/products.json' => '{}', 'washer/images/a.png' => 'png', 'washer/run.php' => '<?php', '__MACOSX/washer/._a.png' => 'x',
            'washer/.hidden.png' => 'x',
        ]), 'washer.zip');

        self::assertSame('washer.zip', $package['name']);
        self::assertStringEndsWith('/washer', $package['dir'], '清单在下一层文件夹里也能找到');
        self::assertFileExists($package['dir'] . '/images/a.png');
        self::assertFileDoesNotExist($package['dir'] . '/run.php', '只收清单与图片');
        self::assertFileDoesNotExist($package['dir'] . '/.hidden.png');
        self::assertSame($package, $packages->get($package['id']));
        self::assertFileExists($this->tmp . '/store/.htaccess');
    }

    public function testUnsafeZipPathsAreRefusedAndNothingIsLeftBehind(): void
    {
        $packages = new ProductImportPackages($this->tmp . '/store');
        foreach (['../evil.png', 'a/../../evil.png', '/abs.png', 'C:/win.png'] as $name) {
            try {
                $packages->extractZip($this->zip(['products.json' => '{}', $name => 'x']), 'bad.zip');
                self::fail('accepted ' . $name);
            } catch (RuntimeException $e) {
                self::assertSame('zip_bad_path', $e->getMessage());
            }
        }
        self::assertSame([], glob($this->tmp . '/store/packages/*') ?: []);
        self::assertFileDoesNotExist($this->tmp . '/evil.png');
    }

    public function testATableAloneIsAPackage(): void
    {
        file_put_contents($this->tmp . '/upload.tmp', "名称\nA\n");
        $packages = new ProductImportPackages($this->tmp . '/store');
        $package = $packages->fromTable($this->tmp . '/upload.tmp', 'csv', 'list.csv');
        self::assertFileExists($package['dir'] . '/products.csv');
        $this->expectExceptionMessage('package_bad_type');
        $packages->fromTable($this->tmp . '/upload.tmp', 'php', 'x.php');
    }

    public function testIncomingFoldersAndZipsAreListedAndUsedInPlace(): void
    {
        $packages = new ProductImportPackages($this->tmp . '/store');
        mkdir($packages->incomingDir() . '/bolts/images', 0777, true);
        file_put_contents($packages->incomingDir() . '/bolts/products.json', '{}');
        copy($this->zip(['products.csv' => "名称\nA\n"]), $packages->incomingDir() . '/nuts.zip');
        file_put_contents($packages->incomingDir() . '/notes.txt', 'x');

        self::assertEqualsCanonicalizing(['bolts' => 'folder', 'nuts.zip' => 'zip'], array_column($packages->incoming(), 'type', 'name'));
        self::assertSame(str_replace('\\', '/', $packages->incomingDir() . '/bolts'), str_replace('\\', '/', $packages->useIncoming('bolts')['dir']), '文件夹直接用，不复制');
        self::assertFileExists($packages->useIncoming('nuts.zip')['dir'] . '/products.csv');
        foreach (['../store', 'notes.txt', '', '.hidden'] as $bad) {
            try {
                $packages->useIncoming($bad);
                self::fail('accepted ' . $bad);
            } catch (RuntimeException $e) {
                self::assertSame('incoming_missing', $e->getMessage());
            }
        }
        self::assertNull($packages->get('../../etc'));
    }
}
