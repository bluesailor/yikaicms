<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/UpgradeRunner.php';

final class UpgradeEntryOrderTest extends TestCase
{
    public function testDependenciesAreWrittenBeforeTheirConsumers(): void
    {
        $sources = [
            'includes/StaticHtml.php' => "<?php require_once ROOT_PATH . '/includes/StaticHtmlUrlPolicy.php';",
            'includes/StaticHtmlUrlPolicy.php' => '<?php final class StaticHtmlUrlPolicy {}',
            'index.php' => "<?php require_once ROOT_PATH . '/includes/Dispatcher.php';",
            'includes/Dispatcher.php' => '<?php final class Dispatcher {}',
        ];
        $entries = array_map(
            static fn (string $rel): array => ['rel' => $rel, 'name' => 'payload/' . $rel],
            array_keys($sources)
        );

        $ordered = UpgradeEntryOrder::sort(
            $entries,
            static fn (array $entry): string => $sources[$entry['rel']]
        );
        $paths = array_column($ordered, 'rel');

        self::assertLessThan(array_search('includes/StaticHtml.php', $paths, true), array_search('includes/StaticHtmlUrlPolicy.php', $paths, true));
        self::assertLessThan(array_search('index.php', $paths, true), array_search('includes/Dispatcher.php', $paths, true));
    }

    public function testUpgradeActivationFilesAndVersionSwitchAreLast(): void
    {
        $entries = [
            ['rel' => 'config/version.php'],
            ['rel' => 'admin/upgrade_online.php'],
            ['rel' => 'assets/css/admin.css'],
            ['rel' => 'config/config.sample.php'],
            ['rel' => 'includes/UpgradeRunner.php'],
            ['rel' => 'includes/UpgradeEntryOrder.php'],
            ['rel' => 'admin/orders.php'],
        ];

        $ordered = UpgradeEntryOrder::sort(
            $entries,
            static fn (array $entry): string => $entry['rel'] === 'config/config.sample.php'
                ? "<?php require_once __DIR__ . '/version.php';"
                : ''
        );

        self::assertSame([
            'assets/css/admin.css',
            'includes/UpgradeEntryOrder.php',
            'config/config.sample.php',
            'admin/orders.php',
            'includes/UpgradeRunner.php',
            'admin/upgrade_online.php',
            'config/version.php',
        ], array_column($ordered, 'rel'));
    }

    public function testZipEnumerationRepairsUnsafeArchiveOrder(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yikai-order-');
        self::assertNotFalse($path);
        @unlink($path);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('payload/index.php', "<?php require_once ROOT_PATH . '/includes/Dispatcher.php';");
        $zip->addFromString('payload/includes/Dispatcher.php', '<?php final class Dispatcher {}');
        $zip->close();

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        $entries = uo_zip_entries($zip, 'payload/');
        $zip->close();
        @unlink($path);

        self::assertSame(['includes/Dispatcher.php', 'index.php'], array_column($entries, 'rel'));
    }

    public function testBuildScriptAlwaysUsesOrderedZipCreator(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');

        self::assertSame(1, substr_count($build, 'php tools/create-upgrade-zip.php'));
        self::assertSame(2, substr_count($build, 'create_upgrade_zip "'));
        self::assertStringNotContainsString('zip -r -q "$ZIP_FILE"', $build);
        self::assertStringNotContainsString('zip -r -q "$DELTA_ZIP"', $build);
    }

    public function testLazyRuntimeIncludesDoNotOverrideActivationTiers(): void
    {
        $source = <<<'PHP'
<?php
require_once __DIR__ . '/Immediate.php';
function renderLater(): void
{
    require_once ROOT_PATH . '/page.php';
}
PHP;

        self::assertSame(
            ['includes/Immediate.php'],
            UpgradeEntryOrder::dependencies('includes/Consumer.php', $source)
        );
    }

    public function testZipCreatorOrdersDeltaPayloadByTargetPath(): void
    {
        $root = sys_get_temp_dir() . '/yikai-delta-order-' . bin2hex(random_bytes(4));
        $payload = $root . '/payload';
        self::assertTrue(mkdir($payload . '/includes', 0755, true));
        file_put_contents($root . '/.delta-manifest.json', '{"from":"1.19.3","to":"1.19.5","deleted":[]}');
        file_put_contents($payload . '/index.php', "<?php require_once ROOT_PATH . '/includes/Dispatcher.php';");
        file_put_contents($payload . '/includes/Dispatcher.php', '<?php final class Dispatcher {}');
        file_put_contents($payload . '/config-version.php', '<?php return "1.19.5";');
        $zipPath = $root . '/result.zip';

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(ROOT_PATH . '/tools/create-upgrade-zip.php')
            . ' ' . escapeshellarg($root)
            . ' ' . escapeshellarg($zipPath);
        exec($command, $output, $status);
        self::assertSame(0, $status, implode("\n", $output));

        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $names[] = $name;
            }
        }
        $zip->close();

        self::assertLessThan(
            array_search('payload/index.php', $names, true),
            array_search('payload/includes/Dispatcher.php', $names, true)
        );
        $this->removeTree($root);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
            $child = $path . '/' . $name;
            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
