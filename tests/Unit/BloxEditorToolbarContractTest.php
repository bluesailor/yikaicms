<?php
/** Blox editor utility toolbar contract. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxEditorToolbarContractTest extends TestCase
{
    public function testCacheEndpointRequiresGlobalBloxPermissionAndCsrf(): void
    {
        $api = $this->source('admin/blox_cache_api.php');
        $policy = json_decode($this->source('config/blox-assets.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('checkLogin();', $api);
        self::assertStringContainsString("requirePermission('blox_global');", $api);
        self::assertStringContainsString("!== 'POST'", $api);
        self::assertStringContainsString('verifyCsrf();', $api);
        self::assertStringContainsString('HtmlCache::invalidate();', $api);
        self::assertStringContainsString("adminLog('cache', 'clear'", $api);
        self::assertContains('admin/blox_cache_api.php', $policy['core']);
    }

    public function testToolbarOffersCacheMaintenanceOnDesktopAndMobile(): void
    {
        $header = $this->source('admin/blox_editor/partials/header.php');
        $editor = $this->source('admin/blox_editor.php');

        self::assertStringContainsString('data-testid="blox-clear-cache"', $header);
        self::assertSame(2, substr_count($header, 'clearSiteCache()'));
        self::assertStringContainsString('<?php if ($canManageBloxDesign): ?>', $header);
        self::assertStringContainsString('fetch("/admin/blox_cache_api.php"', $editor);
        self::assertStringContainsString('new URLSearchParams({ _token: this.csrf })', $editor);
    }

    private function source(string $path): string
    {
        $file = ROOT_PATH . '/' . $path;
        if (!is_file($file) && str_starts_with($path, 'admin/blox_editor')) {
            // 付费 Blox 源码不随公开仓库分发；无注入的 CI 矩阵跳过，注入 job 与本地全量执行。
            self::markTestSkipped('付费 Blox 源码未注入：' . $path);
        }
        return (string) file_get_contents($file);
    }
}
