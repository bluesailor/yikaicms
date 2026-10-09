<?php
/**
 * 发行包文件清单与「本站改过、新包要覆盖的核心文件」体检（2.0.3，WP-09）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ReleaseFiles.php';

final class ReleaseFilesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/yk-release-files-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/includes', 0777, true);
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/index.php', "<?php echo 1;\n");
        file_put_contents($this->dir . '/includes/page.php', "<?php\n// core\n");
        file_put_contents($this->dir . '/includes/same.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return array<string, string> */
    private function writeManifest(string $version = '2.0.3'): array
    {
        $m = \ReleaseFiles::build($this->dir, $version);
        file_put_contents($this->dir . '/' . \ReleaseFiles::FILE, '<?php return ' . var_export($m, true) . ';');
        return $m['files'];
    }

    public function testManifestCoversEveryFileButItself(): void
    {
        $files = $this->writeManifest();
        self::assertSame(['includes/page.php', 'includes/same.php', 'index.php'], array_keys($files));
        // 重新生成时清单文件自身仍不在内
        self::assertArrayNotHasKey(\ReleaseFiles::FILE, \ReleaseFiles::build($this->dir, '2.0.3')['files']);
    }

    public function testManifestMustMatchTheRunningVersion(): void
    {
        $this->writeManifest('2.0.3');
        self::assertNotNull(\ReleaseFiles::load($this->dir, '2.0.3'));
        // 版本号被改过、或文件与版本不配套：无法判断，不能当作「没改过」
        self::assertNull(\ReleaseFiles::load($this->dir, '1.29.2'));
        self::assertNull(\ReleaseFiles::load($this->dir . '/missing', '2.0.3'));
    }

    public function testOnlyModifiedFilesThatTheUpgradeWouldReplaceAreReported(): void
    {
        $manifest = $this->writeManifest();
        $incoming = ['index.php' => "<?php echo 2;\n", 'includes/page.php' => "<?php\n// core v2\n", 'includes/same.php' => "<?php\n", 'includes/new.php' => "<?php\n"];
        $read = static fn (string $rel): string|false => $incoming[$rel] ?? false;
        $rels = array_keys($incoming);

        self::assertSame([], \ReleaseFiles::localChanges($this->dir, $manifest, $rels, $read), 'an untouched site is clean');

        file_put_contents($this->dir . '/includes/page.php', "<?php\n// core\n// 营业时间\n");
        self::assertSame(['includes/page.php'], \ReleaseFiles::localChanges($this->dir, $manifest, $rels, $read));

        // FTP 文本模式改了行尾不算改动
        file_put_contents($this->dir . '/index.php', "<?php echo 1;\r\n");
        self::assertSame(['includes/page.php'], \ReleaseFiles::localChanges($this->dir, $manifest, $rels, $read));

        // 本站改成的内容恰好就是新版内容：谈不上覆盖
        file_put_contents($this->dir . '/includes/page.php', "<?php\n// core v2\n");
        self::assertSame([], \ReleaseFiles::localChanges($this->dir, $manifest, $rels, $read));

        // 清单里没有、但本站放了同名文件，新包要写它：算冲突
        file_put_contents($this->dir . '/includes/new.php', "<?php // site's own\n");
        self::assertSame(['includes/new.php'], \ReleaseFiles::localChanges($this->dir, $manifest, $rels, $read));
    }

    /**
     * 2.0.4 发版 N-1 实测：清单文件不含自身，新包又带着新清单，2.0.3 的比对把它当成「站点自己放的同名文件」
     * 拦下了升级。清单改名为 release-manifest.php（2.0.3 碰不到），比对时新旧两个名字都跳过。
     */
    public function testManifestFilesAreNeverReportedAsLocalChanges(): void
    {
        $manifest = $this->writeManifest('2.0.4');
        self::assertSame('config/release-manifest.php', \ReleaseFiles::FILE);
        file_put_contents($this->dir . '/' . \ReleaseFiles::LEGACY_FILE, "<?php return ['schema' => 1, 'version' => '2.0.3', 'files' => []];");
        $incoming = [
            \ReleaseFiles::FILE => "<?php return ['schema' => 1, 'version' => '2.0.5', 'files' => []];",
            \ReleaseFiles::LEGACY_FILE => "<?php return [];",
            'index.php' => "<?php echo 1;\n",
        ];
        $read = static fn (string $rel): string|false => $incoming[$rel] ?? false;
        self::assertSame([], \ReleaseFiles::localChanges($this->dir, $manifest, array_keys($incoming), $read));
        self::assertArrayNotHasKey(\ReleaseFiles::LEGACY_FILE, \ReleaseFiles::build($this->dir, '2.0.4')['files']);
    }

    public function testCheckRunsBeforeAnyBackupOrWrite(): void
    {
        $src = (string) file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        $check = strpos($src, 'ReleaseFiles::localChanges(');
        self::assertIsInt($check);
        self::assertLessThan(strpos($src, "\$bakDir = ROOT_PATH . '/storage/backups/pre-upgrade-'"), $check, 'blocked before the backup directory is created');
        self::assertLessThan(strpos($src, 'Backup::generateSql('), $check, 'blocked before the database backup');
        self::assertStringContainsString("'error_code' => 'local_modifications'", $src);

        $online = (string) file_get_contents(ROOT_PATH . '/admin/upgrade_online.php');
        self::assertStringContainsString("upgrade_prepare('', '', true, \$backupOverride, \$acceptLocal, \$confirmGit)", $online);
        self::assertStringContainsString("pre.error_code === 'local_modifications'", $online);

        $auto = (string) file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        self::assertStringContainsString("\$pre = upgrade_prepare(\$from, \$to);", $auto, 'unattended upgrades never accept overwriting local changes');
        self::assertStringContainsString("return 'skipped: local modifications';", $auto);
    }

    public function testBuildShipsTheListInFullAndDeltaPackages(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        self::assertStringContainsString('php "tools/build-release-files.php" "$VERIFY_PKG_DIR" "$VERSION"', $build);
        self::assertLessThan(
            strpos($build, 'php "tools/build-release-files.php"'),
            strpos($build, 'php "tools/build-product-manifest.php"'),
            'generated after provenance so provenance.php is covered'
        );
        self::assertStringContainsString('cp "$PKG_DIR/config/release-manifest.php" "$PAYLOAD/config/release-manifest.php"', $build);
    }
}
