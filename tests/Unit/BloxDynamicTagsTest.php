<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxDynamicTagsTest extends TestCase
{
    private array $previous;

    protected function setUp(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $this->previous = $GLOBALS['_test_config'] ?? [];
        $GLOBALS['_test_config'] = ['site_lang' => 'zh-CN', 'site_name' => 'Example & Co',
            'contact_phone' => '+86 (400) 123-4567', 'contact_email' => 'hello@example.com',
            'contact_address' => '<img src=x onerror=alert(1)>', 'site_icp' => 'ICP-123', 'site_police' => 'Police-456'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_test_config'] = $this->previous;
    }

    public function testWhitelistAndLinkContexts(): void
    {
        self::assertSame('Call: +86 (400) 123-4567', DynamicSiteData::interpolate('Call: {phone}'));
        self::assertSame('{db_pass} {echo:phpinfo()} {{phone}}', DynamicSiteData::interpolate('{db_pass} {echo:phpinfo()} {{phone}}'));
        self::assertSame('tel:+864001234567', DynamicSiteData::interpolate('{phone_link}', true));
        self::assertSame('mailto:hello@example.com', DynamicSiteData::interpolate('{email_link}', true));
        self::assertSame('{address}', DynamicSiteData::interpolate('{address}', true));
        self::assertStringContainsString(date('Y') . ' Example & Co', DynamicSiteData::interpolate('{copyright}'));
        $GLOBALS['_test_config']['contact_phone'] = '{site_name}';
        self::assertSame('{site_name}', DynamicSiteData::interpolate('{phone}'));
    }

    public function testRegistrationLanguageAndEmptyValues(): void
    {
        self::assertSame('ICP-123 Police-456', DynamicSiteData::interpolate('{icp} {police}'));
        foreach (['en', 'ja'] as $lang) {
            $GLOBALS['_test_config']['site_lang'] = $lang;
            self::assertSame(' ', DynamicSiteData::interpolate('{icp} {police}'));
        }
        $GLOBALS['_test_config']['contact_phone'] = '';
        self::assertSame('', DynamicSiteData::interpolate('{phone}'));
    }

    public function testAuthorUsesCurrentArticleAndEscapesOutput(): void
    {
        self::assertArrayHasKey('{author}', DynamicSiteData::tagOptions());
        self::assertArrayHasKey('{icp}', DynamicSiteData::tagOptions());
        self::assertArrayHasKey('{police}', DynamicSiteData::tagOptions());
        self::assertSame('', DynamicSiteData::interpolate('{author}'));
        ArticleTemplateDocument::withContent(['author' => 'Alice & <b>Bob</b>'], static function (): string {
            self::assertSame('Alice & <b>Bob</b>', DynamicSiteData::interpolate('{author}'));
            $html = (new HeadingElement())->render(['text' => '{author}']);
            self::assertStringContainsString('Alice &amp; &lt;b&gt;Bob&lt;/b&gt;', $html);
            self::assertStringNotContainsString('<b>Bob</b>', $html);
            self::assertStringContainsString('Alice &amp; &lt;b&gt;Bob&lt;/b&gt;', DynamicSiteData::interpolateHtml('<p>{author}</p>'));
            self::assertSame('', ArticleTemplateDocument::withContent([], static fn(): string => DynamicSiteData::interpolate('{author}')));
            self::assertSame('Alice & <b>Bob</b>', DynamicSiteData::interpolate('{author}'));
            return '';
        });
        self::assertSame('', DynamicSiteData::interpolate('{author}'));
        self::assertSame('', ArticleTemplateDocument::withContent(['author' => ['invalid']], static fn(): string => DynamicSiteData::interpolate('{author}')));
    }

    public function testRichTextOnlySubstitutesTextNodes(): void
    {
        $html = DynamicSiteData::interpolateHtml('<p title="{phone}"><strong>{site_name}</strong> {address}</p><code>{phone}</code>');
        self::assertStringContainsString('title="{phone}"', $html);
        self::assertStringContainsString('<strong>Example &amp; Co</strong>', $html);
        self::assertStringContainsString('&lt;img', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<code>{phone}</code>', $html);
        self::assertSame('<p>Unchanged</p>', DynamicSiteData::interpolateHtml('<p>Unchanged</p>'));
    }

    public function testElementRenderersAndEditorMarker(): void
    {
        self::assertStringContainsString('Call: +86 (400) 123-4567', (new HeadingElement())->render(['text' => 'Call: {phone}']));
        $button = (new ButtonElement())->render(['text' => '{site_name}', 'url' => '{phone_link}']);
        self::assertStringContainsString('href="tel:+864001234567"', $button);
        self::assertStringContainsString('Example &amp; Co', $button);
        self::assertStringContainsString('Example &amp; Co', (new TextElement())->render(['html' => '<p>{site_name}</p>']));
        $el = ['id' => 'test', 'type' => 'heading', 'data' => ['text' => '{phone}']];
        self::assertStringContainsString('data-yk-dynamic-tags="1"', BlockRenderer::renderElementNode($el, 0, true, [0, 0, 0]));
        self::assertStringNotContainsString('data-yk-dynamic-tags', BlockRenderer::renderElementNode($el));
        BloxQueryLoopPolicy::assertSectionsAllowed([['columns' => [['elements' => [$el]]]]], false);
    }

    // ── v1.24：{{provider.field}} 双花括号动态标签 ──────────────────

    public function testDoubleBraceTagsResolveWithFallbackPipeline(): void
    {
        self::assertSame('+86 (400) 123-4567', BloxDynamicTags::resolveText('{{site.phone}}'));
        self::assertSame('Tel: +86 (400) 123-4567 !', BloxDynamicTags::resolveText('Tel: {{ site.phone }} !'));
        // 管道：article 无上下文 → 落到 site.name
        self::assertSame('Example & Co', BloxDynamicTags::resolveText('{{article.title|site.name|后备文案}}'));
        // 全空 → 字面量兜底（非首段）
        $GLOBALS['_test_config']['site_name'] = '';
        self::assertSame('后备文案', BloxDynamicTags::resolveText('{{article.title|site.name|后备文案}}'));
        // 全空且无字面量 → 空串
        self::assertSame('', BloxDynamicTags::resolveText('{{article.title|site.name}}'));
        // 元素端到端：heading 的既有转义兜底
        $GLOBALS['_test_config']['site_name'] = 'Example & Co';
        self::assertStringContainsString('Example &amp; Co', (new HeadingElement())->render(['text' => '{{site.name}}']));
    }

    public function testLegacyDoubleBraceContentStaysUntouched(): void
    {
        // 首段不是合法 source（无点）→ 整个标签原样保留——旧内容里的 {{任意文字}} 不被吃
        self::assertSame('{{phone}}', BloxDynamicTags::resolveText('{{phone}}'));
        self::assertSame('{{not a tag}}', BloxDynamicTags::resolveText('{{not a tag}}'));
        // 禁嵌套：完整合法的内层标签照常解析，未闭合/嵌套的外层残片保持字面
        self::assertSame('a{{aExample & Co', BloxDynamicTags::resolveText('a{{a{{site.name}}'));
        self::assertSame('{{a{{b}}}}', BloxDynamicTags::resolveText('{{a{{b}}}}'));
        // 无标签字符串逐字节不变（零开销路径）
        self::assertSame('plain {phone} text', BloxDynamicTags::resolveText('plain {phone} text'));
    }

    public function testContextProvidersResolveArticleProductPageLoopAndLang(): void
    {
        self::assertSame('zh-CN', BloxDynamicTags::resolveText('{{lang.code}}'));

        ArticleTemplateDocument::withContent(['title' => 'A&B', 'summary' => 'S1', 'author' => 'W'], static function (): string {
            self::assertSame('A&B', BloxDynamicTags::resolveText('{{article.title}}'));
            self::assertSame('S1 / W', BloxDynamicTags::resolveText('{{article.summary}} / {{article.author}}'));
            return '';
        });
        self::assertSame('', BloxDynamicTags::resolveText('{{article.title}}'), '上下文出栈后不残留');

        ProductTemplateDocument::withProduct(['title' => 'P1', 'model' => 'M-1'], static function (): string {
            self::assertSame('M-1', BloxDynamicTags::resolveText('{{product.model}}'));
            return '';
        });

        PageTitleElement::withPage(['name' => '关于我们'], static function (): string {
            self::assertSame('关于我们', BloxDynamicTags::resolveText('{{page.title}}'));
            return '';
        });

        TagEngine::pushContext(['_index' => 2, 'title' => 'Row A', 'secret' => 'nope']);
        try {
            self::assertSame('2', BloxDynamicTags::resolveText('{{loop.index}}'));
            self::assertSame('Row A', BloxDynamicTags::resolveText('{{loop.title}}'));
            self::assertSame('', BloxDynamicTags::resolveText('{{loop.secret}}'), '循环项字段必须白名单');
        } finally {
            TagEngine::popContext();
        }
        self::assertSame('', BloxDynamicTags::resolveText('{{loop.index}}'));
    }

    public function testHtmlDestinationEscapesResolvedValues(): void
    {
        ArticleTemplateDocument::withContent(['title' => '<b>X</b> & "Y"'], static function (): string {
            $html = (new TextElement())->render(['html' => '<p>{{article.title}}</p>']);
            self::assertStringContainsString('&lt;b&gt;X&lt;/b&gt; &amp; &quot;Y&quot;', $html);
            self::assertStringNotContainsString('<b>X</b>', $html);
            // 属性上下文逃逸尝试也被引号转义封死
            self::assertSame(
                '<a title="&lt;b&gt;X&lt;/b&gt; &amp; &quot;Y&quot;">t</a>',
                BloxDynamicTags::resolveHtml('<a title="{{article.title}}">t</a>')
            );
            return '';
        });
    }

    public function testUnknownProvidersFallThroughAndEditorMarksDoubleBraces(): void
    {
        self::assertSame('', BloxDynamicTags::resolveText('{{nope.field}}'));
        self::assertSame('okay', BloxDynamicTags::resolveText('{{nope.field|okay}}'));
        self::assertSame('', BloxDynamicTags::resolveText('{{site.smtp_pass}}'), 'site 字段白名单外一律关闭');

        // 编辑器标记：{{tag}} 与 {tag} 一样禁用画布内联直编
        $el = ['id' => 'test-dd', 'type' => 'heading', 'data' => ['text' => '{{article.title}}']];
        self::assertStringContainsString('data-yk-dynamic-tags="1"', BlockRenderer::renderElementNode($el, 0, true, [0, 0, 0]));

        // 插入面板候选（外审 P2-3：按槽位与运行时能力对齐）：文本槽列全 loop 文本/虚拟字段，
        // 链接槽列 loop.url；与单花括号候选不撞键
        $options = BloxDynamicTags::tagOptions();
        self::assertArrayHasKey('{{article.title}}', $options);
        foreach (['title', 'subtitle', 'summary', 'model', 'price', 'date', 'index'] as $loopField) {
            self::assertArrayHasKey('{{loop.' . $loopField . '}}', $options, "文本槽缺 loop.$loopField");
        }
        self::assertArrayNotHasKey('{{loop.cover}}', $options, '图片路径不进文本槽（图片控件尚无插入入口）');
        self::assertSame(['{{loop.url}}'], array_keys(BloxDynamicTags::tagOptions(true)));
        self::assertSame([], array_intersect_key($options, DynamicSiteData::tagOptions()));
    }

    public function testBoundSiteFieldsFollowThePageLanguage(): void
    {
        $GLOBALS['_test_config']['site_description'] = '专业的企业内容管理系统';
        $GLOBALS['_test_config']['site_description_ja'] = '企業向けコンテンツ管理システム';
        self::assertSame('专业的企业内容管理系统', DynamicSiteData::value('site_description', 'text'));

        $GLOBALS['_test_config']['site_lang'] = 'ja';
        self::assertSame('企業向けコンテンツ管理システム', DynamicSiteData::value('site_description', 'text'));
        self::assertStringContainsString('企業向けコンテンツ管理システム', (new TextElement())->render(['site_field' => 'site_description']));

        // 该语言没有单独填写时回落默认语言
        $GLOBALS['_test_config']['site_lang'] = 'en';
        self::assertSame('专业的企业内容管理系统', DynamicSiteData::value('site_description', 'text'));
        self::assertSame('Fallback', DynamicSiteData::value('site_keywords', 'text', 'Fallback'), 'non-whitelisted fields stay closed');
    }
}
