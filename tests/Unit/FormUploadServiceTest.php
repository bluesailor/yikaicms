<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/FormUploadService.php';
require_once ROOT_PATH . '/admin/includes/form_fields.php';

final class FormUploadServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/yikai-form-upload-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) return;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->directory);
    }

    private function service(): FormUploadService
    {
        return new FormUploadService(
            $this->directory . '/private',
            static fn(string $path): bool => is_file($path),
            static fn(string $from, string $to): bool => rename($from, $to)
        );
    }

    private function upload(string $name, string $contents): array
    {
        $path = $this->directory . '/' . bin2hex(random_bytes(4));
        file_put_contents($path, $contents);
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)];
    }

    public function testAllowedFileUsesRandomPrivatePathAndPortableReference(): void
    {
        $service = $this->service();
        $total = 0;
        $stored = $service->store($this->upload('报价单.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"),
            ['accept' => ['pdf'], 'max_size' => 2], $total);
        $metadata = FormUploadService::decodeReference($stored['reference']);
        self::assertNotNull($metadata);
        self::assertSame('报价单.pdf', $metadata['name']);
        self::assertMatchesRegularExpression('~^\d{4}/\d{2}/[a-f0-9]{32}\.pdf$~', $metadata['path']);
        self::assertStringNotContainsString($this->directory, $stored['reference']);
        self::assertStringNotContainsString('报价单', $metadata['path']);
        self::assertFileExists((string) $service->pathForReference($stored['reference']));
        self::assertSame($stored['size'], $total);
        self::assertSame([[
            'key' => 'proof',
            'label' => 'Proof',
            'value' => '报价单.pdf',
            'file_url' => '/admin/form_file.php?id=42&field=proof',
        ]], formSubmissionExtraFields(json_encode(['proof' => $stored['reference']], JSON_THROW_ON_ERROR), ['proof' => 'Proof'], 42));

        $service->remove($stored['reference']);
        self::assertNull($service->pathForReference($stored['reference']));
    }

    public function testExtensionMimeTraversalSvgAndDefaultAllowlistFailClosed(): void
    {
        $cases = [
            ['shell.pdf', '<?php echo 1;', ['accept' => ['pdf'], 'max_size' => 2], 'form_upload_mime'],
            ['shell.php', '%PDF-1.4', ['accept' => ['pdf'], 'max_size' => 2], 'form_upload_type'],
            ['active.svg', '<svg onload="alert(1)"></svg>', ['accept' => ['svg'], 'max_size' => 2], 'form_upload_type'],
            ['../safe.pdf', '%PDF-1.4', ['accept' => ['pdf'], 'max_size' => 2], 'form_upload_name'],
            ['safe.pdf', '%PDF-1.4', ['accept' => [], 'max_size' => 2], 'form_upload_type'],
        ];
        foreach ($cases as [$name, $contents, $field, $reason]) {
            $total = 0;
            try {
                $this->service()->store($this->upload($name, $contents), $field, $total);
                self::fail('Unsafe upload accepted: ' . $name);
            } catch (RuntimeException $error) {
                self::assertSame($reason, $error->getMessage());
            }
        }
    }

    public function testPerFileAndTotalBudgetsAreEnforcedBeforeMove(): void
    {
        $file = $this->upload('large.pdf', "%PDF-1.4\n" . str_repeat('x', 1048576));
        $total = 0;
        try {
            $this->service()->store($file, ['accept' => ['pdf'], 'max_size' => 1], $total);
            self::fail('Oversized upload accepted');
        } catch (RuntimeException $error) {
            self::assertSame('form_upload_size', $error->getMessage());
            self::assertFileExists($file['tmp_name']);
        }

        $small = $this->upload('small.pdf', "%PDF-1.4\n%%EOF");
        $total = 20971520;
        try {
            $this->service()->store($small, ['accept' => ['pdf'], 'max_size' => 1], $total);
            self::fail('Total budget bypassed');
        } catch (RuntimeException $error) {
            self::assertSame('form_upload_total', $error->getMessage());
        }
    }

    public function testAdminDownloadBoundaryNeverPublishesStoragePaths(): void
    {
        $endpoint = (string) file_get_contents(ROOT_PATH . '/admin/form_file.php');
        self::assertStringContainsString("requirePermission('form')", $endpoint);
        self::assertStringContainsString('Content-Disposition: attachment', $endpoint);
        self::assertStringContainsString('X-Content-Type-Options: nosniff', $endpoint);
        self::assertStringNotContainsString("Content-Type: ' . \$metadata['mime']", $endpoint);

        $submit = (string) file_get_contents(ROOT_PATH . '/form_submit.php');
        self::assertStringContainsString('$cleanupUploads = static function ()', $submit);
        self::assertStringContainsString('$cleanupUploads();', $submit);
        self::assertStringContainsString('$product = productModel()->find($candidateId);', $submit);
        self::assertStringContainsString("'file:' . \$stored['fingerprint']", $submit);
    }

    public function testMovedTargetIsRevalidatedAndRejectedWhenTemporaryFileChanges(): void
    {
        $service = new FormUploadService(
            $this->directory . '/private',
            static fn(string $path): bool => is_file($path),
            static function (string $from, string $to): bool {
                $ok = rename($from, $to);
                if ($ok) file_put_contents($to, '<?php echo "changed";');
                return $ok;
            }
        );
        $total = 0;
        try {
            $service->store($this->upload('safe.png', (string) base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true
            )), ['accept' => ['png'], 'max_size' => 1], $total);
            self::fail('Changed target accepted');
        } catch (RuntimeException $error) {
            self::assertSame('form_upload_mime', $error->getMessage());
        }
        self::assertSame([], glob($this->directory . '/private/*/*/*') ?: []);
    }

    public function testInvalidUtf8FilenameFailsAsAControlledUploadError(): void
    {
        $total = 0;
        try {
            $this->service()->store($this->upload("bad-\xFF.pdf", '%PDF-1.4'), ['accept' => ['pdf'], 'max_size' => 1], $total);
            self::fail('Invalid UTF-8 accepted');
        } catch (RuntimeException $error) {
            self::assertSame('form_upload_name', $error->getMessage());
        }
    }

    public function testSymlinkOrJunctionCannotEscapePrivateRoot(): void
    {
        $root = $this->directory . '/private';
        $outside = $this->directory . '/outside';
        mkdir($root . '/' . date('Y'), 0750, true);
        mkdir($outside, 0750, true);
        $link = $root . '/' . date('Y/m');
        $linked = false;
        if (DIRECTORY_SEPARATOR === '\\') {
            exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($outside), $output, $code);
            $linked = $code === 0;
        } else {
            $linked = symlink($outside, $link);
        }
        if (!$linked) self::markTestSkipped('Cannot create a filesystem link on this host');
        $total = 0;
        try {
            $this->service()->store($this->upload('safe.pdf', '%PDF-1.4'), ['accept' => ['pdf'], 'max_size' => 1], $total);
            self::fail('Linked directory escaped containment');
        } catch (RuntimeException $error) {
            self::assertSame('form_upload_failed', $error->getMessage());
        }
        self::assertSame([], glob($outside . '/*') ?: []);
        if (DIRECTORY_SEPARATOR === '\\') {
            exec('cmd /c rmdir ' . escapeshellarg($link));
        } else {
            unlink($link);
        }
    }
}
