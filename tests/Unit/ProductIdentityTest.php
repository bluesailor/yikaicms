<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ProductIdentity.php';

final class ProductIdentityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yikai-product-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/core', 0777, true);
        $this->writeProductConfig(['core/a.php', 'core/b.php']);
        file_put_contents($this->root . '/core/a.php', '<?php echo "a";');
        file_put_contents($this->root . '/core/b.php', '<?php echo "b";');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRepositoryUsesCanonicalReverseDomainIdentity(): void
    {
        $identity = YikaiProductIdentity::identity(ROOT_PATH);

        self::assertSame('cn.yikai', $identity['vendor_id']);
        self::assertSame('cn.yikai.yikaicms', $identity['product_id']);
        self::assertSame('https://yikai.cn', $identity['vendor_url']);
        self::assertSame('https://www.yikaicms.com', $identity['product_url']);
        self::assertSame('Yikai', $identity['copyright_holder']);
        self::assertContains('includes/ProductIdentity.php', YikaiProductIdentity::fingerprintFiles(ROOT_PATH));
        self::assertContains('includes/FooterNavigation.php', YikaiProductIdentity::fingerprintFiles(ROOT_PATH));
        self::assertSame($identity, yikaiCmsIdentity());
    }

    public function testBuildManifestDetectsCoreFileChanges(): void
    {
        $manifest = YikaiProductIdentity::createBuildManifest(
            $this->root,
            '1.19.3',
            '1.19.3-20260829010101',
            'abcdef1234567890',
            false
        );
        $this->writePhpReturn($this->root . '/config/provenance.php', $manifest);
        $this->writePhpReturn($this->root . '/config/build.php', $manifest['build_id']);

        $verified = YikaiProductIdentity::buildInfo($this->root);
        self::assertSame('verified', $verified['integrity']);
        self::assertSame($manifest['core_tree_sha256'], $verified['core_tree_sha256']);
        self::assertSame('abcdef1234567890', $verified['source_commit']);

        file_put_contents($this->root . '/core/b.php', '<?php echo "changed";');
        $modified = YikaiProductIdentity::buildInfo($this->root);
        self::assertSame('modified', $modified['integrity']);
        self::assertSame(['core/b.php'], $modified['changed_files']);
    }

    public function testDirtyAndInvalidManifestsAreNotReportedAsVerified(): void
    {
        $dirty = YikaiProductIdentity::createBuildManifest(
            $this->root,
            '1.19.3',
            '1.19.3-dirty',
            'abcdef1',
            true
        );
        $this->writePhpReturn($this->root . '/config/provenance.php', $dirty);
        $this->writePhpReturn($this->root . '/config/build.php', $dirty['build_id']);
        self::assertSame('uncommitted', YikaiProductIdentity::buildInfo($this->root)['integrity']);

        $dirty['product_id'] = 'example.invalid';
        $this->writePhpReturn($this->root . '/config/provenance.php', $dirty);
        self::assertSame('invalid', YikaiProductIdentity::buildInfo($this->root)['integrity']);
    }

    public function testSourceCheckoutWithoutManifestUsesDevelopmentState(): void
    {
        $info = YikaiProductIdentity::buildInfo($this->root);

        self::assertSame('development', $info['integrity']);
        self::assertSame('development', $info['build_id']);
        self::assertSame([], $info['changed_files']);
    }

    public function testReleaseBuildCreatesAndShipsProvenanceManifest(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        $runtime = require ROOT_PATH . '/config/release-runtime.php';

        self::assertStringContainsString('tools/build-product-manifest.php', $build);
        self::assertStringContainsString('cp "$PKG_DIR/config/provenance.php" "$PAYLOAD/config/provenance.php"', $build);
        self::assertContains('config/product.php', $runtime['required_files']);
        self::assertContains('config/provenance.php', $runtime['generated_files']);
        self::assertContains('includes/ProductIdentity.php', $runtime['required_files']);
        self::assertContains('includes/FooterNavigation.php', $runtime['required_files']);
    }

    /** @param list<string> $files */
    private function writeProductConfig(array $files): void
    {
        $config = [
            'vendor_id' => 'cn.yikai',
            'product_id' => 'cn.yikai.yikaicms',
            'product_name' => 'YikaiCMS',
            'vendor_name' => 'Yikai',
            'copyright_holder' => 'Yikai',
            'vendor_url' => 'https://yikai.cn',
            'product_url' => 'https://www.yikaicms.com',
            'license_id' => 'YIKAI-CMS-LICENSE-1.0',
            'fingerprint_files' => $files,
        ];
        $this->writePhpReturn($this->root . '/config/product.php', $config);
    }

    private function writePhpReturn(string $path, mixed $value): void
    {
        file_put_contents($path, "<?php\nreturn " . var_export($value, true) . ";\n");
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
