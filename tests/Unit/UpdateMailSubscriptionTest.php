<?php
/**
 * 升级与安全邮件通知订阅（2.0.3，WP-14 B）。
 *
 * 钉死隐私边界：没订阅就不上报任何个人信息；退订送达一次后不再带；邮箱走 POST 正文不进 URL。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class UpdateMailSubscriptionTest extends TestCase
{
    private function boot(): void
    {
        db()->execute('CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, `key` TEXT UNIQUE, `value` TEXT, `group` TEXT, `name` TEXT, `tip` TEXT, sort_order INTEGER DEFAULT 0)');
        db()->execute("DELETE FROM settings WHERE `key` LIKE 'update_mail_%'");
        settingModel()->clearCache();
        require_once ROOT_PATH . '/includes/UpdateMailSubscription.php';
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNothingIsReportedUntilTheOwnerSubscribes(): void
    {
        $this->boot();
        self::assertSame([], \UpdateMailSubscription::reportFields());
        self::assertFalse(\UpdateMailSubscription::current()['on']);

        self::assertFalse(\UpdateMailSubscription::subscribe('not-an-email', 'en'));
        self::assertFalse(\UpdateMailSubscription::subscribe(str_repeat('a', 250) . '@x.io', 'en'), 'longer than 254 characters');
        self::assertSame([], \UpdateMailSubscription::reportFields(), 'a rejected address changes nothing');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSubscribeReportAndUnsubscribeIsDeliveredOnce(): void
    {
        $this->boot();
        self::assertTrue(\UpdateMailSubscription::subscribe(' owner@example.com ', 'ja-JP'));
        self::assertSame(['notify_email' => 'owner@example.com', 'notify_lang' => 'ja', 'notify_resubscribe' => '1'], \UpdateMailSubscription::reportFields());
        \UpdateMailSubscription::acknowledge(\UpdateMailSubscription::reportFields());
        self::assertSame(['notify_email' => 'owner@example.com', 'notify_lang' => 'ja'], \UpdateMailSubscription::reportFields());

        \UpdateMailSubscription::unsubscribe();
        $sent = \UpdateMailSubscription::reportFields();
        self::assertSame(['notify_email' => ''], $sent, 'the next heartbeat tells the server to delete the address');

        \UpdateMailSubscription::acknowledge($sent);
        self::assertSame([], \UpdateMailSubscription::reportFields(), 'after delivery nothing is sent any more');

        // 从没订阅过的站退订：不必通知服务器
        \UpdateMailSubscription::unsubscribe();
        self::assertSame([], \UpdateMailSubscription::reportFields());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnsubscribeFromEmailAndExplicitResubscribe(): void
    {
        $this->boot();
        \UpdateMailSubscription::subscribe('owner@example.com', 'en');
        // 在后台亲自订阅：下一次回访带解除退订标记，送达后不再带
        $sent = \UpdateMailSubscription::reportFields();
        self::assertSame('1', $sent['notify_resubscribe'] ?? null);
        \UpdateMailSubscription::acknowledge($sent);
        self::assertArrayNotHasKey('notify_resubscribe', \UpdateMailSubscription::reportFields());

        // 站长点了邮件里的退订链接：服务器回告后本地停止上报，也不再弹提示
        \UpdateMailSubscription::unsubscribedByServer();
        self::assertSame([], \UpdateMailSubscription::reportFields());
        self::assertFalse(\UpdateMailSubscription::promptDue());

        $src = (string) file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        self::assertStringContainsString("(\$d['data']['notify_state'] ?? '') === 'unsubscribed'", $src);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDashboardPromptStopsAfterSubscribeOrDismiss(): void
    {
        $this->boot();
        self::assertTrue(\UpdateMailSubscription::promptDue());
        \UpdateMailSubscription::subscribe('owner@example.com', 'zh-CN');
        self::assertFalse(\UpdateMailSubscription::promptDue());

        \UpdateMailSubscription::unsubscribe();
        self::assertTrue(\UpdateMailSubscription::promptDue());
        \UpdateMailSubscription::dismissPrompt();
        self::assertFalse(\UpdateMailSubscription::promptDue());
    }

    public function testEmailTravelsInThePostBodyNotTheUrl(): void
    {
        $src = (string) file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        self::assertStringContainsString('UpdateMailSubscription::reportFields()', $src);
        self::assertStringContainsString("'content' => \$body", $src);
        self::assertStringContainsString('CURLOPT_POSTFIELDS => $body', $src);
        self::assertStringContainsString('UpdateMailSubscription::acknowledge($notify)', $src);
        // 邮箱不能拼进查询串
        self::assertDoesNotMatchRegularExpression('/\$q\s*\[\s*[\'"]notify_email/', $src);
        self::assertStringNotContainsString("'notify_email' =>", $src);
    }

    public function testInstallerOptInIsUncheckedByDefault(): void
    {
        $src = (string) file_get_contents(ROOT_PATH . '/install/index.php');
        self::assertStringContainsString('<input type="checkbox" name="update_mail" value="1" class="mt-0.5">', $src);
        self::assertStringContainsString("(\$_POST['update_mail'] ?? '') === '1'", $src);
        foreach (['zh', 'en', 'ja'] as $lang) {
            $l = require ROOT_PATH . '/install/lang/' . $lang . '.php';
            self::assertNotSame('', $l['update_mail_subscribe'] ?? '', $lang);
            self::assertNotSame('', $l['update_mail_note'] ?? '', $lang);
        }
    }
}
