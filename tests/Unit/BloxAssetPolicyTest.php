<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxAssetPolicyTest extends TestCase
{
    /** @return array{version:int,core:list<string>,pro:list<string>,runtime:list<string>,private:list<string>} */
    private function policy(): array
    {
        $raw = file_get_contents(ROOT_PATH . '/config/blox-assets.json');
        self::assertIsString($raw);
        $policy = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($policy);

        return $policy;
    }

    public function testEveryBloxJavascriptAssetHasExactlyOneDistributionScope(): void
    {
        $policy = $this->policy();
        $classified = array_values(array_filter(
            array_merge($policy['core'], $policy['pro'], $policy['runtime']),
            static fn(string $path): bool => str_starts_with($path, 'assets/js/blox-')
        ));
        self::assertSame($classified, array_values(array_unique($classified)));

        $files = glob(ROOT_PATH . '/assets/js/blox-*.js');
        self::assertIsArray($files);
        $actual = array_map(
            static fn(string $path): string => 'assets/js/' . basename($path),
            $files
        );
        sort($actual);
        sort($classified);
        self::assertSame($actual, $classified);
    }

    public function testPublishedPageRuntimeAssetsRemainFree(): void
    {
        $policy = $this->policy();
        self::assertSame([
            'assets/css/blox-banner.css',
            'assets/js/blox-banner.js',
            'assets/js/blox-counter.js',
            'assets/js/blox-language-switcher.js',
            'assets/js/blox-nav-drawer.js',
            'assets/css/blox-org-chart.css',
            'assets/js/blox-org-chart.js',
            'assets/d3',
            'assets/d3-flextree',
            'assets/d3-org-chart',
            'assets/js/blox-sticky-header.js',
            'includes/builder/BloxPopupDocument.php',
            'includes/builder/BloxPopupRuntime.php',
            'assets/css/blox-popup.css',
            'assets/js/blox-popup.js',
            'migrations/20260812_banner_group_height_mode.php',
            'migrations/20260812_banner_group_runtime.php',
            'migrations/20260812_banner_item_runtime.php',
        ], $policy['runtime']);
    }

    public function testGatedBloxSurfacesShipWithTheCoreAndKeepRuntimeAuthorization(): void
    {
        $policy = $this->policy();

        self::assertSame(2, $policy['version']);
        self::assertContains('admin/blox_editor.php', $policy['core']);
        self::assertContains('admin/blox_page_api.php', $policy['core']);
        self::assertContains('admin/blox_preview.php', $policy['core']);
        self::assertContains('includes/builder/BloxAreaDocument.php', $policy['core']);
        self::assertContains('includes/builder/BloxHeaderStates.php', $policy['core']);
        self::assertContains('includes/builder/BloxResponsiveValue.php', $policy['core']);
        self::assertContains('includes/builder/BloxAreaConditions.php', $policy['core']);
        self::assertContains('includes/builder/BloxDisplayConditions.php', $policy['core']);
        self::assertContains('includes/builder/BloxDesignDependencies.php', $policy['core']);
        self::assertContains('includes/builder/BloxAreaTemplatePresets.php', $policy['core']);
        self::assertContains('includes/builder/PageBloxDocument.php', $policy['core']);
        self::assertContains('includes/builder/ChannelBloxDocument.php', $policy['core']);
        self::assertContains('includes/models/BloxPageDraftModel.php', $policy['core']);
        self::assertContains('migrations/20260812_blox_page_drafts.php', $policy['core']);
        self::assertContains('migrations/20260818_blox_channel_published_documents.php', $policy['core']);
        self::assertContains('admin/blox_home_api.php', $policy['core']);
        self::assertContains('admin/blox_template_api.php', $policy['core']);
        self::assertContains('admin/blox_templates.php', $policy['core']);
        self::assertContains('assets/js/blox-responsive.js', $policy['core']);
        self::assertContains('templates/blox/areas', $policy['core']);
        self::assertContains('plugins/blox-example', $policy['pro']);
        self::assertNotContains('themes/blox', $policy['pro']);
        self::assertNotContains('themes/blox', $policy['private']);
        self::assertSame([], array_values(array_intersect($policy['core'], $policy['pro'])));
        self::assertSame([], array_values(array_intersect($policy['core'], $policy['runtime'])));
        self::assertSame([], array_values(array_intersect($policy['pro'], $policy['runtime'])));
    }

    public function testBuildConsumesThePolicyInsteadOfRepeatingEditorPaths(): void
    {
        $build = file_get_contents(ROOT_PATH . '/build.sh');
        self::assertIsString($build);
        self::assertStringContainsString('for scope in core runtime', $build);
        self::assertStringContainsString('blox-assets.php" list "$scope"', $build);
        self::assertStringContainsString('blox-assets.php" list pro', $build);
        self::assertStringContainsString('blox-assets.php" verify-free', $build);
        self::assertStringContainsString('WIN_SOURCE=$(wslpath -w "$TMP_DIR")', $build);
        self::assertStringNotContainsString('"assets/js/blox-draft-recovery.js"', $build);
        self::assertStringNotContainsString('"assets/js/blox-dialog-focus.js"', $build);
        self::assertSame(2, substr_count($build, 'for scope in core runtime'));
        self::assertStringContainsString('git -C "$ROOT_DIR" ls-files --cached --others --exclude-standard -z', $build);
        self::assertStringContainsString('git -C "$ROOT_DIR" ls-files --others --exclude-standard -z', $build);
        self::assertStringContainsString('php "bin/blox-assets.php" verify-free "$VERIFY_DELTA_PAYLOAD"', $build);
        self::assertStringContainsString('"plugins/icon-maker"', $build);
    }

    public function testReleasePackagesRotateTheHtmlCacheNamespace(): void
    {
        $build = file_get_contents(ROOT_PATH . '/build.sh');
        $cache = file_get_contents(ROOT_PATH . '/includes/HtmlCache.php');
        self::assertIsString($build);
        self::assertIsString($cache);

        self::assertStringContainsString('> "$PKG_DIR/config/build.php"', $build);
        self::assertStringContainsString('cp "$PKG_DIR/config/build.php" "$PAYLOAD/config/build.php"', $build);
        self::assertStringContainsString('self::releaseNamespace()', $cache);
        self::assertStringContainsString("ROOT_PATH . '/config/build.php'", $cache);
    }

    public function testCiBuildsAndVerifiesTheFreeArtifactBeforePublishingDiagnostics(): void
    {
        $workflow = file_get_contents(ROOT_PATH . '/.github/workflows/ci.yml');
        $runner = file_get_contents(ROOT_PATH . '/tests/e2e/run-local.js');
        self::assertIsString($workflow);
        self::assertIsString($runner);

        self::assertStringContainsString('free-package:', $workflow);
        self::assertStringContainsString('run: bash build.sh', $workflow);
        self::assertStringContainsString('sha256sum -c "$SHA"', $workflow);
        self::assertStringContainsString('php bin/blox-assets.php verify-free "$PACKAGE_ROOT"', $workflow);
        self::assertStringContainsString('name: yikaicms-free-${{ github.sha }}', $workflow);
        self::assertSame(3, substr_count($workflow, 'bash .github/scripts/inject-blox.sh'));

        self::assertStringContainsString('node tests/e2e/run-local.js --grep "@ci"', $workflow);
        self::assertStringContainsString('node tests/e2e/run-local.js --free', $workflow);
        self::assertStringContainsString('BLOX_E2E_SERVER_LOG:', $workflow);
        self::assertStringContainsString('test-results/e2e/php-server.log', $workflow);
        self::assertStringContainsString('persistServerLog()', $runner);
        self::assertStringContainsString('php tests/smoke/blox_upgrade_compat.php --from="$tag"', $workflow);
        foreach (['v1.12.9', 'v1.14.0', 'v1.17.0', 'v1.17.3.2'] as $tag) {
            self::assertStringContainsString($tag, $workflow);
        }
    }
}
