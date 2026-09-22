<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SiteTemplateMarketReviewRouterTest extends TestCase
{
    public function testOnlyDeclaredPublicFilesCanBeServedFromTheDeliveryDirectory(): void
    {
        $root = sys_get_temp_dir() . '/yk-gallery-router-' . bin2hex(random_bytes(6));
        $directories = ['', '/packages', '/backups', '/backups/machinery', '/reports', '/assets', '/assets/site-templates', '/assets/site-templates/fixture', '/assets/site-templates/fixture/1.0.0'];
        $files = [
            '/index.php' => '<?php echo "GALLERY";',
            '/backups/machinery/database.sqlite' => 'PRIVATE-DATABASE',
            '/reports/server.log' => 'PRIVATE-LOG',
            '/packages/fixture-site-v1.0.0.zip' => 'PUBLIC-ZIP',
            '/packages/not-listed.zip' => 'NOT-PUBLISHED',
            '/assets/site-templates/fixture/1.0.0/preview.webp' => 'PUBLIC-COVER',
            '/catalog.json' => json_encode(['templates' => [['slug' => 'fixture', 'version' => '1.0.0', 'package' => 'fixture-site-v1.0.0.zip']]], JSON_THROW_ON_ERROR),
            '/review-router.php' => (string) file_get_contents(ROOT_PATH . '/deploy/site-template-market/review-router.php'),
            '/probe.php' => '<?php $_SERVER["REQUEST_METHOD"]=$argv[1]; $_SERVER["REQUEST_URI"]=$argv[2]; register_shutdown_function(static function(): void { fwrite(STDERR, (string) (http_response_code() ?: 200)); }); require __DIR__ . "/review-router.php";',
        ];
        foreach ($directories as $directory) mkdir($root . $directory, 0700);
        foreach ($files as $path => $bytes) file_put_contents($root . $path, $bytes);
        try {
            foreach (['/backups/machinery/database.sqlite', '/%62ackups/machinery/database.sqlite', '/reports/server.log', '/catalog.json',
                '/review-router.php', '/probe.php', '/packages/not-listed.zip', '/packages/../backups/machinery/database.sqlite', '/assets/../../backups/machinery/database.sqlite'] as $path) {
                [$status, $body] = $this->request($root, $path);
                self::assertSame(404, $status, $path);
                self::assertSame('Not found', $body, $path);
                self::assertStringNotContainsString('PRIVATE-', $body);
            }
            foreach (['/' => 'GALLERY', '/index.php' => 'GALLERY', '/packages/fixture-site-v1.0.0.zip' => 'PUBLIC-ZIP',
                '/assets/site-templates/fixture/1.0.0/preview.webp?v=1' => 'PUBLIC-COVER'] as $path => $expected) {
                self::assertSame([200, $expected], $this->request($root, $path));
                self::assertSame([200, ''], $this->request($root, $path, 'HEAD'));
            }
            self::assertSame([405, ''], $this->request($root, '/', 'POST'));
            self::assertSame([404, ''], $this->request($root, '/backups/machinery/database.sqlite', 'HEAD'));
        } finally {
            foreach (array_keys($files) as $path) unlink($root . $path);
            foreach (array_reverse($directories) as $directory) rmdir($root . $directory);
        }
    }

    private function request(string $root, string $path, string $method = 'GET'): array
    {
        $process = proc_open([PHP_BINARY, $root . '/probe.php', $method, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($process);
        $body = stream_get_contents($pipes[1]);
        $status = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        return [(int) $status, $body];
    }
}
