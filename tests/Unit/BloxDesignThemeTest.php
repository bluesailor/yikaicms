<?php
/** E04 global theme: normalization, compilation and draft/publish contract. */

declare(strict_types=1);

namespace {
    if (!function_exists('cacheClear')) {
        function cacheClear(): void {}
    }
    if (!function_exists('do_action')) {
        function do_action(string $hook, mixed ...$args): void {}
    }
}

namespace Yikai\Tests\Unit {
    use Yikai\Tests\TestCase;

    final class BloxDesignThemeTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        }

        protected function schemaSql(): array
        {
            return ['CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', "key" TEXT UNIQUE, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)'];
        }

        public function testEmptyAndLegacyThemesCompileToNothing(): void
        {
            self::assertSame('', \BloxDesignTheme::compile([]));
            self::assertSame('', \BloxDesignTheme::compile(['typography' => ['h2' => ['size' => '', 'weight' => 'bold']]]));
            $GLOBALS['_test_config'] = [];
            self::assertSame(['typography' => [], 'buttons' => [], 'layout' => []], \BloxDesignTheme::published());
        }

        public function testInvalidValuesAreDroppedAndZeroIsKept(): void
        {
            $theme = \BloxDesignTheme::normalize([
                'typography' => [
                    'h2' => ['size' => ['d' => 40, 't' => '32', 'm' => '9'], 'weight' => '700', 'line_height' => '1.2', 'color' => 'heading'],
                    'h1' => ['family' => 'comic-sans', 'color' => 'bad);color:red', 'line_height' => '9'],
                    'script' => ['size' => 20],
                ],
                'buttons' => ['padding_x' => 0, 'padding_y' => '-4', 'radius' => 'huge'],
                'layout' => ['container_gap' => ['d' => '0'], 'content_max_width' => 100],
            ]);
            self::assertSame(['size' => ['d' => 40, 't' => 32], 'weight' => '700', 'line_height' => '1.2', 'color' => 'heading'], $theme['typography']['h2']);
            self::assertArrayNotHasKey('h1', $theme['typography']);
            self::assertArrayNotHasKey('script', $theme['typography']);
            self::assertSame(['padding_x' => 0], $theme['buttons']);
            self::assertSame(['container_gap' => ['d' => 0]], $theme['layout']);
        }

        public function testResponsiveValuesInheritWiderBreakpointsAndDoNotLeakAcrossRoles(): void
        {
            $css = \BloxDesignTheme::compile(['typography' => ['h2' => ['size' => ['d' => 40, 'm' => 28]]]]);
            // 手机基础值 28，平板继承桌面 40，桌面与平板相同不重复输出。
            self::assertStringContainsString(':root{--yk-type-h2-size:28px;}', $css);
            self::assertStringContainsString('@media (min-width:768px){:root{--yk-type-h2-size:40px;}}', $css);
            self::assertStringNotContainsString('min-width:1024px', $css);
            self::assertStringContainsString('h2.yk-type-h2{font-size:var(--yk-type-h2-size);}', $css);
            self::assertStringNotContainsString('yk-type-h1', $css);
            self::assertStringNotContainsString('</style', \BloxDesignTheme::compile(['typography' => ['h2' => ['color' => '</style>']]]));
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY]);
            \BloxDesignTheme::resetCache();
            parent::tearDown();
        }

        public function testElementsOnlyOptIntoPublishedThemeWhenLocalValuesAreDefault(): void
        {
            $heading = new \HeadingElement();
            $button = new \ButtonElement();
            $container = new \ContainerElement();
            $samples = [
                [$heading, ['text' => 'Title', 'level' => 'h2']],
                [$heading, ['text' => 'Big', 'level' => 'h2', 'visual_size' => 'xl']],
                [$button, ['text' => 'Go', 'url' => '/go']],
                [$button, ['text' => 'Go', 'url' => '/go', 'shape' => 'pill']],
                [$container, ['direction' => 'column']],
                [$container, ['direction' => 'column', 'gap' => 'lg']],
            ];

            \BloxDesignTheme::resetCache();
            $GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY] = '';
            $baseline = array_map(static fn(array $sample): string => $sample[0]->render($sample[1]), $samples);
            foreach ($baseline as $html) {
                self::assertStringNotContainsString('yk-type-', $html);
                self::assertStringNotContainsString('-theme', $html);
            }

            $GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY] = json_encode(['revision' => 1, 'state' => [
                'typography' => ['h2' => ['size' => ['d' => 36]]],
                'buttons' => ['radius' => 'full'],
                'layout' => ['container_gap' => ['d' => 20]],
            ]], JSON_THROW_ON_ERROR);
            \BloxDesignTheme::resetCache();
            $themed = array_map(static fn(array $sample): string => $sample[0]->render($sample[1]), $samples);

            self::assertSame(str_replace('font-bold mb-4', 'font-bold mb-4 yk-type-h2', $baseline[0]), $themed[0]);
            self::assertSame($baseline[1], $themed[1], 'explicit visual size keeps local sizing');
            self::assertStringContainsString('yk-btn-theme', $themed[2]);
            self::assertSame($baseline[3], $themed[3], 'pill shape keeps local radius');
            self::assertStringContainsString('yk-gap-theme', $themed[4]);
            self::assertSame($baseline[5], $themed[5], 'explicit gap keeps local spacing');
            // H1 未配置，不受 H2 设置影响。
            self::assertStringNotContainsString('yk-type-', $heading->render(['text' => 'Main', 'level' => 'h1']));
        }

        public function testDraftDoesNotPublishAndStaleRevisionConflicts(): void
        {
            $draft = \BloxDesignTheme::saveDraft(['layout' => ['container_gap' => ['d' => 24]]], 0);
            self::assertSame(1, $draft['revision']);
            self::assertTrue($draft['has_draft']);
            self::assertSame([], $draft['published']['layout']);

            try {
                \BloxDesignTheme::saveDraft(['layout' => ['container_gap' => ['d' => 8]]], 0);
                self::fail('Stale theme revision was accepted');
            } catch (\RuntimeException $error) {
                self::assertSame(__('blox_save_conflict'), $error->getMessage());
            }

            $published = \BloxDesignTheme::publish(['layout' => ['container_gap' => ['d' => 24]]], 1);
            self::assertSame(2, $published['revision']);
            self::assertSame(2, $published['published_revision']);
            self::assertFalse($published['has_draft']);
            self::assertSame(['container_gap' => ['d' => 24]], $published['published']['layout']);
            self::assertFalse(db()->getPdo()->inTransaction());
        }

        public function testLayoutConsumersPreserveExplicitLocalSettings(): void
        {
            $render = static fn(array $settings): string => \BlockRenderer::render(json_encode([
                ['id' => 'layout-test', 'settings' => $settings, 'columns' => [['elements' => []]]],
            ], JSON_THROW_ON_ERROR));
            $GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY] = '';
            \BloxDesignTheme::resetCache();
            $baseline = $render([]);
            self::assertStringContainsString('class="py-8"', $baseline);
            self::assertStringContainsString('class="max-w-6xl mx-auto px-4"', $baseline);

            $GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY] = json_encode(['state' => [
                'layout' => ['content_max_width' => 1200, 'section_spacing' => ['d' => 64, 'm' => 0]],
            ]], JSON_THROW_ON_ERROR);
            \BloxDesignTheme::resetCache();
            self::assertStringContainsString('yk-section-space-theme', $render([]));
            self::assertStringContainsString('yk-width-theme', $render(['max_width' => 'default']));
            foreach (['none', 'md', ['d' => 'lg', 'm' => 'none']] as $padding) {
                self::assertStringNotContainsString('yk-section-space-theme', $render(['padding' => $padding]));
            }
            foreach (['narrow', 'wide', 'full', 'custom'] as $width) {
                self::assertStringNotContainsString('yk-width-theme', $render(['max_width' => $width, 'max_width_px' => 900]));
            }
            self::assertStringContainsString('max-width:900px;', $render(['max_width' => 'custom', 'max_width_px' => 900]));

            $GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY] = '';
            \BloxDesignTheme::resetCache();
            self::assertSame($baseline, $render([]));
        }

        public function testLayoutCssRestoresDesktopDefaultForMobileOnlySpacing(): void
        {
            $css = \BloxDesignTheme::compile(['layout' => ['content_max_width' => 1280, 'section_spacing' => ['m' => 0]]]);
            self::assertStringContainsString('--yk-layout-max-width:1280px', $css);
            self::assertStringContainsString('--yk-layout-section-spacing:0px', $css);
            self::assertStringContainsString('@media (min-width:768px){:root{--yk-layout-section-spacing:32px;}}', $css);
            self::assertStringContainsString('div.yk-width-theme{max-width:var(--yk-layout-max-width);}', $css);
            self::assertStringContainsString('section.yk-section-space-theme{padding-top:var(--yk-layout-section-spacing);padding-bottom:var(--yk-layout-section-spacing);}', $css);
        }

        public function testPartialResponsiveThemeValuesKeepWiderScreensUntouched(): void
        {
            $theme = \BloxDesignTheme::normalize([
                'typography' => ['h2' => ['size' => ['m' => 28]], 'h3' => ['size' => ['t' => 30]]],
                'buttons' => ['size' => ['m' => 18]],
                'layout' => ['container_gap' => ['t' => 16], 'section_spacing' => ['m' => 0]],
            ]);
            self::assertSame(['m' => 28], $theme['typography']['h2']['size']);
            self::assertSame(['t' => 30], $theme['typography']['h3']['size']);
            self::assertSame(['m' => 18], $theme['buttons']['size']);
            self::assertSame(['t' => 16], $theme['layout']['container_gap']);
            self::assertSame(['m' => 0], $theme['layout']['section_spacing']);
            unset($theme['layout']['section_spacing']);
            $partialCss = \BloxDesignTheme::compile($theme);
            self::assertStringNotContainsString(':root{', $partialCss);
            self::assertStringContainsString('@media not all and (min-width:768px){h2.yk-type-h2{--yk-type-h2-size:28px;font-size:var(--yk-type-h2-size);}}', $partialCss);
            self::assertStringContainsString('@media not all and (min-width:1024px){h3.yk-type-h3{--yk-type-h3-size:30px;font-size:var(--yk-type-h3-size);}}', $partialCss);
            self::assertStringContainsString('@media not all and (min-width:768px){a.yk-btn-theme{', $partialCss);
            self::assertStringContainsString('@media not all and (min-width:1024px){div.yk-gap-theme{', $partialCss);

            // 清除 m 回 t、清除 t 回 d；0 是有效值不是清除。
            $gap = \BloxDesignTheme::compile(['layout' => ['container_gap' => ['d' => 40, 'm' => 0]]]);
            self::assertStringContainsString(':root{--yk-layout-gap:0px;}', $gap);
            self::assertStringContainsString('@media (min-width:768px){:root{--yk-layout-gap:40px;}}', $gap);
            self::assertStringNotContainsString('min-width:1024px', $gap);
        }

        public function testButtonVariantPresetsCompileTrustedRules(): void
        {
            $css = \BloxDesignTheme::compile(['buttons' => ['variants' => [
                'filled' => [
                    'color' => 'surface', 'bg' => 'primary', 'border_color' => 'primary',
                    'hover' => ['bg' => 'secondary'],
                    'focus_color' => 'secondary',
                    'disabled' => ['color' => 'muted'],
                ],
                'outline' => ['color' => 'text'],
            ]]]);
            self::assertStringContainsString(
                'a.yk-btn-v-filled{color:var(--yk-color-surface);background-color:var(--yk-color-primary);border-color:var(--yk-color-primary);}',
                $css
            );
            self::assertStringContainsString('a.yk-btn-v-filled.yk-btn-v-hover:hover{background-color:var(--yk-color-secondary);}', $css);
            self::assertStringContainsString('a.yk-btn-v-filled:focus-visible{outline:2px solid var(--yk-color-secondary);outline-offset:2px}', $css);
            self::assertStringContainsString('a.yk-btn-v-filled[aria-disabled="true"]{color:var(--yk-color-muted);opacity:.55;pointer-events:none}', $css);
            self::assertStringContainsString('a.yk-btn-v-outline{color:var(--yk-color-text);}', $css);
            $dropped = \BloxDesignTheme::compile(['buttons' => ['variants' => [
                'text' => ['color' => 'bad);color:red', 'bg' => ''],
            ]]]);
            self::assertStringNotContainsString('yk-btn-v-text', $dropped);
        }

        public function testButtonVariantMappingAndLocalOverride(): void
        {
            $button = new \ButtonElement();
            $base = ['text' => 'Go', 'url' => '/go'];

            \BloxDesignTheme::resetCache();
            $GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY] = '';
            $baseline = $button->render($base);
            $ghostBaseline = $button->render($base + ['variant' => 'ghost']);
            $darkBaseline = $button->render($base + ['variant' => 'dark']);
            self::assertStringContainsString('bg-primary', $baseline);

            $GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY] = json_encode(['state' => [
                'buttons' => ['variants' => [
                    'filled' => ['bg' => 'primary', 'hover' => ['bg' => 'secondary'], 'focus_color' => 'secondary'],
                    'text' => ['color' => 'primary'],
                ]],
            ]], JSON_THROW_ON_ERROR);
            \BloxDesignTheme::resetCache();

            self::assertSame('filled', \BloxDesignTheme::buttonVariantPreset('primary'));
            self::assertSame('text', \BloxDesignTheme::buttonVariantPreset('ghost'));
            foreach (['dark', 'soft', 'link', 'outline'] as $unmapped) {
                self::assertSame('', \BloxDesignTheme::buttonVariantPreset($unmapped));
            }

            $themed = $button->render($base);
            self::assertStringContainsString('yk-btn-v-filled', $themed);
            self::assertStringContainsString('bg-primary', $themed);
            self::assertStringContainsString('hover:bg-secondary', $themed);
            self::assertStringContainsString('focus-visible:outline-none', $themed);

            // 局部显式颜色 → 完整局部路径，不消费预设。
            $local = $button->render($base + ['color' => '#ff0000']);
            self::assertStringNotContainsString('yk-btn-v-filled', $local);
            self::assertStringContainsString('bg-primary', $local);
            self::assertStringContainsString('color:#ff0000', $local);

            $ghost = $button->render($base + ['variant' => 'ghost']);
            self::assertStringContainsString('yk-btn-v-text', $ghost);
            self::assertStringContainsString('hover:bg-gray-100', $ghost);

            // 无映射变体输出与未配置主题时逐字节一致。
            self::assertSame($darkBaseline, $button->render($base + ['variant' => 'dark']));
            self::assertNotSame($ghostBaseline, $ghost);
        }

        public function testPartialButtonPresetsPreserveBaseAndExplicitHoverChoice(): void
        {
            $GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY] = json_encode(['state' => [
                'buttons' => ['variants' => ['filled' => ['hover' => ['bg' => 'secondary']]]],
            ]], JSON_THROW_ON_ERROR);
            \BloxDesignTheme::resetCache();
            $button = new \ButtonElement();
            $base = ['text' => 'Go', 'url' => '/go'];
            $html = $button->render($base);
            self::assertStringContainsString('bg-primary text-white', $html);
            self::assertStringContainsString('yk-btn-v-hover', $html);
            foreach (['none', 'lift', 'bright'] as $effect) {
                $local = $button->render($base + ['hover_effect' => $effect]);
                self::assertStringContainsString('yk-btn-v-filled', $local);
                self::assertStringNotContainsString('yk-btn-v-hover', $local);
                self::assertStringNotContainsString('hover:bg-secondary', $local);
            }
            self::assertStringNotContainsString('border-color:transparent', \BloxDesignTheme::compile(\BloxDesignTheme::published()));
        }

        public function testPreviewThemeRestoresPublishedStateEvenAfterFailure(): void
        {
            $GLOBALS['_test_config'][\BloxDesignTheme::PUBLISHED_KEY] = '';
            \BloxDesignTheme::resetCache();
            $button = new \ButtonElement();
            $baseline = $button->render(['text' => 'Go']);
            try {
                \BloxDesignTheme::withPreviewState(['buttons' => ['radius' => 'full']], static function () use ($button): void {
                    self::assertStringContainsString('yk-btn-theme', $button->render(['text' => 'Go']));
                    self::assertStringContainsString('border-radius:9999px', \BloxDesignTheme::compile(\BloxDesignTheme::published()));
                    \BloxDesignTheme::withPreviewState([], static function (): void {
                        self::assertFalse(\BloxDesignTheme::hasButtons());
                    });
                    self::assertTrue(\BloxDesignTheme::hasButtons());
                    throw new \RuntimeException('Preview failed');
                });
                self::fail('Preview exception was swallowed');
            } catch (\RuntimeException $error) {
                self::assertSame('Preview failed', $error->getMessage());
            }
            self::assertSame($baseline, $button->render(['text' => 'Go']));
            self::assertSame('', \BloxDesignTheme::compile(\BloxDesignTheme::published()));
            self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM settings'));
        }
    
        /** 2.0.5 三源归一：排版角色以 theme-<角色> 排版 token 为准，旧变量名保留为别名；没 token 时输出不变。 */
        public function testTypographyRolesAliasTypographyTokens(): void
        {
            $theme = ['typography' => ['h2' => ['size' => ['d' => 40, 'm' => 28], 'weight' => '700', 'line_height' => '1.2', 'family' => 'system-serif']]];
            $GLOBALS['_test_config'] = [];
            $literal = \BloxDesignTheme::compile($theme);
            self::assertStringContainsString(':root{--yk-type-h2-size:28px;}', $literal, '没有 token：原样输出');
            self::assertStringContainsString('font-weight:700;line-height:1.2;', $literal);

            $GLOBALS['_test_config'] = [\BloxDesignSystem::SETTING_KEY => json_encode(['tokens' => [], 'typography' => [
                ['id' => 'theme-h2', 'name' => 'H2', 'size' => ['d' => '40px', 'm' => '28px'], 'line_height' => '1.2', 'weight' => '700'],
            ]], JSON_THROW_ON_ERROR)];
            $aliased = \BloxDesignTheme::compile($theme);
            self::assertStringContainsString('--yk-type-h2-size:var(--yk-typo-theme-h2-size)', $aliased);
            self::assertStringContainsString('h2.yk-type-h2{font-size:var(--yk-type-h2-size);font-family:', $aliased);
            self::assertStringContainsString('font-weight:var(--yk-typo-theme-h2-weight);line-height:var(--yk-typo-theme-h2-lh);', $aliased);
            self::assertStringNotContainsString('28px', $aliased, '断点值改由 token 变量的媒体查询给');
        }

        /** 重新发布时某角色不再有桌面字号（或被清空）：删掉它的 theme-* token，不让旧值残留在前台和编辑器里。 */
        public function testRepublishingRemovesStaleRoleTokens(): void
        {
            $GLOBALS['_test_config'] = [];
            \BloxDesignTheme::publish(['typography' => ['h2' => ['size' => ['d' => 36]]]], 0);
            $tokens = static fn (): array => array_column(json_decode((string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [\BloxDesignSystem::SETTING_KEY]), true)['typography'], null, 'id');
            self::assertArrayHasKey('theme-h2', $tokens());
            // 测试里 config() 读 _test_config：把刚写入的设计系统同步过去（线上 config 直接读库）
            $GLOBALS['_test_config'] = [\BloxDesignSystem::SETTING_KEY => (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [\BloxDesignSystem::SETTING_KEY])];
            \BloxDesignTheme::publish(['typography' => ['h2' => ['size' => ['t' => 30, 'm' => 26]]]], 1);
            self::assertArrayNotHasKey('theme-h2', $tokens(), '只剩平板 / 手机字号时不成 token，旧 token 删除');
            self::assertArrayHasKey('text-base', $tokens(), '其它排版 token 不动');
            $GLOBALS['_test_config'] = [\BloxDesignSystem::SETTING_KEY => (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [\BloxDesignSystem::SETTING_KEY])];
            self::assertStringNotContainsString('var(--yk-typo-theme-h2', \BloxDesignTheme::compile(['typography' => ['h2' => ['size' => ['t' => 30, 'm' => 26]]]]));
        }

        public function testLayoutBecomesScaleTokensWithResponsiveOverridesKept(): void
        {
            $GLOBALS['_test_config'] = [];
            $layout = ['layout' => ['content_max_width' => 1120, 'section_spacing' => ['d' => 96, 'm' => 56], 'container_gap' => ['t' => 20]]];
            $literal = \BloxDesignTheme::compile($layout);
            \BloxDesignTheme::publish($layout, 0);
            $stored = static fn (): array => json_decode((string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [\BloxDesignSystem::SETTING_KEY]), true);
            self::assertSame('1120px', array_column($stored()['containers'], null, 'id')['theme-content']['value']);
            $spaces = array_column($stored()['spaces'], null, 'id');
            self::assertSame('96px', $spaces['theme-section']['value']);
            self::assertArrayNotHasKey('theme-gap', $spaces, '只设了平板的间距不成 token');
            self::assertArrayHasKey('md', $spaces, '出厂刻度不动');

            $GLOBALS['_test_config'] = [\BloxDesignSystem::SETTING_KEY => json_encode($stored(), JSON_THROW_ON_ERROR)];
            $css = \BloxDesignTheme::compile($layout);
            self::assertStringContainsString('--yk-layout-max-width:var(--yk-container-theme-content)', $css);
            // 手机单独改写仍是原值，桌面改引用 token；只改了平板的间隙照旧输出
            self::assertStringContainsString(':root{--yk-layout-max-width:var(--yk-container-theme-content);--yk-layout-section-spacing:56px', $css);
            self::assertStringContainsString('--yk-layout-section-spacing:var(--yk-space-theme-section)', $css);
            self::assertStringNotContainsString('96px', $css);
            self::assertSame(str_replace(['var(--yk-container-theme-content)', 'var(--yk-space-theme-section)'], ['1120px', '96px'], $css), $literal);

            // 设计系统页把 theme-section 改成 80px：全站排版编辑器显示 80
            $state = $stored();
            $state['spaces'] = array_map(static fn (array $t): array => $t['id'] === 'theme-section' ? ['value' => '80px'] + $t : $t, $state['spaces']);
            db()->execute('UPDATE settings SET value = ? WHERE "key" = ?', [json_encode($state, JSON_THROW_ON_ERROR), \BloxDesignSystem::SETTING_KEY]);
            $GLOBALS['_test_config'] = [\BloxDesignSystem::SETTING_KEY => json_encode($state, JSON_THROW_ON_ERROR)];
            self::assertSame(['d' => 80, 'm' => 56], \BloxDesignTheme::snapshot()['published']['layout']['section_spacing']);

            // 再发布不带布局：theme-* 刻度 token 删除
            \BloxDesignTheme::publish([], 1);
            self::assertArrayNotHasKey('theme-content', array_column($stored()['containers'], null, 'id'));
            self::assertArrayNotHasKey('theme-section', array_column($stored()['spaces'], null, 'id'));
        }

        public function testPublishWritesRoleTokensAndEditorShowsTokenValues(): void
        {
            $GLOBALS['_test_config'] = [];
            \BloxDesignTheme::publish(['typography' => ['h1' => ['size' => ['d' => 52, 't' => 44, 'm' => 34], 'weight' => '700', 'line_height' => 1.15],
                'caption' => ['size' => ['m' => 13]]]], 0);
            $stored = json_decode((string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [\BloxDesignSystem::SETTING_KEY]), true);
            $tokens = array_column($stored['typography'], null, 'id');
            self::assertSame(['d' => '52px', 't' => '44px', 'm' => '34px'], $tokens['theme-h1']['size']);
            self::assertSame(['700', '1.2'], [$tokens['theme-h1']['weight'], $tokens['theme-h1']['line_height']]);
            self::assertArrayNotHasKey('theme-caption', $tokens, '没有桌面字号的角色不成 token');

            // 站长在设计系统页把 theme-h1 改成 48px：全站排版编辑器显示 48；只补缺的同步不覆盖它
            $stored['typography'] = array_map(static fn (array $t): array => $t['id'] === 'theme-h1' ? ['size' => ['d' => '48px', 't' => '44px', 'm' => '34px']] + $t : $t, $stored['typography']);
            $GLOBALS['_test_config'] = [\BloxDesignSystem::SETTING_KEY => json_encode($stored, JSON_THROW_ON_ERROR),
                \BloxDesignTheme::PUBLISHED_KEY => (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [\BloxDesignTheme::PUBLISHED_KEY])];
            db()->execute('UPDATE settings SET value = ? WHERE "key" = ?', [json_encode($stored, JSON_THROW_ON_ERROR), \BloxDesignSystem::SETTING_KEY]);
            \BloxDesignTheme::resetCache();
            self::assertSame(48, \BloxDesignTheme::snapshot()['published']['typography']['h1']['size']['d']);
            \BloxDesignTheme::syncTokens(\BloxDesignTheme::published(), true);
            $again = json_decode((string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [\BloxDesignSystem::SETTING_KEY]), true);
            self::assertSame('48px', array_column($again['typography'], null, 'id')['theme-h1']['size']['d']);
        }
    }
}
