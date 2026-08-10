<?php
/**
 * SettingModel::normalizeDefaultLangRows —— 切换站点默认语言时的行角色归位。
 *
 * 起因：这个方法里写的是
 *     "... WHERE `key` LIKE ? ESCAPE '\\'"
 * PHP **双引号**串里 '\\' 解析后只剩一个反斜杠，SQL 收到 ESCAPE '\' ——
 * 反斜杠在 MySQL 字符串字面量里本身又是转义符，其中的 \' 被当成转义引号，
 * 字符串永远闭合不了，直接 1064。症状是「打开语言设置切换默认语言就 500」。
 *
 * 上线时没有任何测试跑过这个方法，于是「切默认语言」这条路径带着一个必炸的
 * SQL 进了发行包。本测试就守两件事：
 *   1. SQL 语法合法 —— 方法能跑完不抛异常；
 *   2. LIKE 的转义真的生效 —— 下划线不被当通配符，别把不相干的键卷进来。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class SettingNormalizeLangTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', '
            . '"key" TEXT, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', '
            . 'options TEXT, sort_order INTEGER DEFAULT 0)',
        ];
    }

    private function seed(array $rows): void
    {
        foreach ($rows as $k => $v) {
            db()->execute('INSERT INTO settings ("group", "key", value) VALUES (?, ?, ?)', ['basic', $k, $v]);
        }
    }

    private function val(string $key): ?string
    {
        $r = db()->fetchOne('SELECT value FROM settings WHERE "key" = ?', [$key]);
        return $r === null ? null : (string) $r['value'];
    }

    /** 曾经必炸的那条路径：能跑完就说明 SQL 语法是对的 */
    public function testRunsWithoutSqlError(): void
    {
        $this->seed([
            'site_name'    => '易凯建站',
            'site_name_en' => 'Yikai CMS',
        ]);

        $moved = settingModel()->normalizeDefaultLangRows('en', 'zh-CN');

        $this->assertSame(1, $moved, '应归位 1 个键');
    }

    /** 归位规则：新默认语言的值升为 base，旧默认语言的值落到后缀行 */
    public function testPromotesNewDefaultAndPreservesOld(): void
    {
        $this->seed([
            'site_name'    => '易凯建站',
            'site_name_en' => 'Yikai CMS',
        ]);

        settingModel()->normalizeDefaultLangRows('en', 'zh-CN');

        $this->assertSame('Yikai CMS', $this->val('site_name'), '新默认语言的值应升为 base');
        $this->assertSame('易凯建站', $this->val('site_name_zh-CN'), '旧默认语言的内容必须留住，不能丢');
        $this->assertNull($this->val('site_name_en'), '提升后原后缀行应删除');
    }

    /**
     * 转义的实质考核：LIKE 里的 `_` 必须当字面量。
     *
     * 后缀是 `_en`。若不转义，`%_en` 会把任何「倒数第三位是任意字符 + en」的键
     * 也匹配进来——比如 `site_token`。那些键会被误当成英文版内容提升为 base，
     * 把默认语言的内容覆盖掉，且删除源行后无法还原。
     */
    public function testUnderscoreIsEscapedNotTreatedAsWildcard(): void
    {
        $this->seed([
            'site_name'     => '易凯建站',
            'site_name_en'  => 'Yikai CMS',
            'site_token'    => 'SECRET-KEEP-ME',   // 以 "en" 结尾，但不是 "_en" 后缀
            'brand_golden'  => 'GOLD-KEEP-ME',     // 同上
        ]);

        settingModel()->normalizeDefaultLangRows('en', 'zh-CN');

        $this->assertSame('SECRET-KEEP-ME', $this->val('site_token'), 'site_token 不该被通配符卷进来');
        $this->assertSame('GOLD-KEEP-ME', $this->val('brand_golden'), 'brand_golden 不该被通配符卷进来');
        $this->assertSame('Yikai CMS', $this->val('site_name'), '真正的 _en 键仍应正常归位');
    }

    /** 幂等：同样的参数再跑一次不应再动任何行 */
    public function testIdempotent(): void
    {
        $this->seed([
            'site_name'    => '易凯建站',
            'site_name_en' => 'Yikai CMS',
        ]);

        settingModel()->normalizeDefaultLangRows('en', 'zh-CN');
        $second = settingModel()->normalizeDefaultLangRows('en', 'zh-CN');

        $this->assertSame(0, $second, '第二次应无事可做');
        $this->assertSame('Yikai CMS', $this->val('site_name'));
        $this->assertSame('易凯建站', $this->val('site_name_zh-CN'));
    }

    /** 新旧默认语言相同 / 新默认为空时直接返回，不动库 */
    public function testNoopWhenSameOrEmpty(): void
    {
        $this->seed(['site_name' => '易凯建站', 'site_name_en' => 'Yikai CMS']);

        $this->assertSame(0, settingModel()->normalizeDefaultLangRows('en', 'en'));
        $this->assertSame(0, settingModel()->normalizeDefaultLangRows('', 'zh-CN'));
        $this->assertSame('易凯建站', $this->val('site_name'), '未触发归位时 base 不应变化');
    }
}
