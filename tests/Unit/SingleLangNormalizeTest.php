<?php
/**
 * 单语言化两项修复的回归（2026-08-09 实测事故）：
 *  - ChannelModel::siblingForLang：版块存的 channel id 是建块当时语言的行，
 *    渲染必须映射到当前语言的翻译兄弟行，无兄弟行不渲染（不串语言）；
 *  - SettingModel::normalizeDefaultLangRows：切默认语言后 base/后缀行角色归位，
 *    否则后台表单显示旧语言且保存不生效（前台后缀优先）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use ChannelModel;
use Yikai\Tests\TestCase;

class SingleLangNormalizeTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lang TEXT DEFAULT 'zh-CN', translation_group_id INTEGER DEFAULT 0,
                parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT, type TEXT DEFAULT 'list',
                status INTEGER DEFAULT 1, is_home INTEGER DEFAULT 0, is_nav INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 0
            )",
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', "key" TEXT, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)',
        ];
    }

    // ── siblingForLang ──

    public function testSiblingForLangMapsToTranslationRow(): void
    {
        $this->insertRow('channels', ['lang' => 'zh-CN', 'translation_group_id' => 5, 'name' => '产品中心', 'slug' => 'product-zh']);
        $this->insertRow('channels', ['lang' => 'en', 'translation_group_id' => 5, 'name' => 'Products', 'slug' => 'product']);

        $row = (new ChannelModel())->siblingForLang(1, 'en');
        $this->assertIsArray($row);
        $this->assertSame('Products', $row['name'], 'zh 行 id 应映射到 en 兄弟行');
    }

    public function testSiblingForLangSameLangReturnsRowItself(): void
    {
        $this->insertRow('channels', ['lang' => 'en', 'translation_group_id' => 5, 'name' => 'Products', 'slug' => 'product']);

        $row = (new ChannelModel())->siblingForLang(1, 'en');
        $this->assertSame('Products', $row['name'] ?? null);
    }

    public function testSiblingForLangNoTranslationReturnsNull(): void
    {
        // 语言不匹配且无兄弟行：按语言页面宁可不显示，不把别的语言渲进来
        $this->insertRow('channels', ['lang' => 'zh-CN', 'translation_group_id' => 9, 'name' => '仅中文', 'slug' => 'zh-only']);

        $this->assertNull((new ChannelModel())->siblingForLang(1, 'en'));
    }

    public function testSiblingForLangDisabledRowReturnsNull(): void
    {
        $this->insertRow('channels', ['lang' => 'en', 'name' => 'Hidden', 'slug' => 'hidden', 'status' => 0]);

        $this->assertNull((new ChannelModel())->siblingForLang(1, 'en'));
    }

    // ── normalizeDefaultLangRows ──

    public function testNormalizePromotesSuffixAndPreservesOldDefault(): void
    {
        settingModel()->set('site_keywords', '中文关键词');
        settingModel()->set('site_keywords_en', 'english,keywords');

        $moved = settingModel()->normalizeDefaultLangRows('en', 'zh-CN');

        $this->assertSame(1, $moved);
        $this->assertSame('english,keywords', (string) settingModel()->get('site_keywords'), '_en 值应提升为 base');
        $this->assertSame('中文关键词', (string) settingModel()->get('site_keywords_zh-CN'), '旧默认内容应落到 _zh-CN 行');
        $this->assertSame('', (string) settingModel()->get('site_keywords_en'), '_en 行应删除');
    }

    public function testNormalizeDoesNotOverwriteExistingOldDefaultRow(): void
    {
        settingModel()->set('site_name', 'Base 值');
        settingModel()->set('site_name_zh-CN', '已有的中文行');
        settingModel()->set('site_name_en', 'English Name');

        settingModel()->normalizeDefaultLangRows('en', 'zh-CN');

        $this->assertSame('English Name', (string) settingModel()->get('site_name'));
        $this->assertSame('已有的中文行', (string) settingModel()->get('site_name_zh-CN'), '已存在的旧默认行不覆盖');
    }

    public function testNormalizeIsIdempotentAndIgnoresSameLang(): void
    {
        settingModel()->set('footer_copyright_text', '© 中文');
        settingModel()->set('footer_copyright_text_en', '© English');

        $this->assertSame(0, settingModel()->normalizeDefaultLangRows('en', 'en'), '同语言切换不动任何行');
        settingModel()->normalizeDefaultLangRows('en', 'zh-CN');
        $this->assertSame(0, settingModel()->normalizeDefaultLangRows('en', 'zh-CN'), '再跑一次无后缀行可归位');
    }

    public function testNormalizeUnderscoreInLikeDoesNotOverMatch(): void
    {
        // LIKE 的 _ 是单字符通配——未转义时 '%_en' 会把 token_len 之类也吞进来
        settingModel()->set('screen_men', 'not-a-lang-row');
        settingModel()->set('site_keywords_en', 'kw');

        $moved = settingModel()->normalizeDefaultLangRows('en', 'zh-CN');

        $this->assertSame(1, $moved, '只应归位真正的 _en 后缀行');
        $this->assertSame('not-a-lang-row', (string) settingModel()->get('screen_men'), '非语言行不得被当作后缀行处理');
    }
}
