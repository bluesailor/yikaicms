<?php
/**
 * 整站包 ZIP 层的 st_unsafe 防线（合并前补齐的集成覆盖）。
 *
 * 开发树已覆盖 13 条拒绝路径与 safePath() 的 6 个危险路径用例，但 ZIP 条目层的三条
 * 防御——symlink 条目、theme/ 与 media/ 的扩展名白名单、media/ 下 .php.jpg 双扩展名
 * 二次拒绝——此前只有实现没有验证。这三条恰是包解析最关键的攻击面。
 *
 * 这些检查在 manifest/schema 比对**之前**执行，所以无需数据库即可验证。
 * 每个用例都断言抛出的确实是 st_unsafe，而不是"因为别的原因也失败了"。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SiteTemplateArchive;
use ZipArchive;

require_once ROOT_PATH . '/includes/SiteTemplateArchive.php';

final class SiteTemplateArchiveSafetyTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/yk_st_safety_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** @param callable(ZipArchive):void $build */
    private function makeZip(callable $build): string
    {
        $path = $this->dir . '/probe_' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        // site.json 必须在：缺它会走到 manifest 分支，掩盖我们要验的 st_unsafe
        $zip->addFromString('site.json', '{"format":"yikaicms-site-template","version":1}');
        $build($zip);
        $zip->close();
        return $path;
    }

    private function assertRejectedAsUnsafe(string $zipPath, string $because): void
    {
        try {
            SiteTemplateArchive::read($zipPath);
            self::fail('应被拒绝：' . $because);
        } catch (RuntimeException $e) {
            self::assertSame('st_unsafe', $e->getMessage(), $because . '（须以 st_unsafe 拒绝，不能靠其它原因偶然失败）');
        }
    }

    /** symlink 条目：解压后会指向包外任意路径。 */
    public function testSymlinkEntriesAreRejected(): void
    {
        $zip = $this->makeZip(static function (ZipArchive $zip): void {
            $zip->addFromString('media/evil.png', '../../../../etc/passwd');
            // Unix 文件类型位 S_IFLNK(0120000)，与 SiteTemplateArchive::read 的判定对应
            $zip->setExternalAttributesName('media/evil.png', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
        $this->assertRejectedAsUnsafe($zip, 'ZIP 内的符号链接条目');
    }

    /** media/ 下的双扩展名：扩展名白名单看到的是 .jpg，但服务器可能按 .php 执行。 */
    public function testDoubleExtensionUnderMediaIsRejected(): void
    {
        foreach (['media/shell.php.jpg', 'media/shell.phtml.png', 'media/shell.phar.webp'] as $name) {
            $zip = $this->makeZip(static function (ZipArchive $zip) use ($name): void {
                $zip->addFromString($name, '<?php echo 1;');
            });
            $this->assertRejectedAsUnsafe($zip, $name);
        }
    }

    /** 扩展名白名单：media/ 只收静态资源，theme/ 另外允许 php/css/js/json。 */
    public function testExtensionAllowlistIsEnforcedPerPrefix(): void
    {
        foreach (['media/script.php', 'media/style.css', 'media/app.js', 'theme/run.sh', 'theme/data.sql'] as $name) {
            $zip = $this->makeZip(static function (ZipArchive $zip) use ($name): void {
                $zip->addFromString($name, 'payload');
            });
            $this->assertRejectedAsUnsafe($zip, $name . ' 不在该前缀的白名单内');
        }
    }

    /** 只有 theme/ 与 media/ 两个前缀被接受，其余一律拒绝。 */
    public function testEntriesOutsideKnownPrefixesAreRejected(): void
    {
        foreach (['config/config.php', 'index.php', 'uploads/a.png', 'a.png'] as $name) {
            $zip = $this->makeZip(static function (ZipArchive $zip) use ($name): void {
                $zip->addFromString($name, 'payload');
            });
            $this->assertRejectedAsUnsafe($zip, $name . ' 不在 theme/ 或 media/ 下');
        }
    }

    /**
     * 对照组：合法条目**不得**被 st_unsafe 拦下。
     * 没有这一条，上面四个用例可能只是"因为任何包都被拒"而假绿。
     */
    public function testLegitimateEntriesPassTheUnsafeGate(): void
    {
        $zip = $this->makeZip(static function (ZipArchive $zip): void {
            $zip->addFromString('media/photo.jpg', 'binary');
            $zip->addFromString('theme/layouts/header.php', '<?php declare(strict_types=1);');
            $zip->addFromString('theme/assets/app.css', 'body{}');
        });
        try {
            SiteTemplateArchive::read($zip);
            self::fail('该包的 manifest 不完整，应在 manifest 阶段被拒');
        } catch (RuntimeException $e) {
            self::assertNotSame('st_unsafe', $e->getMessage(),
                '合法条目不该被条目层拦下——它应当走到 manifest/schema 阶段才失败');
        }
    }
}
