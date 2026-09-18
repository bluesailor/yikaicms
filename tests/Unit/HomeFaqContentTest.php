<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeFaqContent;
use HomeBloxRenderer;
use BloxDocumentPipeline;
use AccordionElement;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeFaqContentTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['_test_config'] = [];
    }

    private function seed(): void
    {
        $GLOBALS['_test_config'] = ['site_lang' => 'zh-CN', 'home_custom_2' => json_encode([
            'blocks' => [[
                'id' => 'old', 'settings' => ['title' => 'FAQ', 'subtitle' => 'Support', 'padding' => 'lg', 'max_width' => 'default'],
                'columns' => [['id' => 'old-col', 'elements' => [[
                    'id' => 'old-faq', 'type' => 'accordion', 'data' => [
                        'items' => "First?|Answer one\nSecond?|Answer two", 'open_first' => true, 'seo_schema' => true,
                    ],
                ]]]],
            ]],
        ], JSON_THROW_ON_ERROR)];
    }

    public function testPromotionKeepsLayoutOverridesAndOrdinaryFaqControls(): void
    {
        $this->seed();
        $section = HomeFaqContent::toSection([
            'block_type' => 'custom:2',
            'custom_subtitle' => 'Edited subtitle',
            'custom_overrides' => ['zh_CN' => [['columns' => [['elements' => [['data' => [
                'accordion_mode' => 'custom', 'accordion_items' => [['question' => 'Edited?', 'answer' => 'Edited answer']],
            ]]]]]]]],
        ], 'home_faq');
        self::assertNotNull($section);
        self::assertSame('FAQ', $section['name']);
        self::assertSame('lg', $section['settings']['padding']);
        self::assertSame('Edited subtitle', $section['settings']['subtitle']);
        $element = $section['columns'][0]['elements'][0];
        self::assertSame('accordion', $element['type']);
        self::assertSame('home_faq_faq', $element['id']);
        self::assertSame([['question' => 'Edited?', 'answer' => 'Edited answer']], $element['data']['items']);
        self::assertTrue($element['data']['open_first']);
        self::assertTrue($element['data']['seo_schema']);
        self::assertContains('faq_style', array_column((new AccordionElement())->controls(), 'key'));
        $processed = BloxDocumentPipeline::process(json_encode([$section], JSON_THROW_ON_ERROR));
        self::assertSame($element['data'][HomeFaqContent::KEY], $processed['sections'][0]['columns'][0]['elements'][0]['data'][HomeFaqContent::KEY]);
    }

    public function testTranslationsSurviveStyleEditsAndEditedFieldsDetach(): void
    {
        $this->seed();
        $section = HomeFaqContent::toSection(['block_type' => 'custom:2'], 'native');
        $element = &$section['columns'][0]['elements'][0];
        $element['data'][HomeFaqContent::KEY]['translations']['ja'] = [
            'title' => 'Japanese title', 'subtitle' => 'Japanese subtitle',
            'items' => [['question' => 'Japanese question', 'answer' => 'Japanese answer'], ['question' => 'Second translated', 'answer' => 'Second answer translated']],
        ];
        $element['data']['faq_style'] = 'soft';
        $localized = HomeFaqContent::localize($section, 'ja');
        self::assertSame('Japanese title', $localized['settings']['title']);
        self::assertSame('Japanese question', $localized['columns'][0]['elements'][0]['data']['items'][0]['question']);
        self::assertSame('soft', $localized['columns'][0]['elements'][0]['data']['faq_style']);
        self::assertSame($section, HomeFaqContent::localize($section, 'en'));
        $section['settings']['title'] = 'My own title';
        $element['data']['items'][0]['question'] = 'My own question';
        $localized = HomeFaqContent::localize($section, 'ja');
        self::assertSame('My own title', $localized['settings']['title']);
        self::assertSame('My own question', $localized['columns'][0]['elements'][0]['data']['items'][0]['question']);
        self::assertSame('Japanese answer', $localized['columns'][0]['elements'][0]['data']['items'][0]['answer']);
        self::assertSame('Second translated', $localized['columns'][0]['elements'][0]['data']['items'][1]['question']);
        self::assertSame('Japanese subtitle', $localized['settings']['subtitle']);
        $element['data']['items'] = array_reverse($element['data']['items']);
        $roundTrip = BloxDocumentPipeline::process(json_encode([$section], JSON_THROW_ON_ERROR));
        $localized = HomeFaqContent::localize($roundTrip['sections'][0], 'ja');
        self::assertSame('Second translated', $localized['columns'][0]['elements'][0]['data']['items'][0]['question']);
        self::assertSame('My own question', $localized['columns'][0]['elements'][0]['data']['items'][1]['question']);
    }

    public function testEditorShowsTheEditingLanguageAndSavesEditsIntoThatTranslation(): void
    {
        $this->seed();
        $section = HomeFaqContent::toSection(['block_type' => 'custom:2'], 'native');
        $element = &$section['columns'][0]['elements'][0];
        $element['data'][HomeFaqContent::KEY]['translations']['ja'] = [
            'title' => 'よくある質問', 'subtitle' => 'サポート',
            'items' => [['question' => '一つ目？', 'answer' => '回答一'], ['question' => '二つ目？', 'answer' => '回答二']],
        ];
        // 中文编辑器里改过第二条答案：日语面板只翻译问题，答案保留中文改动
        $element['data']['items'][1]['answer'] = 'Edited in Chinese';
        unset($element);
        $shared = $section;

        self::assertSame([$shared], HomeFaqContent::forEditor([$shared], 'zh-CN'));
        $view = HomeFaqContent::forEditor([$shared], 'ja')[0];
        $items = $view['columns'][0]['elements'][0]['data']['items'];
        self::assertSame('よくある質問', $view['settings']['title']);
        self::assertSame(['一つ目？', '回答一'], [$items[0]['question'], $items[0]['answer']]);
        self::assertSame(['二つ目？', 'Edited in Chinese'], [$items[1]['question'], $items[1]['answer']]);

        // 未改动保存：共享文档原样还原，不带编辑标记
        $unchanged = json_decode(HomeFaqContent::fromEditorJson(json_encode(['sections' => [$view]], JSON_THROW_ON_ERROR)), true);
        self::assertSame($shared, $unchanged['sections'][0]);

        // 日语编辑：问题、标题写进日语译文；新增行按普通内容保留；复制行不抢占同一条译文
        $view['settings']['title'] = '質問集';
        $viewItems = &$view['columns'][0]['elements'][0]['data']['items'];
        $viewItems[0]['question'] = '購入方法は？';
        $viewItems[1]['answer'] = 'Changed again';
        $viewItems[] = $viewItems[0];
        $viewItems[] = ['question' => '新しい質問', 'answer' => '新しい回答'];
        unset($viewItems);
        $saved = BloxDocumentPipeline::process(HomeFaqContent::fromEditorJson(json_encode([$view], JSON_THROW_ON_ERROR)))['sections'][0];
        $data = $saved['columns'][0]['elements'][0]['data'];
        self::assertArrayNotHasKey(HomeFaqContent::EDIT_KEY, $data);
        self::assertSame('FAQ', $saved['settings']['title']);
        self::assertSame(['question' => 'First?', 'answer' => 'Answer one'], $data['items'][0]);
        self::assertSame(['question' => 'Second?', 'answer' => 'Changed again'], $data['items'][1]);
        self::assertSame(['question' => '購入方法は？', 'answer' => '回答一'], $data['items'][2]);
        self::assertSame(['question' => '新しい質問', 'answer' => '新しい回答'], $data['items'][3]);
        $ja = $data[HomeFaqContent::KEY]['translations']['ja'];
        self::assertSame('質問集', $ja['title']);
        self::assertSame('購入方法は？', $ja['items'][0]['question']);
        self::assertSame('回答二', $ja['items'][1]['answer']);

        $render = HomeFaqContent::localize($saved, 'ja');
        self::assertSame('質問集', $render['settings']['title']);
        self::assertSame('購入方法は？', $render['columns'][0]['elements'][0]['data']['items'][0]['question']);
        self::assertSame('First?', HomeFaqContent::localize($saved, 'zh-CN')['columns'][0]['elements'][0]['data']['items'][0]['question']);
    }

    public function testMixedCustomContentIsNeverSilentlyDropped(): void
    {
        $this->seed();
        self::assertNull(HomeFaqContent::toSection(['block_type' => 'about'], 'native'));
        self::assertNull(HomeFaqContent::toSection(['block_type' => 'custom:99'], 'native'));
        $custom = json_decode($GLOBALS['_test_config']['home_custom_2'], true);
        $custom['blocks'][0]['columns'][0]['elements'][] = ['type' => 'text', 'data' => ['html' => 'Keep me']];
        $GLOBALS['_test_config']['home_custom_2'] = json_encode($custom);
        self::assertNull(HomeFaqContent::toSection(['block_type' => 'custom:2'], 'native'));
    }

    public function testRenderedFaqIsNativeAndHiddenStateSurvives(): void
    {
        $this->seed();
        $section = HomeFaqContent::toSection(['block_type' => 'custom:2', 'enabled' => false], 'native');
        self::assertTrue($section['settings']['hidden']);
        $section['settings']['hidden'] = false;
        $html = HomeBloxRenderer::render([$section], static function (): string {
            self::fail('A native FAQ must not call the dynamic homepage renderer.');
        });
        self::assertStringContainsString('First?', $html);
        self::assertStringContainsString('FAQPage', $html);
        self::assertSame(2, substr_count($html, '<details '));
    }

    public function testUnsupportedTranslatedStructureAndOversizedLegacyFaqAreRejected(): void
    {
        $this->seed();
        $custom = json_decode($GLOBALS['_test_config']['home_custom_2'], true);
        $custom['blocks'][0]['settings']['title'] = 'Translated title';
        $GLOBALS['_test_config']['home_custom_2_ja'] = json_encode($custom);
        $section = HomeFaqContent::toSection(['block_type' => 'custom:2'], 'native', ['ja']);
        self::assertNotNull($section);
        self::assertSame('Translated title', HomeFaqContent::localize($section, 'ja')['settings']['title']);
        $custom['blocks'][0]['columns'][0]['elements'][] = ['type' => 'text', 'data' => ['html' => 'Keep translated content']];
        $GLOBALS['_test_config']['home_custom_2_ja'] = json_encode($custom);
        self::assertNull(HomeFaqContent::toSection(['block_type' => 'custom:2'], 'native', ['ja']));
        array_pop($custom['blocks'][0]['columns'][0]['elements']);
        $custom['blocks'][0]['columns'][0]['elements'][0]['data']['open_first'] = false;
        $GLOBALS['_test_config']['home_custom_2_ja'] = json_encode($custom);
        self::assertNull(HomeFaqContent::toSection(['block_type' => 'custom:2'], 'native', ['ja']));
        $this->seed();
        $custom = json_decode($GLOBALS['_test_config']['home_custom_2'], true);
        $custom['blocks'][0]['columns'][0]['elements'][0]['data']['items'] = implode("\n", array_fill(0, 31, 'Question?|Answer'));
        $GLOBALS['_test_config']['home_custom_2'] = json_encode($custom);
        self::assertNull(HomeFaqContent::toSection(['block_type' => 'custom:2'], 'native', []));
    }
}
