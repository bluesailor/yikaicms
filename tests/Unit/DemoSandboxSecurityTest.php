<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/DemoSandbox.php';

final class DemoSandboxSecurityTest extends TestCase
{
    private bool $hadOwnerToken = false;
    private string $previousOwnerToken = '';
    private string $previousOwnerTokenGroup = 'cron';

    protected function setUp(): void
    {
        db()->execute(
            'CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                `key` TEXT NOT NULL UNIQUE,
                `value` TEXT NOT NULL DEFAULT \'\',
                `group` TEXT NOT NULL DEFAULT \'basic\',
                `name` TEXT NOT NULL DEFAULT \'\',
                `tip` TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $existing = db()->fetchOne(
            'SELECT `value`, `group` FROM ' . DB_PREFIX . 'settings WHERE `key` = ?',
            ['demo_owner_token']
        );
        if (is_array($existing)) {
            $this->hadOwnerToken = true;
            $this->previousOwnerToken = (string) ($existing['value'] ?? '');
            $this->previousOwnerTokenGroup = (string) ($existing['group'] ?? 'cron');
        }
        settingModel()->set('demo_owner_token', 'demo-owner-token', 'system');
    }

    protected function tearDown(): void
    {
        if ($this->hadOwnerToken) {
            settingModel()->set('demo_owner_token', $this->previousOwnerToken, $this->previousOwnerTokenGroup);
            return;
        }

        db()->delete('settings', '`key` = ?', ['demo_owner_token']);
        settingModel()->clearCache();
    }

    public function testEverySupportedModeHasOneCanonicalContract(): void
    {
        self::assertTrue(DemoSandbox::isValidMode(DemoSandbox::MODE_OFF));
        self::assertTrue(DemoSandbox::isValidMode(DemoSandbox::MODE_READONLY));
        self::assertTrue(DemoSandbox::isValidMode(DemoSandbox::MODE_SANDBOX));
        self::assertFalse(DemoSandbox::isValidMode('3'));
    }

    public function testOwnerTokenUsesIndependentSecret(): void
    {
        self::assertTrue(DemoSandbox::ownerTokenMatches('demo-owner-token'));
        self::assertFalse(DemoSandbox::ownerTokenMatches('wrong-token'));
        self::assertFalse(DemoSandbox::ownerTokenMatches(''));
    }

    public function testCronTokenCannotManageSandboxOrIssueOwnerToken(): void
    {
        $before = settingModel()->get('cron_token', null);
        try {
            settingModel()->set('cron_token', 'cron-only-token', 'cron');
            self::assertFalse(DemoSandbox::ownerTokenMatches('cron-only-token'));
            settingModel()->set('demo_owner_token', '', 'system');
            self::assertFalse(DemoSandbox::ownerTokenMatches('cron-only-token'));
            self::assertSame('', settingModel()->get('demo_owner_token'));
            $token = DemoSandbox::ownerToken();
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
            self::assertSame($token, DemoSandbox::ownerToken());
            self::assertTrue(DemoSandbox::ownerTokenMatches($token));
            self::assertSame('cron-only-token', settingModel()->get('cron_token'));
        } finally {
            if ($before === null) db()->delete('settings', '`key` = ?', ['cron_token']);
            else settingModel()->set('cron_token', (string) $before, 'cron');
            settingModel()->clearCache();
        }
    }

    public function testExternalSideEffectPagesRemainProtectedInSandbox(): void
    {
        foreach ([
            'license.php', 'setting_email.php', 'setting_ai.php', 'setting_translate.php',
            'setting_api.php', 'setting_channel_translate.php', 'setting_product_cat_translate.php',
            'api_ai.php', 'api_ai_agent.php', 'api_ai_apply.php', 'api_ai_undo.php',
            'static_html.php', 'setting_seo.php', 'system.php',
        ] as $page) {
            self::assertTrue(DemoSandbox::isProtectedPage('/admin/' . $page), $page);
        }
    }

    /**
     * 口令校验必须早于**任何**动作分派。
     *
     * 这里刻意不写死动作名或变量名：那样锁的是实现字符串而不是行为，
     * 改个变量名就会误报，新增一个动作却不会被发现。
     * 断言口径是「第一次出现动作分派之前，已经出现过口令校验」——
     * 于是以后新增动作若插在闸门之前，这条会立刻红。
     */
    public function testOwnerTokenIsVerifiedBeforeAnyActionDispatch(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/setting_demo.php');

        $guard = strpos($source, 'DemoSandbox::ownerTokenMatches(');
        self::assertNotFalse($guard, 'setting_demo.php 必须真的校验站长口令。');

        self::assertSame(
            1,
            preg_match('/\$action\s*===|in_array\(\s*\$action/', $source, $m, PREG_OFFSET_CAPTURE),
            '找不到任何动作分派，断言口径需要复核。'
        );
        self::assertLessThan(
            $m[0][1],
            $guard,
            '存在早于口令校验的动作分派；公开演示站的超管账号可借此绕过站长口令。'
        );
    }

    public function testProtectedPagesAreBlockedBeforeRequestMethodBranch(): void
    {
        $auth = (string) file_get_contents(ROOT_PATH . '/admin/includes/auth.php');
        $protected = strpos($auth, 'DemoSandbox::isProtectedPage');
        $postBranch = strpos($auth, "if (\$_SERVER['REQUEST_METHOD'] === 'POST')");
        self::assertNotFalse($protected);
        self::assertNotFalse($postBranch);
        self::assertLessThan($postBranch, $protected);

        $mediaApi = (string) file_get_contents(ROOT_PATH . '/admin/media_api.php');
        self::assertStringContainsString("in_array(\$action, ['remote_list', 'remote_import'], true)", $mediaApi);
    }
}
