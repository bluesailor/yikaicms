<?php
/** Blox 在线模板目录、短时授权缓存与签名包解析。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxRemoteTemplateProvider;
use BloxTemplateImporter;
use RuntimeException;
use Yikai\Tests\TestCase;
use ZipArchive;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxRemoteTemplateProviderTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT 'user',
                source_ref TEXT NOT NULL DEFAULT '',
                schema_version INTEGER NOT NULL DEFAULT 1,
                draft_data TEXT NOT NULL,
                published_data TEXT,
                requirements TEXT,
                metadata TEXT,
                thumbnail TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 0,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testCatalogLocalizesRemoteItemsAndPreservesLockState(): void
    {
        $catalog = $this->catalogResponse([
            $this->catalogItem([
                'tier' => 'pro',
                'access' => 'licensed',
                'module' => 'blox',
                'paid' => true,
                'entitled' => false,
                'locked_reason' => 'module_missing',
                'package' => '',
                'hash' => '',
                'sig' => '',
                'download_url' => '',
            ]),
        ]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url, int $timeout, int $maxBytes): string => $catalog,
            static fn (string $canonical, string $signature): bool => true,
            'en'
        );

        $items = $provider->items('page');

        $this->assertCount(1, $items);
        $this->assertSame('remote:pricing-3col', $items[0]['key']);
        $this->assertSame('Three-column pricing', $items[0]['name']);
        $this->assertTrue($items[0]['locked']);
        $this->assertSame('module_missing', $items[0]['locked_reason']);
        $this->assertSame('marketing', $items[0]['category']);
    }

    public function testRemotePageResolvePreservesValidatedFrameSettings(): void
    {
        $data = json_decode($this->templateJson(), true, 512, JSON_THROW_ON_ERROR);
        $data['type'] = 'page';
        $data['document'] = [
            'schema' => 1,
            'settings' => ['page_header_hidden' => true, 'page_footer_hidden' => true, 'unknown_key' => true],
            'sections' => $data['document'],
        ];
        $package = $this->package(json_encode($data, JSON_THROW_ON_ERROR));
        $catalog = $this->catalogResponse([$this->catalogItem([
            'type' => 'page', 'hash' => 'sha256:' . hash('sha256', $package),
        ])]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url): string => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
            static fn (): bool => true,
            'en'
        );
        $this->assertSame(
            ['page_header_hidden' => true, 'page_footer_hidden' => true],
            $provider->resolve('pricing-3col')['settings']
        );
    }

    public function testBodyInsertionCannotDownloadAreaOrAdvancedTemplates(): void
    {
        foreach (['header', 'footer', 'popup', 'product-detail', 'article-detail'] as $type) {
            $downloads = 0;
            $catalog = $this->catalogResponse([$this->catalogItem(['type' => $type])]);
            $provider = new BloxRemoteTemplateProvider(
                static function (string $url) use ($catalog, &$downloads): string {
                    if (str_contains($url, '/api/templates/download.php')) {
                        $downloads++;
                    }
                    return $catalog;
                },
                static fn (): bool => true,
                'en'
            );
            try {
                $provider->resolve('pricing-3col');
                self::fail('Non-body template resolved: ' . $type);
            } catch (RuntimeException $error) {
                self::assertSame(__('blox_template_remote_invalid'), $error->getMessage());
            }
            self::assertSame(0, $downloads, $type);
        }
    }

    public function testCatalogRejectsUnsafeCategoryAndFallsBackToType(): void
    {
        $catalog = $this->catalogResponse([$this->catalogItem(['category' => '<script>'])]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (): string => $catalog,
            static fn (): bool => true,
            'en'
        );

        $this->assertSame('section', $provider->items()[0]['category']);
    }

    public function testCatalogNormalizesRemoteRecommendationMetadata(): void
    {
        $catalog = $this->catalogResponse([$this->catalogItem([
            'metadata' => [
                'purpose' => 'cta',
                'page_types' => ['service', 'service', '<script>'],
                'required_plugins' => ['forms', '../escape'],
                'priority' => 150,
            ],
        ])]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (): string => $catalog,
            static fn (): bool => true,
            'en'
        );

        $metadata = $provider->items()[0]['metadata'];
        $this->assertSame('cta', $metadata['purpose']);
        $this->assertSame(['service'], $metadata['page_types']);
        $this->assertSame(['forms'], $metadata['required_plugins']);
        $this->assertSame(100, $metadata['priority']);
    }

    public function testResolveRefreshesEntitlementsVerifiesEachPackageAndDoesNotPersist(): void
    {
        $package = $this->package($this->templateJson());
        $hash = 'sha256:' . hash('sha256', $package);
        $catalog = $this->catalogResponse([$this->catalogItem(['hash' => $hash])]);
        $canonicalSeen = '';
        $requests = 0;
        $cache = [];
        $cacheTtl = 0;
        $provider = new BloxRemoteTemplateProvider(
            static function (string $url, int $timeout, int $maxBytes) use ($catalog, $package, &$requests): string {
                $requests++;
                return str_contains($url, '/api/templates/download.php') ? $package : $catalog;
            },
            static function (string $canonical, string $signature) use (&$canonicalSeen): bool {
                $canonicalSeen = $canonical;
                return $signature === 'valid-signature';
            },
            'zh-CN',
            BloxRemoteTemplateProvider::API_URL,
            static function (string $key) use (&$cache): mixed {
                return $cache[$key] ?? null;
            },
            static function (string $key, mixed $value, int $ttl) use (&$cache, &$cacheTtl): void {
                $cache[$key] = $value;
                $cacheTtl = $ttl;
            }
        );

        $first = $provider->resolve('pricing-3col');
        $second = $provider->resolve('pricing-3col');

        $this->assertSame('pricing-3col|1.0.0|' . $hash, $canonicalSeen);
        $this->assertSame('remote:pricing-3col', $first['key']);
        $this->assertSame('heading', $first['sections'][0]['columns'][0]['elements'][0]['type']);
        $this->assertNotSame($first['sections'][0]['id'], $second['sections'][0]['id']);
        $this->assertSame(4, $requests, 'Every download refreshes entitlements and verifies the package');
        $this->assertSame(604800, $cacheTtl);
        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
    }
    /** r16：installable() 列全类型（含 header/footer）；items() 编辑器窄类型行为不变 */
    public function testInstallableListsHeaderTypesWhileItemsStaysNarrow(): void
    {
        $catalog = $this->catalogResponse([
            $this->catalogItem(),
            $this->catalogItem(['slug' => 'header-mega', 'type' => 'header', 'name' => 'Mega 头部']),
        ]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (): string => $catalog,
            static fn (): bool => true,
            'zh-CN'
        );
        $this->assertSame(['pricing-3col'], array_map(
            static fn (array $i): string => str_replace('remote:', '', (string) $i['key']),
            $provider->items()
        ), '编辑器插入目录仍只列 section/page');
        $this->assertSame(['pricing-3col', 'header-mega'], array_map(
            static fn (array $i): string => str_replace('remote:', '', (string) $i['key']),
            $provider->installable()
        ), '官方模板库列全类型');
    }

    /** r16：fetchPackageJson 走同一 hash+签名安检段并返回包原文（安装用） */
    public function testFetchPackageJsonVerifiesAndReturnsRawJson(): void
    {
        // 包内 source_ref 与业务 type 都必须与目录一致（verifiedPackage 复核项）
        $json = str_replace(
            ['"source_ref":"pricing-3col"', '"type":"section"'],
            ['"source_ref":"header-mega"', '"type":"header"'],
            $this->templateJson()
        );
        $package = $this->package($json);
        $hash = 'sha256:' . hash('sha256', $package);
        $catalog = $this->catalogResponse([$this->catalogItem([
            'slug' => 'header-mega', 'type' => 'header', 'hash' => $hash,
            'package' => 'header-mega-v1.0.0.zip',
            'download_url' => 'https://update.yikaicms.com/api/templates/download.php?protocol_version=2&slug=header-mega&version=1.0.0',
        ])]);
        $canonicalSeen = '';
        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url): string => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
            static function (string $canonical, string $signature) use (&$canonicalSeen): bool {
                $canonicalSeen = $canonical;
                return $signature === 'valid-signature';
            },
            'zh-CN'
        );
        $this->assertSame($json, $provider->fetchPackageJson('header-mega'));
        $this->assertSame('header-mega|1.0.0|' . $hash, $canonicalSeen);

        $bad = new BloxRemoteTemplateProvider(
            static fn (string $url): string => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
            static fn (): bool => false,
            'zh-CN'
        );
        $this->expectException(RuntimeException::class);
        $bad->fetchPackageJson('header-mega');
    }

    /** r17：source_ref 必填 + 目录/包 type 一致——缺失与不一致都必须拒绝（审计负例） */
    public function testVerifiedPackageRejectsMissingRefAndTypeMismatch(): void
    {
        // 负例1：包内 source_ref 缺失（str_replace 移除 meta 行）
        $noRef = str_replace('"meta":{"source_ref":"pricing-3col"},', '', $this->templateJson());
        $this->assertStringNotContainsString('source_ref', $noRef);
        $package = $this->package($noRef);
        $catalog = $this->catalogResponse([$this->catalogItem(['hash' => 'sha256:' . hash('sha256', $package)])]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url): string => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
            static fn (): bool => true,
            'zh-CN'
        );
        try {
            $provider->fetchPackageJson('pricing-3col');
            $this->fail('缺失 source_ref 必须拒绝');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_template_remote_invalid', $e->getMessage());
        }

        // 负例2：目录声明 header、签名包内是 section → 拒绝（业务字段一致性不靠签名）
        $goodJson = str_replace('"source_ref":"pricing-3col"', '"source_ref":"hd"', $this->templateJson());
        $pkg2 = $this->package($goodJson);
        $catalog2 = $this->catalogResponse([$this->catalogItem([
            'slug' => 'hd', 'type' => 'header', 'hash' => 'sha256:' . hash('sha256', $pkg2),
            'download_url' => 'https://update.yikaicms.com/api/templates/download.php?protocol_version=2&slug=hd&version=1.0.0',
        ])]);
        $provider2 = new BloxRemoteTemplateProvider(
            static fn (string $url): string => str_contains($url, '/api/templates/download.php') ? $pkg2 : $catalog2,
            static fn (): bool => true,
            'zh-CN'
        );
        try {
            $provider2->fetchPackageJson('hd');
            $this->fail('目录/包 type 不一致必须拒绝');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_template_remote_invalid', $e->getMessage());
        }
    }

    public function testResolveRejectsBadSignatureBeforeImport(): void
    {
        $package = $this->package($this->templateJson());
        $catalog = $this->catalogResponse([$this->catalogItem([
            'hash' => 'sha256:' . hash('sha256', $package),
        ])]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url, int $timeout, int $maxBytes): string
                => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
            static fn (string $canonical, string $signature): bool => false
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_template_remote_signature_failed');
        try {
            $provider->resolve('pricing-3col');
        } finally {
            $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        }
    }

    public function testResolveRejectsPackagesWithAdditionalFiles(): void
    {
        $package = $this->package($this->templateJson(), ['extra.txt' => 'unexpected']);
        $catalog = $this->catalogResponse([$this->catalogItem([
            'hash' => 'sha256:' . hash('sha256', $package),
        ])]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url, int $timeout, int $maxBytes): string
                => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
            static fn (string $canonical, string $signature): bool => true
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_template_remote_invalid');
        $provider->resolve('pricing-3col');
    }

    public function testEditorTypesRemainLimitedUntilHeaderFooterContractsExist(): void
    {
        $this->assertTrue(\BloxTemplateCatalog::supportsEditorType('section'));
        $this->assertTrue(\BloxTemplateCatalog::supportsEditorType('page'));
        $this->assertFalse(\BloxTemplateCatalog::supportsEditorType('header'));
        $this->assertFalse(\BloxTemplateCatalog::supportsEditorType('footer'));
    }
    public function testResolveDoesNotRequireTemplateTable(): void
    {
        db()->execute('DROP TABLE blox_templates');
        $package = $this->package($this->templateJson());
        $catalog = $this->catalogResponse([$this->catalogItem([
            'hash' => 'sha256:' . hash('sha256', $package),
        ])]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url, int $timeout, int $maxBytes): string
                => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
            static fn (string $canonical, string $signature): bool => true
        );

        $resolved = $provider->resolve('pricing-3col');

        $this->assertSame('remote:pricing-3col', $resolved['key']);
        $this->assertCount(1, $resolved['sections']);
    }
    public function testRegisteredTemplateIsFreeButRequiresExplicitEntitlement(): void
    {
        $catalog = $this->catalogResponse([$this->catalogItem([
            'access' => 'registered', 'tier' => 'pro', 'entitled' => false,
        ])]);
        $calls = 0;
        $provider = new BloxRemoteTemplateProvider(static function () use ($catalog, &$calls): string {
            $calls++;
            return $catalog;
        });
        $item = $provider->installable()[0];
        $this->assertSame('registered', $item['access']);
        $this->assertFalse($item['paid']);
        $this->assertTrue($item['locked']);
        $this->assertSame('account_required', $item['locked_reason']);
        $this->expectExceptionMessage('blox_template_remote_account_required');
        try {
            $provider->fetchPackageJson('pricing-3col');
        } finally {
            $this->assertSame(2, $calls, 'Locked resources must not request a package');
        }
    }

    public function testRegisteredTemplateDownloadsWithoutPaidModule(): void
    {
        $json = $this->templateJson();
        $package = $this->package($json);
        $catalog = $this->catalogResponse([$this->catalogItem([
            'access' => 'registered', 'hash' => 'sha256:' . hash('sha256', $package),
        ])]);
        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url): string => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
            static fn (): bool => true
        );
        $this->assertSame($json, $provider->fetchPackageJson('pricing-3col'));
        $this->assertSame('', $provider->installable()[0]['module']);
    }

    public function testMalformedAccessFailsClosedEvenWhenDownloadUrlExists(): void
    {
        foreach ([['access' => null], ['access' => 'unknown'], ['entitled' => null],
            ['entitled' => 'false'], ['paid' => 0], ['access' => 'registered', 'paid' => true],
            ['access' => 'licensed', 'paid' => true, 'module' => '']] as $override) {
            $catalog = $this->catalogResponse([$this->catalogItem($override)]);
            $calls = 0;
            $provider = new BloxRemoteTemplateProvider(static function () use ($catalog, &$calls): string {
                $calls++;
                return $catalog;
            });
            try {
                $provider->fetchPackageJson('pricing-3col');
                $this->fail('Invalid access fields must be rejected');
            } catch (RuntimeException $e) {
                $this->assertSame('blox_template_remote_protocol', $e->getMessage());
            }
            $this->assertSame(1, $calls);
        }
    }

    public function testProtocolVersionIsRequiredAndAdvertised(): void
    {
        foreach ([null, 1, '2', 3] as $version) {
            $catalog = json_decode($this->catalogResponse([$this->catalogItem()]), true, 512, JSON_THROW_ON_ERROR);
            $catalog['data']['protocol_version'] = $version;
            $urlSeen = '';
            $provider = new BloxRemoteTemplateProvider(static function (string $url) use ($catalog, &$urlSeen): string {
                $urlSeen = $url;
                return json_encode($catalog, JSON_THROW_ON_ERROR);
            });
            try {
                $provider->installable();
                $this->fail('Unsupported protocol must not fall back');
            } catch (RuntimeException $e) {
                $this->assertSame('blox_template_remote_protocol', $e->getMessage());
            }
            $this->assertStringContainsString('protocol_version=2', $urlSeen);
        }
    }

    public function testDownloadRechecksRevocationInsteadOfUsingBrowseCache(): void
    {
        $cache = [];
        $requests = 0;
        $allowed = $this->catalogResponse([$this->catalogItem(['access' => 'registered'])]);
        $denied = $this->catalogResponse([$this->catalogItem([
            'access' => 'registered', 'entitled' => false, 'locked_reason' => 'binding_revoked',
        ])]);
        $provider = new BloxRemoteTemplateProvider(
            static function () use (&$requests, $allowed, $denied): string {
                return ++$requests === 1 ? $allowed : $denied;
            },
            null, 'en', BloxRemoteTemplateProvider::API_URL,
            static function (string $key) use (&$cache): mixed { return $cache[$key] ?? null; },
            static function (string $key, mixed $value) use (&$cache): void { $cache[$key] = $value; }
        );
        $this->assertFalse($provider->installable()[0]['locked']);
        $this->assertFalse($provider->installable()[0]['locked']);
        $this->assertSame(1, $requests);
        $this->expectExceptionMessage('blox_template_remote_reconnect');
        try {
            $provider->fetchPackageJson('pricing-3col');
        } finally {
            $this->assertSame(2, $requests);
        }
    }

    public function testOnlyCanonicalControlledDownloadEndpointIsAllowed(): void
    {
        $good = $this->catalogItem()['download_url'];
        foreach ([
            'https://update.yikaicms.com/packages/templates/pricing-3col-v1.0.0.zip',
            str_replace('https:', 'http:', $good),
            str_replace('update.yikaicms.com', 'example.com', $good),
            str_replace('slug=pricing-3col', 'slug=other', $good),
            str_replace('version=1.0.0', 'version=2.0.0', $good),
            $good . '&token=secret', $good . '#fragment',
        ] as $url) {
            $catalog = $this->catalogResponse([$this->catalogItem([
                'hash' => 'sha256:' . str_repeat('0', 64), 'download_url' => $url,
            ])]);
            $requests = 0;
            $provider = new BloxRemoteTemplateProvider(static function () use ($catalog, &$requests): string {
                $requests++;
                return $catalog;
            });
            try {
                $provider->fetchPackageJson('pricing-3col');
                $this->fail('Unsafe download endpoint must be rejected');
            } catch (RuntimeException $e) {
                $this->assertSame('blox_template_remote_invalid', $e->getMessage());
            }
            $this->assertSame(1, $requests);
        }
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function catalogItem(array $overrides = []): array
    {
        return array_replace([
            'slug' => 'pricing-3col',
            'type' => 'section',
            'category' => 'marketing',
            'tier' => 'free',
            'access' => 'public',
            'name' => '价格表三栏',
            'name_en' => 'Three-column pricing',
            'name_ja' => '3列料金表',
            'description' => '三个价格方案',
            'description_en' => 'Three pricing plans',
            'description_ja' => '3つの料金プラン',
            'version' => '1.0.0',
            'package' => 'pricing-3col-v1.0.0.zip',
            'hash' => str_repeat('0', 64),
            'sig' => 'valid-signature',
            'paid' => false,
            'entitled' => true,
            'download_url' => 'https://update.yikaicms.com/api/templates/download.php?protocol_version=2&slug=pricing-3col&version=1.0.0',
            'locked_reason' => '',
        ], $overrides);
    }

    /** @param list<array<string,mixed>> $items */
    private function catalogResponse(array $items): string
    {
        return json_encode([
            'code' => 0,
            'data' => ['protocol_version' => 2, 'updated_at' => '2026-08-07', 'templates' => $items],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function templateJson(): string
    {
        return json_encode([
            'format' => BloxTemplateImporter::FORMAT,
            'version' => BloxTemplateImporter::VERSION,
            'type' => 'section',
            'name' => '价格表三栏',
            'requires' => ['elements' => ['heading'], 'plugins' => []],
            'meta' => ['source_ref' => 'pricing-3col'],
            'document' => [[
                'type' => 'section',
                'settings' => [],
                'columns' => [[
                    'span' => 12,
                    'elements' => [[
                        'type' => 'heading',
                        'data' => ['text' => '价格方案'],
                    ]],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,string> $extraFiles */
    private function package(string $json, array $extraFiles = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blox-remote-test-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $this->assertTrue($zip->addFromString('template.json', $json));
        foreach ($extraFiles as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $this->assertTrue($zip->close());
        $package = file_get_contents($path);
        @unlink($path);
        $this->assertIsString($package);
        return $package;
    }

    // ---- 拉取失败负缓存（v1.18.5：远程抖动时管理页/CI 不再每次导航挂满 15s 超时）----

    /** @return array{provider:BloxRemoteTemplateProvider,calls:callable():int} */
    private function failingProviderWithCache(): array
    {
        $store = [];
        $calls = 0;
        $provider = new BloxRemoteTemplateProvider(
            static function () use (&$calls): ?string { $calls++; return null; },   // 远程不可达
            static fn (): bool => true,
            'en',
            BloxRemoteTemplateProvider::API_URL,
            static function (string $key) use (&$store): mixed { return $store[$key] ?? null; },
            static function (string $key, mixed $value) use (&$store): void { $store[$key] = $value; }
        );
        return ['provider' => $provider, 'calls' => static function () use (&$calls): int { return $calls; }];
    }

    public function testFetchFailureIsNegativelyCachedWithinWindow(): void
    {
        ['provider' => $provider, 'calls' => $calls] = $this->failingProviderWithCache();

        try { $provider->installable(); } catch (RuntimeException) {}
        $this->assertSame(1, $calls());

        // 负缓存窗口内：直接短路抛错，不再打远程
        $this->expectException(RuntimeException::class);
        try {
            $provider->installable();
        } finally {
            $this->assertSame(1, $calls());
        }
    }

    public function testForceRefreshBypassesFailureCache(): void
    {
        ['provider' => $provider, 'calls' => $calls] = $this->failingProviderWithCache();

        try { $provider->installable(); } catch (RuntimeException) {}
        try { $provider->installable(true); } catch (RuntimeException) {}

        $this->assertSame(2, $calls());   // 「刷新授权状态」按钮必须穿透负缓存
    }
}
