<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 演示模式的站长口令闸与三态收敛。
 *
 * 起因：DemoSandbox::ownerTokenMatches() 从设计出来就没有任何调用点
 * （方法上还留着「待 setting_demo.php 的切换/快照按钮接入」的注释），
 * 而 setting_demo.php 只有 requirePermission('*')。公开演示站的超管账号密码本身
 * 就是公开的，于是任何访客都能打开那一页把演示模式关掉，拿到一个完全可写的真站。
 * 更糟的是那页只认 '1'/'0'：沙盒站（mode=2）只要有人打开点一次保存，
 * 沙盒就被静默降级成关闭。
 *
 * 本测试锁住：三态判定不许再退化成两态，且切换入口必须真的验口令。
 */
final class DemoSandboxOwnerGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/DemoSandbox.php';
    }

    /** 沙盒不能被任何取值路径静默降级 */
    public function testSandboxModeSurvivesNormalization(): void
    {
        self::assertSame(DemoSandbox::MODE_SANDBOX, DemoSandbox::normalizeMode('2'));
        self::assertSame(DemoSandbox::MODE_SANDBOX, DemoSandbox::normalizeMode(2));
        self::assertSame(DemoSandbox::MODE_READONLY, DemoSandbox::normalizeMode('1'));
    }

    /** 非法输入一律落到 OFF：宁可把演示站误判成正常站被拦下，也不要把正常站误判成沙盒去跑重置 */
    public function testUnknownInputFallsBackToOff(): void
    {
        foreach (['', '3', 'on', 'true', 'sandbox', null, [], '0'] as $raw) {
            self::assertSame(DemoSandbox::MODE_OFF, DemoSandbox::normalizeMode($raw));
        }
    }

    /**
     * 空口令必须在查库之前就被拒绝。
     *
     * 「口令不为空但不正确」那条路径要读 settings 表拿 cron_token，单测环境没有库，
     * 所以不在这里断言；它由 setting_demo.php 的端到端验证覆盖（不带口令 / 错口令 /
     * 对口令三种情况，以及演示模式已开启时能否被关掉）。
     */
    public function testBlankOwnerTokenIsRejectedWithoutTouchingTheDatabase(): void
    {
        self::assertFalse(DemoSandbox::ownerTokenMatches(''), '空口令必须拒绝。');
        self::assertFalse(DemoSandbox::ownerTokenMatches('   '), '纯空白口令必须拒绝，且不该为此查一次库。');
        self::assertFalse(DemoSandbox::ownerTokenMatches("\t\n"), '空白字符口令必须拒绝。');
    }

    /**
     * 切模式 / 建快照 / 立即重置 这三个动作都必须在服务端验口令。
     * 这里对源码断言而不是发 HTTP：真正的回归形态是「有人把某个分支挪到闸外」，
     * 扫源码正好能在合并前抓住。
     */
    public function testAllHighRiskActionsAreBehindTheOwnerGate(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/setting_demo.php');
        self::assertNotFalse($source);

        self::assertStringContainsString(
            'DemoSandbox::ownerTokenMatches(',
            $source,
            'setting_demo.php 必须真的调用 ownerTokenMatches——它曾经全库零调用点。'
        );

        // 闸门不挑动作：本页所有 POST 都是高风险操作。用具名白名单意味着
        // 以后新增一个动作、忘了加进名单就会绕过闸门。
        self::assertDoesNotMatchRegularExpression(
            '/in_array\(\s*\$action\s*,\s*\[[^]]*\]\s*,\s*true\s*\)\s*\)\s*\{\s*\$(?:owner_?)?token/i',
            $source,
            '站长口令校验不该只覆盖一份具名动作白名单。'
        );

        // 三态单选，不能退回只认 0/1 的复选框
        self::assertStringContainsString('DemoSandbox::MODE_SANDBOX', $source, '演示模式页必须提供沙盒这一档。');
        self::assertStringNotContainsString(
            "post('demo_mode', '0') === '1' ? '1' : '0'",
            $source,
            '这是旧的两态写法：它会把沙盒站（mode=2）静默降级成关闭。'
        );
    }

    /**
     * 已配置的口令永远不回显。
     *
     * 只在「尚未配置」时显示一次——那是它刚被创建、除了这里没有别处能拿到的时刻。
     * 之后再印进 HTML 只会扩大暴露面（浏览器历史、截图、旁人一瞥），
     * 而这恰恰削弱了「只有能读库或登 shell 的人才知道口令」这条边界。
     */
    public function testConfiguredTokenIsNeverEchoedBack(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/setting_demo.php');
        self::assertNotFalse($source);

        self::assertStringContainsString(
            "\$ownerTokenConfigured = trim((string) settingModel()->get('cron_token', '')) !== '';",
            $source,
            '必须先判断口令是否已配置。'
        );
        self::assertStringContainsString(
            "\$issuedToken = \$ownerTokenConfigured ? '' : Cron::token();",
            $source,
            '只有在尚未配置时才允许签发并显示口令。'
        );
        self::assertStringNotContainsString(
            'e(Cron::token())',
            $source,
            '不得把已配置的站长口令直接回显到页面上。'
        );
    }
}
