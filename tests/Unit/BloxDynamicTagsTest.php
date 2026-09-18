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
