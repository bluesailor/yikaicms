<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use MediaWebpConverter;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MediaWebpConverter.php';

final class MediaWebpConverterTest extends TestCase
{
    private string $root;
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available');
        }
        $this->root = sys_get_temp_dir() . '/yk-webp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->uploads = $this->root . '/uploads';
        self::assertTrue(mkdir($this->uploads, 0775, true));
        $this->writeImage($this->uploads . '/hero.png', 'png', 320, 180);
        $this->writeImage($this->uploads . '/product.jpg', 'jpg', 200, 120);
        $this->resetTables();
        $this->seedReferences();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploads . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->uploads);
        @rmdir($this->root);
        $this->resetTables(false);
        parent::tearDown();
    }

    public function testDryRunReportsWithoutChangingFilesOrReferences(): void
    {
        $report = (new MediaWebpConverter($this->root))->run();

        self::assertTrue($report['dry_run']);
        self::assertSame(2, $report['scanned']);
        self::assertSame(2, $report['pending']);
        self::assertSame(0, $report['converted']);
        self::assertGreaterThanOrEqual(8, $report['reference_changes']);
        self::assertSame(1, $report['missing_references']);
        self::assertSame(['missing.png'], $report['missing_paths']);
        self::assertFileDoesNotExist($this->uploads . '/hero.webp');
        self::assertStringContainsString('hero.png', (string) db()->fetchColumn('SELECT content FROM contents WHERE id = ?', [1]));
    }

    public function testApplyRewritesHtmlBloxTablesAndSettingsWhileKeepingSources(): void
    {
        $report = (new MediaWebpConverter($this->root))->run(true, 85);

        self::assertFalse($report['dry_run']);
        self::assertSame(2, $report['converted']);
        self::assertGreaterThan(0, $report['saved_bytes']);
        self::assertFileExists($this->uploads . '/hero.webp');
        self::assertFileExists($this->uploads . '/product.webp');
        self::assertFileExists($this->uploads . '/hero.png');
        self::assertFileExists($this->uploads . '/product.jpg');

        self::assertSame('<img src="/uploads/hero.webp">', db()->fetchColumn('SELECT content FROM contents WHERE id = ?', [1]));
        self::assertStringContainsString('/uploads/hero.webp', (string) db()->fetchColumn('SELECT blocks_data FROM contents WHERE id = ?', [1]));
        self::assertSame('/uploads/hero.webp', db()->fetchColumn('SELECT image FROM banners WHERE id = ?', [1]));
        self::assertSame('/uploads/product.webp', db()->fetchColumn('SELECT cover FROM products WHERE id = ?', [1]));
        self::assertStringContainsString('/uploads/hero.webp', (string) db()->fetchColumn('SELECT published_data FROM blox_templates WHERE id = ?', [1]));
        self::assertStringContainsString('/uploads/product.webp', (string) db()->fetchColumn('SELECT value FROM settings WHERE `key` = ?', ['home_blox_published']));

        $media = db()->fetchOne('SELECT * FROM media WHERE id = ?', [1]);
        self::assertSame('/uploads/hero.webp', $media['url']);
        self::assertSame(
            str_replace('\\', '/', $this->uploads) . '/hero.webp',
            str_replace('\\', '/', (string) $media['path'])
        );
        self::assertSame('hero.webp', $media['name']);
        self::assertSame('webp', $media['ext']);
        self::assertSame('image/webp', $media['mime']);
        self::assertSame(filesize($this->uploads . '/hero.webp'), (int) $media['size']);
        self::assertSame(md5_file($this->uploads . '/hero.webp'), $media['md5']);
    }

    public function testSecondApplyIsIdempotent(): void
    {
        $converter = new MediaWebpConverter($this->root);
        $converter->run(true);
        $heroHash = hash_file('sha256', $this->uploads . '/hero.webp');

        $second = $converter->run(true);

        self::assertSame(0, $second['pending']);
        self::assertSame(0, $second['converted']);
        self::assertSame(2, $second['reused']);
        self::assertSame(0, $second['saved_bytes']);
        self::assertSame(0, $second['reference_changes']);
        self::assertSame($heroHash, hash_file('sha256', $this->uploads . '/hero.webp'));
        self::assertFileExists($this->uploads . '/hero.png');
    }

    public function testWindowsAbsoluteMediaPathKeepsItsSeparatorStyle(): void
    {
        $windowsPath = str_replace('/', '\\', $this->uploads) . '\\hero.png';
        db()->execute('UPDATE media SET path = ? WHERE id = ?', [$windowsPath, 1]);

        (new MediaWebpConverter($this->root))->run(true);

        self::assertSame(
            str_replace('/', '\\', $this->uploads) . '\\hero.webp',
            db()->fetchColumn('SELECT path FROM media WHERE id = ?', [1])
        );
    }

    public function testDatabaseFailureRollsBackReferencesAndRemovesNewWebpFiles(): void
    {
        db()->execute("CREATE TRIGGER fail_webp_reference BEFORE UPDATE OF content ON contents BEGIN SELECT RAISE(ABORT, 'forced'); END");

        try {
            (new MediaWebpConverter($this->root))->run(true);
            self::fail('Expected the forced database failure');
        } catch (\Throwable $error) {
            self::assertStringContainsString('forced', $error->getMessage());
        }

        self::assertFileDoesNotExist($this->uploads . '/hero.webp');
        self::assertFileDoesNotExist($this->uploads . '/product.webp');
        self::assertFileExists($this->uploads . '/hero.png');
        self::assertSame('<img src="/uploads/hero.png">', db()->fetchColumn('SELECT content FROM contents WHERE id = ?', [1]));
        self::assertSame('/uploads/hero.png', db()->fetchColumn('SELECT url FROM media WHERE id = ?', [1]));
    }

    public function testUnknownExistingWebpIsRejectedWithoutOverwrite(): void
    {
        file_put_contents($this->uploads . '/hero.webp', 'not-a-webp');
        $before = hash_file('sha256', $this->uploads . '/hero.webp');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('同名 WebP 已存在但不是有效图片');
        try {
            (new MediaWebpConverter($this->root))->run(true);
        } finally {
            self::assertSame($before, hash_file('sha256', $this->uploads . '/hero.webp'));
            self::assertFileDoesNotExist($this->uploads . '/product.webp');
            self::assertSame('/uploads/hero.png', db()->fetchColumn('SELECT url FROM media WHERE id = ?', [1]));
        }
    }

    public function testNestedEscapedDraftAndRevisionReferencesFollowPublishedContent(): void
    {
        db()->execute('CREATE TABLE blox_page_drafts (id INTEGER PRIMARY KEY, draft_data TEXT, published_data TEXT)');
        db()->execute('CREATE TABLE content_revisions (id INTEGER PRIMARY KEY, snapshot TEXT, note TEXT)');
        $document = json_encode(['sections' => [['image' => '/uploads/hero.png']]], JSON_THROW_ON_ERROR);
        $snapshot = json_encode(['targets' => [['table' => 'contents', 'fields' => ['blocks_data' => $document]]]], JSON_THROW_ON_ERROR);
        db()->insert('blox_page_drafts', ['id' => 1, 'draft_data' => $document, 'published_data' => $document]);
        db()->insert('content_revisions', ['id' => 1, 'snapshot' => $snapshot, 'note' => '/uploads/hero.png']);
        db()->insert('settings', ['key' => 'home_blox_history', 'value' => $snapshot]);
        db()->insert('settings', ['key' => 'home_blox_data', 'value' => $document]);

        (new MediaWebpConverter($this->root))->run(true);

        $draft = db()->fetchOne('SELECT * FROM blox_page_drafts WHERE id = ?', [1]);
        self::assertStringContainsString('/uploads/hero.webp', $draft['draft_data']);
        self::assertStringContainsString('/uploads/hero.webp', $draft['published_data']);
        self::assertStringNotContainsString('hero.png', (string) db()->fetchColumn('SELECT snapshot FROM content_revisions WHERE id = ?', [1]));
        self::assertSame('/uploads/hero.png', db()->fetchColumn('SELECT note FROM content_revisions WHERE id = ?', [1]));
        self::assertStringNotContainsString('hero.png', (string) db()->fetchColumn('SELECT value FROM settings WHERE `key` = ?', ['home_blox_history']));
        self::assertStringContainsString('/uploads/hero.webp', (string) db()->fetchColumn('SELECT value FROM settings WHERE `key` = ?', ['home_blox_data']));
    }

    public function testActualImageMimeDeterminesDecoder(): void
    {
        $this->writeImage($this->uploads . '/mislabeled.png', 'jpg', 240, 160);
        db()->insert('banners', ['id' => 2, 'image' => '/uploads/mislabeled.png']);

        $report = (new MediaWebpConverter($this->root))->run(true);

        self::assertSame(3, $report['converted']);
        self::assertSame('/uploads/mislabeled.webp', db()->fetchColumn('SELECT image FROM banners WHERE id = ?', [2]));
        $info = getimagesize($this->uploads . '/mislabeled.webp');
        self::assertSame('image/webp', $info['mime']);
        self::assertSame([240, 160], [$info[0], $info[1]]);
    }

    public function testDifferentSameSizedExistingWebpIsRejectedWithoutChangingReferences(): void
    {
        $image = imagecreatetruecolor(320, 180);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 10, 20));
        imagewebp($image, $this->uploads . '/hero.webp', 85);
        imagedestroy($image);
        $before = hash_file('sha256', $this->uploads . '/hero.webp');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('同名 WebP 与当前源图或转换品质不一致');

        try {
            (new MediaWebpConverter($this->root))->run(true);
        } finally {
            self::assertSame($before, hash_file('sha256', $this->uploads . '/hero.webp'));
            self::assertSame('/uploads/hero.png', db()->fetchColumn('SELECT url FROM media WHERE id = ?', [1]));
            self::assertFileDoesNotExist($this->uploads . '/product.webp');
        }
    }

    private function resetTables(bool $create = true): void
    {
        foreach (['media', 'contents', 'products', 'banners', 'blox_templates', 'settings', 'blox_page_drafts', 'content_revisions'] as $table) {
            db()->execute('DROP TABLE IF EXISTS ' . $table);
        }
        settingModel()->clearCache();
        if (!$create) {
            return;
        }
        db()->execute('CREATE TABLE contents (id INTEGER PRIMARY KEY, content TEXT, blocks_data TEXT)');
        db()->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, cover TEXT, images TEXT, content TEXT)');
        db()->execute('CREATE TABLE banners (id INTEGER PRIMARY KEY, image TEXT)');
        db()->execute('CREATE TABLE blox_templates (id INTEGER PRIMARY KEY, draft_data TEXT, published_data TEXT)');
        db()->execute('CREATE TABLE media (id INTEGER PRIMARY KEY, name TEXT, path TEXT, url TEXT, type TEXT, ext TEXT, mime TEXT, size INTEGER, width INTEGER, height INTEGER, md5 TEXT)');
        db()->execute('CREATE TABLE settings (id INTEGER PRIMARY KEY, `key` TEXT UNIQUE, `value` TEXT, `group` TEXT DEFAULT \'basic\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', type TEXT DEFAULT \'text\', options TEXT, sort_order INTEGER DEFAULT 0)');
    }

    private function seedReferences(): void
    {
        db()->execute('INSERT INTO contents (id, content, blocks_data) VALUES (?, ?, ?)', [
            1,
            '<img src="/uploads/hero.png">',
            json_encode(['sections' => [['image' => '/uploads/hero.png'], ['missing' => '/uploads/missing.png']]], JSON_THROW_ON_ERROR),
        ]);
        db()->execute('INSERT INTO products (id, cover, images, content) VALUES (?, ?, ?, ?)', [
            1, '/uploads/product.jpg', json_encode(['/uploads/hero.png'], JSON_THROW_ON_ERROR), '<p>/uploads/product.jpg</p>',
        ]);
        db()->execute('INSERT INTO banners (id, image) VALUES (?, ?)', [1, '/uploads/hero.png']);
        db()->execute('INSERT INTO blox_templates (id, draft_data, published_data) VALUES (?, ?, ?)', [
            1,
            json_encode(['image' => '/uploads/hero.png'], JSON_THROW_ON_ERROR),
            json_encode(['image' => '/uploads/hero.png'], JSON_THROW_ON_ERROR),
        ]);
        db()->execute('INSERT INTO media (id, name, path, url, type, ext, mime, size, width, height, md5) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            1, 'hero.png', $this->uploads . '/hero.png', '/uploads/hero.png', 'image', 'png', 'image/png',
            filesize($this->uploads . '/hero.png'), 320, 180, md5_file($this->uploads . '/hero.png'),
        ]);
        db()->execute('INSERT INTO settings (id, `key`, `value`) VALUES (?, ?, ?)', [
            1, 'home_blox_published', json_encode(['image' => '/uploads/product.jpg'], JSON_THROW_ON_ERROR),
        ]);
    }

    private function writeImage(string $path, string $format, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, imagecolorallocate($image, 33, 98, 166));
        $ok = $format === 'png' ? imagepng($image, $path) : imagejpeg($image, $path, 90);
        imagedestroy($image);
        self::assertTrue($ok);
    }
}
