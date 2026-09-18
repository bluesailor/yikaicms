<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

/** 网页头/网页脚编辑器顶栏的语言切换：跳转目标与模板库多语言面板的判定一致。 */
final class BloxAreaEditorLanguageLinksTest extends TestCase
{
    private const LANGUAGES = ['zh-CN' => '中文', 'en' => 'English', 'ja' => '日本語', 'ko' => '한국어'];

    /** @return list<array{code:string,label:string,is_default:bool,areas:array<string,array<string,mixed>>}> */
    private function rows(): array
    {
        $area = static fn (string $mode, ?int $candidate, ?int $draft = null): array => ['footer' => [
            'mode' => $mode,
            'candidate' => $candidate === null ? null : ['id' => $candidate],
            'draft' => $draft === null ? null : ['id' => $draft],
        ]];
        return [
            ['code' => 'zh-CN', 'label' => '中文', 'is_default' => true, 'areas' => $area('default', 22)],
            ['code' => 'en', 'label' => 'English', 'is_default' => false, 'areas' => $area('inherit', 22)],
            ['code' => 'ja', 'label' => '日本語', 'is_default' => false, 'areas' => $area('independent', 31)],
            ['code' => 'ko', 'label' => '한국어', 'is_default' => false, 'areas' => $area('theme', null, 40)],
        ];
    }

    public function testEachLanguageOpensTheDesignItActuallyUses(): void
    {
        $links = BloxAreaEditorLanguageLinks::build(['id' => 22, 'type' => 'footer'], 'en', self::LANGUAGES, $this->rows());
        $byCode = array_column($links, null, 'code');

        self::assertSame(['zh-CN', 'en', 'ja', 'ko'], array_column($links, 'code'));
        self::assertSame(['ZH', 'EN', 'JA', 'KO'], array_column($links, 'short'));

        // 默认语言与继承语言共用同一份设计，按各自语言预览
        self::assertSame('/admin/blox_editor.php?template=22&area_lang=zh-CN', $byCode['zh-CN']['url']);
        self::assertSame('/admin/blox_editor.php?template=22&area_lang=en', $byCode['en']['url']);
        self::assertSame('inherit', $byCode['en']['state']);
        // 独立设计跳到它自己的模板
        self::assertSame('/admin/blox_editor.php?template=31&area_lang=ja', $byCode['ja']['url']);
        self::assertSame('independent', $byCode['ja']['state']);
        // 有独立草稿优先编辑草稿
        self::assertSame('/admin/blox_editor.php?template=40&area_lang=ko', $byCode['ko']['url']);
        self::assertSame('draft', $byCode['ko']['state']);

        self::assertSame([false, true, false, false], array_column($links, 'current'));
        self::assertStringStartsWith('English · ', $byCode['en']['title']);
    }

    public function testLanguageWithoutAnyDesignPreviewsTheCurrentSharedDesign(): void
    {
        $rows = $this->rows();
        $rows[3]['areas']['footer'] = ['mode' => 'theme', 'candidate' => null, 'draft' => null];
        $link = BloxAreaEditorLanguageLinks::build(['id' => 22, 'type' => 'footer'], 'zh-CN', self::LANGUAGES, $rows)[3];

        // 当前设计不是语言专属：留在当前设计，用该语言站点资料预览
        self::assertSame('preview', $link['state']);
        self::assertSame('/admin/blox_editor.php?template=22&area_lang=ko', $link['url']);

        // 当前是日文专属的独立设计：没有设计的语言去模板库复制或选择
        $japaneseOnly = ['id' => 31, 'type' => 'footer', 'conditions' => json_encode([['main' => 'any', 'ids' => [], 'exclude' => false, 'langs' => ['ja']]])];
        self::assertSame('ja', BloxAreaLanguageManager::managedLanguage($japaneseOnly));
        $link = BloxAreaEditorLanguageLinks::build($japaneseOnly, 'ja', self::LANGUAGES, $rows)[3];
        self::assertSame('none', $link['state']);
        self::assertSame('/admin/blox_templates.php?type=footer&area_lang=ko#blox-language-areas', $link['url']);
    }

    public function testSingleLanguageSitesAndNonAreaTemplatesShowNoSwitch(): void
    {
        self::assertSame([], BloxAreaEditorLanguageLinks::build(['id' => 22, 'type' => 'footer'], 'zh-CN', ['zh-CN' => '中文'], []));
        self::assertSame([], BloxAreaEditorLanguageLinks::build(['id' => 5, 'type' => 'page'], 'zh-CN', self::LANGUAGES, $this->rows()));
    }
}
