<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeAboutLocalizationTest extends TestCase
{
    private mixed $previous;
    private bool $created;

    protected function setUp(): void
    {
        $this->previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        $GLOBALS['yikai_config_runtime_overrides'] = [
            'site_lang' => 'zh-CN', 'home_about_title' => 'Original title', 'home_about_content' => 'Original body',
            'home_about_tag_title' => 'Original badge', 'home_about_tag_desc' => 'Original caption',
            'home_about_button' => 'Original button', 'home_about_link' => '/company.html',
        ];
        $this->created = !db()->tableExists('channels');
        if ($this->created) {
            db()->execute('CREATE TABLE channels (id INTEGER PRIMARY KEY, slug TEXT, status INTEGER)');
        }
    }

    protected function tearDown(): void
    {
        if ($this->created) { db()->execute('DROP TABLE channels'); }
        if ($this->previous === null) { unset($GLOBALS['yikai_config_runtime_overrides']); }
        else { $GLOBALS['yikai_config_runtime_overrides'] = $this->previous; }
    }

    private function language(string $lang): void
    {
        $GLOBALS['yikai_config_runtime_overrides']['site_lang'] = $lang;
        foreach (['title', 'content', 'tag_title', 'tag_desc', 'button'] as $key) {
            $GLOBALS['yikai_config_runtime_overrides']['home_about_' . $key . '_' . $lang] = $lang . ' ' . $key;
        }
    }

    public function testNewConversionSurvivesSavePipelineAndRendersBothLanguagesWithoutMutatingSource(): void
    {
        $section = HomeAboutContent::toSection([], 'home_s_1');
        $section = BloxDocumentPipeline::process(json_encode([$section], JSON_THROW_ON_ERROR))['sections'][0];
        $before = json_encode($section, JSON_THROW_ON_ERROR);
        foreach (['en', 'ja'] as $lang) {
            $this->language($lang);
            $localized = HomeAboutLocalization::localize($section);
            self::assertSame($lang . ' title', $localized['columns'][0]['elements'][0]['data']['text']);
            self::assertStringContainsString('>' . $lang . ' content<', $localized['columns'][0]['elements'][2]['data']['html']);
            self::assertSame($lang . ' button', $localized['columns'][0]['elements'][3]['data']['text']);
            self::assertSame('/company.html', $localized['columns'][0]['elements'][3]['data']['url']);
            self::assertStringContainsString($lang . ' tag_desc', $localized['columns'][1]['elements'][0]['data']['children'][1]['data']['html']);
            self::assertSame($section['settings'], $localized['settings']);
            self::assertSame($before, json_encode($section, JSON_THROW_ON_ERROR));
            self::assertSame($localized, HomeAboutLocalization::localize($localized));
        }
    }

    public function testEditorShowsTheEditingLanguageAndSavesEditsAsThatLanguageOnly(): void
    {
        $section = HomeAboutContent::toSection([], 'home_s_1');
        $section = BloxDocumentPipeline::process(json_encode([$section], JSON_THROW_ON_ERROR))['sections'][0];
        $this->language('en');
        $save = static fn(array $view): array => HomeAboutLocalization::fromEditor(BloxDocumentPipeline::process(
            HomeAboutLocalization::markEditorChanges(json_encode([$view], JSON_THROW_ON_ERROR))
        )['sections'])[0];

        $view = HomeAboutLocalization::forEditor([$section])[0];
        self::assertSame('en title', $view['columns'][0]['elements'][0]['data']['text']);
        self::assertSame('en button', $view['columns'][0]['elements'][3]['data']['text']);
        self::assertSame($section, $save($view));

        $view['columns'][0]['elements'][0]['data']['text'] = 'About us';
        $view['columns'][0]['elements'][3]['data']['text'] = 'Read more';
        $saved = $save($view);
        self::assertSame('Original title', $saved['columns'][0]['elements'][0]['data']['text']);
        self::assertSame('Original button', $saved['columns'][0]['elements'][3]['data']['text']);
        self::assertArrayNotHasKey(HomeAboutLocalization::EDIT_KEY, $saved['columns'][0]['elements'][0]['data']);
        $rendered = HomeAboutLocalization::localize($saved);
        self::assertSame('About us', $rendered['columns'][0]['elements'][0]['data']['text']);
        self::assertSame('Read more', $rendered['columns'][0]['elements'][3]['data']['text']);
        self::assertStringContainsString('en content', $rendered['columns'][0]['elements'][2]['data']['html']);
        self::assertSame('About us', HomeAboutLocalization::forEditor([$saved])[0]['columns'][0]['elements'][0]['data']['text']);

        $this->language('ja');
        self::assertSame('ja title', HomeAboutLocalization::localize($saved)['columns'][0]['elements'][0]['data']['text']);
    }

    public function testEditedAndClearedFieldsStayEditableWhileOtherFieldsRemainLocalized(): void
    {
        $section = HomeAboutContent::toSection([], 'home_s_1');
        $section['columns'][0]['elements'][0]['data']['text'] = 'Customer title';
        $section['columns'][0]['elements'][3]['data']['text'] = '';
        $section['columns'][0]['span'] = 7;
        $this->language('en');
        $result = HomeAboutLocalization::localize($section);
        self::assertSame('Customer title', $result['columns'][0]['elements'][0]['data']['text']);
        self::assertSame('', $result['columns'][0]['elements'][3]['data']['text']);
        self::assertStringContainsString('en content', $result['columns'][0]['elements'][2]['data']['html']);
        self::assertSame(7, $result['columns'][0]['span']);
    }

    public function testCustomOverridesAreNotReplacedBySiteTranslations(): void
    {
        $section = HomeAboutContent::toSection(['override_title' => 'Custom', 'override_content' => 'Custom body'], 'custom');
        $this->language('ja');
        $result = HomeAboutLocalization::localize($section);
        self::assertSame('Custom', $result['columns'][0]['elements'][0]['data']['text']);
        self::assertStringContainsString('Custom body', $result['columns'][0]['elements'][2]['data']['html']);
    }

    public function testMalformedProvenanceIsIgnoredAndLocalizedHtmlIsEscaped(): void
    {
        $section = HomeAboutContent::toSection([], 'home_s_1');
        $this->language('en');
        $GLOBALS['yikai_config_runtime_overrides']['home_about_content_en'] = '<script>alert(1)</script>';
        $section['columns'][0]['elements'][0]['data'][HomeAboutLocalization::KEY] = ['lang' => ['en']];
        $result = HomeAboutLocalization::localize($section);
        self::assertSame('Original title', $result['columns'][0]['elements'][0]['data']['text']);
        self::assertStringContainsString('&lt;script&gt;', $result['columns'][0]['elements'][2]['data']['html']);
        self::assertStringNotContainsString('<script>', $result['columns'][0]['elements'][2]['data']['html']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testLegacyDynamicUrlMatchingPreservesRouteAndChangesOnlyLanguage(): void
    {
        function channelUrl(array $channel): string { return $channel['test_url']; }
        $method = new ReflectionMethod(HomeAboutLocalization::class, 'channelUrlInLanguage');
        $method->setAccessible(true);
        $channel = ['type' => 'page', 'test_url' => '/index.php?yk_route=page&slug=about&lang=en'];
        self::assertSame('/index.php?yk_route=page&slug=about', $method->invoke(null, $channel, 'zh-CN'));
        self::assertSame('/index.php?yk_route=page&slug=about&lang=ja', $method->invoke(null, $channel, 'ja'));
        $channel['type'] = 'link';
        self::assertSame($channel['test_url'], $method->invoke(null, $channel, 'ja'));
    }

    /**
     * 旧快照（无写作语言记录）是中文写的，之后站点默认语言改成了日语：
     * 不能按「当前默认语言」判定写作语言，否则日语页永远显示中文原文。
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testOldSnapshotDetectsItsAuthoringLanguageAfterTheDefaultLanguageChanged(): void
    {
        $section = HomeAboutContent::toSection([], 'about_1f590307e82e');
        foreach ($section['columns'] as &$column) {
            foreach ($column['elements'] as &$element) {
                unset($element['data'][HomeAboutLocalization::KEY]);
            }
            unset($element);
        }
        unset($column);
        $section['id'] = 'home_s_1';
        // 快照里是当时中文站点值；之后日语成了默认语言，基础键也变成了日语
        $overrides = &$GLOBALS['yikai_config_runtime_overrides'];
        foreach (['title', 'content', 'button'] as $key) {
            $overrides['home_about_' . $key . '_zh-CN'] = $overrides['home_about_' . $key];
            $overrides['home_about_' . $key . '_ja'] = 'ja ' . $key;
            $overrides['home_about_' . $key . '_en'] = 'en ' . $key;
            $overrides['home_about_' . $key] = 'ja ' . $key;
        }
        $overrides['site_lang'] = 'ja';
        unset($overrides);

        $result = HomeAboutLocalization::localize($section);
        self::assertSame('ja title', $result['columns'][0]['elements'][0]['data']['text']);
        self::assertStringContainsString('>ja content<', $result['columns'][0]['elements'][2]['data']['html']);
        self::assertSame('ja button', $result['columns'][0]['elements'][3]['data']['text']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testOldSnapshotPreservesEditedFieldsAndHandlesTheOriginalBadgeMarkup(): void
    {
        $section = HomeAboutContent::toSection([], 'about_1f590307e82e');
        $strip = static function (array $element) use (&$strip): array {
            unset($element['data'][HomeAboutLocalization::KEY]);
            foreach ($element['data']['children'] ?? [] as $i => $child) {
                $element['data']['children'][$i] = $strip($child);
            }
            return $element;
        };
        foreach ($section['columns'] as &$column) { $column['elements'] = array_map($strip, $column['elements']); }
        unset($column);
        $section['id'] = 'home_s_1';
        $section['columns'][0]['span'] = 7;
        $section['columns'][0]['elements'][0]['data']['text'] = 'Customer title';
        $caption = &$section['columns'][1]['elements'][0]['data']['children'][1]['data']['html'];
        $caption = '<div class="bg-primary text-white rounded-lg p-6">'
            . '<h3 class="text-xl font-bold text-white m-0">Original badge</h3>'
            . '<p class="text-white">Original caption</p></div>';
        unset($caption);
        $before = json_encode($section);
        define('SITE_LANG', 'en');
        $GLOBALS['yikai_config_runtime_overrides']['home_about_content_en'] = 'English body';
        $GLOBALS['yikai_config_runtime_overrides']['home_about_tag_desc_en'] = 'English caption';
        $result = HomeAboutLocalization::localize($section);
        self::assertSame('Customer title', $result['columns'][0]['elements'][0]['data']['text']);
        self::assertSame(7, $result['columns'][0]['span']);
        self::assertStringContainsString('English body', $result['columns'][0]['elements'][2]['data']['html']);
        self::assertStringContainsString('<p class="text-white">English caption</p>', $result['columns'][1]['elements'][0]['data']['children'][1]['data']['html']);
        self::assertSame($before, json_encode($section));
        $section['columns'][0]['id'] = 'customer-column';
        self::assertSame($section, HomeAboutLocalization::localize($section));
    }
}
