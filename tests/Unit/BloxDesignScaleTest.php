<?php
/** 圆角与阴影 token（2.0.4，v2.1 token 扩展第一步）：取值校验、引用一层、:root 输出、类与容器消费、读写保留。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxDesignScale;
use BloxDesignSystem;
use BloxGlobalClasses;
use BlockRenderer;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxDesignScaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BloxGlobalClasses::resetForTests();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, \"group\" TEXT DEFAULT 'basic', \"key\" TEXT UNIQUE, value TEXT, type TEXT DEFAULT 'text', name TEXT DEFAULT '', tip TEXT DEFAULT '', options TEXT, sort_order INT DEFAULT 0)",
        ];
    }

    public function testValuesAreWhitelisted(): void
    {
        self::assertSame('8px', BloxDesignScale::normalizeValue('radius', 8));
        self::assertSame('0.5rem', BloxDesignScale::normalizeValue('radius', ' 0.5REM '));
        self::assertSame('9999px', BloxDesignScale::normalizeValue('radius', '9999px'));
        self::assertSame('{md}', BloxDesignScale::normalizeValue('radius', '{md}'));
        foreach (['8%', 'calc(1px)', '1px;}x{', 'var(--x)', '10em'] as $bad) {
            self::assertNull(BloxDesignScale::normalizeValue('radius', $bad), $bad);
        }
        self::assertSame('0 4px 12px rgba(15,23,42,.08)', BloxDesignScale::normalizeValue('shadow', '0  4px 12px rgba(15,23,42,.08)'));
        self::assertSame('inset 0 1px 0 #ffffff,0 2px 4px var(--yk-color-primary)',
            BloxDesignScale::normalizeValue('shadow', 'inset 0 1px 0 #FFFFFF, 0 2px 4px var(--yk-color-primary)'));
        self::assertSame('none', BloxDesignScale::normalizeValue('shadow', 'none'));
        foreach (['0 4px red', '0 4px 12px url(x)', '0 4px 12px #fff;}body{', '1px 1px 1px #000,1px 1px 1px #000,1px 1px 1px #000,1px 1px 1px #000'] as $bad) {
            self::assertNull(BloxDesignScale::normalizeValue('shadow', $bad), $bad);
        }
    }

    public function testSeedsReferencesAndRootDeclarations(): void
    {
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode([
            'tokens' => [],
            'radii' => [
                ['id' => 'md', 'name' => 'Medium', 'value' => '10px'],
                ['id' => 'card', 'name' => 'Card', 'value' => '{md}'],
                ['id' => 'chain', 'name' => 'Chain', 'value' => '{card}'],
                ['id' => 'self', 'name' => 'Self', 'value' => '{self}'],
                ['id' => 'old', 'name' => 'Old', 'value' => '2px', 'status' => 'archived'],
            ],
        ], JSON_THROW_ON_ERROR);
        $state = BloxDesignSystem::snapshot();
        self::assertSame(['md', 'card', 'chain', 'old'], array_column($state['radii'], 'id'), '自引用丢弃');
        self::assertSame(['sm', 'md', 'lg'], array_column($state['shadows'], 'id'), '从未保存过的类用出厂刻度');
        self::assertSame(['md' => 'Medium', 'card' => 'Card', 'chain' => 'Chain'], BloxDesignSystem::scaleOptions('radius'), '归档的不出现在选项里');

        $tag = BloxDesignSystem::styleTag();
        self::assertStringContainsString('--yk-radius-md:10px;--yk-radius-card:var(--yk-radius-md);', $tag);
        self::assertStringNotContainsString('--yk-radius-chain', $tag, '引用只解析一层：引用的引用不输出');
        self::assertStringContainsString('--yk-radius-old:2px;', $tag, '归档的仍输出（墓碑）');
        self::assertStringContainsString('--yk-shadow-md:0 4px 12px rgba(15,23,42,.08);', $tag);
    }

    public function testClassesAndContainersConsumeTokensWithFallbacks(): void
    {
        $settings = BloxGlobalClasses::normalizeSettings(['radius_token' => 'card', 'radius_px' => 6, 'shadow_token' => 'md',
            'states' => ['hover' => ['shadow_token' => 'lg']], 'bad' => 1]);
        self::assertSame('card', $settings['radius_token']);
        self::assertSame(['shadow_token' => 'lg'], $settings['states']['hover']);
        $css = BloxGlobalClasses::classRules('panel', $settings);
        self::assertStringContainsString('border-radius:var(--yk-radius-card,6px)', $css, '像素值作回退');
        self::assertStringContainsString('box-shadow:var(--yk-shadow-md,none)', $css);
        self::assertStringContainsString(':hover{box-shadow:var(--yk-shadow-lg,none)}', $css);
        self::assertArrayNotHasKey('radius_token', BloxGlobalClasses::normalizeSettings(['radius_token' => 'x;}y{']));

        $html = BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1',
            'columns' => [['width' => 'w-full', 'elements' => [['type' => 'container', 'id' => 'c1',
                'data' => ['bg_color' => '#ffffff', 'radius_token' => 'lg', 'shadow_token' => 'md']]]]],
        ]]));
        self::assertStringContainsString('background-color:#ffffff;border-radius:var(--yk-radius-lg,0);box-shadow:var(--yk-shadow-md,none);', $html);
        self::assertStringNotContainsString('--yk-radius', BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1',
            'columns' => [['width' => 'w-full', 'elements' => [['type' => 'container', 'id' => 'c1', 'data' => ['bg_color' => '#ffffff']]]]],
        ]])), '默认输出不变');
    }

    /** 2.0.5 设计系统 2.0：间距与容器宽度两类，同一套取值校验、出厂刻度、引用与输出。 */
    public function testSpaceAndContainerTokens(): void
    {
        self::assertSame('16px', BloxDesignScale::normalizeValue('space', 16));
        self::assertSame('1.5rem', BloxDesignScale::normalizeValue('space', '1.5rem'));
        self::assertSame('0px', BloxDesignScale::normalizeValue('space', '0'));
        foreach (['401px', '30rem', '10%', '-4px', 'auto', '{md'] as $bad) self::assertNull(BloxDesignScale::normalizeValue('space', $bad), $bad);
        self::assertSame('1200px', BloxDesignScale::normalizeValue('container', 1200));
        self::assertSame('100%', BloxDesignScale::normalizeValue('container', '100%'));
        self::assertSame('80rem', BloxDesignScale::normalizeValue('container', '80rem'));
        foreach (['100px', '5000px', '90%', '100vw'] as $bad) self::assertNull(BloxDesignScale::normalizeValue('container', $bad), $bad);

        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode(['tokens' => [],
            'spaces' => [['id' => 'md', 'name' => 'M', 'value' => '18px'], ['id' => 'gutter', 'name' => 'Gutter', 'value' => '{md}']]], JSON_THROW_ON_ERROR);
        $state = BloxDesignSystem::snapshot();
        self::assertSame(['md', 'gutter'], array_column($state['spaces'], 'id'));
        self::assertSame(['narrow', 'content', 'wide', 'full'], array_column($state['containers'], 'id'), '从未保存过给出厂刻度');
        $tag = BloxDesignSystem::styleTag();
        self::assertStringContainsString('--yk-space-md:18px;--yk-space-gutter:var(--yk-space-md);', $tag);
        self::assertStringContainsString('--yk-container-content:1200px;', $tag);
        self::assertStringContainsString('--yk-container-full:100%;', $tag);
        self::assertSame('var(--yk-space-gutter,16px)', BloxDesignScale::cssVar('space', 'gutter', '16px'));

        BloxDesignSystem::mutate('container_update', ['id' => 'content', 'value' => '1280px'], false);
        $stored = json_decode((string) db()->fetchColumn("SELECT value FROM settings WHERE \"key\" = ?", [BloxDesignSystem::SETTING_KEY]), true);
        self::assertSame('1280px', array_column($stored['containers'], 'value', 'id')['content']);
        self::assertSame('18px', array_column($stored['spaces'], 'value', 'id')['md'], '改容器宽度不动间距');
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode($stored, JSON_THROW_ON_ERROR);
        BloxDesignSystem::mutate('space_add', ['name' => 'Section', 'value' => '96px'], false);
        $stored = json_decode((string) db()->fetchColumn("SELECT value FROM settings WHERE \"key\" = ?", [BloxDesignSystem::SETTING_KEY]), true);
        $last = end($stored['spaces']);
        self::assertStringStartsWith('sp_', (string) $last['id']);
        self::assertSame('96px', $last['value']);
    }

    /** 元素外边距 / 内边距选全站间距刻度：token:<id> → var(--yk-space-<id>, 出厂值)；四档响应式照常。 */
    public function testBoxSpacingUsesSpaceTokens(): void
    {
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode(['tokens' => []], JSON_THROW_ON_ERROR);
        self::assertSame(['2xs', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'], array_column(BloxDesignSystem::snapshot()['spaces'], 'id'), '数字开头的出厂 id 保留');
        self::assertStringContainsString('--yk-space-2xs:4px;', BloxDesignSystem::styleTag());
        $render = static fn (array $data): string => BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1',
            'columns' => [['width' => 'w-full', 'elements' => [['type' => 'container', 'id' => 'c1', 'data' => $data]]]],
        ]]));
        self::assertStringContainsString('padding:var(--yk-space-lg,24px)!important;', $render(['style_padding' => 'token:lg']));
        self::assertStringContainsString('margin-top:var(--yk-space-sp_custom,0)!important;', $render(['style_margin_top' => 'token:sp_custom']), '自建刻度回退 0');
        $responsive = $render(['style_padding' => ['d' => 'token:xl', 'm' => 'token:sm']]);
        self::assertStringContainsString('--yk-sp-p-d:var(--yk-space-xl,32px);', $responsive);
        self::assertStringContainsString('--yk-sp-p-m:var(--yk-space-sm,12px);', $responsive);
        self::assertStringNotContainsString('padding', $render(['style_padding' => 'token:x;}y{']), '非法 id 不输出');
    }

    /** 区块容器宽度选全站容器刻度：token:<id> → max-width:var(--yk-container-<id>, 出厂值)。 */
    public function testSectionWidthUsesContainerTokens(): void
    {
        $section = static fn (string $width): string => BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1', 'settings' => ['max_width' => $width],
            'columns' => [['width' => 'w-full', 'elements' => [['type' => 'container', 'id' => 'c1', 'data' => ['bg_color' => '#ffffff']]]]],
        ]]));
        $html = $section('token:narrow');
        self::assertStringContainsString('max-width:var(--yk-container-narrow,768px);', $html);
        self::assertStringNotContainsString('max-w-6xl mx-auto', $html, '不再叠默认版心类');
        self::assertStringContainsString('max-width:var(--yk-container-ct_mine,72rem);', $section('token:ct_mine'), '自建刻度回退默认版心');
        self::assertStringContainsString('max-w-6xl mx-auto', $section('token:bad;}x{'), '非法值回落默认宽度');
        self::assertStringContainsString('max-w-4xl mx-auto', $section('narrow'), '原四档不变');
    }

    public function testMutationsKeepColorTokensAndValidateReferences(): void
    {
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode([
            'tokens' => [['id' => 'c_ink', 'name' => 'Ink', 'value' => '#111111']], 'styles' => [],
        ], JSON_THROW_ON_ERROR);
        BloxDesignSystem::mutate('radius_update', ['id' => 'md', 'value' => '14px'], false);
        $stored = json_decode((string) db()->fetchColumn("SELECT value FROM settings WHERE \"key\" = ?", [BloxDesignSystem::SETTING_KEY]), true);
        self::assertSame('c_ink', $stored['tokens'][0]['id'], '改圆角不丢颜色 token');
        self::assertSame('14px', array_column($stored['radii'], 'value', 'id')['md']);
        self::assertCount(3, $stored['shadows'], '出厂阴影随首次保存落盘');

        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode($stored, JSON_THROW_ON_ERROR);
        foreach ([['radius_add', ['name' => 'Bad', 'value' => '{missing}']], ['radius_update', ['id' => 'sm', 'value' => '{sm}']]] as [$action, $input]) {
            try {
                BloxDesignSystem::mutate($action, $input, false);
                self::fail($action . ' 应被拒绝');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('blox_design_invalid', $e->getMessage());
            }
        }
    }
}
