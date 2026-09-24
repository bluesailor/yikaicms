<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/MarketDownloadStatus.php';
require_once __DIR__ . '/MarketCatalogItems.php';
require_once __DIR__ . '/MarketCatalogRequest.php';
require_once __DIR__ . '/MarketDownloadUrl.php';
require_once __DIR__ . '/PluginMarketPackage.php';
require_once __DIR__ . '/PluginInstaller.php';

/**
 * 从官方插件市场安装插件：插件页的「安装 / 升级」与整站模板导入「一并安装所需插件」共用这一条链，
 * 不各写一份。顺序固定：下载状态 → 来源 → 官方地址 → 不降级 → 限量下载 → sha256 → RSA 验签 → PluginInstaller。
 * 以服务端重新拉取的市场元数据为准，不信任调用方传来的 URL / 哈希。安装后不自动启用。
 */
final class PluginMarketInstall
{
    public const API = 'https://update.yikaicms.com/api/plugins/list.php';

    /** @var Closure(string,int,int):array{body:?string,status:int,too_large:bool} */
    private Closure $httpGet;

    /** @param null|Closure(string,int,int):array{body:?string,status:int,too_large:bool} $httpGet 测试注入 */
    public function __construct(private string $root, ?Closure $httpGet = null)
    {
        $this->httpGet = $httpGet ?? static function (string $url, int $timeout, int $maxBytes): array {
            $status = 0;
            $tooLarge = false;
            $body = self::httpGet($url, $timeout, $status, $maxBytes, $tooLarge);
            return ['body' => $body, 'status' => $status, 'too_large' => $tooLarge];
        };
    }

    /**
     * 市场目录（带本站授权码与域名：付费插件的下载地址由服务端按授权下发）。
     * @return null|list<array<string,mixed>> 取不到或格式不对时 null
     */
    public function catalog(string $query = ''): ?array
    {
        $response = ($this->httpGet)(self::API . '?' . MarketCatalogRequest::query($query), 15, 0);
        $data = MarketCatalogRequest::decode($response['body'], 'plugins');
        if ($data === null) return null;
        return MarketCatalogItems::select(is_array($data['data']['plugins'] ?? null) ? $data['data']['plugins'] : []);
    }

    /** @param list<array<string,mixed>> $catalog @return null|array<string,mixed> */
    public static function find(array $catalog, string $slug): ?array
    {
        foreach ($catalog as $item) {
            if (($item['slug'] ?? '') === $slug) return $item;
        }
        return null;
    }

    /**
     * @param null|list<array<string,mixed>> $catalog 已拉取的目录（批量安装时复用）；null 时现拉
     * @return array{ok:bool,msg:string,slug:string,version:string}
     */
    public function install(string $slug, ?array $catalog = null): array
    {
        $fail = static fn(string $msg): array => ['ok' => false, 'msg' => $msg, 'slug' => '', 'version' => ''];
        if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/D', $slug) !== 1) return $fail(__('pl_bad_slug'));
        $catalog ??= $this->catalog();
        if ($catalog === null) return $fail(__('pl_market_offline'));
        $item = self::find($catalog, $slug);
        if ($item && MarketDownloadStatus::reason($item) !== '') {
            return $fail(MarketDownloadStatus::message(MarketDownloadStatus::reason($item), (string) ($item['requires_cms'] ?? '')));
        }
        if (!$item || empty($item['download_url']) || empty($item['hash'])) return $fail(__('pl_not_in_market'));

        $origin = (string) ($item['source'] ?? 'official');
        try {
            (new PluginInstaller($this->root . '/plugins', $this->root . '/storage'))->assertOrigin($slug, $origin);
        } catch (RuntimeException $error) {
            return $fail(self::errorMessage($error->getMessage()));
        }

        // 下载前的本地边界：只向官方包路径或市场令牌地址发请求，且不把已装插件换成旧版本
        $marketVersion = (string) ($item['version'] ?? '');
        if (!PluginMarketPackage::isOfficialUrl((string) $item['download_url'], $slug, $marketVersion)) {
            self::log('market_install_blocked', 'Untrusted plugin download URL: ' . $slug);
            return $fail(__('pl_download_untrusted'));
        }
        $installedVersion = PluginMarketPackage::installedVersion($this->root . '/plugins', $slug);
        if (PluginMarketPackage::isDowngrade($installedVersion, $marketVersion)) {
            self::log('market_install_blocked', 'Plugin marketplace downgrade blocked: ' . $slug
                . ' local=' . $installedVersion . ' remote=' . $marketVersion);
            return $fail(__('pl_downgrade_refused'));
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'ykplg');
        if (!is_string($tmpZip)) return $fail(__('pl_download_failed'));
        try {
            $download = ($this->httpGet)((string) $item['download_url'], 120, PluginMarketPackage::MAX_PACKAGE_BYTES);
            if ($download['body'] === null || file_put_contents($tmpZip, $download['body']) === false) {
                return $fail($download['too_large'] ? __('pl_package_too_large')
                    : ($download['body'] === null ? MarketDownloadStatus::httpMessage($download['status']) : __('pl_download_failed')));
            }

            // 完整性：sha256 必须一致
            $expected = strtolower((string) preg_replace('/^sha256:/', '', (string) $item['hash']));
            if (!hash_equals($expected, strtolower((string) hash_file('sha256', $tmpZip)))) return $fail(__('pl_hash_failed'));

            // 来源：RSA-SHA256 验签（规范串 slug|version|sha256:hash，公钥同在线升级）
            require_once __DIR__ . '/License.php';
            $sig = base64_decode((string) ($item['sig'] ?? ''), true);
            $canonical = $slug . '|' . $marketVersion . '|sha256:' . $expected;
            if ($sig === false || $sig === '' || !function_exists('openssl_verify')
                || openssl_verify($canonical, $sig, license_pubkey(), OPENSSL_ALGO_SHA256) !== 1) {
                return $fail(__('pl_sig_failed'));
            }

            $result = $this->installZip($tmpZip, $slug, $marketVersion, $origin);
            if ($result['ok']) self::log('market_install', 'Market plugin installed: ' . $result['slug'] . ' v' . $marketVersion);
            return $result + ['version' => $result['ok'] ? $marketVersion : ''];
        } finally {
            @unlink($tmpZip);
        }
    }

    /**
     * 从本地 ZIP 安装插件（上传安装与市场安装共用）。校验及暂存完成后替换目录；登记失败恢复旧目录，不自动启用。
     * @return array{ok:bool,msg:string,slug:string}
     */
    public function installZip(string $zipPath, string $expectedSlug = '', string $expectedVersion = '', string $origin = 'local'): array
    {
        $installer = new PluginInstaller($this->root . '/plugins', $this->root . '/storage');
        $result = $installer->install($zipPath, static function (string $pluginSlug): void {
            if (!pluginModel()->findBySlug($pluginSlug)) {
                pluginModel()->create([
                    'slug' => $pluginSlug, 'status' => 0,
                    'installed_at' => time(), 'activated_at' => 0,
                ]);
            }
        }, $expectedSlug, $expectedVersion, $origin);
        if ($result['ok']) return ['ok' => true, 'msg' => __('pl_installed') . ': ' . $result['name'], 'slug' => $result['slug']];
        return ['ok' => false, 'msg' => self::errorMessage($result['code']), 'slug' => $result['slug']];
    }

    public static function errorMessage(string $code): string
    {
        return __(match ($code) {
            'no_zip' => 'pl_no_zip_ext',
            'open_zip' => 'pl_zip_open_failed',
            'invalid' => 'pl_manifest_invalid',
            'mismatch' => 'pl_mismatch',
            'unsafe', 'resource' => 'pl_package_unsafe',
            'origin_unknown' => 'market_origin_unknown',
            'origin_changed' => 'market_origin_changed',
            'busy' => 'pl_install_busy',
            'rollback_failed' => 'pl_install_recovery_required',
            default => 'pl_install_failed_preserved',
        });
    }

    /**
     * GET 一个 URL 返回 body（curl 优先，回退 allow_url_fopen；失败返回 null）。不跟随跳转。
     * $maxBytes > 0 时边下边计数，超过上限立即中止并把 $tooLarge 置 true（不先整包读入内存）。
     */
    public static function httpGet(string $url, int $timeout = 15, ?int &$status = null, int $maxBytes = 0, bool &$tooLarge = false): ?string
    {
        $status = 0;
        $tooLarge = false;
        if (function_exists('curl_init')) {
            $body = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes, &$tooLarge): int {
                    if ($maxBytes > 0 && strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooLarge = true;
                        return 0; // 返回值与块长不符 → curl 中止传输
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return !$tooLarge && $status >= 200 && $status < 300 && $body !== '' ? $body : null;
        }
        if (ini_get('allow_url_fopen')) {
            $stream = @fopen($url, 'rb', false, stream_context_create(['http' => [
                'timeout' => $timeout, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true,
            ]]));
            if ($stream === false) return null;
            $meta = stream_get_meta_data($stream);
            $headers = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
            preg_match('/^HTTP\/\S+\s+(\d{3})/', (string) ($headers[0] ?? ''), $match);
            $status = (int) ($match[1] ?? 0);
            $body = '';
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) break;
                if ($maxBytes > 0 && strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    break;
                }
                $body .= $chunk;
            }
            fclose($stream);
            return !$tooLarge && $status >= 200 && $status < 300 && $body !== '' ? $body : null;
        }
        return null;
    }

    private static function log(string $action, string $message): void
    {
        if (function_exists('adminLog')) adminLog('plugin', $action, $message);
    }
}
