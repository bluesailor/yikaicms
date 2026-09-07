<?php

/**
 * 发布渠道核对的反例测试（R1）。
 *
 * 这些用例的价值全在「造出真实的失败场景，确认它真的红」。一个只测happy path
 * 的门禁等于没有门禁——v1.19.8 就是在「工具说通过」的情况下被记成发布完成的。
 *
 * 每个 fixture 都是一棵完整可用的假 workspace，只坏掉一个点，其余保持正确，
 * 这样断言失败时指向的就是那一个点，而不是一堆连锁噪音。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReleaseChannelAudit;

require_once ROOT_PATH . '/tools/ReleaseChannelAudit.php';

final class ReleaseChannelAuditTest extends TestCase
{
    private const VERSION = '9.9.9';

    private string $workspace = '';

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            self::removeTree($this->workspace);
        }
        $this->workspace = '';
    }

    // ── happy path：先证明这套 fixture 本身是干净的 ────────────────────

    public function testFullySyncedChannelsPassInBothModes(): void
    {
        $this->workspace = $this->buildFixture();

        $candidate = $this->audit(ReleaseChannelAudit::MODE_CANDIDATE);
        self::assertTrue($candidate['ok'], $this->describe($candidate));

        // 发布后模式必须带 fetcher，否则线上项记「未执行」——见下面的独立用例。
        $post = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, $this->okFetcher());
        self::assertTrue($post['ok'], $this->describe($post));
        foreach ($post['channels'] as $key => $channel) {
            self::assertSame(ReleaseChannelAudit::VERIFIED, $channel['status'], "渠道 {$key} 应为已验证");
        }
    }

    // ── 场景 1：官网停在旧版本 ─────────────────────────────────────────

    public function testStaleWebsiteVersionFails(): void
    {
        $this->workspace = $this->buildFixture();
        // 日文首页整体回退到上一版：最高版本低于目标。
        file_put_contents(
            $this->workspace . '/yikaicms.com.yikai/ja/index.html',
            $this->homeHtml('9.9.8')
        );

        $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, $this->okFetcher());
        self::assertFalse($report['ok']);
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['website']['status']);
        self::assertStringContainsString(
            '官网落后于本版',
            $this->detailOf($report['channels']['website'], '首页 ja 版本号')
        );
    }

    public function testChangelogWithTargetOnlyInHistoryFails(): void
    {
        $this->workspace = $this->buildFixture();
        // 目标版本出现在页面里，但不是最新条目——这正是 grep 式判据放过去的情况。
        file_put_contents(
            $this->workspace . '/yikaicms.com.yikai/changelog.html',
            '<h2>v9.9.8</h2><p>older</p><h2>v' . self::VERSION . '</h2><p>historical mention</p>'
        );

        // 候选阶段官网本来就允许没更新，所以这条只能在发布后模式判失败。
        $candidate = $this->audit(ReleaseChannelAudit::MODE_CANDIDATE);
        self::assertSame(ReleaseChannelAudit::SKIPPED, $candidate['channels']['website']['status']);

        $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, $this->okFetcher());
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['website']['status']);
        $detail = $this->detailOf($report['channels']['website'], '更新日志 zh-CN 最新条目');
        self::assertStringContainsString('最新条目是 9.9.8', $detail);
        self::assertStringContainsString('只出现在历史条目里', $detail);
    }

    // ── 场景 2：下载链接指向错误的包 ───────────────────────────────────

    public function testWrongDownloadTargetFails(): void
    {
        $this->workspace = $this->buildFixture();
        $path = $this->workspace . '/yikaicms.com.yikai/en/index.html';
        // 版本号文字都对，只有下载链接还指着旧包——最容易漏掉的一种。
        file_put_contents($path, str_replace(
            'yikaicms-v' . self::VERSION . '.zip',
            'yikaicms-v9.9.8.zip',
            (string) file_get_contents($path)
        ));

        $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, $this->okFetcher());
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['website']['status']);
        self::assertStringContainsString(
            '未找到指向本版完整包的下载链接',
            $this->detailOf($report['channels']['website'], '首页 en 下载入口')
        );
        // 版本号那一项仍应通过：诊断必须精确指向下载链接，而不是把整页判死。
        self::assertTrue($this->checkOf($report['channels']['website'], '首页 en 版本号')['ok']);
    }

    // ── 场景 3：本地归档缺失 ───────────────────────────────────────────

    public function testMissingLocalArchiveFailsEvenInCandidateMode(): void
    {
        $this->workspace = $this->buildFixture();
        unlink($this->workspace . '/yikaicms.yikai/releases/yikaicms-v' . self::VERSION . '.zip');

        // 归档不在 candidate_optional 里：包都没打出来，候选阶段也不该算通过。
        $report = $this->audit(ReleaseChannelAudit::MODE_CANDIDATE);
        self::assertFalse($report['ok']);
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['archive']['status']);
        self::assertStringContainsString('缺失', $this->detailOf($report['channels']['archive'], '正式完整包'));
    }

    // ── 场景 4：包哈希与记录不符 ───────────────────────────────────────

    public function testChecksumMismatchFails(): void
    {
        $this->workspace = $this->buildFixture();
        $zip = $this->workspace . '/yikaicms.yikai/releases/yikaicms-v' . self::VERSION . '.zip';
        file_put_contents($zip, 'tampered payload');

        $report = $this->audit(ReleaseChannelAudit::MODE_CANDIDATE);
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['archive']['status']);
        self::assertStringContainsString('不符', $this->detailOf($report['channels']['archive'], '校验文件'));
        self::assertStringContainsString(
            'artifact_sha256 与实际包不符',
            $this->detailOf($report['channels']['archive'], '构建证据')
        );
    }

    // ── 场景 5：某渠道未执行，且「未执行」不得冒充通过 ─────────────────

    public function testSkippedChannelBlocksPostReleaseButNotCandidate(): void
    {
        $this->workspace = $this->buildFixture();

        // 不给 fetcher：线上项只能记「未执行」。
        $candidate = $this->audit(ReleaseChannelAudit::MODE_CANDIDATE);
        self::assertTrue($candidate['ok'], '候选阶段允许线上尚未同步');
        self::assertSame(ReleaseChannelAudit::SKIPPED, $candidate['channels']['github']['status']);

        $post = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE);
        self::assertFalse($post['ok'], '发布后核对不能用跳过代替成功');
        self::assertSame(ReleaseChannelAudit::SKIPPED, $post['channels']['github']['status']);
    }

    // ── 线上异常：403 / 空响应 / 传输失败都不是成功 ────────────────────

    public function testOnlineFailuresAreNotTreatedAsSuccess(): void
    {
        $this->workspace = $this->buildFixture();

        $forbidden = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, static fn (string $url): array
            => ['status' => 403, 'type' => 'text/html', 'bytes' => 120, 'error' => '']);
        self::assertSame(ReleaseChannelAudit::FAILED, $forbidden['channels']['github']['status']);
        self::assertStringContainsString('HTTP 403', $this->detailOf($forbidden['channels']['github'], 'Release 页面'));

        $empty = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, static fn (string $url): array
            => ['status' => 200, 'type' => 'text/html', 'bytes' => 0, 'error' => '']);
        self::assertSame(ReleaseChannelAudit::FAILED, $empty['channels']['github']['status']);
        self::assertStringContainsString('响应体为空', $this->detailOf($empty['channels']['github'], 'Release 页面'));

        // 传输失败必须说成传输失败，不能伪装成「线上资源坏了」，也不许归因为 WAF。
        $broken = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, static fn (string $url): array
            => ['status' => 0, 'type' => '', 'bytes' => 0, 'error' => 'curl exit 6 could not resolve host']);
        $detail = $this->detailOf($broken['channels']['github'], 'Release 页面');
        self::assertStringContainsString('请求未完成', $detail);
        self::assertStringContainsString('could not resolve host', $detail);
    }

    // ── 演示站：线上版本是判据，本地副本只作上下文 ─────────────────────

    public function testDemoSiteStillOnTheOldVersionFails(): void
    {
        $this->workspace = $this->buildFixture();

        // 演示站没升级：前台资源查询串仍报上一版。这是「发布记为完成、演示站还没动」
        // 的唯一可公开观测信号。
        $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, $this->okFetcher('9.9.8'));
        self::assertFalse($report['ok']);
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['demo']['status']);
        self::assertStringContainsString('演示站尚未升级', $this->detailOf($report['channels']['demo'], '线上版本'));
    }

    public function testDemoVersionProbeGoingBlindIsAFailureNotAPass(): void
    {
        $this->workspace = $this->buildFixture();

        // 页面拿得到，但里面没有资源版本查询串——探针失效。这种情况必须报出来，
        // 否则「读不到版本」会被当成「版本没问题」。
        $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, static fn (string $url, bool $wantBody = false): array
            => ['status' => 200, 'type' => 'text/html', 'bytes' => 512, 'error' => '',
                'body' => $wantBody ? '<html><body>no assets here</body></html>' : '']);
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['demo']['status']);
        self::assertStringContainsString('探针可能已失效', $this->detailOf($report['channels']['demo'], '线上版本'));
    }

    public function testDemoLocalCopyIsContextNotAGate(): void
    {
        $this->workspace = $this->buildFixture();
        // fixture 里根本没有 demo 本地目录，线上版本正确即应通过。
        $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, $this->okFetcher());
        self::assertSame(ReleaseChannelAudit::VERIFIED, $report['channels']['demo']['status']);
        // 本地副本仍要出现在报告里，只是不参与判定。
        $local = $this->checkOf($report['channels']['demo'], '本地副本');
        self::assertNull($local['ok']);
        self::assertTrue($local['informational']);
    }

    // ── 模板市场：批准清单是唯一依据 ───────────────────────────────────

    public function testDelistedThemeReappearingFails(): void
    {
        $this->workspace = $this->buildFixture();
        $path = $this->workspace . '/update.yikaicms/data/themes.json';
        $registry = json_decode((string) file_get_contents($path), true);
        // 下架的主题被下一次打包悄悄放回来——这正是「从源码目录推导」会漏掉的。
        $registry['themes'][] = ['slug' => 'aurora', 'version' => '1.0.5', 'package' => 'aurora-v1.0.5.zip',
            'hash' => 'sha256:' . str_repeat('0', 64), 'sig' => 'x', 'requires_cms' => '>=9.9.9'];
        file_put_contents($path, json_encode($registry));

        // 下架是不变量：候选阶段也不许豁免，否则「下架」会被下一次打包悄悄撤销。
        $report = $this->audit(ReleaseChannelAudit::MODE_CANDIDATE);
        self::assertFalse($report['ok']);
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['market']['status']);
        self::assertStringContainsString('已决定下架', $this->detailOf($report['channels']['market'], '下架 aurora'));
    }

    public function testApprovedThemeMissingFromRegistryFails(): void
    {
        $this->workspace = $this->buildFixture();
        $path = $this->workspace . '/update.yikaicms/data/themes.json';
        $registry = json_decode((string) file_get_contents($path), true);
        $registry['themes'] = array_values(array_filter(
            $registry['themes'],
            static fn (array $t): bool => ($t['slug'] ?? '') !== 'minimal'
        ));
        file_put_contents($path, json_encode($registry));

        $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, $this->okFetcher());
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['market']['status']);
        self::assertStringContainsString('批准上架但注册表里没有', $this->detailOf($report['channels']['market'], '上架 minimal'));
    }

    // ── 目录缺失必须报错，不能静默跳过 ─────────────────────────────────

    public function testMissingWebsiteDirectoryIsAnError(): void
    {
        $this->workspace = $this->buildFixture();
        self::removeTree($this->workspace . '/yikaicms.com.yikai');

        $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, $this->okFetcher());
        self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['website']['status']);
        self::assertStringContainsString('找不到官网目录', $this->detailOf($report['channels']['website'], '官网目录'));
    }

    // ── 辅助 ──────────────────────────────────────────────────────────

    public function testOldDownloadCannotBeHiddenByCommentsScriptsOrPlainText(): void
    {
        $this->workspace = $this->buildFixture();
        $path = $this->workspace . '/yikaicms.com.yikai/index.html';
        $target = 'https://update.yikaicms.com/packages/yikaicms-v9.9.9.zip';
        foreach (['<!-- v9.9.9 ' . $target . ' -->', '<script>"v9.9.9 ' . $target . '"</script>', '<p>v9.9.9 ' . $target . '</p>'] as $noise) {
            file_put_contents($path, $this->homeHtml('9.9.8') . $noise);
            $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, $this->okFetcher());
            self::assertFalse($report['ok']);
            self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['website']['status']);
        }
    }

    public function testMissingUpdateDirectoryIsNeverCandidateOptional(): void
    {
        $this->workspace = $this->buildFixture();
        $config = $this->config();
        $config['update_server']['dir_default'] = 'missing-update';
        foreach ([ReleaseChannelAudit::MODE_CANDIDATE, ReleaseChannelAudit::MODE_POST_RELEASE] as $mode) {
            $report = ReleaseChannelAudit::run($config, self::VERSION, $mode, $this->workspace);
            self::assertFalse($report['ok']);
            self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['market']['status']);
        }
    }

    public function testMalformedDeltaManifestCannotPassCandidateArchive(): void
    {
        $this->workspace = $this->buildFixture();
        foreach (['{BROKEN JSON', '{}', '{"deltas":{}}', '{"deltas":[{"from":"9.9.8","package":"delta-9.9.8-to-9.9.9.zip"}]}'] as $invalid) {
            file_put_contents($this->workspace . '/yikaicms.yikai/releases/deltas-v9.9.9.json', $invalid);
            $report = $this->audit(ReleaseChannelAudit::MODE_CANDIDATE);
            self::assertFalse($report['ok']);
            self::assertSame(ReleaseChannelAudit::FAILED, $report['channels']['archive']['status']);
        }
    }

    public function testOnlineRequestCoverageIncludesEveryReleaseChannel(): void
    {
        $this->workspace = $this->buildFixture();
        $fetch = $this->okFetcher();
        $requests = [];
        $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, static function (string $url, bool $body) use ($fetch, &$requests): array {
            $requests[] = $url;
            return $fetch($url, $body);
        });
        self::assertTrue($report['ok'], $this->describe($report));
        foreach (['index.html', 'en/index.html', 'ja/index.html', 'changelog.html', 'en/changelog.html', 'ja/changelog.html'] as $page) {
            self::assertContains('https://www.yikaicms.com/' . $page, $requests);
        }
        foreach (['https://update.yikaicms.com/catalog.json', 'https://update.yikaicms.com/api/themes/list.php',
            'https://update.yikaicms.com/packages/yikaicms-v9.9.9.zip',
            'https://update.yikaicms.com/packages/delta-9.9.8-to-9.9.9.zip',
            'https://api.github.com/repos/bluesailor/yikaicms/releases/tags/v9.9.9',
            'https://api.github.com/repos/bluesailor/yikaicms/commits/v9.9.9',
            'https://github.com/bluesailor/yikaicms/releases/download/v9.9.9/yikaicms-v9.9.9.zip'] as $url) {
            self::assertContains($url, $requests);
        }
    }

    public function testUnavailableReadOnlyCatalogCannotClaimPostReleaseSuccess(): void
    {
        $this->workspace = $this->buildFixture();
        $config = $this->config();
        $config['update_server']['catalog_url'] = '';
        $report = ReleaseChannelAudit::run($config, self::VERSION, ReleaseChannelAudit::MODE_POST_RELEASE, $this->workspace, $this->okFetcher());
        self::assertFalse($report['ok']);
        self::assertSame(ReleaseChannelAudit::SKIPPED, $report['channels']['update_server']['status']);
        self::assertCount(2, array_filter($report['channels']['update_server']['checks'], static fn (array $check): bool => $check['name'] === 'Online package SHA256' && $check['ok'] === true));
    }

    public function testRemoteFailuresCannotBeMaskedByCorrectLocalFiles(): void
    {
        $this->workspace = $this->buildFixture();
        $fetch = $this->okFetcher();
        $cases = [
            ['website', 'https://www.yikaicms.com/ja/index.html', 'body', $this->homeHtml('9.9.8')],
            ['update_server', 'https://update.yikaicms.com/packages/yikaicms-v9.9.9.zip', 'status', 404],
            ['update_server', 'https://update.yikaicms.com/packages/delta-9.9.8-to-9.9.9.zip', 'sha256', hash('sha256', 'wrong')],
            ['update_server', 'https://update.yikaicms.com/catalog.json', 'body', '{"latest":"9.9.8","releases":[]}'],
            ['market', 'https://update.yikaicms.com/packages/themes/business-v1.0.15.zip', 'sha256', hash('sha256', 'wrong')],
            ['market', 'https://update.yikaicms.com/api/themes/list.php', 'body', '{"code":0,"data":{"themes":[{"slug":"aurora"}]}}'],
            ['github', 'https://api.github.com/repos/bluesailor/yikaicms/releases/tags/v9.9.9', 'body', '<html>Login</html>'],
            ['github', 'https://api.github.com/repos/bluesailor/yikaicms/releases/tags/v9.9.9', 'body', '{"draft":true,"prerelease":false,"tag_name":"v9.9.9","published_at":"2026-09-08T00:00:00Z","assets":[]}'],
            ['github', 'https://api.github.com/repos/bluesailor/yikaicms/releases/tags/v9.9.9', 'body', '{"draft":false,"prerelease":false,"tag_name":"v9.9.9","published_at":"2026-09-08T00:00:00Z","assets":[]}'],
            ['github', 'https://api.github.com/repos/bluesailor/yikaicms/commits/v9.9.9', 'body', '{"sha":"wrong"}'],
            ['github', 'https://github.com/bluesailor/yikaicms/releases/download/v9.9.9/yikaicms-v9.9.9.zip', 'sha256', hash('sha256', 'wrong')],
        ];
        foreach ($cases as [$channel, $target, $key, $value]) {
            $report = $this->audit(ReleaseChannelAudit::MODE_POST_RELEASE, static function (string $url, bool $body) use ($fetch, $target, $key, $value): array {
                $result = $fetch($url, $body);
                if ($url === $target) {
                    $result[$key] = $value;
                }
                return $result;
            });
            self::assertFalse($report['ok'], $target);
            self::assertSame(ReleaseChannelAudit::FAILED, $report['channels'][$channel]['status'], $target);
        }
    }

    /** @return array<string,mixed> */
    private function audit(string $mode, ?callable $fetcher = null): array
    {
        return ReleaseChannelAudit::run($this->config(), self::VERSION, $mode, $this->workspace, $fetcher);
    }

    /** @param string $demoAssetVersion 演示站前台资源查询串报出的版本 */
    private function okFetcher(string $demoAssetVersion = self::VERSION): callable
    {
        return function (string $url, bool $wantBody = false) use ($demoAssetVersion): array {
            $root = $this->workspace;
            $body = '<html>Release</html>';
            if (str_starts_with($url, 'https://www.yikaicms.com/')) {
                $path = $root . '/yikaicms.com.yikai/' . substr($url, strlen('https://www.yikaicms.com/'));
                if (!is_file($path)) {
                    return ['status' => 404, 'type' => 'text/html', 'bytes' => 0, 'error' => ''];
                }
                $body = (string) file_get_contents($path);
            } elseif (str_contains($url, 'demo.yikaicms.com')) {
                $body = '<script src="/assets/js/code-copy.js?v=' . $demoAssetVersion . '"></script>';
            } elseif (str_ends_with($url, '/catalog.json')) {
                $body = (string) file_get_contents($root . '/update.yikaicms/data/releases.json');
            } elseif (str_contains($url, '/api/themes/list.php')) {
                $data = json_decode((string) file_get_contents($root . '/update.yikaicms/data/themes.json'), true);
                foreach ($data['themes'] as &$theme) {
                    $theme['download_url'] = 'https://update.yikaicms.com/packages/themes/' . $theme['package'];
                }
                unset($theme);
                $body = (string) json_encode(['code' => 0, 'data' => $data]);
            } elseif (str_contains($url, 'api.github.com') && str_contains($url, '/commits/')) {
                $body = (string) json_encode(['sha' => str_repeat('a', 40)]);
            } elseif (str_contains($url, 'api.github.com')) {
                $body = (string) json_encode(['draft' => false, 'prerelease' => false, 'tag_name' => 'v9.9.9',
                    'published_at' => '2026-09-08T00:00:00Z', 'assets' => [
                        ['name' => 'yikaicms-v9.9.9.zip', 'state' => 'uploaded'],
                        ['name' => 'yikaicms-v9.9.9.sha256', 'state' => 'uploaded'],
                    ]]);
            } elseif (str_contains($url, '/packages/') || str_contains($url, '/releases/download/')) {
                $file = basename($url);
                $dir = str_contains($url, '/packages/themes/') ? '/update.yikaicms/packages/themes/' : '/yikaicms.yikai/releases/';
                $body = (string) file_get_contents($root . $dir . $file);
            }
            return ['status' => 200, 'type' => str_ends_with($url, '.zip') ? 'application/zip' : 'text/html',
                'bytes' => strlen($body), 'error' => '', 'body' => $wantBody ? $body : '', 'sha256' => hash('sha256', $body)];
        };
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        // dir_env 用测试专属名字，保证不被真实环境变量干扰。
        return [
            'schema' => 1,
            'website' => [
                'base_url' => 'https://www.yikaicms.com',
                'dir_env' => 'YK_TEST_WEBSITE_DIR',
                'dir_default' => 'yikaicms.com.yikai',
                'home_pages' => ['zh-CN' => 'index.html', 'en' => 'en/index.html', 'ja' => 'ja/index.html'],
                'changelog_pages' => ['zh-CN' => 'changelog.html', 'en' => 'en/changelog.html', 'ja' => 'ja/changelog.html'],
                'download_url' => 'https://update.yikaicms.com/packages/yikaicms-v{version}.zip',
            ],
            'archive' => [
                'dir_env' => 'YK_TEST_RELEASE_DIR',
                'dir_default' => 'yikaicms.yikai/releases',
                'package' => 'yikaicms-v{version}.zip',
                'checksum' => 'yikaicms-v{version}.sha256',
                'evidence' => 'yikaicms-v{version}.evidence.json',
                'delta_manifest' => 'deltas-v{version}.json',
            ],
            'update_server' => [
                'catalog_url' => 'https://update.yikaicms.com/catalog.json',
                'package_url' => 'https://update.yikaicms.com/packages/{package}',
                'dir_env' => 'YK_TEST_UPDATE_ROOT',
                'dir_default' => 'update.yikaicms',
                'catalog' => 'data/releases.json',
                'registry' => 'data/release-registry.json',
            ],
            'market' => [
                'registry_url' => 'https://update.yikaicms.com/api/themes/list.php',
                'registry' => 'data/themes.json',
                'approved' => ['business', 'minimal'],
                'delisted' => ['aurora', 'trade'],
                'package_url' => 'https://update.yikaicms.com/packages/themes/{package}',
            ],
            'demo' => [
                'dir_env' => 'YK_TEST_DEMO_DIR',
                'dir_default' => 'demo.yikaicms.yikai',
                'url' => 'https://demo.yikaicms.com/',
                'asset_version_pattern' => '/\/assets\/[^"\'>\s]+\?v=(\d+\.\d+\.\d+(?:\.\d+)?)/',
            ],
            'github' => [
                'repo' => 'bluesailor/yikaicms',
                'release_url' => 'https://github.com/bluesailor/yikaicms/releases/tag/v{version}',
            ],
            'candidate_optional' => ['website', 'update_server', 'market', 'github', 'demo'],
        ];
    }

    private function buildFixture(): string
    {
        $root = sys_get_temp_dir() . '/yikai-channel-' . bin2hex(random_bytes(6));
        foreach ([
            '/yikaicms.com.yikai/en', '/yikaicms.com.yikai/ja',
            '/yikaicms.yikai/releases',
            '/update.yikaicms/data', '/update.yikaicms/packages', '/update.yikaicms/packages/themes',
        ] as $dir) {
            mkdir($root . $dir, 0777, true);
        }

        $version = self::VERSION;
        foreach (['index.html', 'en/index.html', 'ja/index.html'] as $page) {
            file_put_contents($root . '/yikaicms.com.yikai/' . $page, $this->homeHtml($version));
        }
        foreach (['changelog.html', 'en/changelog.html', 'ja/changelog.html'] as $page) {
            file_put_contents(
                $root . '/yikaicms.com.yikai/' . $page,
                '<h2>v' . $version . '</h2><p>newest</p><h2>v9.9.8</h2><p>older</p><h2>v1.16.0</h2>'
            );
        }

        // 归档：完整包 + 校验 + 证据 + 一个 delta
        $releases = $root . '/yikaicms.yikai/releases';
        $fullName = "yikaicms-v{$version}.zip";
        file_put_contents($releases . '/' . $fullName, 'full package payload');
        $fullHash = hash_file('sha256', $releases . '/' . $fullName);
        file_put_contents($releases . "/yikaicms-v{$version}.sha256", "{$fullHash} *{$fullName}\n");
        $commit = str_repeat('a', 40);
        file_put_contents($releases . "/yikaicms-v{$version}.evidence.json", json_encode([
            'schema' => 1, 'version' => $version, 'artifact' => $fullName,
            'artifact_sha256' => $fullHash, 'source_commit' => $commit,
        ]));

        $deltaName = "delta-9.9.8-to-{$version}.zip";
        file_put_contents($releases . '/' . $deltaName, 'delta payload');
        $deltaHash = hash_file('sha256', $releases . '/' . $deltaName);
        $deltaEntry = ['from' => '9.9.8', 'package' => $deltaName, 'hash' => 'sha256:' . $deltaHash, 'size' => '13'];
        // 清单文件是「裸键值片段」，与 ReleaseUploadGuard 的读法保持一致。
        file_put_contents($releases . "/deltas-v{$version}.json", '"deltas": ' . json_encode([$deltaEntry]));

        // 升级服务器：包副本 + 目录 + 注册表
        $update = $root . '/update.yikaicms';
        copy($releases . '/' . $fullName, $update . '/packages/' . $fullName);
        copy($releases . '/' . $deltaName, $update . '/packages/' . $deltaName);
        file_put_contents($update . '/data/releases.json', json_encode([
            'latest' => $version,
            'releases' => [[
                'version' => $version, 'channel' => 'stable', 'min_php' => '8.0',
                'package' => $fullName, 'hash' => 'sha256:' . $fullHash,
                'deltas' => [$deltaEntry],
            ]],
        ]));
        file_put_contents($update . '/data/release-registry.json', json_encode([
            'versions' => [$version => ['channel' => 'stable']],
        ]));

        $themes = [];
        foreach (['business' => '1.0.15', 'minimal' => '1.0.17'] as $slug => $themeVersion) {
            $package = "{$slug}-v{$themeVersion}.zip";
            file_put_contents($update . '/packages/themes/' . $package, "{$slug} theme payload");
            $themes[] = [
                'slug' => $slug, 'version' => $themeVersion, 'package' => $package,
                'hash' => 'sha256:' . hash_file('sha256', $update . '/packages/themes/' . $package),
                'sig' => base64_encode('signature-' . $slug), 'requires_cms' => '>=' . $version,
            ];
        }
        file_put_contents($update . '/data/themes.json', json_encode(['updated_at' => gmdate('c'), 'themes' => $themes]));

        // ReleaseUploadGuard 会比对 mtime 构建窗口：让 delta 清单不早于完整包。
        $now = time();
        touch($releases . '/' . $fullName, $now);
        touch($releases . '/' . $deltaName, $now);
        touch($releases . "/deltas-v{$version}.json", $now);

        return $root;
    }

    private function homeHtml(string $version): string
    {
        return '<html><body><h1>YikaiCMS v' . $version . '</h1>'
            . '<p>自 v1.16.0 起采用《YikaiCMS 软件许可协议》</p>'
            . '<a href="https://update.yikaicms.com/packages/yikaicms-v' . $version . '.zip">下载 v' . $version . '</a>'
            . '<span>v' . $version . '</span></body></html>';
    }

    /** @param array<string,mixed> $channel @return array<string,mixed> */
    private function checkOf(array $channel, string $name): array
    {
        foreach ($channel['checks'] as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }
        self::fail("渠道里没有名为 {$name} 的检查项");
    }

    /** @param array<string,mixed> $channel */
    private function detailOf(array $channel, string $name): string
    {
        return (string) $this->checkOf($channel, $name)['detail'];
    }

    /** @param array<string,mixed> $report */
    private function describe(array $report): string
    {
        $lines = [];
        foreach ($report['channels'] as $key => $channel) {
            $lines[] = "{$key}={$channel['status']}";
            foreach ($channel['checks'] as $check) {
                if ($check['ok'] !== true) {
                    $lines[] = "    {$check['name']}: {$check['detail']}";
                }
            }
        }
        return implode("\n", $lines);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? self::removeTree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
