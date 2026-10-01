<?php
/**
 * 官方远程修复（2.0.3）：配方验签、SQL 白名单、执行与备份、去重与回报。
 *
 * 这条通道能在无人值守时改客户站的数据库，所以每一条「不该执行」的路径都钉死：
 * 签名、站点绑定、有效期、步骤类型、敏感表与敏感设置。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use InstallIdentity;
use PHPUnit\Framework\TestCase;
use RemoteRepair;

require_once ROOT_PATH . '/includes/permissions.php';
require_once ROOT_PATH . '/includes/InstallIdentity.php';
require_once ROOT_PATH . '/includes/RemoteRepair.php';

final class RemoteRepairTest extends TestCase
{
    private static string $privatePem = '';
    private static string $publicPem = '';

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        self::$privatePem = (string) $pem;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::$publicPem = (string) $details['key'];
    }

    protected function setUp(): void
    {
        db()->execute('CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, `key` TEXT UNIQUE, `value` TEXT, `group` TEXT, `name` TEXT, `tip` TEXT, sort_order INTEGER DEFAULT 0)');
        db()->execute('CREATE TABLE IF NOT EXISTS repair_items (id INTEGER PRIMARY KEY, title TEXT, color TEXT)');
        db()->execute('DELETE FROM repair_items');
        db()->execute("INSERT INTO repair_items (id, title, color) VALUES (1, 'a', '#000'), (2, 'b', '#000')");
        db()->execute("DELETE FROM settings WHERE `key` IN ('remote_repair_log', 'repair_probe')");
        settingModel()->clearCache();
        $_SERVER['HTTP_HOST'] = 'site.example';
    }

    protected function tearDown(): void
    {
        foreach (glob(ROOT_PATH . '/storage/backups/repair_rt-*.sql') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** @param array<string,mixed> $recipe */
    private function sign(array $recipe, array $over = []): array
    {
        $json = (string) json_encode($recipe, JSON_UNESCAPED_UNICODE);
        $item = $over + [
            'id' => 'rt-' . bin2hex(random_bytes(4)),
            'domain' => 'site.example',
            'install' => InstallIdentity::id(),
            'issued_at' => time() - 10,
            'expires_at' => time() + 3600,
            'recipe' => $json,
        ];
        $canonical = 'repair1|' . $item['domain'] . '|' . $item['install'] . '|' . $item['id'] . '|'
            . hash('sha256', (string) ($over['signed_recipe'] ?? $item['recipe'])) . '|' . $item['issued_at'] . '|' . $item['expires_at'];
        openssl_sign($canonical, $sig, self::$privatePem, OPENSSL_ALGO_SHA256);
        unset($item['signed_recipe']);
        return $item + ['sig' => base64_encode((string) $sig)];
    }

    public function testValidRecipeVerifies(): void
    {
        $item = $this->sign(['title' => 't', 'steps' => [['type' => 'cache_clear']]]);
        $ok = RemoteRepair::verifyWith($item, self::$publicPem);
        self::assertNotNull($ok);
        self::assertSame($item['id'], $ok['id']);
        self::assertSame('t', $ok['recipe']['title']);
    }

    public function testTamperedRecipeOtherSiteAndExpiredAreRejected(): void
    {
        $recipe = ['steps' => [['type' => 'cache_clear']]];
        // 配方原文被改：哈希对不上
        $tampered = $this->sign($recipe, ['signed_recipe' => (string) json_encode($recipe)]);
        $tampered['recipe'] = (string) json_encode(['steps' => [['type' => 'setting', 'key' => 'site_name', 'value' => 'x']]]);
        self::assertNull(RemoteRepair::verifyWith($tampered, self::$publicPem));
        // 同域名下别的站
        self::assertNull(RemoteRepair::verifyWith($this->sign($recipe, ['install' => str_repeat('0', 32)]), self::$publicPem));
        // 别的域名
        self::assertNull(RemoteRepair::verifyWith($this->sign($recipe, ['domain' => 'other.example']), self::$publicPem));
        // 过期、有效期过长
        self::assertNull(RemoteRepair::verifyWith($this->sign($recipe, ['expires_at' => time() - 1]), self::$publicPem));
        self::assertNull(RemoteRepair::verifyWith($this->sign($recipe, ['issued_at' => time(), 'expires_at' => time() + RemoteRepair::MAX_TTL + 60]), self::$publicPem));
        // 别的私钥签的
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $otherPub = (string) openssl_pkey_get_details($other)['key'];
        self::assertNull(RemoteRepair::verifyWith($this->sign($recipe), $otherPub));
        // 非法 id
        self::assertNull(RemoteRepair::verifyWith($this->sign($recipe, ['id' => '../x']), self::$publicPem));
    }

    public function testSqlAllowlist(): void
    {
        foreach ([
            "UPDATE {p}channels SET color = '#fff' WHERE id = 3",
            "UPDATE {p}channels SET title = 'it''s; -- fine' WHERE id = 3",
            'ALTER TABLE {p}users ADD COLUMN note TEXT',
            'CREATE INDEX idx_slug ON {p}contents (slug)',
            'CREATE TABLE IF NOT EXISTS {p}repair_new (id INTEGER)',
            'DELETE FROM {p}content_revisions WHERE created_at < 1',
        ] as $ok) {
            self::assertNull(RemoteRepair::sqlProblem($ok, false), $ok);
        }
        foreach ([
            'UPDATE {p}channels SET a = 1; DROP TABLE {p}contents',
            'UPDATE {p}channels SET a = 1 # x',
            'UPDATE {p}channels SET a = 1 /* x */',
            "UPDATE {p}channels SET a = 'x",
            'UPDATE channels SET a = 1',
            'DROP TABLE {p}contents',
            'TRUNCATE {p}contents',
            'UPDATE {p}users SET password = 1',
            'UPDATE {p}contents SET a = (SELECT password FROM {p}users LIMIT 1)',
            'DELETE FROM {p}members',
            'UPDATE {p}shop_orders SET status = 1',
            'UPDATE {p}settings SET value = 1',
            "SELECT LOAD_FILE('/etc/passwd') INTO OUTFILE '/tmp/x'",
        ] as $bad) {
            self::assertNotNull(RemoteRepair::sqlProblem($bad, false), $bad);
        }
        self::assertNull(RemoteRepair::sqlProblem("SELECT COUNT(*) FROM {p}contents WHERE x = 'sleep'", true));
        self::assertNotNull(RemoteRepair::sqlProblem('SELECT SLEEP(5)', true));
        self::assertNotNull(RemoteRepair::sqlProblem('SELECT COUNT(*) FROM {p}users', true));
        self::assertNotNull(RemoteRepair::sqlProblem('UPDATE {p}contents SET a = 1', true));
    }

    public function testProtectedSettingsCannotBeChanged(): void
    {
        foreach (['managed_upgrade_enabled', 'auto_upgrade_enabled', 'license_key', 'smtp_pass', 'cron_token', 'support_access_until', 'remote_repair_log', 'update_channel',
            'mail_admin', 'smtp_host', 'upload_file_types', 'trusted_proxies', 'custom_head_code', 'custom_body_code', 'site_url', 'demo_mode'] as $key) {
            self::assertNotNull(RemoteRepair::stepProblem(['type' => 'setting', 'key' => $key, 'value' => '1']), $key);
        }
        foreach (['site_name', 'contact_qrcode', 'header_sticky', 'html_cache_ttl'] as $key) {
            self::assertNull(RemoteRepair::stepProblem(['type' => 'setting', 'key' => $key, 'value' => 'x']), $key);
        }
        self::assertNotNull(RemoteRepair::stepProblem(['type' => 'php', 'code' => 'phpinfo();']));
        self::assertNotNull(RemoteRepair::stepProblem(['type' => 'file', 'path' => 'index.php']));
    }

    public function testRunAppliesStepsAndBacksUpTouchedTables(): void
    {
        $result = RemoteRepair::run('rt-apply', ['steps' => [
            ['type' => 'sql', 'sql' => "UPDATE {p}repair_items SET color = '#fff' WHERE id = 1"],
            ['type' => 'setting', 'key' => 'repair_probe', 'value' => 'done'],
        ]]);
        self::assertSame('ok', $result['status'], $result['msg']);
        self::assertSame('#fff', db()->fetchColumn('SELECT color FROM repair_items WHERE id = 1'));
        self::assertSame('#000', db()->fetchColumn('SELECT color FROM repair_items WHERE id = 2'));
        settingModel()->clearCache();
        self::assertSame('done', settingModel()->get('repair_probe', ''));
        self::assertStringStartsWith('repair_rt-apply_', $result['backup']);
        $backup = (string) file_get_contents(ROOT_PATH . '/storage/backups/' . $result['backup']);
        self::assertStringContainsString('repair_items', $backup);
    }

    public function testInvalidStepRejectsWholeRecipeBeforeAnyChange(): void
    {
        $result = RemoteRepair::run('rt-reject', ['steps' => [
            ['type' => 'sql', 'sql' => "UPDATE {p}repair_items SET color = '#fff'"],
            ['type' => 'sql', 'sql' => 'DROP TABLE {p}repair_items'],
        ]]);
        self::assertSame('rejected', $result['status']);
        self::assertSame('#000', db()->fetchColumn('SELECT color FROM repair_items WHERE id = 1'));
    }

    public function testWhenAndVersionGateSkipWithoutChanges(): void
    {
        $steps = [['type' => 'sql', 'sql' => "UPDATE {p}repair_items SET color = '#fff'"]];
        $notNeeded = RemoteRepair::run('rt-when', ['when' => "SELECT COUNT(*) FROM {p}repair_items WHERE color = '#abc'", 'steps' => $steps]);
        self::assertSame('not_needed', $notNeeded['status']);
        $notApplicable = RemoteRepair::run('rt-ver', ['versions' => ['min' => '99.0.0'], 'steps' => $steps]);
        self::assertSame('not_applicable', $notApplicable['status']);
        self::assertSame('#000', db()->fetchColumn('SELECT color FROM repair_items WHERE id = 1'));
    }

    public function testFailureMessageDoesNotLeakValues(): void
    {
        db()->execute('CREATE TABLE IF NOT EXISTS repair_unique (email TEXT UNIQUE)');
        db()->execute('DELETE FROM repair_unique');
        db()->execute("INSERT INTO repair_unique (email) VALUES ('someone@example.com')");
        $result = RemoteRepair::run('rt-fail', ['steps' => [
            ['type' => 'sql', 'sql' => "INSERT INTO {p}repair_unique (email) VALUES ('someone@example.com')"],
        ]]);
        self::assertSame('failed', $result['status']);
        self::assertStringNotContainsString('someone@example.com', $result['msg']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testProcessRunsOnceAndReportsResult(): void
    {
        define('LICENSE_PUBKEY_B64', (string) preg_replace('/-----[^-]+-----|\s/', '', self::$publicPem));
        require_once ROOT_PATH . '/includes/License.php';
        $item = $this->sign(['title' => 'Fix colors', 'steps' => [['type' => 'sql', 'sql' => "UPDATE {p}repair_items SET color = '#fff' WHERE id = 2"]]]);

        self::assertSame(1, RemoteRepair::process([$item]));
        self::assertSame('#fff', db()->fetchColumn('SELECT color FROM repair_items WHERE id = 2'));
        // 服务器在收到回报前会重复下发：同一 id 不再执行
        db()->execute("UPDATE repair_items SET color = '#000' WHERE id = 2");
        self::assertSame(0, RemoteRepair::process([$item]));
        self::assertSame('#000', db()->fetchColumn('SELECT color FROM repair_items WHERE id = 2'));

        $log = RemoteRepair::log();
        self::assertSame($item['id'], $log[0]['id']);
        self::assertSame('Fix colors', $log[0]['title']);
        self::assertSame(['repairs' => $item['id'] . ':ok'], RemoteRepair::reportParams());
    }
}
