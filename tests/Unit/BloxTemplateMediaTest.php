<?php
/** Blox 模板包携带图片（2.0.3）：导出收集、严格校验、引用改写、内容寻址落盘与回滚。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxTemplateImporter;
use BloxTemplateMedia;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BloxTemplateMediaTest extends TestCase
{
    /** 1×1 PNG */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $uploads;

    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    protected function setUp(): void
    {
        $this->uploads = sys_get_temp_dir() . '/yk-tpl-media-' . bin2hex(random_bytes(4));
        mkdir($this->uploads . '/2026/09', 0777, true);
        file_put_contents($this->uploads . '/2026/09/hero.png', base64_decode(self::PNG));
        file_put_contents($this->uploads . '/2026/09/fake.png', 'not an image');
        file_put_contents($this->uploads . '/2026/09/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->uploads, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->uploads);
    }

    /** @return list<mixed> */
    private function document(): array
    {
        return [[
            'id' => 's1', 'settings' => ['bg_image' => '/uploads/2026/09/hero.png'],
            'columns' => [['id' => 'c1', 'elements' => [
                ['id' => 'e1', 'type' => 'image', 'data' => ['src' => '/uploads/2026/09/hero.png', 'alt' => 'Hero']],
                ['id' => 'e2', 'type' => 'image', 'data' => ['src' => '/uploads/2026/09/logo.svg']],
                ['id' => 'e3', 'type' => 'image', 'data' => ['src' => '/uploads/2026/09/fake.png']],
                ['id' => 'e4', 'type' => 'image', 'data' => ['src' => '/uploads/2026/09/missing.png']],
            ]]],
        ]];
    }

    public function testExportEmbedsOnlyRealReferencedRasterImages(): void
    {
        $media = BloxTemplateMedia::export($this->document(), '/uploads/2026/09/hero.png', $this->uploads);
        self::assertCount(1, $media, 'svg, fake and missing files are skipped; duplicates collapse');
        self::assertSame('2026/09/hero.png', $media[0]['path']);
        self::assertSame('image/png', $media[0]['mime']);
        self::assertSame(hash('sha256', base64_decode(self::PNG)), $media[0]['sha256']);
        self::assertSame(self::PNG, $media[0]['data']);
    }

    public function testValidationRejectsAnythingSuspicious(): void
    {
        $good = ['path' => '2026/09/hero.png', 'sha256' => hash('sha256', base64_decode(self::PNG)), 'data' => self::PNG];
        $valid = BloxTemplateMedia::validate([$good]);
        self::assertSame('blox-templates/' . substr($good['sha256'], 0, 24) . '.png', $valid[0]['target'], 'content-addressed target');
        self::assertSame([], BloxTemplateMedia::validate(null));

        foreach ([
            'traversal' => ['path' => '../evil.png'] + $good,
            'absolute' => ['path' => '/etc/hero.png'] + $good,
            'svg' => ['path' => '2026/09/logo.svg'] + $good,
            'php' => ['path' => '2026/09/x.php'] + $good,
            'hash mismatch' => ['sha256' => str_repeat('0', 64)] + $good,
            'not base64' => ['data' => '***'] + $good,
            'fake image' => ['data' => base64_encode('<?php echo 1;'), 'sha256' => hash('sha256', '<?php echo 1;')] + $good,
            'type mismatch' => ['path' => '2026/09/hero.jpg'] + $good,
        ] as $case => $item) {
            try {
                BloxTemplateMedia::validate([$item]);
                self::fail("$case should be rejected");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('blox_tpl_media_invalid', $e->getMessage(), $case);
            }
        }
        $this->expectException(RuntimeException::class);
        BloxTemplateMedia::validate(array_fill(0, BloxTemplateMedia::MAX_FILES + 1, $good));
    }

    public function testImportRewritesReferencesToTheLocalCopy(): void
    {
        $media = BloxTemplateMedia::export($this->document(), '', $this->uploads);
        $package = [
            'format' => BloxTemplateImporter::FORMAT, 'version' => BloxTemplateImporter::VERSION,
            'type' => 'section', 'name' => 'Hero with image', 'thumbnail' => '/uploads/2026/09/hero.png',
            'document' => $this->document(), 'media' => $media,
        ];
        $prepared = BloxTemplateImporter::prepare((string) json_encode($package));
        $target = '/uploads/blox-templates/' . substr($media[0]['sha256'], 0, 24) . '.png';
        self::assertStringContainsString($target, $prepared['draft_json']);
        self::assertStringNotContainsString('/uploads/2026/09/hero.png', $prepared['draft_json']);
        self::assertStringContainsString('/uploads/2026/09/logo.svg', $prepared['draft_json'], 'references without a bundled file stay as they were');
        self::assertSame($target, $prepared['thumbnail']);
        self::assertCount(1, $prepared['media']);

        // 不带 media 的旧包：行为不变
        unset($package['media']);
        self::assertStringContainsString('/uploads/2026/09/hero.png', BloxTemplateImporter::prepare((string) json_encode($package))['draft_json']);
    }

    public function testFilesAreContentAddressedAndRollbackRemovesNewOnes(): void
    {
        $valid = BloxTemplateMedia::validate(BloxTemplateMedia::export($this->document(), '', $this->uploads));
        $written = BloxTemplateMedia::writeFiles($valid, $this->uploads);
        self::assertCount(1, $written);
        self::assertFileExists($this->uploads . '/' . $valid[0]['target']);
        self::assertSame([], BloxTemplateMedia::writeFiles($valid, $this->uploads), 'identical content is reused, not rewritten');
        BloxTemplateMedia::removeFiles($written);
        self::assertFileDoesNotExist($this->uploads . '/' . $valid[0]['target']);
    }

    public function testExportOptionIsWiredIntoTheAdmin(): void
    {
        $admin = (string) file_get_contents(ROOT_PATH . '/admin/blox_templates.php');
        self::assertStringContainsString("exportJson(\$template, false, (string) get('media', '') === '1')", $admin);
        self::assertStringContainsString('data-testid="blox-template-export-media"', $admin);
        $importer = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxTemplateImporter.php');
        self::assertStringContainsString('BloxTemplateMedia::removeFiles($writtenMedia);', $importer, 'a failed import cleans up the files it wrote');
    }
}
