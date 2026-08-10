<?php
/**
 * adminLangView() —— 后台「正在编辑哪个语言槽」的解析。
 *
 * 起因（2026-08-10 客户实测）：视图语言原先只从 GET ?lang= 取。这个参数非常易丢——
 * 写死的 tab 链接、fetch('/admin/xxx.php') 都会把它丢掉。丢掉后表单里显示的还是英文
 * 内容，保存却写进默认语言（中文）槽位且毫无提示：英文版内容改不动，中文前台反而
 * 显示英文。修法是表单渲染时把视图语言烙进隐藏字段（adminLangField()），
 * 保存端以 POST view_lang 为最高优先。
 *
 * 本测试锁三件事：POST 优先于 GET、POST 缺失时回落 GET、非法语言一律钳回默认。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class AdminLangViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT_PATH . '/admin/includes/trans_pills.php';
        // 测试桩的 config() 读 $GLOBALS['_test_config']（见 tests/bootstrap.php），不走库
        $GLOBALS['_test_config']['site_lang'] = 'zh-CN';
        $GLOBALS['_test_config']['enabled_languages'] = '["zh-CN","en","ja"]';
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        unset($GLOBALS['_test_config']['site_lang'], $GLOBALS['_test_config']['enabled_languages']);
        parent::tearDown();
    }

    public function testPostViewLangBeatsMissingGet(): void
    {
        // URL 丢了 ?lang=（正是事故场景），但表单隐藏字段带着 en
        $_POST['view_lang'] = 'en';

        $lang = adminLangView();

        $this->assertSame('en', $lang['view'], 'POST view_lang 必须生效——这是防「保存写错语言槽」的关键');
        $this->assertSame('zh-CN', $lang['default']);
        $this->assertFalse($lang['isSource']);
    }

    public function testFallsBackToGetWhenNoPost(): void
    {
        $_GET['lang'] = 'ja';

        $lang = adminLangView();

        $this->assertSame('ja', $lang['view'], '无 POST 时沿用原有 GET 行为');
    }

    public function testInvalidLangClampsToDefault(): void
    {
        // 未启用/伪造的语言码不能成为写入后缀
        $_POST['view_lang'] = 'fr';
        $_GET['lang'] = 'fr';

        $lang = adminLangView();

        $this->assertSame('zh-CN', $lang['view'], '非启用语言必须钳回默认，否则会写出 _fr 孤儿行');
        $this->assertTrue($lang['isSource']);
    }

    public function testPostBeatsConflictingGet(): void
    {
        // tab 链接把 URL 上的 lang 换掉了，但表单是按 en 渲染的——以表单为准
        $_POST['view_lang'] = 'en';
        $_GET['lang'] = 'ja';

        $lang = adminLangView();

        $this->assertSame('en', $lang['view'], '表单隐藏字段（渲染时的语言）优先于 URL');
    }
}
