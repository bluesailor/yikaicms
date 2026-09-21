<?php
/**
 * 分阶段读取与一次性读取必须给出同一份 manifest（E03 前置）。
 *
 * inspect() 只做条目校验与 manifest 解析，不把内容读进内存；entry() 逐条提取并在
 * 提取时核对 sha256 与消毒 SVG。两条路径共用 validateManifest()/inspectEntries()，
 * 本测试锁住"口径一致"这条契约——否则分阶段路径迟早与一次性路径漂移。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SiteTemplateArchive;
use ZipArchive;

require_once ROOT_PATH . '/includes/SiteTemplateArchive.php';

final class SiteTemplateStagedReadTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/yk_st_staged_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    /** 条目层校验对两条路径必须一致：危险包在 inspect() 上同样被拒。 */
    public function testInspectAppliesTheSameEntryGateAsRead(): void
    {
        $path = $this->dir . '/unsafe.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('site.json', '{}');
        $zip->addFromString('media/shell.php.jpg', '<?php');
        $zip->close();

        foreach ([
            'read' => static fn() => SiteTemplateArchive::read($path),
            'inspect' => static fn() => SiteTemplateArchive::inspect($path),
        ] as $label => $call) {
            try {
                $call();
                self::fail($label . ' 应拒绝危险条目');
            } catch (RuntimeException $e) {
                self::assertSame('st_unsafe', $e->getMessage(), $label . ' 必须以 st_unsafe 拒绝');
            }
        }
    }

    /** entry() 的内容校验：摘要不符必须拒绝，而不是把坏内容写到盘上。 */
    public function testEntryRejectsContentThatDoesNotMatchTheDigest(): void
    {
        $path = $this->dir . '/digest.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('media/photo.jpg', 'REAL');
        $zip->close();

        $manifest = ['files' => ['media/photo.jpg' => hash('sha256', 'REAL')]];
        self::assertSame('REAL', SiteTemplateArchive::entry($path, $manifest, 'media/photo.jpg'));

        $tampered = ['files' => ['media/photo.jpg' => hash('sha256', 'SOMETHING ELSE')]];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('st_invalid');
        SiteTemplateArchive::entry($path, $tampered, 'media/photo.jpg');
    }

    /** 清单外的条目名不可提取（防止调用方拿 manifest 之外的路径来取内容）。 */
    public function testEntryRefusesNamesOutsideTheManifest(): void
    {
        $path = $this->dir . '/scope.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('media/a.jpg', 'A');
        $zip->addFromString('media/b.jpg', 'B');
        $zip->close();

        $manifest = ['files' => ['media/a.jpg' => hash('sha256', 'A')]];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('st_invalid');
        SiteTemplateArchive::entry($path, $manifest, 'media/b.jpg');
    }
}
