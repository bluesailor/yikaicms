<?php
/**
 * 控制台推荐插件清单（v1.18.6）：logo-maker 移出核心包后靠它引导安装。
 *
 * 锁三条语义：已安装的不再推荐（否则装完还在弹）、被忽略的不再出现、
 * 未知 slug 的忽略请求不写脏数据（dismiss 的入参来自前端）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecommendedPlugins;

require_once ROOT_PATH . '/includes/RecommendedPlugins.php';

final class RecommendedPluginsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['_test_config']['recommended_plugins_dismissed']);
    }

    public function testInstalledPluginIsNeverRecommended(): void
    {
        // 仓库里 logo-maker 目录始终存在（源码保留，只是不进发行包），
        // 因此本仓库环境下 pending() 恒为空——这正是「已装不再推荐」的口径。
        $this->assertTrue(RecommendedPlugins::isInstalled('logo-maker'));
        $this->assertSame([], RecommendedPlugins::pending());
    }

    public function testUnknownAndMalformedSlugsAreNotInstalled(): void
    {
        $this->assertFalse(RecommendedPlugins::isInstalled('no-such-plugin'));
        $this->assertFalse(RecommendedPlugins::isInstalled('../config'));
        $this->assertFalse(RecommendedPlugins::isInstalled(''));
    }

    public function testDismissIgnoresUnknownSlug(): void
    {
        // 入参来自前端，未登记的 slug 不得写进设置
        $before = $GLOBALS['_test_config']['recommended_plugins_dismissed'] ?? null;
        RecommendedPlugins::dismiss('totally-made-up');
        $this->assertSame($before, $GLOBALS['_test_config']['recommended_plugins_dismissed'] ?? null);
    }

    public function testDismissedSlugDropsOutOfPending(): void
    {
        $GLOBALS['_test_config']['recommended_plugins_dismissed'] = json_encode(['logo-maker']);
        $this->assertSame([], RecommendedPlugins::pending());
    }

    public function testCorruptDismissRecordDegradesToEmpty(): void
    {
        // 设置值被改坏不该炸控制台首页
        $GLOBALS['_test_config']['recommended_plugins_dismissed'] = '{not json';
        $this->assertIsArray(RecommendedPlugins::pending());
    }
}
