<?php
/**
 * 种子迁移 × 归位迁移的收敛性——不再无限横跳（kksky.ph 实病，2026-08-10）。
 *
 * 死循环机制：
 *   - 20260810_normalize_default_lang_shadow 删除默认语言的 <key>_<default> 后缀行；
 *   - 三个种子迁移（home_footer / contact_form / mail_template）用「xxx_en 存在」当
 *     幂等标记，且无差别插 _en。
 *   normalize 删 _en → 种子 check 变未应用 → 执行又插回 _en → normalize 又未应用 …
 *
 * 修复：三个种子 check 加守卫「默认语言非 zh-CN → 视为已应用（no-op）」。
 * 本测试锁两点：非中文默认站上种子迁移 no-op；两拨迁移在同一数据上不再互相翻转。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class SeedNormalizeNoLoopTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['_test_config']['site_lang']);
        parent::tearDown();
    }

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', '
            . '"key" TEXT, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', '
            . 'options TEXT, sort_order INTEGER DEFAULT 0)',
        ];
    }

    /** 从 Migrator::loadAll() 里按 id 取一条迁移定义 */
    private function migration(string $id): array
    {
        require_once ROOT_PATH . '/includes/Migrator.php';
        foreach (\Migrator::loadAll() as $m) {
            if (($m['id'] ?? '') === $id) {
                return $m;
            }
        }
        $this->fail("迁移不存在：$id");
    }

    public function testSeedMigrationsNoopOnEnglishDefaultSite(): void
    {
        $GLOBALS['_test_config']['site_lang'] = 'en';

        foreach ([
            '20260511_home_footer_translations',
            '20260511_contact_form_translations',
            '20260512_mail_template_translations',
        ] as $id) {
            $m = $this->migration($id);
            $this->assertTrue(
                ($m['check'])(),
                "$id 在英文默认站上必须视为已应用（否则与归位迁移无限横跳）"
            );
        }
    }

    public function testSeedMigrationsStillActiveOnChineseDefaultSite(): void
    {
        // 中文默认站：守卫不生效，仍按「_en 是否存在」判定——行为不回退
        $GLOBALS['_test_config']['site_lang'] = 'zh-CN';

        $m = $this->migration('20260511_home_footer_translations');
        $this->assertFalse(($m['check'])(), '中文默认站、无 _en 种子时仍应判为待应用');
    }

    public function testConvergesInstesdOfFlipping(): void
    {
        // 英文默认站，残留一批 _en 影子行（安装种子）
        $GLOBALS['_test_config']['site_lang'] = 'en';
        db()->execute('INSERT INTO settings ("key", value) VALUES (?, ?)', ['home_about_content_en', 'shadow']);
        db()->execute('INSERT INTO settings ("key", value) VALUES (?, ?)', ['home_about_content', '中文种子']);
        db()->execute('INSERT INTO settings ("key", value) VALUES (?, ?)', ['site_lang', 'en']);  // normalize 直接查表读 site_lang

        $normalize = $this->migration('20260810_normalize_default_lang_shadow');
        $seed = $this->migration('20260511_home_footer_translations');

        // 跑归位：删掉 _en 影子行
        ($normalize['php'])();
        $this->assertTrue(($normalize['check'])(), '归位后自身应已应用');
        // 关键：归位删了 _en 后，种子迁移不能因此复活
        $this->assertTrue(
            ($seed['check'])(),
            '归位删除 _en 后，种子迁移必须仍判为已应用——这正是不横跳的保证'
        );
    }
}
