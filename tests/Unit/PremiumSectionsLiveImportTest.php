<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxRemoteTemplateInstaller;
use BloxRemoteTemplateProvider;
use MarketDownloadUrl;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/License.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

/**
 * 精品区块：CMS 客户端对真实隔离市场服务的导入闸口（opt-in）。
 *
 * 由更新服务侧的 tests/premium-sections-http.php 逐个权益状态拉起（设置 YIKAI_PREMIUM_* 环境变量），
 * 不单独运行时整体跳过。被验证的是**导入接口**本身而非界面：
 *   ok               → 目录解锁、下载验签、两段式安装落为草稿（不自动发布）
 *   license_expired / domain_mismatch / disabled / license_required
 *                    → 目录锁定且原因准确、prepareInstall 直接拒绝、库里零写入
 *   bad_signature    → 服务端给了包，但验签失败时拒绝导入、库里零写入
 * 只用测试公钥；不读生产公钥、不连正式服务。
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PremiumSectionsLiveImportTest extends TestCase
{
    private const SAMPLE = 'hero-split';

    /** 服务端拒绝原因 → 客户端展示的文案键（测试环境 __() 原样返回键名）。 */
    private const MESSAGE_KEYS = [
        'license_expired' => 'blox_template_locked_expired',
        'domain_mismatch' => 'plugin_locked_domain',
        'disabled' => 'blox_template_locked_disabled',
        'license_required' => 'blox_template_locked_license',
    ];

    protected function schemaSql(): array
    {
        $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql');
        $statements = [];
        foreach (['blox_templates', 'blox_remote_template_states', 'blox_import_reviews'] as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE "yikai_' . $table . '" \([\s\S]*?\n\);/', $sql, $match));
            $statements[] = str_replace('"yikai_' . $table . '"', '"' . $table . '"', $match[0]);
        }
        return $statements;
    }

    public function testPremiumSectionImportFollowsTheServerEntitlement(): void
    {
        $base = (string) getenv('YIKAI_PREMIUM_BASE');
        if ($base === '') self::markTestSkipped('Opt-in: started by the update service premium-sections-http.php');
        self::assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#D', $base);
        $expect = (string) getenv('YIKAI_PREMIUM_EXPECT');
        $keyFile = (string) getenv('YIKAI_G5_TEST_PUBLIC_KEY');
        self::assertFileExists($keyFile);
        $publicKey = (string) file_get_contents($keyFile);

        if ($expect === 'bad_signature') {
            // 另起一把与服务端无关的钥匙：包字节没问题，但签名对不上这把钥匙
            $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            if (getenv('OPENSSL_CONF') === false && is_file(dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf')) {
                $options['config'] = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
            }
            $other = openssl_pkey_new($options);
            self::assertNotFalse($other);
            $publicKey = (string) openssl_pkey_get_details($other)['key'];
        }

        $provider = $this->provider($base, (string) getenv('YIKAI_PREMIUM_KEY'), (string) getenv('YIKAI_PREMIUM_DOMAIN'), $publicKey);
        $items = array_column($provider->installable(true), null, 'key');
        self::assertArrayHasKey('remote:' . self::SAMPLE, $items, '十二款摘要在任何权益状态下都可浏览');
        $item = $items['remote:' . self::SAMPLE];

        $installer = new BloxRemoteTemplateInstaller($provider);

        if ($expect === 'ok') {
            self::assertFalse($item['locked']);
            $review = $installer->prepareInstall(self::SAMPLE, 9);
            self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'), '评审阶段不写模板');
            $installed = $installer->confirmInstall($review['review_id'], [], 9);
            $row = bloxTemplateModel()->findForExport($installed['id']);
            self::assertSame(self::SAMPLE, $row['source_ref']);
            self::assertNull($row['published_data'], '导入只生成草稿，不自动发布');
            $document = json_decode((string) $row['draft_data'], true, 128, JSON_THROW_ON_ERROR);
            self::assertNotEmpty($document['sections'][0]['columns'][0]['elements']);
            return;
        }

        if ($expect === 'bad_signature') {
            self::assertFalse($item['locked'], '权益有效，服务端照常给包');
        } else {
            self::assertArrayHasKey($expect, self::MESSAGE_KEYS, 'unknown expectation ' . $expect);
            self::assertTrue($item['locked']);
            self::assertSame($expect, $item['locked_reason']);
        }

        try {
            $installer->prepareInstall(self::SAMPLE, 9);
            self::fail('导入应被拒绝：' . $expect);
        } catch (RuntimeException $error) {
            // 验签失败必须是「签名」这一步拒的，而不是哈希、网络或别的原因碰巧抛了异常
            $expectedMessage = $expect === 'bad_signature'
                ? 'blox_template_remote_signature_failed'
                : self::MESSAGE_KEYS[$expect];
            self::assertSame($expectedMessage, $error->getMessage(), '拒绝原因要准确');
        }
        self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'), '被拒绝时库里零写入');
        self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_import_reviews'), '被拒绝时不留评审记录');
    }

    private function provider(string $base, string $licenseKey, string $domain, string $publicKey): BloxRemoteTemplateProvider
    {
        return new BloxRemoteTemplateProvider(
            static function (string $url) use ($base, $licenseKey, $domain): string {
                $catalog = str_starts_with($url, BloxRemoteTemplateProvider::API_URL);
                if ($catalog) {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    unset($query['key'], $query['domain']);
                    if ($licenseKey !== '') $query['key'] = $licenseKey;
                    if ($domain !== '') $query['domain'] = $domain;
                    $target = $base . '/api/templates/list.php?' . http_build_query($query);
                } else {
                    // 客户端只接受固定官方端点的令牌地址；这里把它映射到隔离服务，令牌原样透传
                    self::assertTrue(MarketDownloadUrl::isTokenUrl($url));
                    $target = $base . '/api/market/download.php' . substr($url, strlen(MarketDownloadUrl::ENDPOINT));
                }
                $body = file_get_contents($target, false, stream_context_create(['http' => [
                    'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
                ]]));
                self::assertMatchesRegularExpression('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '', 'isolated market answered ' . ($http_response_header[0] ?? 'nothing'));
                self::assertIsString($body);
                if (!$catalog) return $body;
                $response = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                foreach ($response['data']['templates'] as &$entry) {
                    if (($entry['download_url'] ?? '') === '') continue;
                    self::assertStringStartsWith($base . '/api/market/download.php?token=', $entry['download_url']);
                    $entry['download_url'] = MarketDownloadUrl::ENDPOINT . substr($entry['download_url'], strlen($base . '/api/market/download.php'));
                }
                unset($entry);
                return json_encode($response, JSON_THROW_ON_ERROR);
            },
            static fn (string $canonical, string $signature): bool
                => openssl_verify($canonical, (string) base64_decode($signature, true), $publicKey, OPENSSL_ALGO_SHA256) === 1,
            'zh-CN'
        );
    }
}
