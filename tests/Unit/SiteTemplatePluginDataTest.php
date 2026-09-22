<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/SiteTemplateArchive.php';

final class SiteTemplatePluginDataTest extends TestCase
{
    public function testPublicPluginDataRoundtripPrivacyFailureRollbackAndRestore(): void
    {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/site-template-plugin-probe.php'));
        self::assertSame("Site template plugin roundtrip passed\n", $output);
    }

    public function testPackageUsesHashedIndependentJsonAndNeverSerializesLocalState(): void
    {
        $entry = $this->entry(['z' => 1, 'a' => ['public' => true]]);
        $package = SiteTemplatePluginData::package(['sample' => $entry]);
        self::assertSame(['sample' => 'plugin-data/sample.json'], $package['manifest']);
        $bytes = $package['files']['plugin-data/sample.json'];
        self::assertStringNotContainsString('replaceable', $bytes);
        self::assertStringNotContainsString($entry['state']['sha256'], $bytes);
        $decoded = SiteTemplatePluginData::decode(
            $package['manifest'], [['slug' => 'sample', 'version' => '1.0.0']], $package['files'],
            ['plugin-data/sample.json' => hash('sha256', $bytes)]
        );
        self::assertSame(['a' => ['public' => true], 'z' => 1], $decoded['sample']['payload']);
    }

    public function testPackageRejectsNonJsonDataAndTamperedDigest(): void
    {
        foreach ([1.5, new stdClass()] as $invalid) {
            $entry = $this->entry(['invalid' => $invalid]);
            try {
                SiteTemplatePluginData::package(['sample' => $entry]);
                self::fail('Non-portable value accepted');
            } catch (RuntimeException $error) {
                self::assertSame('st_plugin_adapter', $error->getMessage());
            }
        }
        $entry = $this->entry(['public' => 'ok']);
        $package = SiteTemplatePluginData::package(['sample' => $entry]);
        $path = 'plugin-data/sample.json';
        try {
            SiteTemplatePluginData::decode($package['manifest'], [['slug' => 'sample', 'version' => '1.0.0']],
                [$path => $package['files'][$path] . 'x'], [$path => hash('sha256', $package['files'][$path])]);
            self::fail('Tampered plugin JSON accepted');
        } catch (RuntimeException $error) {
            self::assertSame('st_invalid', $error->getMessage());
        }
    }

    public function testArchiveAdapterErrorsAreReportedAsInvalidPackage(): void
    {
        $entry = $this->entry(['public' => 'ok']);
        $package = SiteTemplatePluginData::package(['sample' => $entry]);
        $path = 'plugin-data/sample.json';
        $decoded = json_decode($package['files'][$path], true, 16, JSON_THROW_ON_ERROR);
        $decoded['schema']['sha256'] = 'not-a-contract-hash';
        $invalidSchema = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ([$invalidSchema, '{'] as $bytes) {
            try {
                SiteTemplatePluginData::decode(
                    $package['manifest'],
                    [['slug' => 'sample', 'version' => '1.0.0']],
                    [$path => $bytes],
                    [$path => hash('sha256', $bytes)]
                );
                self::fail('Malformed plugin package accepted');
            } catch (RuntimeException $error) {
                self::assertSame('st_invalid', $error->getMessage());
            }
        }
    }

    public function testPackageBoundsAggregatePluginData(): void
    {
        $chunk = str_repeat('x', 3 * 1024 * 1024);
        $entries = [
            'one' => $this->entry(['blob' => $chunk]),
            'two' => $this->entry(['blob' => $chunk]),
            'three' => $this->entry(['blob' => $chunk]),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('st_limit');
        SiteTemplatePluginData::package($entries);
    }

    public function testPackageAndDecoderShareTheAggregateNodeBudget(): void
    {
        $entries = [
            'one' => $this->entry(['items' => array_fill(0, 100000, null)]),
            'two' => $this->entry(['items' => array_fill(0, 100000, null)]),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('st_limit');
        SiteTemplatePluginData::package($entries);
    }

    /** @return array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array,state:array{sha256:string,replaceable:bool}} */
    private function entry(array $payload): array
    {
        return [
            'contract' => 1,
            'schema' => ['id' => 'sample/catalog', 'version' => 1, 'sha256' => 'sha256:' . hash('sha256', 'sample-contract')],
            'payload' => $payload,
            'state' => ['sha256' => 'sha256:' . hash('sha256', 'sample-state'), 'replaceable' => true],
        ];
    }
}
