<?php
/**
 * 多套 AI API 配置（2.1.2）：旧单配置承接为 1 号配置、密钥加密与脱敏、切换主 API 后真实请求的去向、
 * 翻译模型随主 API 走、归档与删除的规则、新存储键不随配方/模板导出。
 * 请求去向用本地模拟上游（tests/fixtures/ai-mock-router.php）核对，不碰真实接口。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiProfiles;
use AiService;
use InvalidArgumentException;
use SensitiveSettings;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/SecretStore.php';
require_once ROOT_PATH . '/includes/AiService.php';
require_once ROOT_PATH . '/includes/AiProfiles.php';
require_once ROOT_PATH . '/includes/SensitiveSettings.php';

final class AiProfilesTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';
    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        if (!defined('ENCRYPT_KEY')) define('ENCRYPT_KEY', 'unit-test-encrypt-key-0123456789');
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($probe, $errstr);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        self::$log = tempnam(sys_get_temp_dir(), 'ai-mock-');
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", ROOT_PATH . '/tests/fixtures/ai-mock-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$log . '.out', 'a'], 2 => ['file', self::$log . '.out', 'a']],
            $pipes,
            ROOT_PATH,
            array_merge(getenv(), ['AI_MOCK_LOG' => self::$log])
        );
        self::$base = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 50; $i++) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($sock) { fclose($sock); return; }
            usleep(100000);
        }
        self::fail('mock AI upstream did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) proc_terminate(self::$server);
        foreach ([self::$log, self::$log . '.out'] as $f) if (is_file($f)) @unlink($f);
    }

    protected function setUp(): void
    {
        parent::setUp();
        settingModel()->clearCache();
        file_put_contents(self::$log, '');
    }

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT NOT NULL DEFAULT \'basic\', "key" TEXT NOT NULL UNIQUE, value TEXT, name TEXT, tip TEXT, type TEXT)',
            'CREATE TABLE ai_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT, model TEXT, action TEXT, prompt_tokens INTEGER, completion_tokens INTEGER, total_tokens INTEGER, success INTEGER, error_msg TEXT, admin_id INTEGER, created_at TEXT)',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function requests(): array
    {
        $rows = [];
        foreach (file(self::$log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) $rows[] = json_decode($line, true);
        return $rows;
    }

    private function setting(string $key): string
    {
        return (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [$key]);
    }

    /** 生产的 config() 读 settings 表；测试启动文件的 config() 读 $GLOBALS['_test_config']，构造 AiService 前对齐 */
    private function ai(?string $model = null): AiService
    {
        $GLOBALS['_test_config'] = array_column(db()->fetchAll('SELECT "key", value FROM settings'), 'value', 'key');
        return new AiService(null, null, $model ?? null);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_config']);
        parent::tearDown();
    }

    public function testLegacySingleConfigIsProfileOneUntilSavedAndNeverDuplicated(): void
    {
        $sealed = AiService::encryptKey('sk-legacy-9999');
        foreach (['ai_provider' => 'custom_openai', 'ai_base_url' => 'https://api.example.test/v1', 'ai_api_key' => $sealed, 'ai_model' => 'Qwen/Qwen2.5-7B-Instruct', 'ai_translation_model' => 'Qwen/Qwen2.5-72B-Instruct'] as $k => $v) {
            settingModel()->set($k, $v);
        }
        $profiles = AiProfiles::all();
        self::assertCount(1, $profiles);
        self::assertSame(1, $profiles[0]['id']);
        self::assertSame('custom_openai', $profiles[0]['provider']);
        self::assertSame('Qwen/Qwen2.5-72B-Instruct', $profiles[0]['translation_model'], '翻译模型覆盖随旧配置承接');
        self::assertSame(1, AiProfiles::activeId());
        self::assertSame('', $this->setting('ai_profiles'), '只读不写：没保存前不落库');

        // 第一次保存旧配置：落成 1 号，旧键原样保留（密钥没改就不重新加密），再加一套也不会重复生成 1 号
        AiProfiles::save(1, ['name' => '硅基流动', 'provider' => 'custom_openai', 'base_url' => 'https://api.example.test/v1', 'model' => 'Qwen/Qwen2.5-7B-Instruct', 'translation_model' => 'Qwen/Qwen2.5-72B-Instruct'], '***9999');
        $second = AiProfiles::save(0, ['name' => 'DeepSeek', 'provider' => 'deepseek'], 'sk-deep-1111');
        self::assertSame(2, $second);
        self::assertSame([1, 2], array_column(AiProfiles::all(), 'id'));
        self::assertSame($sealed, $this->setting('ai_api_key'), '主 API 的密钥未改动');
        self::assertSame(1, AiProfiles::activeId());
    }

    public function testKeysAreSealedMaskedAndBlankInputKeepsThem(): void
    {
        $a = AiProfiles::save(0, ['name' => 'A', 'provider' => 'openai'], 'sk-alpha-1234');
        $b = AiProfiles::save(0, ['name' => 'B', 'provider' => 'deepseek'], 'sk-bravo-5678');
        $stored = $this->setting('ai_profiles');
        self::assertStringNotContainsString('sk-alpha-1234', $stored);
        self::assertStringNotContainsString('sk-bravo-5678', $stored);
        self::assertSame($a, AiProfiles::activeId(), '第一套自动成为主 API');

        $view = AiProfiles::publicView(AiProfiles::find($b));
        self::assertArrayNotHasKey('api_key', $view, '页面拿不到密文');
        self::assertStringEndsWith('5678', $view['key_masked']);
        self::assertStringNotContainsString('sk-bravo', $view['key_masked']);

        AiProfiles::save($b, ['name' => 'B2', 'provider' => 'deepseek'], '');
        AiProfiles::save($b, ['name' => 'B3', 'provider' => 'deepseek'], $view['key_masked']);
        self::assertSame('sk-bravo-5678', AiProfiles::plainKey($b), '空输入和脱敏值都不清空已存密钥');
        self::assertSame('B3', AiProfiles::find($b)['name']);
        self::assertSame('sk-alpha-1234', AiService::decryptKey($this->setting('ai_api_key')), '改非主 API 不影响旧键');
    }

    /** 主 API 切换后，AiService 的默认配置与翻译模型都跟着走，真实请求到了所选配置的地址、模型、密钥 */
    public function testSwitchingPrimaryRoutesActualRequestsWithoutMixingProviders(): void
    {
        $a = AiProfiles::save(0, ['name' => 'A', 'provider' => 'custom_openai', 'base_url' => self::$base . '/a/v1', 'model' => 'model-a', 'translation_model' => 'translate-a'], 'key-aaaa');
        $b = AiProfiles::save(0, ['name' => 'B', 'provider' => 'custom_anthropic', 'base_url' => self::$base . '/b/v1', 'model' => 'model-b'], 'key-bbbb');

        $reply = $this->ai()->chat('ping');
        self::assertTrue($reply['success'], (string) $reply['error']);
        $translation = $this->ai($this->setting('ai_translation_model'))->chat('ping');
        self::assertTrue($translation['success']);

        AiProfiles::setActive($b);
        self::assertSame('', $this->setting('ai_translation_model'), '新主 API 没设翻译模型：不残留旧服务商的模型名');
        $afterSwitch = $this->ai()->chat('ping');
        self::assertTrue($afterSwitch['success'], (string) $afterSwitch['error']);
        $translationAfter = $this->ai($this->setting('ai_translation_model'))->chat('ping');
        self::assertTrue($translationAfter['success']);

        $req = $this->requests();
        self::assertCount(4, $req);
        self::assertSame(['/a/v1/chat/completions', 'model-a', 'Bearer key-aaaa'], [$req[0]['path'], $req[0]['body']['model'], $req[0]['auth']]);
        self::assertSame(['/a/v1/chat/completions', 'translate-a', 'Bearer key-aaaa'], [$req[1]['path'], $req[1]['body']['model'], $req[1]['auth']]);
        self::assertSame(['/b/v1/messages', 'model-b', 'key-bbbb', ''], [$req[2]['path'], $req[2]['body']['model'], $req[2]['x_api_key'], $req[2]['auth']]);
        self::assertSame(['/b/v1/messages', 'model-b', 'key-bbbb'], [$req[3]['path'], $req[3]['body']['model'], $req[3]['x_api_key']], '翻译跟随新主 API 的默认模型');
        self::assertSame(
            [['custom_openai', 'model-a'], ['custom_openai', 'translate-a'], ['custom_anthropic', 'model-b'], ['custom_anthropic', 'model-b']],
            array_map(static fn(array $r): array => [$r['provider'], $r['model']], db()->fetchAll('SELECT provider, model FROM ai_logs ORDER BY id')),
            '调用日志记下的是实际使用的服务商与模型'
        );
        self::assertSame($a, (int) AiProfiles::find($a)['id']);
    }

    /** 本轮翻译用到的 JSON 输出与错误处理在多配置下照旧：不支持 JSON 模式时回退普通请求，上游故障返回可读错误 */
    public function testJsonModeFallbackAndUpstreamErrorsSurvive(): void
    {
        $strict = AiProfiles::save(0, ['name' => 'Strict', 'provider' => 'custom_openai', 'base_url' => self::$base . '/strict/v1', 'model' => 'm'], 'key-s');
        $result = $this->ai()->chat('{"a":1}', 'json please', 0.1, true);
        self::assertTrue($result['success'], (string) $result['error']);
        $req = $this->requests();
        self::assertCount(2, $req, '先带 response_format 被拒，再普通请求');
        self::assertArrayHasKey('response_format', $req[0]['body']);
        self::assertArrayNotHasKey('response_format', $req[1]['body']);

        $fail = AiProfiles::save(0, ['name' => 'Down', 'provider' => 'custom_openai', 'base_url' => self::$base . '/fail/v1', 'model' => 'm'], 'key-f');
        AiProfiles::setActive($fail);
        $down = $this->ai()->chat('ping');
        self::assertFalse($down['success']);
        self::assertStringContainsString('mock upstream down', $down['error']);
        self::assertStringNotContainsString('key-f', $down['error'], '错误信息不带密钥');
        AiProfiles::setActive($strict);
        self::assertSame($strict, AiProfiles::activeId());
    }

    public function testArchiveRestoreDeleteRules(): void
    {
        $a = AiProfiles::save(0, ['name' => 'A', 'provider' => 'openai'], 'k1');
        $b = AiProfiles::save(0, ['name' => 'B', 'provider' => 'deepseek'], 'k2');
        $expectError = static function (callable $run, string $key): void {
            try { $run(); } catch (InvalidArgumentException $e) { self::assertSame($key, $e->getMessage()); return; }
            self::fail("expected {$key}");
        };
        $expectError(static fn() => AiProfiles::archive($a), 'ai_profile_primary_locked');
        $expectError(static fn() => AiProfiles::delete($a), 'ai_profile_primary_locked');
        $expectError(static fn() => AiProfiles::delete($b), 'ai_profile_archive_first');
        AiProfiles::archive($b);
        $expectError(static fn() => AiProfiles::setActive($b), 'ai_profile_archived_cannot_be_primary');
        self::assertSame('k2', AiProfiles::plainKey($b), '归档保留密钥，可恢复');
        AiProfiles::restore($b);
        AiProfiles::setActive($b);
        AiProfiles::archive($a);
        AiProfiles::delete($a);
        self::assertNull(AiProfiles::find($a));
        self::assertSame($b, AiProfiles::activeId());
        $expectError(static fn() => AiProfiles::save(99, ['provider' => 'openai'], ''), 'ai_profile_not_found');
        $expectError(static fn() => AiProfiles::save(0, ['provider' => 'no-such-provider'], ''), 'ai_profile_bad_provider');
        self::assertSame($b, AiProfiles::activeId(), '失败的操作不改原配置');
    }

    public function testFreshSiteHasNoProfilesAndFirstSaveBecomesPrimary(): void
    {
        self::assertSame([], AiProfiles::all());
        self::assertSame(0, AiProfiles::activeId());
        $id = AiProfiles::save(0, ['name' => '', 'provider' => 'deepseek'], 'sk-first');
        self::assertSame($id, AiProfiles::activeId());
        self::assertSame('DeepSeek', AiProfiles::find($id)['name'], '名称留空时用服务商名');
        self::assertSame('deepseek', $this->setting('ai_provider'));
        self::assertSame('sk-first', AiService::decryptKey($this->setting('ai_api_key')));
    }

    /** 新的存储键必须纳入敏感排除：配方、整站模板、AI 助手都拿不到；主 API 编号与旧单配置键不随导出外流、也不能被导入 */
    public function testNewStorageKeysStayOutOfExportsAndImports(): void
    {
        self::assertTrue(isSensitiveSettingKey('ai_profiles'));
        foreach (['ai_profiles', 'ai_active_profile', 'ai_provider', 'ai_base_url', 'ai_model', 'ai_translation_model', 'ai_api_key'] as $key) {
            self::assertTrue(SensitiveSettings::isSensitive($key), "{$key} 不该导出");
            self::assertFalse(SensitiveSettings::isImportable($key), "{$key} 不该被配方写入");
        }
        AiProfiles::save(0, ['name' => 'A', 'provider' => 'openai'], 'sk-export-check');
        $exported = SensitiveSettings::filterExportable(array_column(db()->fetchAll('SELECT "key", value FROM settings'), 'value', 'key'));
        foreach (['ai_profiles', 'ai_api_key', 'ai_provider', 'ai_active_profile'] as $key) {
            self::assertArrayNotHasKey($key, $exported['settings']);
        }
    }
}
