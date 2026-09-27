<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ThemeContent.php';

/**
 * Themes declare their own text (section eyebrows, secondary links) in
 * content-fields.json. A field with `area: home:<block type>` must surface
 * as a Blox control on that homepage block, be editable inline in the
 * canvas, and resolve block override → site value → declared default.
 */
final class ThemeContentBloxFieldsTest extends TestCase
{
    private const FIELDS = [
        ['key' => 'about_kicker', 'type' => 'text', 'area' => 'home:about', 'label' => ['zh-CN' => '关于小标签', 'en' => 'About eyebrow'], 'default' => '01 / THE IDEA'],
        ['key' => 'process_link', 'type' => 'url', 'area' => 'home:advantage', 'label' => '流程链接'],
        ['key' => 'projects_kicker', 'type' => 'text', 'area' => 'home:channel', 'label' => '项目小标签'],
        ['key' => 'header_cta', 'type' => 'text', 'area' => 'header', 'label' => '页头按钮'],
        ['key' => 'show_badge', 'type' => 'toggle', 'area' => 'home:about', 'label' => '显示徽章'],
    ];

    private static function writeTheme(string $root, string $slug, array $fields): void
    {
        @mkdir($root . '/' . $slug, 0777, true);
        file_put_contents($root . '/' . $slug . '/content-fields.json', json_encode(['version' => 1, 'fields' => $fields]));
    }

    private static function removeTheme(string $root, string $slug): void
    {
        @unlink($root . '/' . $slug . '/content-fields.json');
        @rmdir($root . '/' . $slug);
    }

    public function testSchemaAcceptsKnownAreasAndRejectsOthers(): void
    {
        $root = sys_get_temp_dir() . '/yk-tc-' . bin2hex(random_bytes(4));
        self::writeTheme($root, 'good', self::FIELDS);
        self::assertSame('home:about', ThemeContent::schema('good', $root)['about_kicker']['area']);

        foreach (['home:', 'home:About', 'sidebar', 'home:about;x', 42] as $area) {
            self::writeTheme($root, 'bad', [['key' => 'x', 'type' => 'text', 'area' => $area, 'label' => 'X']]);
            try {
                ThemeContent::schema('bad', $root);
                self::fail('area ' . var_export($area, true) . ' should be rejected');
            } catch (RuntimeException $error) {
                self::assertSame('tc_schema', $error->getMessage());
            }
        }
        self::removeTheme($root, 'good');
        self::removeTheme($root, 'bad');
        @rmdir($root);
    }

    public function testChannelBlocksShareTheChannelArea(): void
    {
        $fields = ['a' => ['area' => 'home:channel'], 'b' => ['area' => 'home:about'], 'c' => ['area' => 'header']];

        self::assertSame(['a'], array_keys(ThemeContent::homeBlockFields($fields, 'channel:32')));
        self::assertSame(['b'], array_keys(ThemeContent::homeBlockFields($fields, 'about')));
        self::assertSame([], ThemeContent::homeBlockFields($fields, 'cta'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeclaredHomeFieldsBecomeBloxControlsAndInlinePaths(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $slug = 'tc-fixture-' . bin2hex(random_bytes(4));
        self::writeTheme(ROOT_PATH . '/themes', $slug, self::FIELDS);
        $GLOBALS['yikai_config_runtime_overrides']['current_theme'] = $slug;
        $GLOBALS['yikai_config_runtime_overrides']['home_blocks_config'] = json_encode([['type' => 'channel:32', 'enabled' => true]]);
        try {
            $controls = [];
            foreach (HomeBloxBlockSchema::themeFieldControls() as $control) {
                $controls[$control['key']] = $control;
            }

            // Only home:* text/url/image fields become block controls; header fields and toggles do not.
            self::assertSame(['tc_about_kicker', 'tc_process_link'], array_keys($controls));
            self::assertSame('text', $controls['tc_about_kicker']['type']);
            self::assertSame('关于小标签', $controls['tc_about_kicker']['label']);
            self::assertSame('01 / THE IDEA', $controls['tc_about_kicker']['placeholder']);
            self::assertSame(['block_type', '=', ['about']], $controls['tc_about_kicker']['required']);
            self::assertSame('url', $controls['tc_process_link']['type']);

            $elementKeys = array_column((new HomeBlockElement())->controls(), 'key');
            self::assertContains('tc_about_kicker', $elementKeys, 'the save pipeline sanitizes only declared controls');

            $group = array_values(array_filter(
                HomeBloxBlockSchema::editorBlueprints()['about']['groups'],
                static fn (array $group): bool => $group['key'] === 'theme_fields'
            ))[0] ?? null;
            self::assertIsArray($group);
            self::assertSame(['tc_about_kicker'], array_column($group['fields'], 'key'));
            self::assertTrue(HomeBloxBlockSchema::isEditableFieldPath('about', 'tc_about_kicker'));
            self::assertFalse(HomeBloxBlockSchema::isEditableFieldPath('cta', 'tc_about_kicker'));
        } finally {
            self::removeTheme(ROOT_PATH . '/themes', $slug);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTemplatesResolveBlockOverrideBeforeTheFallback(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $template = tempnam(sys_get_temp_dir(), 'yk-tc-tpl');
        file_put_contents($template, '<?php echo $ykThemeField("about_kicker", "FALLBACK");');
        try {
            $context = HomeBloxRenderContext::fromHomePageData([['type' => 'about', 'enabled' => true]], ['about' => $template], [], [], null, [], false);

            self::assertSame('FALLBACK', $context->renderLegacyBlock(['data' => ['block_type' => 'about', 'enabled' => true]]));
            self::assertSame('Custom eyebrow', $context->renderLegacyBlock([
                'data' => ['block_type' => 'about', 'enabled' => true, 'tc_about_kicker' => '  Custom eyebrow '],
            ]));
        } finally {
            @unlink($template);
        }
    }

    public function testPartialSaveKeepsFieldsItDidNotSubmit(): void
    {
        $fields = [
            'header_cta_text' => ['type' => 'text'],
            'header_cta_url' => ['type' => 'url'],
            'about_note' => ['type' => 'text'],
            'legacy_link' => ['type' => 'url'],
        ];
        $previous = ['header_cta_text' => 'Old', 'about_note' => 'Keep me', 'legacy_link' => 'javascript:alert(1)'];

        // The Blox header/footer panel submits only its own fields.
        self::assertSame(
            ['header_cta_text' => 'New', 'header_cta_url' => '/quote', 'about_note' => 'Keep me'],
            ThemeContent::merge($fields, $previous, ['header_cta_text' => ' New ', 'header_cta_url' => '/quote'], true)
        );
        // The theme content page is a whole form: anything missing is cleared, as before.
        self::assertSame(
            ['header_cta_text' => 'New', 'header_cta_url' => '', 'about_note' => '', 'legacy_link' => ''],
            ThemeContent::merge($fields, $previous, ['header_cta_text' => 'New'], false)
        );
        // A bad submitted value is still rejected.
        $this->expectExceptionMessage('tc_url');
        ThemeContent::merge($fields, $previous, ['header_cta_url' => 'javascript:alert(1)'], true);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEditorStateOnlyCarriesHeaderAndFooterFields(): void
    {
        db()->execute('CREATE TABLE IF NOT EXISTS ' . DB_PREFIX . 'settings (id INTEGER PRIMARY KEY, `key` TEXT UNIQUE, `value` TEXT, `group` TEXT, `name` TEXT, `tip` TEXT)');
        $slug = 'tc-fixture-' . bin2hex(random_bytes(4));
        $fields = self::FIELDS;
        $fields[] = ['key' => 'footer_slogan', 'type' => 'text', 'area' => 'footer', 'label' => ['zh-CN' => '标语', 'en' => 'Slogan'], 'default' => ['en' => 'BUILT BETTER', 'zh-CN' => '更好']];
        self::writeTheme(ROOT_PATH . '/themes', $slug, $fields);
        try {
            $state = ThemeContent::editorState($slug, 'en', 'zh-CN');

            self::assertSame(['header_cta', 'footer_slogan'], array_column($state['fields'], 'key'));
            self::assertSame(['header', 'footer'], array_column($state['fields'], 'area'));
            self::assertSame('标语', $state['fields'][1]['label'], 'labels follow the admin language');
            self::assertSame('BUILT BETTER', $state['values']['footer_slogan'], 'values follow the content language');
            self::assertNotSame('', $state['fingerprint']);
            self::assertSame('en', $state['language']);
        } finally {
            self::removeTheme(ROOT_PATH . '/themes', $slug);
        }
    }

    public function testBloxEditorWiresTheHeaderFooterPanel(): void
    {
        $api = (string) file_get_contents(ROOT_PATH . '/admin/blox_site_api.php');
        self::assertStringContainsString("['save_copyright', 'save_theme_content']", $api);
        self::assertStringContainsString("ThemeContent::save(\$theme, \$language, \$input, (string) post('fingerprint'), true);", $api);
        self::assertStringContainsString('array_diff(array_keys($input), $allowed)', $api, 'only header/footer keys may be submitted');

        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        self::assertStringContainsString("if (payload.source === \"theme\" && self.openThemeContent(payload.area)) return;", $editor);
        self::assertStringContainsString('|| this.themeContentChanged;', $editor);
        self::assertStringContainsString("partials/theme-content-methods.php", $editor);
        self::assertStringContainsString("require __DIR__ . '/theme-content-panel.php';", (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/workspace.php'));
        self::assertStringContainsString(
            'data-yk-context-source="\' . ($source === \'theme\' ? \'theme\' : \'blox\')',
            (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxCanvasPreview.php')
        );
    }
}
