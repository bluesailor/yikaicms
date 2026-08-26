<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxAreaDocumentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testAreaSettingsAreScopedByTemplateType(): void
    {
        $json = json_encode([
            'schema' => 1,
            'settings' => ['sticky' => true, 'unknown' => 'drop'],
            'sections' => [],
        ], JSON_THROW_ON_ERROR);

        self::assertSame([
            'sticky' => true,
            'sticky_behavior' => 'always',
            'sticky_devices' => ['desktop', 'tablet', 'mobile'],
            'header_overlay_enabled' => true,
            'header_states' => BloxHeaderStates::defaults(),
        ], BloxAreaDocument::process('header', $json)['settings']);
        self::assertSame([], BloxAreaDocument::process('footer', $json)['settings']);
    }

    public function testHeaderStatesAreNormalizedAndRenderedByTheSharedShell(): void
    {
        $settings = BloxAreaDocument::normalizeSettings('header', [
            'sticky' => true,
            'sticky_behavior' => 'scroll-up',
            'sticky_devices' => ['desktop'],
            'header_overlay_enabled' => false,
            'header_states' => [
                'normal' => ['background' => '#ABC', 'text' => 'javascript:alert(1)', 'shadow' => 'huge'],
                'overlay' => ['background' => 'rgba(1, 2, 3, .5)', 'shadow' => 'md'],
            ],
        ]);

        self::assertSame('#abc', $settings['header_states']['normal']['background']);
        self::assertSame('', $settings['header_states']['normal']['text']);
        self::assertSame('none', $settings['header_states']['normal']['shadow']);
        self::assertSame('rgba(1, 2, 3, .5)', $settings['header_states']['overlay']['background']);
        self::assertSame('md', $settings['header_states']['overlay']['shadow']);
        self::assertSame('scroll-up', $settings['sticky_behavior']);
        self::assertSame(['desktop'], $settings['sticky_devices']);

        BloxAssetCollector::reset();
        $html = BloxAreaDocument::renderShell('header', $settings, '<nav>Menu</nav>', 'overlay');
        self::assertStringContainsString('id="siteHeader"', $html);
        self::assertStringContainsString('yk-blox-header yk-sticky-header yk-header-preview-overlay', $html);
        self::assertStringContainsString('data-yk-overlay-enabled="0"', $html);
        self::assertStringContainsString('data-yk-sticky-behavior="scroll-up"', $html);
        self::assertStringContainsString('data-yk-sticky-desktop="1"', $html);
        self::assertStringContainsString('data-yk-sticky-tablet="0"', $html);
        self::assertStringContainsString('data-yk-sticky-mobile="0"', $html);
        self::assertStringContainsString('--yk-header-normal-bg:#abc', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertContains('/assets/js/blox-sticky-header.js', BloxAssetCollector::scripts());
    }

    public function testInvalidStickyOptionsFallBackToTheLegacyBehavior(): void
    {
        $settings = BloxAreaDocument::normalizeSettings('header', [
            'sticky_behavior' => 'unknown',
            'sticky_devices' => [],
        ]);

        self::assertSame('always', $settings['sticky_behavior']);
        self::assertSame(['desktop', 'tablet', 'mobile'], $settings['sticky_devices']);
    }

    public function testAreaShellAcceptsOnlyInternalBloxEditorTargets(): void
    {
        $header = BloxAreaDocument::renderShell(
            'header',
            [],
            '<nav>Menu</nav>',
            '',
            '/admin/blox_editor.php?template=12&open=header-settings'
        );
        $footer = BloxAreaDocument::renderShell(
            'footer',
            [],
            '<p>Footer</p>',
            '',
            '/admin/blox_editor.php?template=13'
        );
        $external = BloxAreaDocument::renderShell('footer', [], '<p>Footer</p>', '', 'https://example.com/edit');

        self::assertStringContainsString('data-yk-edit="/admin/blox_editor.php?template=12&amp;open=header-settings"', $header);
        self::assertStringContainsString('data-yk-edit-label="' . __('fe_edit_layout') . '"', $header);
        self::assertStringContainsString('data-yk-edit="/admin/blox_editor.php?template=13"', $footer);
        self::assertStringContainsString('data-yk-edit-label="' . __('fe_edit_footer') . '"', $footer);
        self::assertStringNotContainsString('data-yk-edit', $external);
    }

    public function testFrontendReturnTargetsStayOnPublicSameSitePaths(): void
    {
        $valid = '/en/service/process.html?tab=flow&step=2#details';
        self::assertSame($valid, BloxAreaEditorTarget::normalizeReturnTo($valid));
        self::assertSame(
            '/admin/blox_editor.php?id=11&focus_section=sec_1&return_to=' . rawurlencode($valid),
            BloxAreaEditorTarget::withReturnTo('/admin/blox_editor.php?id=11&focus_section=sec_1', $valid)
        );

        foreach ([
            'https://evil.example/page',
            '//evil.example/page',
            'javascript:alert(1)',
            '/admin/users.php',
            '/%61dmin/users.php',
            '/service/../admin/users.php',
            "/service\nprocess.html",
            '\\evil.example\\page',
        ] as $invalid) {
            self::assertSame('', BloxAreaEditorTarget::normalizeReturnTo($invalid), $invalid);
        }
        self::assertSame('', BloxAreaEditorTarget::normalizeReturnTo([]));
        self::assertSame(
            'https://evil.example/admin/blox_editor.php?id=11',
            BloxAreaEditorTarget::withReturnTo('https://evil.example/admin/blox_editor.php?id=11', $valid)
        );
        self::assertSame(
            '/admin/page_edit.php?id=11',
            BloxAreaEditorTarget::withReturnTo('/admin/page_edit.php?id=11', $valid)
        );
    }

    public function testBundledAreaPackagesPassTheSameImporterAsUploadedTemplates(): void
    {
        $expected = [
            'clean-site-header.json' => ['header', ['container', 'logo', 'nav-drawer', 'nav-mega'], 1],
            'full-width-site-header.json' => ['header', ['container', 'logo', 'nav-drawer', 'nav-mega'], 1],
            'centered-site-header.json' => ['header', ['container', 'logo', 'nav', 'nav-drawer'], 1],
            'clean-site-footer.json' => ['footer', ['logo', 'nav', 'site-copyright'], 2],
            'corporate-site-header.json' => ['header', ['container', 'language-switcher', 'logo', 'nav-drawer', 'nav-mega', 'site-contact', 'site-search'], 2],
            'corporate-site-footer.json' => ['footer', ['container', 'logo', 'nav', 'site-contact', 'site-copyright', 'social-links'], 2],
        ];
        foreach ($expected as $file => [$type, $elements, $sectionCount]) {
            $json = file_get_contents(ROOT_PATH . '/templates/blox/areas/' . $file);
            self::assertIsString($json);
            $package = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('conditions', $package);

            $prepared = BloxTemplateImporter::prepare($json);
            self::assertSame($type, $prepared['type']);
            self::assertSame($elements, $prepared['requirements']['elements']);
            self::assertCount($sectionCount, $prepared['sections']);
            self::assertSame('', $prepared['thumbnail']);
            if ($file === 'corporate-site-header.json') {
                self::assertSame(['m'], $prepared['sections'][0]['settings']['hide_on']);
            }
        }
    }

    public function testPresetCatalogContainsOnlyHeaderAndFooterStarters(): void
    {
        $catalog = BloxAreaTemplatePresets::catalog();
        self::assertSame(
            ['clean-site-header', 'full-width-site-header', 'centered-site-header', 'clean-site-footer', 'corporate-site-header', 'corporate-site-footer'],
            array_column($catalog, 'slug')
        );
        self::assertSame(['header', 'header', 'header', 'footer', 'header', 'footer'], array_column($catalog, 'type'));
        self::assertSame(
            [
                'content-left',
                'viewport-left',
                'centered-brand',
                'footer-columns',
                'corporate',
                'footer-columns-dark',
            ],
            array_column($catalog, 'preview')
        );
    }

    public function testHeaderStartersKeepDistinctWidthAndBrandAlignmentContracts(): void
    {
        $readPackage = static function (string $file): array {
            $json = file_get_contents(ROOT_PATH . '/templates/blox/areas/' . $file);
            self::assertIsString($json);
            $package = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
            self::assertIsArray($package);
            return $package;
        };

        $clean = $readPackage('clean-site-header.json');
        $cleanSection = $clean['document']['sections'][0];
        self::assertSame('wide', $cleanSection['settings']['max_width']);
        self::assertArrayNotHasKey('container_gutter', $cleanSection['settings']);
        self::assertSame('row', $cleanSection['columns'][0]['elements'][0]['data']['direction']);

        $fullWidth = $readPackage('full-width-site-header.json');
        $fullSection = $fullWidth['document']['sections'][0];
        self::assertSame('full', $fullSection['settings']['max_width']);
        self::assertSame('none', $fullSection['settings']['container_gutter']);
        self::assertSame('sm', $fullSection['columns'][0]['elements'][0]['data']['padding']);

        $centered = $readPackage('centered-site-header.json');
        $centeredContainer = $centered['document']['sections'][0]['columns'][0]['elements'][0]['data'];
        self::assertSame('column', $centeredContainer['direction']);
        self::assertSame('center', $centeredContainer['align']);
        self::assertSame(['logo', 'nav', 'nav-drawer'], array_column($centeredContainer['children'], 'type'));
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function testSiteBindingsKeepLanguageHomeUrlAndDynamicNodeUrl(): void
    {
        if (!function_exists('configRawLang')) {
            function configRawLang(string $key, mixed $default = ''): mixed
            {
                return match ($key) {
                    'site_logo' => '',
                    'site_name' => 'Example',
                    'nav_home_show' => '1',
                    default => $default,
                };
            }
        }
        if (!function_exists('configLang')) {
            function configLang(string $key, string $fallback = ''): string
            {
                return $key === 'nav_home_text' ? 'Home' : $fallback;
            }
        }
        if (!function_exists('langPrefix')) {
            function langPrefix(?string $lang = null): string
            {
                return '/ja';
            }
        }
        if (!function_exists('getNavChannels')) {
            function getNavChannels(): array
            {
                return [['name' => 'Products', 'url' => '/wrong.html', '_url' => '/ja/products.html', 'children' => []]];
            }
        }

        $logo = (new LogoElement())->render(['display' => 'text']);
        self::assertStringContainsString('href="/ja/"', $logo);

        $tree = NavMegaElement::navTree([]);
        self::assertSame('/ja/', $tree[0]['_url']);
        $nav = (new NavMegaElement())->render([]);
        self::assertStringContainsString('yk-mega relative hidden xl:flex min-w-0 flex-1 justify-end', $nav);
        self::assertStringContainsString('flex-nowrap items-center gap-1 whitespace-nowrap', $nav);
        self::assertStringContainsString('href="/ja/products.html"', $nav);
        self::assertSame('/ja/products/laser.html', NavMegaElement::nodeHref([
            'url' => '/wrong.html',
            '_url' => '/ja/products/laser.html',
        ]));
    }
}
