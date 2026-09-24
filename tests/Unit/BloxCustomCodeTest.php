<?php
/**
 * 建站人员的高级配置：元素 ID / CSS 类 / 属性 / 自定义 CSS，页面 CSS，全局类自定义 CSS。
 * 净化规则与 tests/js/blox-custom-code.test.js 同一组样例（客户端即时检查必须与服务端一致）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAssetCollector;
use BloxCustomCode;
use BloxDocumentPipeline;
use BloxGlobalClasses;
use BlockRenderer;
use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxCustomCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BloxCustomCode::resetForTests();
        BloxAssetCollector::resetForTests();
        BloxGlobalClasses::resetForTests();
    }

    protected function tearDown(): void
    {
        BloxCustomCode::resetForTests();
        BloxAssetCollector::resetForTests();
        parent::tearDown();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_global_classes (
                id INTEGER PRIMARY KEY AUTOINCREMENT, class_id TEXT NOT NULL, name TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT '', settings TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'active',
                trashed_at INTEGER NOT NULL DEFAULT 0, modified INTEGER NOT NULL DEFAULT 0, revision INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE blox_class_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, class_id TEXT NOT NULL, doc_key TEXT NOT NULL,
                ref_count INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testCssCheckBlocksEscapesExternalUrlsAndMarkup(): void
    {
        $cases = [
            ['', ''],
            ['color: red;', ''],
            ['%root% { color: red } %root%:hover { color: blue }', ''],
            ['%root% .md\\:flex { display: flex } /* 注释 */', ''],
            ['%root% { background: url(/uploads/a.png) }', ''],
            ['%root% { background: url(data:image/png;base64,AAAA) }', ''],
            ['%root% { content: "{" }', ''],
            ['%root% { background: url(https://evil.test/x) }', 'external'],
            ['%root% { background: url(//evil.test/x) }', 'external'],
            ['%root% { background: \\75 rl(\\2f\\2f evil.test) }', 'external'],
            ['@import "x.css";', 'forbidden'],
            ['@\\69mport "x.css";', 'forbidden'],
            ['%root% { width: expression(alert(1)) }', 'forbidden'],
            ['%root% { background: url(javascript:alert(1)) }', 'forbidden'],
            ['%root% { background: src("x") }', 'forbidden'],
            ['</style><script>alert(1)</script>', 'markup'],
            ['%root% { color: red', 'braces'],
            ['%root% { color: red }}', 'braces'],
            ['%root% { color: red } /* open', 'comment'],
            [42, 'invalid'],
        ];
        foreach ($cases as [$css, $expected]) {
            self::assertSame($expected, BloxCustomCode::checkCss($css)['error'], (string) $css);
        }
        self::assertSame('too_long', BloxCustomCode::checkCss(str_repeat('a', 10001))['error']);
        self::assertSame('', BloxCustomCode::checkCss(str_repeat('a', 15000), BloxCustomCode::PAGE_CSS_MAX)['error']);

        self::assertSame('.x{color:red}', BloxCustomCode::scope('color:red', '.x'));
        self::assertSame('.x .y{color:red}.x:hover{color:blue}', BloxCustomCode::scope('%root% .y{color:red}%root%:hover{color:blue}', '.x'));
    }

    public function testElementDataIsWhitelisted(): void
    {
        $data = BloxCustomCode::normalizeElementData([
            '_html_id' => ' hero-1 ',
            '_css_classes' => 'card  md:flex yk-c-fake w-1/2 bad"class card <x>',
            '_attributes' => [
                ['name' => 'data-track', 'value' => "cta\nmain"],
                ['name' => 'aria-label', 'value' => 'Open'],
                ['name' => 'onclick', 'value' => 'alert(1)'],
                ['name' => 'data-yk-el', 'value' => 'spoof'],
                ['name' => 'style', 'value' => 'color:red'],
                ['name' => 'tabindex', 'value' => '5'],
                ['name' => 'tabindex', 'value' => '0'],
            ],
            '_custom_css' => "  color: red;\r\n  ",
        ]);
        self::assertSame('hero-1', $data['_html_id']);
        self::assertSame('card md:flex w-1/2', $data['_css_classes'], 'yk- 前缀、非法字符、重复都丢弃');
        self::assertSame([
            ['name' => 'data-track', 'value' => 'cta main'],
            ['name' => 'aria-label', 'value' => 'Open'],
            ['name' => 'tabindex', 'value' => '0'],
        ], $data['_attributes'], '事件、style、内部 data-yk-* 与非法 tabindex 丢弃');
        self::assertSame('color: red;', $data['_custom_css']);

        $empty = BloxCustomCode::normalizeElementData(['_html_id' => '1bad', '_css_classes' => '  ', '_attributes' => 'x', '_custom_css' => ' ']);
        self::assertSame([], $empty);
    }

    public function testRendererWritesIdClassesAttributesAndScopedCss(): void
    {
        $html = BlockRenderer::renderElementNode([
            'id' => 'el-1',
            'type' => 'button',
            'data' => [
                'text' => 'Go', 'url' => '/go',
                '_html_id' => 'cta', '_css_classes' => 'btn-wrap md:flex',
                '_attributes' => [['name' => 'data-track', 'value' => 'hero']],
                '_custom_css' => '%root% a { letter-spacing: .1em }',
            ],
        ], 0, false, [0, 0, 0]);
        preg_match('/yk-css-([a-f0-9]{10})/', $html, $match);
        self::assertNotEmpty($match);
        // 按钮的根是外层 div：ID、属性、类都写在它上面
        self::assertMatchesRegularExpression('/^<div [^>]*\bid="cta"/', $html);
        self::assertMatchesRegularExpression('/^<div [^>]*\bdata-track="hero"/', $html);
        self::assertMatchesRegularExpression('/^<div [^>]*\bclass="[^"]*btn-wrap md:flex yk-css-[a-f0-9]{10}"/', $html);
        $styles = BloxAssetCollector::renderStyles();
        self::assertStringContainsString(
            '<style data-yk-custom-css>.yk-css-' . $match[1] . '.yk-css-' . $match[1] . ' a { letter-spacing: .1em }</style>',
            $styles
        );
        self::assertSame('', BloxAssetCollector::renderStyles(), '同一段 CSS 只输出一次');

        // 同页重复 ID 只保留第一个；标题自己的 html_id 优先
        $second = BlockRenderer::renderElementNode(['id' => 'el-2', 'type' => 'button', 'data' => ['text' => 'B', '_html_id' => 'cta']], 0, false, [0, 0, 1]);
        self::assertStringNotContainsString('id="cta"', $second);
        $heading = BlockRenderer::renderElementNode(['id' => 'el-3', 'type' => 'heading', 'data' => ['text' => 'H', 'html_id' => 'own', '_html_id' => 'other']], 0, false, [0, 0, 2]);
        self::assertStringContainsString('id="own"', $heading);
        self::assertStringNotContainsString('other', $heading);

        // 非法 CSS 渲染时跳过，不输出作用域类
        $bad = BlockRenderer::renderElementNode(['id' => 'el-4', 'type' => 'button', 'data' => ['text' => 'X', '_custom_css' => 'color:red}}']], 0, false, [0, 0, 3]);
        self::assertStringNotContainsString('yk-css-', $bad);
    }

    private function document(array $elementData, array $settings = []): string
    {
        return json_encode([
            'schema' => 1,
            'settings' => $settings,
            'sections' => [['id' => 's1', 'columns' => [['id' => 'c1', 'elements' => [
                ['id' => 'e1', 'type' => 'heading', 'data' => ['text' => 'T'] + $elementData],
            ]]]]],
        ], JSON_THROW_ON_ERROR);
    }

    public function testSavingRequiresValidCssAndTheDesignPermission(): void
    {
        try {
            BloxDocumentPipeline::process($this->document(['_custom_css' => '%root%{background:url(https://evil.test)}']));
            self::fail('外链 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_custom_css_error_external', $e->getMessage());
        }

        $withCss = $this->document(['_custom_css' => 'color:red']);
        self::assertSame('color:red', BloxDocumentPipeline::process($withCss)['sections'][0]['columns'][0]['elements'][0]['data']['_custom_css']);

        // 没有「全站设计」权限：新增被拒；原样保留已有的可以；修改被拒；其他字段照常可改
        BloxCustomCode::resetForTests(false);
        try {
            BloxDocumentPipeline::process($withCss);
            self::fail('无权限新增自定义 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_custom_css_permission', $e->getMessage());
        }
        $kept = BloxDocumentPipeline::process($this->document(['_custom_css' => 'color:red', 'text' => 'Edited']), 'blox', trustedJson: $withCss);
        self::assertSame('color:red', $kept['sections'][0]['columns'][0]['elements'][0]['data']['_custom_css']);
        try {
            BloxDocumentPipeline::process($this->document(['_custom_css' => 'color:blue']), 'blox', trustedJson: $withCss);
            self::fail('无权限修改自定义 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_protected_fields_changed', $e->getMessage());
        }
        // ID / 类 / 属性不需要设计权限
        $plain = BloxDocumentPipeline::process($this->document(['_html_id' => 'intro', '_css_classes' => 'lead']));
        self::assertSame('intro', $plain['sections'][0]['columns'][0]['elements'][0]['data']['_html_id']);
    }

    public function testPageCssIsValidatedGatedAndRenderedOnce(): void
    {
        $json = $this->document([], ['custom_css' => '%root% { background: #fafafa } .promo { color: red }']);
        $processed = BloxDocumentPipeline::process($json);
        self::assertSame('%root% { background: #fafafa } .promo { color: red }', $processed['settings']['custom_css']);

        BlockRenderer::render($processed['json']);
        self::assertStringContainsString('body { background: #fafafa } .promo { color: red }', BloxAssetCollector::renderStyles());

        try {
            BloxDocumentPipeline::process($this->document([], ['custom_css' => '@import "x.css";']));
            self::fail('页面 CSS 同样要过净化');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_custom_css_error_forbidden', $e->getMessage());
        }

        BloxCustomCode::resetForTests(false);
        BloxDocumentPipeline::process($json, 'blox', trustedJson: $json); // 原样保留可以
        try {
            BloxDocumentPipeline::process($this->document([], ['custom_css' => 'body{color:red}']), 'blox', trustedJson: $json);
            self::fail('无权限修改页面 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_custom_css_permission', $e->getMessage());
        }
    }

    public function testGlobalClassCustomCssIsScopedToTheClassAndRejectedWhenUnsafe(): void
    {
        $row = BloxGlobalClasses::mutate('class_add', ['name' => 'card', 'settings' => ['text_color' => '#111111']], true);
        $updated = BloxGlobalClasses::mutate('class_update', ['id' => $row['class_id'], 'settings' => [
            'text_color' => '#111111', 'custom_css' => '%root% > img { border-radius: 8px } %root%::after { content: "" }',
        ]], true);
        BloxGlobalClasses::resetForTests();
        self::assertSame(
            '.yk-c-card:not(yk-none){color:#111111}.yk-c-card:not(yk-none) > img { border-radius: 8px } .yk-c-card:not(yk-none)::after { content: "" }',
            BloxGlobalClasses::classRules('card', BloxGlobalClasses::catalog()[$updated['class_id']]['settings'])
        );
        self::assertSame('.yk-c-x:not(yk-none){box-shadow: none}', BloxGlobalClasses::classRules('x', ['custom_css' => 'box-shadow: none']));

        try {
            BloxGlobalClasses::mutate('class_update', ['id' => $row['class_id'], 'settings' => ['custom_css' => '@import "x";']], true);
            self::fail('类的自定义 CSS 同样要过净化');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_custom_css_error_forbidden', $e->getMessage());
        }
        self::assertArrayNotHasKey('custom_css', BloxGlobalClasses::normalizeSettings(['custom_css' => 'x{}}']));
    }

    private function sectionDocument(array $sectionSettings): string
    {
        return json_encode([
            'schema' => 1,
            'settings' => [],
            'sections' => [['id' => 's1', 'settings' => $sectionSettings, 'columns' => [['id' => 'c1', 'elements' => [
                ['id' => 'e1', 'type' => 'heading', 'data' => ['text' => 'T']],
            ]]]]],
        ], JSON_THROW_ON_ERROR);
    }

    public function testSectionClassesAndCssAreNormalizedAndRenderedOnTheSectionRoot(): void
    {
        $processed = BloxDocumentPipeline::process($this->sectionDocument([
            'anchor_id' => 'features',
            '_css_classes' => 'band-dark  yk-c-spoof md:py-24',
            '_custom_css' => "%root% h2 { letter-spacing: .02em }\r\n",
        ]));
        $settings = $processed['sections'][0]['settings'];
        self::assertSame('band-dark md:py-24', $settings['_css_classes']);
        self::assertSame('%root% h2 { letter-spacing: .02em }', $settings['_custom_css']);

        $html = BlockRenderer::render($processed['json']);
        preg_match('/<section class="([^"]*)"[^>]*\bid="features"/', $html, $section);
        self::assertNotEmpty($section, '区块 ID 仍由锚点输出');
        self::assertMatchesRegularExpression('/\bband-dark md:py-24 yk-css-[a-f0-9]{10}\b/', $section[1]);
        preg_match('/yk-css-([a-f0-9]{10})/', $section[1], $scope);
        self::assertStringContainsString(
            '.yk-css-' . $scope[1] . '.yk-css-' . $scope[1] . ' h2 { letter-spacing: .02em }',
            BloxAssetCollector::renderStyles()
        );

        $empty = BloxDocumentPipeline::process($this->sectionDocument(['_css_classes' => '  ', '_custom_css' => ' ']));
        self::assertArrayNotHasKey('_css_classes', $empty['sections'][0]['settings']);
        self::assertArrayNotHasKey('_custom_css', $empty['sections'][0]['settings']);
    }

    public function testSectionCssFollowsTheSameSafetyAndPermissionRules(): void
    {
        try {
            BloxDocumentPipeline::process($this->sectionDocument(['_custom_css' => '%root% { background: url(//evil.test/x) }']));
            self::fail('区块的外链 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_custom_css_error_external', $e->getMessage());
        }

        $withCss = $this->sectionDocument(['_custom_css' => 'color:red']);
        BloxCustomCode::resetForTests(false);
        try {
            BloxDocumentPipeline::process($withCss);
            self::fail('无权限新增区块 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_custom_css_permission', $e->getMessage());
        }
        // 原样保留可以，其他区块设置照常可改；改 CSS 被拒；类不需要设计权限
        $kept = BloxDocumentPipeline::process($this->sectionDocument(['_custom_css' => 'color:red', 'padding' => 'lg', '_css_classes' => 'band']), 'blox', trustedJson: $withCss);
        self::assertSame('color:red', $kept['sections'][0]['settings']['_custom_css']);
        self::assertSame('band', $kept['sections'][0]['settings']['_css_classes']);
        try {
            BloxDocumentPipeline::process($this->sectionDocument(['_custom_css' => 'color:blue']), 'blox', trustedJson: $withCss);
            self::fail('无权限修改区块 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_protected_fields_changed', $e->getMessage());
        }
    }
    public function testSectionTitleAndSubtitleGetTheSameAdvancedOptionsAsElements(): void
    {
        $processed = BloxDocumentPipeline::process($this->sectionDocument([
            'title' => 'FAQ',
            'subtitle' => 'Answers',
            '_title_html_id' => 'faq-title',
            '_title_css_classes' => 'title-grad  yk-c-spoof',
            '_title_attributes' => [['name' => 'data-track', 'value' => 'faq'], ['name' => 'onclick', 'value' => 'x()']],
            '_title_custom_css' => "letter-spacing: .04em;\r\n",
            '_subtitle_css_classes' => 'lead',
            '_subtitle_custom_css' => '%root% { opacity: .8 }',
            '_subtitle_html_id' => '1bad',
        ]));
        $settings = $processed['sections'][0]['settings'];
        self::assertSame('faq-title', $settings['_title_html_id']);
        self::assertSame('title-grad', $settings['_title_css_classes']);
        self::assertSame([['name' => 'data-track', 'value' => 'faq']], $settings['_title_attributes']);
        self::assertSame('letter-spacing: .04em;', $settings['_title_custom_css']);
        self::assertArrayNotHasKey('_subtitle_html_id', $settings, '非法 ID 丢弃');

        $html = BlockRenderer::render($processed['json']);
        preg_match('/<h2 ([^>]*)>FAQ<\/h2>/', $html, $title);
        self::assertNotEmpty($title);
        self::assertStringContainsString('id="faq-title"', $title[1]);
        self::assertStringContainsString('data-track="faq"', $title[1]);
        self::assertStringNotContainsString('onclick', $title[1]);
        self::assertMatchesRegularExpression('/class="blk-title title-grad yk-css-([a-f0-9]{10})"/', $title[1]);
        preg_match('/yk-css-([a-f0-9]{10})/', $title[1], $titleScope);
        preg_match('/<p ([^>]*)>Answers<\/p>/', $html, $subtitle);
        self::assertMatchesRegularExpression('/class="blk-sub lead yk-css-([a-f0-9]{10})"/', $subtitle[1]);
        preg_match('/yk-css-([a-f0-9]{10})/', $subtitle[1], $subScope);
        self::assertNotSame($titleScope[1], $subScope[1]);
        $styles = BloxAssetCollector::renderStyles();
        self::assertStringContainsString('.yk-css-' . $titleScope[1] . '.yk-css-' . $titleScope[1] . '{letter-spacing: .04em;}', $styles);
        self::assertStringContainsString('.yk-css-' . $subScope[1] . '.yk-css-' . $subScope[1] . ' { opacity: .8 }', $styles);
    }

    public function testSectionTitleCssFollowsTheSameSafetyAndPermissionRules(): void
    {
        try {
            BloxDocumentPipeline::process($this->sectionDocument(['title' => 'T', '_title_custom_css' => '%root% { background: url(//evil.test/x) }']));
            self::fail('标题的外链 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_custom_css_error_external', $e->getMessage());
        }

        $withCss = $this->sectionDocument(['title' => 'T', '_subtitle_custom_css' => 'color:red']);
        BloxCustomCode::resetForTests(false);
        try {
            BloxDocumentPipeline::process($withCss);
            self::fail('无权限新增标题 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_custom_css_permission', $e->getMessage());
        }
        // 原样保留可以，标题文字和类照常可改；改 CSS 被拒
        $kept = BloxDocumentPipeline::process($this->sectionDocument(['title' => 'New', '_subtitle_custom_css' => 'color:red', '_title_css_classes' => 'x']), 'blox', trustedJson: $withCss);
        self::assertSame('color:red', $kept['sections'][0]['settings']['_subtitle_custom_css']);
        self::assertSame('x', $kept['sections'][0]['settings']['_title_css_classes']);
        try {
            BloxDocumentPipeline::process($this->sectionDocument(['title' => 'T', '_subtitle_custom_css' => 'color:blue']), 'blox', trustedJson: $withCss);
            self::fail('无权限修改标题 CSS 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_protected_fields_changed', $e->getMessage());
        }
    }
}
