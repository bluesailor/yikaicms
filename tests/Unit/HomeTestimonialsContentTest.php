<?php
/**
 * 首页「客户评价」轮播的多语言绑定（HomeTestimonialsContent / HomeItemListLocalization）。
 *
 * 2026-09-18：从区块模板插进首页的评价轮播只有中文，英文、日文首页照样显示中文。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxDocumentPipeline;
use BloxUnknownKeys;
use HomeFaqContent;
use HomeTestimonialsContent;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeTestimonialsContentTest extends TestCase
{
    /** @return array<string,mixed> */
    private function section(): array
    {
        $item = static fn (string $name, string $role, string $content, string $avatar): array =>
            ['avatar' => $avatar, 'name' => $name, 'role' => $role, 'content' => $content, 'rating' => '5'];

        return [
            'id' => 's_t', 'type' => 'section',
            'settings' => ['title' => '客户评价', 'subtitle' => '听听客户怎么说', 'max_width' => 'narrow'],
            'columns' => [['id' => 'c_t', 'elements' => [[
                'id' => 'e_t', 'type' => 'testimonial-carousel',
                'data' => [
                    'items' => [
                        $item('陈思远', '采购总监', '交付很快', '/a1.svg'),
                        $item('林晓雯', '运营经理', '响应及时', '/a2.svg'),
                    ],
                    'per_view' => '1', 'show_dots' => '1',
                    HomeTestimonialsContent::KEY => [
                        'lang' => 'zh-CN',
                        'source' => [
                            'title' => '客户评价', 'subtitle' => '听听客户怎么说',
                            'items' => [
                                ['name' => '陈思远', 'role' => '采购总监', 'content' => '交付很快'],
                                ['name' => '林晓雯', 'role' => '运营经理', 'content' => '响应及时'],
                            ],
                        ],
                        'translations' => [
                            'ja' => [
                                'title' => 'お客様の声', 'subtitle' => 'ご感想',
                                'items' => [
                                    ['name' => '陳 思遠', 'role' => '購買部長', 'content' => '納品が早い'],
                                    ['name' => '林 暁雯', 'role' => '運営マネージャー', 'content' => '対応が早い'],
                                ],
                            ],
                            'en' => [
                                'title' => 'What Our Clients Say', 'subtitle' => 'Reviews',
                                'items' => [
                                    ['name' => 'Siyuan Chen', 'role' => 'Procurement Director', 'content' => 'Fast delivery'],
                                    ['name' => 'Xiaowen Lin', 'role' => 'Operations Manager', 'content' => 'Quick response'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]]]],
        ];
    }

    /** @param array<string,mixed> $section @return list<array<string,mixed>> */
    private function items(array $section): array
    {
        return $section['columns'][0]['elements'][0]['data']['items'];
    }

    public function testEachLanguageRendersItsOwnCopyAndSharedFieldsStay(): void
    {
        $section = $this->section();

        $ja = HomeTestimonialsContent::localize($section, 'ja');
        self::assertSame('お客様の声', $ja['settings']['title']);
        self::assertSame('ご感想', $ja['settings']['subtitle']);
        self::assertSame(['陳 思遠', '購買部長', '納品が早い'], array_values(array_intersect_key(
            $this->items($ja)[0],
            array_flip(['name', 'role', 'content'])
        )));
        // 头像、评分跨语言共用
        self::assertSame('/a1.svg', $this->items($ja)[0]['avatar']);
        self::assertSame('5', $this->items($ja)[0]['rating']);

        self::assertSame('Quick response', $this->items(HomeTestimonialsContent::localize($section, 'en'))[1]['content']);
        // 源语言、以及没有译文的语言，原样输出
        self::assertSame($section, HomeTestimonialsContent::localize($section, 'zh-CN'));
        self::assertSame($section, HomeTestimonialsContent::localize($section, 'ko'));
    }

    /** 站长在源语言里改过的文字保持原样；条目按内容匹配，调换顺序后译文不串。 */
    public function testEditedSourceFieldsDetachAndReorderKeepsTheRightTranslation(): void
    {
        $section = $this->section();
        $items = &$section['columns'][0]['elements'][0]['data']['items'];
        $items = [$items[1], $items[0]];
        $items[1]['content'] = '中文站长刚改过的评价';
        unset($items);

        $ja = $this->items(HomeTestimonialsContent::localize($section, 'ja'));
        self::assertSame('林 暁雯', $ja[0]['name'], '调换顺序后仍按内容找到对应译文');
        self::assertSame('対応が早い', $ja[0]['content']);
        self::assertSame('陳 思遠', $ja[1]['name'], '姓名、职务仍对得上，照常翻译');
        self::assertSame('中文站长刚改过的评价', $ja[1]['content'], '改过的正文不被旧译文盖掉');
    }

    /** 在日文编辑器里改评价：写回日文译文，文档里的中文不变。 */
    public function testEditingInJapaneseWritesBackToTheJapaneseTranslation(): void
    {
        $shared = $this->section();
        self::assertSame([$shared], HomeTestimonialsContent::forEditor([$shared], 'zh-CN'));

        $view = HomeTestimonialsContent::forEditor([$shared], 'ja')[0];
        self::assertSame('お客様の声', $view['settings']['title']);

        // 原样保存：共享文档还原，编辑标记不落库
        $unchanged = json_decode(HomeTestimonialsContent::fromEditorJson(json_encode(['sections' => [$view]], JSON_THROW_ON_ERROR)), true);
        self::assertSame($shared, $unchanged['sections'][0]);

        $view['settings']['subtitle'] = '導入企業の声';
        $view['columns'][0]['elements'][0]['data']['items'][0]['content'] = '3週間で納品されました';
        $saved = BloxDocumentPipeline::process(
            HomeTestimonialsContent::fromEditorJson(json_encode([$view], JSON_THROW_ON_ERROR))
        )['sections'][0];

        $data = $saved['columns'][0]['elements'][0]['data'];
        self::assertArrayNotHasKey(HomeTestimonialsContent::EDIT_KEY, $data);
        self::assertSame('听听客户怎么说', $saved['settings']['subtitle'], '中文副标题不被日文覆盖');
        self::assertSame('交付很快', $data['items'][0]['content'], '中文正文不被日文覆盖');
        self::assertArrayNotHasKey('_i18n', $data['items'][0], '条目编辑标记不落库');

        // 真实保存链路（未知键清洗）之后绑定仍在，日文译文已更新
        $ja = $data[HomeTestimonialsContent::KEY]['translations']['ja'];
        self::assertSame('導入企業の声', $ja['subtitle']);
        self::assertSame('3週間で納品されました', $ja['items'][0]['content']);
        self::assertSame('Fast delivery', $data[HomeTestimonialsContent::KEY]['translations']['en']['items'][0]['content']);

        $render = HomeTestimonialsContent::localize($saved, 'ja');
        self::assertSame('導入企業の声', $render['settings']['subtitle']);
        self::assertSame('3週間で納品されました', $this->items($render)[0]['content']);
    }

    /** 常见问题的条目也用 _i18n 做编辑标记，评价的保存处理不能碰它。 */
    public function testOtherElementsItemMarkersAreLeftAlone(): void
    {
        $faq = ['id' => 's_f', 'type' => 'section', 'settings' => [], 'columns' => [['id' => 'c_f', 'elements' => [[
            'id' => 'e_f', 'type' => 'accordion',
            'data' => ['items' => [['question' => 'Q', 'answer' => 'A', HomeFaqContent::ITEM_EDIT_KEY => ['s' => 0, 'f' => ['question']]]]],
        ]]]]];
        $json = json_encode([$faq, $this->section() + ['_marker' => HomeTestimonialsContent::EDIT_KEY]], JSON_THROW_ON_ERROR);
        $out = json_decode(HomeTestimonialsContent::fromEditorJson($json), true);
        self::assertSame(['s' => 0, 'f' => ['question']], $out[0]['columns'][0]['elements'][0]['data']['items'][0]['_i18n']);
    }

    public function testWiringAndSeed(): void
    {
        self::assertContains(HomeTestimonialsContent::KEY, BloxUnknownKeys::RESERVED);
        self::assertContains(HomeTestimonialsContent::EDIT_KEY, BloxUnknownKeys::RESERVED);

        $renderer = (string) file_get_contents(ROOT_PATH . '/includes/builder/HomeBloxRenderer.php');
        self::assertStringContainsString('$section = HomeTestimonialsContent::localize($section);', $renderer);
        $document = (string) file_get_contents(ROOT_PATH . '/includes/builder/HomeBloxDocument.php');
        self::assertStringContainsString('HomeTestimonialsContent::forEditor($sections, siteLang())', $document);
        self::assertStringContainsString('HomeTestimonialsContent::fromEditorJson($blocksJson)', $document);

        // 安装种子的首页评价轮播带着英文、日文译文，且源文案与译文文件一致
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql')));
        $i18n = json_decode((string) file_get_contents(ROOT_PATH . '/tools/seed-i18n/home-testimonials.json'), true);
        foreach (['home_blox_data', 'home_blox_published'] as $key) {
            $doc = json_decode((string) $pdo->query("SELECT value FROM yikai_settings WHERE key = '{$key}'")->fetchColumn(), true);
            $found = 0;
            foreach ($doc['sections'] as $section) {
                foreach ($section['columns'] ?? [] as $column) {
                    foreach ($column['elements'] ?? [] as $element) {
                        if (($element['type'] ?? '') !== 'testimonial-carousel') {
                            continue;
                        }
                        $found++;
                        $binding = $element['data'][HomeTestimonialsContent::KEY] ?? null;
                        self::assertIsArray($binding, "{$key} 的评价轮播没有语言绑定");
                        self::assertSame($i18n['source'], $binding['source']);
                        self::assertSame(['en', 'ja'], array_keys($binding['translations']));
                        foreach (['en', 'ja'] as $lang) {
                            self::assertCount(count($i18n['source']['items']), $binding['translations'][$lang]['items']);
                            $rendered = HomeTestimonialsContent::localize($section, $lang);
                            self::assertSame($i18n['translations'][$lang]['title'], $rendered['settings']['title']);
                        }
                    }
                }
            }
            self::assertSame(1, $found, "{$key} 里应恰好有一个评价轮播");
        }
    }
}
