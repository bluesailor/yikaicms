<?php
/**
 * YikaiCMS —— 升级管道（无头）。
 *
 * 从 admin/upgrade_online.php 抽出：那里的逻辑原本全程依赖登录会话与 HTTP 响应，
 * cron 无法调用。自动升级（v1.18.6）要无人值守地跑同一条管道，因此把「与传输无关
 * 的部分」搬到这里：状态机、路径护栏、下载与验签、zip 条目枚举、配置补丁、健康自检。
 *
 * 边界：本文件不产出任何输出、不 exit、不假设有 session。Web 层负责鉴权与 JSON 响应。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/LegacyInstallCleanup.php';
require_once __DIR__ . '/UpgradeEntryOrder.php';

final class UpgradeApplyState
{
    public const ERROR_INVALID_STATE = 'invalid_state';
    public const ERROR_INVALID_OFFSET = 'invalid_offset';
    public const ERROR_OFFSET_AHEAD = 'offset_ahead';
    public const ERROR_IO = 'state_io';

    /** @param array<string,mixed> $state */
    public static function write(string $path, array $state): void
    {
        $handle = @fopen($path, 'c+');
        if ($handle === false) throw new RuntimeException(self::ERROR_IO);
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException(self::ERROR_IO);
            self::persist($handle, $state);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param callable(array<string,mixed>&): mixed $callback
     */
    public static function transact(string $path, callable $callback): mixed
    {
        if (!is_file($path)) throw new RuntimeException(self::ERROR_INVALID_STATE);
        $handle = @fopen($path, 'r+');
        if ($handle === false) throw new RuntimeException(self::ERROR_IO);
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException(self::ERROR_IO);
            $state = self::decode($handle);
            $result = $callback($state);
            self::persist($handle, $state);
            return $result;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array<string,mixed> */
    public static function read(string $path): array
    {
        if (!is_file($path)) throw new RuntimeException(self::ERROR_INVALID_STATE);
        $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException(self::ERROR_IO);
        try {
            if (!flock($handle, LOCK_SH)) throw new RuntimeException(self::ERROR_IO);
            return self::decode($handle);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 服务端游标一旦存在就是唯一进度源。旧客户端重放较小 offset 时从服务端进度续跑，
     * 较大 offset 则拒绝，避免跳过文件。旧版状态没有游标时允许用请求值接管一次。
     *
     * @param array<string,mixed> $state
     */
    public static function resolveOffset(array $state, mixed $requestedOffset): int
    {
        $total = self::stateTotal($state);
        $requested = $requestedOffset === null ? null : self::parseOffset($requestedOffset);
        if (!array_key_exists('next_offset', $state)) {
            $offset = $requested ?? 0;
            if ($offset > $total) throw new RuntimeException(self::ERROR_OFFSET_AHEAD);
            return $offset;
        }
        $cursor = self::parseStateOffset($state['next_offset']);
        if ($cursor > $total) throw new RuntimeException(self::ERROR_INVALID_STATE);
        if ($requested !== null && $requested > $cursor) throw new RuntimeException(self::ERROR_OFFSET_AHEAD);
        return $cursor;
    }

    /** @param array<string,mixed> $state */
    public static function isComplete(array $state): bool
    {
        if (!array_key_exists('next_offset', $state)) return true;
        return self::parseStateOffset($state['next_offset']) >= self::stateTotal($state);
    }

    /** @param resource $handle @return array<string,mixed> */
    private static function decode($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false || trim($raw) === '') throw new RuntimeException(self::ERROR_INVALID_STATE);
        try {
            $state = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(self::ERROR_INVALID_STATE);
        }
        if (!is_array($state)) throw new RuntimeException(self::ERROR_INVALID_STATE);
        return $state;
    }

    /** @param resource $handle @param array<string,mixed> $state */
    private static function persist($handle, array $state): void
    {
        try {
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(self::ERROR_INVALID_STATE);
        }
        if (!rewind($handle) || !ftruncate($handle, 0)) throw new RuntimeException(self::ERROR_IO);
        $length = strlen($json);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($handle, substr($json, $written));
            if ($result === false || $result === 0) throw new RuntimeException(self::ERROR_IO);
            $written += $result;
        }
        if (!fflush($handle)) throw new RuntimeException(self::ERROR_IO);
    }

    /** @param array<string,mixed> $state */
    private static function stateTotal(array $state): int
    {
        $total = $state['total'] ?? (is_array($state['entries'] ?? null) ? count($state['entries']) : null);
        if (!is_int($total) || $total < 0) throw new RuntimeException(self::ERROR_INVALID_STATE);
        return $total;
    }

    private static function parseStateOffset(mixed $offset): int
    {
        if (!is_int($offset) || $offset < 0) throw new RuntimeException(self::ERROR_INVALID_STATE);
        return $offset;
    }

    private static function parseOffset(mixed $offset): int
    {
        if (is_int($offset)) {
            if ($offset < 0) throw new RuntimeException(self::ERROR_INVALID_OFFSET);
            return $offset;
        }
        if (!is_string($offset) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $offset) !== 1) {
            throw new RuntimeException(self::ERROR_INVALID_OFFSET);
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($offset) > strlen($max)
            || (strlen($offset) === strlen($max) && strcmp($offset, $max) > 0)) {
            throw new RuntimeException(self::ERROR_INVALID_OFFSET);
        }
        return (int) $offset;
    }
}

const UO_UPDATE_SERVER = 'https://update.yikaicms.com';

/**
 * 升级时永不覆盖、也永不删除的路径（相对站点根）。
 *
 * 后三项是站点覆盖层：各站的模板/配置/文案/逻辑定制都放在这里，是「升级安全的
 * per-site 定制」的立身之本。它们本就被 gitignore、不会进发行包，所以此前不写进
 * 排除表也没出过事——但那是「碰巧安全」：哪天包里出现同名文件，或增量包的删除
 * 清单扫到这些路径，客户的定制就会被静默抹掉。写进契约，不靠巧合。
 */
const UO_EXCLUDES = [
    'config/config.php', 'config/installed.lock', 'installed.lock',
    'storage', 'uploads', 'install',
    'overrides', 'config/overrides.php', 'lang/overrides',
    // 站点自己的东西，随包分发但**装完就归站点所有**，升级不能盖回出厂值：
    //   robots.txt   后台「SEO 设置」可编辑，客户改过的抓取规则不能被冲掉
    //   favicon.ico  图标工坊「一键应用到本站」写的就是它。v1.18.6 起已不随包发，
    //                这条主要保护老站点根目录里已有的那个文件
    //   .htaccess    客户常在里面加自己的重定向/防盗链规则
    // 这几个此前没写进来，属于「碰巧没出事」——发行包里它们和站点版本长得一样，
    // 一旦客户动过就静默丢失，而且丢了不报错、下次访问才发现。
    'robots.txt', 'favicon.ico', '.htaccess',
];

/**
 * 相对路径是否落在受保护路径内（自身或其任一层父目录命中 UO_EXCLUDES）。
 *
 * 不能只比完整路径 + 首段：那样 `lang/overrides/zh-CN.php` 会漏网——完整路径不等于
 * `lang/overrides`，首段是 `lang` 也不在表里，于是客户改的文案会被增量包的删除清单抹掉。
 */
function uo_is_protected(string $rel): bool
{
    $rel = trim(str_replace('\\', '/', $rel), '/');
    if ($rel === '') return false;

    $parts = explode('/', $rel);
    for ($i = count($parts); $i > 0; $i--) {
        if (in_array(implode('/', array_slice($parts, 0, $i)), UO_EXCLUDES, true)) {
            return true;
        }
    }
    return false;
}

function uo_is_legacy_install_upgrade(string $rel): bool
{
    $rel = trim(str_replace('\\', '/', $rel), '/');
    return in_array($rel, ['install/upgrade.php', 'install/run_upgrade.php'], true);
}

function uo_dir(): string { return ROOT_PATH . '/storage/upgrade'; }

function uo_rrmdir(string $d): void
{
    if (!is_dir($d)) { @unlink($d); return; }
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $it) {
        $p = $d . '/' . $it;
        is_dir($p) ? uo_rrmdir($p) : @unlink($p);
    }
    @rmdir($d);
}

/** HTTP GET 下载到文件；TLS 验证(与 check.php 一致)。返回 [bool, errMsg] */
function uo_download(string $url, string $dest): array
{
    $fp = @fopen($dest, 'wb');
    if (!$fp) return [false, '无法写入临时文件，storage/upgrade 不可写'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 600,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FAILONERROR => true,
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code >= 400) return [false, '下载失败: ' . ($err ?: "HTTP $code")];
        return [true, ''];
    }
    if (!ini_get('allow_url_fopen')) { fclose($fp); return [false, '主机禁用 curl 与 allow_url_fopen，无法下载']; }
    $ctx = stream_context_create(['http' => ['timeout' => 600], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $src = @fopen($url, 'rb', false, $ctx);
    if (!$src) { fclose($fp); return [false, '下载失败(allow_url_fopen)']; }
    stream_copy_to_stream($src, $fp);
    fclose($src);
    fclose($fp);
    return [true, ''];
}

/** RSA-SHA256 验签：复用 License 公钥；对 "version|hash" 规范串验签。 */
function uo_verify_sig(string $version, string $hash, string $sigB64): bool
{
    require_once ROOT_PATH . '/includes/UpdatePackageSignature.php';
    return function_exists('license_pubkey')
        && UpdatePackageSignature::verify($version, $hash, $sigB64, license_pubkey());
}

/** 递归覆盖复制（带排除）。返回 [copied, errors[]] */
function uo_copy_tree(string $src, string $dst, string $baseRel = ''): array
{
    $copied = 0; $errors = [];
    foreach (array_diff(scandir($src) ?: [], ['.', '..']) as $it) {
        $rel = $baseRel === '' ? $it : "$baseRel/$it";
        if (uo_is_protected($rel)) continue;
        $s = "$src/$it"; $d = "$dst/$it";
        if (is_dir($s)) {
            if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) { $errors[] = "建目录失败: $rel"; continue; }
            [$c, $e] = uo_copy_tree($s, $d, $rel);
            $copied += $c; $errors = array_merge($errors, $e);
        } elseif (@copy($s, $d)) {
            $copied++;
        } else {
            $errors[] = "复制失败: $rel";
        }
    }
    return [$copied, $errors];
}

/**
 * 列出 zip 内 $prefix 下的所有「文件」条目，返回 [['name'=>zip内条目名, 'rel'=>目标相对路径], ...]。
 * 套用 UO_EXCLUDES；跳过目录条目与越界路径。不解压——供逐条流式写入用（规避共享主机上 extractTo 失败/挂起）。
 */
function uo_zip_entries(ZipArchive $zip, string $prefix, ?callable $sorter = null): array
{
    $out = [];
    $plen = strlen($prefix);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        if ($prefix !== '' && strncmp($name, $prefix, $plen) !== 0) continue;
        $rel = $prefix === '' ? $name : substr($name, $plen);
        if ($rel === '' || substr($rel, -1) === '/') continue;              // 目录条目
        if ($rel[0] === '/' || strpos($rel, '..') !== false) continue;      // 越界防护
        if (uo_is_protected($rel)) continue;
        $out[] = ['name' => $name, 'rel' => $rel];
    }
    $read = static function (array $entry) use ($zip): string {
        $source = $zip->getFromName((string) ($entry['name'] ?? ''));
        return $source === false ? '' : (string) $source;
    };
    try {
        return $sorter !== null
            ? $sorter($out, $read)
            : UpgradeEntryOrder::sort($out, $read);
    } catch (Throwable $e) {
        // Official packages are already written in dependency-safe order. If a
        // future parser regression occurs, preserve archive order so the updater
        // can still apply the package that repairs its own sorter.
        error_log('Upgrade entry ordering failed; using signed archive order: ' . $e->getMessage());
        return $out;
    }
}

/** 分批覆盖的进度状态文件路径 */
function uo_state_file(): string { return uo_dir() . '/apply_state.json'; }

/** 已下载包的验签上下文；prepare 用它把 manifest.to 绑定到已验签目标。 */
function uo_package_meta_file(): string { return uo_dir() . '/package-meta.json'; }

function uo_rollback_file(string $backup): string
{
    return ROOT_PATH . '/storage/backups/' . basename($backup) . '/rollback.json';
}

/**
 * 带进程锁完整写入 JSON。回滚清单是恢复前提，不能像普通日志一样忽略写入失败。
 *
 * @param array<string, mixed> $data
 */
function uo_write_json_locked(string $path, array $data): bool
{
    try {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return false;
    }
    $ok = false;
    try {
        if (!flock($handle, LOCK_EX) || !rewind($handle) || !ftruncate($handle, 0)) {
            return false;
        }
        $length = strlen($json);
        $written = 0;
        while ($written < $length) {
            $n = fwrite($handle, substr($json, $written));
            if ($n === false || $n === 0) {
                return false;
            }
            $written += $n;
        }
        $ok = fflush($handle);
        if ($ok && function_exists('fsync')) {
            $ok = fsync($handle);
        }
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
    return $ok;
}

/** @param list<string> $created @param list<string> $deleted */
function uo_write_rollback_manifest(string $backup, string $from, string $to, array $created, array $deleted): bool
{
    return uo_write_json_locked(uo_rollback_file($backup), [
        'from' => $from,
        'to' => $to,
        'created' => array_values(array_unique($created)),
        'deleted' => array_values(array_unique($deleted)),
        'time' => date('Y-m-d H:i:s'),
    ]);
}

/** 兼容旧 config.php：把硬编码的 CMS_VERSION 定义换成 require version.php。 */
function uo_patch_config_version(): string
{
    $cf = ROOT_PATH . '/config/config.php';
    $raw = @file_get_contents($cf);
    if ($raw === false) return 'unreadable';
    if (strpos($raw, "version.php'") !== false) return 'already';
    $new = preg_replace("/define\\(\\s*'CMS_VERSION'\\s*,\\s*'[^']*'\\s*\\)\\s*;/", "require_once __DIR__ . '/version.php';", $raw, 1, $cnt);
    if ($cnt < 1 || $new === null || $new === $raw) return 'nochange';
    return @file_put_contents($cf, $new) !== false ? 'patched' : 'failed';
}

/** 升级后健康自检（实现在 includes/UpgradeHealth.php，独立类便于单测与 CLI 复用） */
function uo_health_check(): array
{
    require_once ROOT_PATH . '/includes/UpgradeHealth.php';
    return UpgradeHealth::check(ROOT_PATH);
}

// ============================================================
// 升级管道（无头）—— Web 层与 cron 自动升级共用；只返回数组，不产出输出。
// ============================================================

function upgrade_prepare(
    string $expectedFrom = '',
    string $expectedTo = '',
    bool $requireDbBackup = false,
    bool $allowMissingDbBackup = false
): array
{
    $pkg = uo_dir() . '/package.zip';
    if (!is_file($pkg)) return ['code' => 1, 'msg' => '未找到已下载的安装包，请先执行下载'];
    if (!class_exists('ZipArchive')) return ['code' => 1, 'msg' => '缺少 ZipArchive 扩展'];

    $current = defined('CMS_VERSION') ? (string) CMS_VERSION : '';
    $expectedFrom = trim($expectedFrom) !== '' ? trim($expectedFrom) : $current;
    if (trim($expectedTo) === '') {
        $meta = json_decode((string) @file_get_contents(uo_package_meta_file()), true);
        $expectedTo = is_array($meta) ? trim((string) ($meta['version'] ?? '')) : '';
    } else {
        $expectedTo = trim($expectedTo);
    }
    $packageMeta = json_decode((string) @file_get_contents(uo_package_meta_file()), true);
    $owner = is_array($packageMeta) && ($packageMeta['owner'] ?? '') === 'auto' ? 'auto' : 'manual';

    // 备份 config.php + 记录旧版本（轻量、稳妥；完整代码回滚依赖主机备份）
    $oldVer = defined('CMS_VERSION') ? CMS_VERSION : 'unknown';
    $bakDir = ROOT_PATH . '/storage/backups/pre-upgrade-' . $oldVer . '-' . date('YmdHis');
    if (!is_dir($bakDir) && !@mkdir($bakDir, 0755, true) && !is_dir($bakDir)) {
        return ['code' => 1, 'msg' => '无法建立升级备份目录，已中止，未改动任何文件'];
    }
    $configFile = ROOT_PATH . '/config/config.php';
    if (!is_file($configFile) || !@copy($configFile, $bakDir . '/config.php')) {
        return ['code' => 1, 'msg' => 'config.php 备份失败，已中止，未改动任何文件'];
    }

    // 数据库自动备份（v1.18.6）：文件快照管代码，这份 SQL 管「迁移改表之后」
    // 的事故兜底——两者合起来才是完整的升级前状态。自动升级调用方会自行回滚；
    // 人工在线升级传入 requireDbBackup=true，在写任何程序文件前失败关闭。
    $dbBackupNote = '';
    $dbBackupError = '';
    try {
        set_time_limit(300);
        require_once ROOT_PATH . '/includes/Backup.php';
        $dbTables = Backup::listPrefixedTables();
        if ($dbTables !== []) {
            $dbSql = Backup::generateSql($dbTables);
            if (@file_put_contents($bakDir . '/database.sql', $dbSql) !== false) {
                $dbBackupNote = 'database.sql（' . count($dbTables) . ' 表 / ' . round(strlen($dbSql) / 1048576, 1) . 'MB）';
            } else {
                $dbBackupError = 'storage/backups 写入失败';
            }
            unset($dbSql);
        }
    } catch (Throwable $e) {
        $dbBackupError = $e->getMessage();
    }

    $dbBackupOverride = $requireDbBackup && $dbBackupNote === '' && $allowMissingDbBackup;
    if ($requireDbBackup && $dbBackupNote === '' && !$dbBackupOverride) {
        uo_rrmdir($bakDir);
        return [
            'code' => 1,
            'error_code' => 'db_backup_required',
            'msg' => '数据库自动备份失败，升级已在写入程序文件前中止：'
                . ($dbBackupError !== '' ? $dbBackupError : '没有可验证的数据库备份文件'),
        ];
    }

    $dbBackupInfo = $dbBackupNote !== ''
        ? "数据库备份: {$dbBackupNote}\n"
        : ($dbBackupOverride
            ? "数据库备份: 外部备份已由超级管理员确认（自动备份失败：{$dbBackupError}）\n"
            : "数据库备份: 失败（{$dbBackupError}）\n");
    @file_put_contents(
        $bakDir . '/INFO.txt',
        "升级前版本: $oldVer\n时间: " . date('Y-m-d H:i:s') . "\n" . $dbBackupInfo
    );

    $zip = new ZipArchive();
    if ($zip->open($pkg) !== true) return ['code' => 1, 'msg' => '安装包打开失败'];
    // zip-slip 防护：条目名越界则中止
    $unsafe = zipUnsafeEntry($zip);
    if ($unsafe !== null) { $zip->close(); return ['code' => 1, 'msg' => '安装包含非法路径条目，已中止：' . $unsafe]; }
    // zip bomb 防护。限值比主题/插件宽：全量包本身就有 8000+ 文件（v1.18.4 实测 8400）
    $violation = zipResourceViolation($zip, 30000, 524_288_000, 31_457_280);
    if ($violation !== null) { $zip->close(); return ['code' => 1, 'msg' => '安装包未通过资源安全检查，已中止：' . $violation]; }

    // 判定增量 / 全量 + 定位包内前缀（不解压，只读条目名/manifest）
    $deleted = [];
    $manifestRaw = $zip->getFromName('.delta-manifest.json');
    if ($manifestRaw !== false) {
        $manifest = json_decode((string) $manifestRaw, true);
        if (!is_array($manifest)) { $zip->close(); return ['code' => 1, 'msg' => '增量包 manifest 解析失败，已中止，未改动任何文件']; }
        $mode = 'delta'; $prefix = 'payload/';
        $deleted = (array) ($manifest['deleted'] ?? []);
        $from = (string) ($manifest['from'] ?? '');
        $to   = (string) ($manifest['to'] ?? '');
        // 增量包只包含「from → to」之间变化的文件，装在别的基线上会缺文件。
        // 包签名只证明包是官方签发的，**不证明它适用于本站**——服务器一旦把别的
        // 基线的 delta 关联过来（配置错误或缓存串档），签名照样通过。
        // 因此在改动任何文件之前，from/to 必须同时绑定当前事务。to 来自下载阶段
        // 已通过 RSA 验签的 version，不能只信包内 manifest。（codex 审计 P2-2）
        if ($from === '' || $expectedFrom === '' || $from !== $expectedFrom) {
            $zip->close();
            return ['code' => 1, 'msg' => '增量包基线不匹配（包适用于 v' . $from
                . '，本站事务基线为 v' . $expectedFrom . '），已中止，未改动任何文件'];
        }
        if ($to === '' || $expectedTo === '' || $to !== $expectedTo) {
            $zip->close();
            return ['code' => 1, 'msg' => '增量包目标不匹配（包目标 v' . $to
                . '，已验签目标 v' . $expectedTo . '），已中止，未改动任何文件'];
        }
    } else {
        // 全量包通常是单层 yikaicms-vX.Y.Z/ 目录；找含 index.php 的那层作前缀
        $prefix = '';
        if ($zip->locateName('index.php') === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if ($n !== false && preg_match('#^([^/]+)/index\.php$#', $n, $mm)) { $prefix = $mm[1] . '/'; break; }
            }
        }
        if ($zip->locateName($prefix . 'index.php') === false || $zip->locateName($prefix . 'includes/functions.php') === false) {
            $zip->close();
            return ['code' => 1, 'msg' => '安装包结构异常（缺 index.php / includes），已中止，未改动任何文件'];
        }
        $mode = 'full';
    }

    $entries = uo_zip_entries($zip, $prefix);
    $zip->close();
    if (empty($entries)) return ['code' => 1, 'msg' => '安装包内无可覆盖文件，已中止'];
    $state = [
        'mode' => $mode, 'pkg' => $pkg, 'prefix' => $prefix, 'entries' => $entries, 'deleted' => $deleted,
        'backup' => basename($bakDir), 'total' => count($entries), 'done' => 0, 'next_offset' => 0,
        'errors' => [], 'from' => $expectedFrom, 'to' => $expectedTo, 'phase' => 'prepared', 'owner' => $owner,
        'db_backup' => $dbBackupNote, 'db_backup_error' => $dbBackupError,
        'db_backup_override' => $dbBackupOverride,
        // 事务化升级：apply_batch 覆盖前把旧文件快照到 backups/<backup>/files/，
        // 全新写入的文件记入 created——两者合起来就是完整的文件级回滚清单
        'created' => [],
    ];
    $backup = basename($bakDir);
    $filesDir = $bakDir . '/files';
    if ((!is_dir($filesDir) && !@mkdir($filesDir, 0755, true) && !is_dir($filesDir))
        || !uo_write_rollback_manifest($backup, $expectedFrom, $expectedTo, [], [])) {
        return ['code' => 1, 'msg' => '无法建立回滚清单，已中止，未改动任何文件'];
    }
    try {
        UpgradeApplyState::write(uo_state_file(), $state);
    } catch (RuntimeException) {
        return ['code' => 1, 'msg' => __('upgrade_apply_state_write_failed')];
    }
    return [
        'code' => 0, 'mode' => $mode, 'total' => count($entries), 'backup' => $backup,
        'db_backup' => $dbBackupNote, 'db_backup_error' => $dbBackupError,
        'db_backup_override' => $dbBackupOverride,
    ];
    }

function upgrade_batch(mixed $requestedOffset = null): array
{
    $sf = uo_state_file();
    if (!is_file($sf)) return ['code' => 1, 'msg' => '升级状态丢失，请重新开始'];
    try {
        $response = UpgradeApplyState::transact($sf, static function (array &$state) use ($requestedOffset): array {
            if (!isset($state['entries'], $state['pkg']) || !is_array($state['entries']) || !is_string($state['pkg'])) {
                throw new RuntimeException(UpgradeApplyState::ERROR_INVALID_STATE);
            }
            $entries = $state['entries'];
            $total = count($entries);
            if (($state['total'] ?? null) !== $total) {
                throw new RuntimeException(UpgradeApplyState::ERROR_INVALID_STATE);
            }

            $offset = UpgradeApplyState::resolveOffset($state, $requestedOffset);
            $end = min($total, $offset + 80);
            $zip = new ZipArchive();
            if ($zip->open($state['pkg']) !== true) {
                throw new RuntimeException('package_open_failed');
            }

            $copied = 0;
            $errors = [];
            $snapFails = 0;   // 覆盖前快照失败数：无人值守升级据此判定「回滚已失去依据」
            $bakFilesDir = ROOT_PATH . '/storage/backups/' . basename((string) ($state['backup'] ?? '')) . '/files';
            $created = is_array($state['created'] ?? null) ? $state['created'] : [];
            try {
                for ($i = $offset; $i < $end; $i++) {
                    if (!is_array($entries[$i] ?? null)) {
                        throw new RuntimeException(UpgradeApplyState::ERROR_INVALID_STATE);
                    }
                    $rel  = (string) ($entries[$i]['rel'] ?? '');
                    $name = (string) ($entries[$i]['name'] ?? '');
                    if ($rel === '' || $name === '') {
                        throw new RuntimeException(UpgradeApplyState::ERROR_INVALID_STATE);
                    }
                    $data = $zip->getFromName($name);
                    if ($data === false) { $errors[] = "读取失败: $rel"; continue; }
                    $d = ROOT_PATH . '/' . $rel;
                    $dir = dirname($d);
                    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { $errors[] = "建目录失败: $rel"; continue; }
                    // 覆盖前快照旧文件（同一文件只快照第一次的版本）；全新文件必须先写入
                    // rollback.json 再创建。任何路径拿不到回滚依据时都不能继续改目标文件。
                    if (is_file($d)) {
                        $snap = $bakFilesDir . '/' . $rel;
                        if (!is_file($snap)) {
                            $sd = dirname($snap);
                            $okDir = is_dir($sd) || @mkdir($sd, 0755, true) || is_dir($sd);
                            if (!$okDir || !@copy($d, $snap)) {
                                $snapFails++;
                                $errors[] = "快照失败: $rel";
                                continue;
                            }
                        }
                    } else {
                        $nextCreated = array_values(array_unique(array_merge($created, [$rel])));
                        if (!uo_write_rollback_manifest(
                            (string) ($state['backup'] ?? ''),
                            (string) ($state['from'] ?? ''),
                            (string) ($state['to'] ?? ''),
                            $nextCreated,
                            []
                        )) {
                            $errors[] = "回滚清单写入失败: $rel";
                            continue;
                        }
                        $created = $nextCreated;
                    }
                    if (@file_put_contents($d, $data) !== false) $copied++; else $errors[] = "写入失败: $rel";
                }
            } finally {
                $zip->close();
            }
            $state['created'] = $created;

            $state['done'] = (int) ($state['done'] ?? 0) + $copied;
            $state['next_offset'] = $end;
            $state['phase'] = 'applying';
            $state['errors'] = array_slice(array_merge(is_array($state['errors'] ?? null) ? $state['errors'] : [], $errors), 0, 50);
            $state['snapshot_failed'] = (int) ($state['snapshot_failed'] ?? 0) + $snapFails;
            return [
                'code' => 0, 'copied' => $copied, 'next' => $end, 'total' => $total,
                'errors' => $errors, 'snapshot_failed' => $snapFails,
            ];
        });
    } catch (RuntimeException $e) {
        $message = match ($e->getMessage()) {
            UpgradeApplyState::ERROR_INVALID_OFFSET => __('upgrade_apply_offset_invalid'),
            UpgradeApplyState::ERROR_OFFSET_AHEAD => __('upgrade_apply_offset_ahead'),
            UpgradeApplyState::ERROR_IO => __('upgrade_apply_state_write_failed'),
            'package_open_failed' => __('upgrade_apply_package_open_failed'),
            default => __('upgrade_apply_state_invalid'),
        };
        return ['code' => 1, 'msg' => $message];
    }
    return $response;
    }

function uo_pending_migrations(): ?int
{
    try {
        require_once ROOT_PATH . '/includes/Migrator.php';
        $pending = 0;
        foreach (Migrator::loadAll() as $migration) {
            if (!Migrator::isApplied($migration)) {
                $pending++;
            }
        }
        return $pending;
    } catch (Throwable) {
        return null;
    }
}

/**
 * 事务已通过调用方检查后才清理恢复上下文。state 最后删，任一步失败都可在下一轮重试。
 */
function upgrade_complete(): array
{
    $sf = uo_state_file();
    try {
        $state = UpgradeApplyState::read($sf);
    } catch (RuntimeException) {
        return ['code' => 1, 'msg' => '升级完成状态丢失，拒绝清理恢复上下文'];
    }
    if (($state['phase'] ?? '') !== 'finalized' || !is_array($state['finalize_result'] ?? null)) {
        return ['code' => 1, 'msg' => '升级尚未完成收尾，拒绝清理恢复上下文'];
    }
    $result = $state['finalize_result'];
    if ((int) ($result['code'] ?? 1) !== 0) {
        return ['code' => 1, 'msg' => '升级收尾仍有错误，拒绝清理恢复上下文'];
    }

    try {
        settingModel()->set('last_upgrade_from', (string) ($state['from'] ?? ''), 'system');
        settingModel()->set('last_upgrade_to', (string) ($result['new_version'] ?? ($state['to'] ?? '')), 'system');
        settingModel()->set('last_upgrade_at', (string) time(), 'system');
        settingModel()->set('last_upgrade_note', mb_substr(trim((string) ($state['note'] ?? '')), 0, 4000), 'system');
    } catch (Throwable) {
        // 展示记录失败不影响已经验证通过的文件事务。
    }

    uo_rrmdir(uo_dir() . '/extracted');
    foreach ([uo_dir() . '/package.zip', uo_package_meta_file()] as $file) {
        if (is_file($file) && !@unlink($file)) {
            return ['code' => 1, 'msg' => '升级已完成，但临时安装包清理失败，将在下一轮重试'];
        }
    }
    if (!@unlink($sf) && is_file($sf)) {
        return ['code' => 1, 'msg' => '升级已完成，但事务状态清理失败，将在下一轮重试'];
    }
    return ['code' => 0, 'msg' => '升级事务已完成并清理'];
}

function upgrade_finalize(string $note = '', bool $preserveState = false): array
{
    $sf = uo_state_file();
    if (!is_file($sf)) return ['code' => 1, 'msg' => '升级状态丢失，请重新开始'];
    try {
        $state = UpgradeApplyState::read($sf);
        if (!UpgradeApplyState::isComplete($state)) {
            return ['code' => 1, 'msg' => __('upgrade_apply_incomplete')];
        }
    } catch (RuntimeException) {
        return ['code' => 1, 'msg' => __('upgrade_apply_state_invalid')];
    }

    // 自动升级会在 finalize 后继续做健康/迁移安全判定。进程若在两者之间退出，
    // 下一轮直接复用已持久化结果，不重复删除文件或覆盖回滚清单。
    if (($state['phase'] ?? '') === 'finalized' && is_array($state['finalize_result'] ?? null)) {
        $cached = $state['finalize_result'];
        if (!$preserveState && (int) ($cached['code'] ?? 1) === 0) {
            $clean = upgrade_complete();
            if (($clean['code'] ?? 1) !== 0) {
                $cached['code'] = 2;
                $cached['errors'][] = (string) ($clean['msg'] ?? '事务清理失败');
            }
        }
        return $cached;
    }

    // 删除清单（仅增量有）：拒绝绝对路径/越界/受保护路径，仅删普通文件。
    // 删除前先快照并更新回滚清单；任一步失败都保留原文件。
    $bakFilesDir = ROOT_PATH . '/storage/backups/' . basename((string) ($state['backup'] ?? '')) . '/files';
    $deletedCount = 0;
    $existingManifest = json_decode((string) @file_get_contents(uo_rollback_file((string) ($state['backup'] ?? ''))), true);
    $deletedRels = is_array($existingManifest) && is_array($existingManifest['deleted'] ?? null)
        ? array_values($existingManifest['deleted']) : [];
    $created = array_values(array_unique((array) ($state['created'] ?? [])));
    $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
    foreach ((array) ($state['deleted'] ?? []) as $rel) {
        $rel = (string) $rel;
        if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) continue;
        // 这两个历史入口没有鉴权，安全删除不可因 install/ 的普通升级保护而跳过，
        // 也不写入回滚快照，避免故障回滚把已清除的漏洞入口重新放回站点。
        if (uo_is_legacy_install_upgrade($rel)) {
            continue;
        }
        if (uo_is_protected($rel)) continue;
        $p = ROOT_PATH . '/' . $rel;
        if (!is_file($p)) continue;
        $snap = $bakFilesDir . '/' . $rel;
        if (!is_file($snap)) {
            $sd = dirname($snap);
            $okDir = is_dir($sd) || @mkdir($sd, 0755, true) || is_dir($sd);
            if (!$okDir || !@copy($p, $snap)) {
                $errors[] = "删除前快照失败: $rel";
                continue;
            }
        }
        $nextDeleted = array_values(array_unique(array_merge($deletedRels, [$rel])));
        if (!uo_write_rollback_manifest(
            (string) ($state['backup'] ?? ''),
            (string) ($state['from'] ?? ''),
            (string) ($state['to'] ?? ''),
            $created,
            $nextDeleted
        )) {
            $errors[] = "删除前回滚清单写入失败: $rel";
            continue;
        }
        if (@unlink($p)) {
            $deletedCount++;
            $deletedRels = $nextDeleted;
        } else {
            $errors[] = "删除失败: $rel";
        }
    }
    // Full/manual packages do not necessarily carry a delta delete manifest. Run the
    // same irreversible cleanup once at finalize so old installs still lose the entry points.
    $legacyCleanup = LegacyInstallCleanup::run(ROOT_PATH);
    $deletedCount += count($legacyCleanup['removed']);
    foreach ($legacyCleanup['failed'] as $relativePath) {
        $errors[] = 'Unable to remove legacy install upgrade entry: ' . $relativePath;
    }
    if (!uo_write_rollback_manifest(
        (string) ($state['backup'] ?? ''),
        (string) ($state['from'] ?? ''),
        (string) ($state['to'] ?? ''),
        $created,
        $deletedRels
    )) {
        $errors[] = '回滚清单最终写入失败';
    }
    $patch = empty($errors) ? uo_patch_config_version() : 'skipped';
    if ($patch === 'failed') {
        $errors[] = 'config 版本入口补丁写入失败';
    }

    $copied = (int) ($state['done'] ?? $state['total'] ?? 0);
    $mode   = $state['mode'] ?? 'full';
    if (!empty($errors)) {
        $verPair = $mode === 'delta' ? "{$state['from']}→{$state['to']}" : ('→' . ($state['to'] ?? ''));
        @file_put_contents(
            uo_dir() . '/upgrade-failures.log',
            '[' . date('Y-m-d H:i:s') . "] {$verPair} 覆盖 {$copied}，失败 " . count($errors) . "：\n  - " . implode("\n  - ", $errors) . "\n",
            FILE_APPEND
        );
    }
    $failNote = empty($errors) ? '' : ('，失败 ' . count($errors) . '：' . implode('; ', array_slice($errors, 0, 10)));
    $backupAudit = !empty($state['db_backup_override']) ? ', external backup override: yes' : '';
    try { adminLog('upgrade', 'online_apply', ($mode === 'delta' ? "增量升级 {$state['from']}→{$state['to']}" : '在线升级') . "：覆盖 {$copied} / 删 {$deletedCount}，config补丁:{$patch}{$backupAudit}{$failNote}"); } catch (\Throwable $e) {}

    $newVer = '';
    $vf = @file_get_contents(ROOT_PATH . '/config/version.php');
    if ($vf && preg_match("/CMS_VERSION'\\s*,\\s*'([^']+)'/", $vf, $m)) $newVer = $m[1];
    $expectedVersion = trim((string) ($state['to'] ?? ''));
    if ($expectedVersion !== '' && $newVer !== $expectedVersion) {
        $errors[] = '安装后版本不一致：期望 ' . $expectedVersion . '，实际 ' . ($newVer !== '' ? $newVer : '无法识别');
    }

    $pending = uo_pending_migrations();
    $health = uo_health_check();
    if (empty($health['ok'])) {
        $errors[] = '升级后健康检查失败';
    }
    $result = [
        'code'    => empty($errors) ? 0 : 2,
        'pending' => $pending,
        'msg'     => (empty($errors) ? "文件更新完成，共覆盖 $copied 个文件" : "部分文件未能覆盖（$copied 成功，" . count($errors) . " 失败）") . ($deletedCount ? "，删除 $deletedCount 个" : ''),
        'mode'    => $mode,
        'copied'  => $copied,
        'deleted' => $deletedCount,
        'errors'  => array_slice($errors, 0, 20),
        'patch'   => $patch,
        'new_version' => $newVer,
        'backup'  => $state['backup'] ?? '',
        'health'  => $health,
    ];

    $state['phase'] = 'finalized';
    $state['note'] = $note;
    $state['errors'] = array_slice($errors, 0, 50);
    $state['finalize_result'] = $result;
    try {
        UpgradeApplyState::write($sf, $state);
    } catch (RuntimeException) {
        $result['code'] = 2;
        $result['errors'][] = __('upgrade_apply_state_write_failed');
        return $result;
    }

    if (!$preserveState && (int) $result['code'] === 0) {
        $clean = upgrade_complete();
        if (($clean['code'] ?? 1) !== 0) {
            $result['code'] = 2;
            $result['errors'][] = (string) ($clean['msg'] ?? '事务清理失败');
        }
    }
    return $result;
    }

function upgrade_rollback(string $backup): array
{
    $backup = basename($backup);
    if ($backup === '' || !preg_match('/^pre-upgrade-/', $backup)) {
        return ['code' => 1, 'msg' => '备份目录名不合法'];
    }
    $bakDir = ROOT_PATH . '/storage/backups/' . $backup;
    $filesDir = $bakDir . '/files';
    $rb = json_decode((string) @file_get_contents($bakDir . '/rollback.json'), true);
    if (!is_array($rb)) return ['code' => 1, 'msg' => '找不到回滚清单（rollback.json），无法自动回滚，请用主机备份恢复'];
    if (!is_dir($filesDir)) return ['code' => 1, 'msg' => '找不到文件快照目录，无法自动回滚，请用主机备份恢复'];

    // Restore the matching database before replacing executable files. Keep all
    // recovery evidence on failure; old code with a new schema is not a rollback.
    require_once __DIR__ . '/UpgradeDatabaseRollback.php';
    $database = UpgradeDatabaseRollback::restore($bakDir);
    if ($database['errors'] !== []) {
        return [
            'code' => 2, 'msg' => 'Database rollback failed; file restore was not started.',
            'database' => $database, 'errors' => $database['errors'],
        ];
    }

    // state 仍在时与清单取并集。正常路径会先写清单再创建文件；这里再合一次，
    // 兼容旧事务和清单最后一次刷新后进程异常退出的情况。
    try {
        $state = UpgradeApplyState::read(uo_state_file());
        if (basename((string) ($state['backup'] ?? '')) === $backup) {
            $rb['created'] = array_values(array_unique(array_merge(
                (array) ($rb['created'] ?? []),
                (array) ($state['created'] ?? [])
            )));
        }
    } catch (RuntimeException) {
        // 持久化清单本身足以回滚，不强依赖临时 state。
    }

    // 1) 恢复快照（被覆盖 + 被删除的文件都在里面）
    [$restored, $restoreErrors] = uo_copy_tree($filesDir, ROOT_PATH);

    // 2) 移除升级新建的文件（旧版没有它们；防越界与受保护路径）
    $removed = 0;
    $removeErrors = [];
    foreach ((array) ($rb['created'] ?? []) as $rel) {
        $rel = (string) $rel;
        if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) continue;
        if (uo_is_protected($rel)) continue;
        $createdFile = ROOT_PATH . '/' . $rel;
        if (is_file($createdFile)) {
            if (@unlink($createdFile)) {
                $removed++;
            } else {
                $removeErrors[] = "移除新建文件失败: $rel";
            }
        }
    }

    // config.php 不在通用快照树中（它是受保护路径），单独恢复 prepare 时的副本。
    $configBackup = $bakDir . '/config.php';
    if (is_file($configBackup) && !@copy($configBackup, ROOT_PATH . '/config/config.php')) {
        $restoreErrors[] = '恢复 config/config.php 失败';
    }

    $health = uo_health_check();
    if (empty($health['ok'])) {
        $restoreErrors[] = '回滚后健康检查失败';
    }
    $errors = array_merge($restoreErrors, $removeErrors);
    if (empty($errors)) {
        @unlink(uo_dir() . '/package.zip');
        @unlink(uo_package_meta_file());
        @unlink(uo_state_file());
    }
    try { adminLog('upgrade', 'online_rollback', "在线升级回滚（{$backup}）：恢复 {$restored}，移除新建 {$removed}，失败 " . count($errors)); } catch (\Throwable $e) {}
    return [
        'code' => empty($errors) ? 0 : 2,
        'msg' => "回滚完成：恢复 {$restored} 个文件，移除新建 {$removed} 个"
            . (empty($errors) ? '' : ('，' . count($errors) . ' 个失败：' . implode('; ', array_slice($errors, 0, 10)))),
        'restored' => $restored,
        'database' => $database,
        'removed' => $removed,
        'errors' => array_slice($errors, 0, 20),
        'health' => $health,
    ];
    }

/** 下载游标：记录本次续传针对的包与已收字节，换包即作废。 */
function uo_download_state_file(): string { return uo_dir() . '/download-state.json'; }
function uo_download_part_file(): string { return uo_dir() . '/package.zip.part'; }

/** 单次分块请求的字节上限与时间上限。时间上限必须明显小于国内主机常见的 60 秒网关超时。 */
const UO_DOWNLOAD_CHUNK_BYTES = 1048576;   // 1MB
const UO_DOWNLOAD_CHUNK_SECONDS = 25;

/**
 * 取一段字节。返回 [httpStatus, totalBytes|null, error]。
 * 写入交给调用方给的文件句柄——curl 中途超时也算数：已落盘的字节就是进度。
 */
function uo_download_range(string $url, int $from, int $to, $fh): array
{
    $range = $from . '-' . $to;
    if (function_exists('curl_init')) {
        $rangeTotal = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_RANGE => $range,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => UO_DOWNLOAD_CHUNK_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            // Content-Range 才是资源真实长度的权威来源；HEAD 只是提示（见 uo_download_total）
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$rangeTotal): int {
                if (preg_match('#^Content-Range:\s*bytes\s+\d+-\d+/(\d+)#i', $header, $m) === 1) {
                    $rangeTotal = (int) $m[1];
                }
                return strlen($header);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string) curl_error($ch);
        $total = $rangeTotal;
        $len = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        // 206 才是真正的区间响应；200 表示服务端忽略了 Range，整包又发了一遍。
        if ($total === null && $status === 200 && $len !== false && $len > 0) {
            $total = (int) $len;
        }
        // 超时不算错：这一段没拉满，下一轮从新的 offset 接着来。
        if ($err !== '' && $status === 0) {
            return [0, null, $err];
        }
        return [$status, $total, ''];
    }
    if (!ini_get('allow_url_fopen')) {
        return [0, null, '主机禁用 curl 与 allow_url_fopen，无法下载'];
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => UO_DOWNLOAD_CHUNK_SECONDS, 'header' => "Range: bytes=$range\r\n", 'ignore_errors' => true],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $src = @fopen($url, 'rb', false, $ctx);
    if (!$src) return [0, null, '下载失败(allow_url_fopen)'];
    $status = 0; $total = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $h, $m) === 1) $status = (int) $m[1];
        if (preg_match('#^Content-Range:\s*bytes\s+\d+-\d+/(\d+)#i', $h, $m) === 1) $total = (int) $m[1];
        if ($status === 200 && preg_match('#^Content-Length:\s*(\d+)#i', $h, $m) === 1) $total = (int) $m[1];
    }
    stream_copy_to_stream($src, $fh);
    fclose($src);
    return [$status, $total, ''];
}

/**
 * 问一次总字节数——**只是提示，不是权威**。
 * 有些主机/WAF 会对不存在的地址回 200 + 一张错误页，于是这里会把错误页的长度当成包大小
 * （2026-08-24 CI 上就这么红过一次）。权威值来自区间响应的 Content-Range，
 * uo_download_step 里以后者为准并覆盖本函数的结果。
 */
function uo_download_total(string $url): ?int
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_exec($ch);
    $len = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code < 400 && $len !== false && $len > 0) ? (int) $len : null;
}

/**
 * 分块续传的一步。每次调用只拉一段，把进度写进游标文件，由调用方反复调用直到 done。
 *
 * 为什么必须这样：覆盖阶段早就分批了（每批 150 文件），下载却一直是「一个请求拉完整包」。
 * 国内主机拉 SiteGround 慢，Tengine/nginx 网关 60 秒一到就 504——xcidcn 两次栽在这里，
 * 每次都要人工 FTP 送包再手动接续。PHP 侧那个 600 秒超时救不了，因为掐连接的是网关。
 *
 * @param null|callable(string,int,int,resource):array{0:int,1:int|null,2:string} $fetcher 仅供测试注入
 */
function upgrade_download_chunk(
    string $url,
    string $hash,
    string $version,
    string $sig,
    string $owner = 'manual',
    ?callable $fetcher = null
): array {
    if (is_file(uo_state_file())) {
        return ['code' => 1, 'msg' => '已有升级事务尚未结束，拒绝覆盖其安装包'];
    }
    $hash = strtolower((string) preg_replace('/^sha256:/i', '', $hash));
    $ver = trim($version);
    $sig = trim($sig);
    if (!preg_match('#^https://update\.yikaicms\.com/packages/[A-Za-z0-9._-]+\.zip$#', $url)) {
        return ['code' => 1, 'msg' => '下载地址不合法，仅允许官方 packages 目录'];
    }
    if (strlen($hash) !== 64) return ['code' => 1, 'msg' => '缺少有效的 SHA256 校验值，拒绝升级'];
    if ($sig === '') return ['code' => 1, 'msg' => '升级包缺少 RSA 签名，拒绝升级'];
    // 验签在下载任何字节之前——不让未经确认的地址消耗带宽，也避免续传到一半才发现包不对。
    if (!uo_verify_sig($ver, 'sha256:' . $hash, $sig)) {
        return ['code' => 1, 'msg' => 'RSA 签名校验失败，拒绝升级'];
    }
    if (!is_dir(uo_dir()) && !@mkdir(uo_dir(), 0755, true) && !is_dir(uo_dir())) {
        return ['code' => 1, 'msg' => '无法创建 storage/upgrade 目录'];
    }

    $part = uo_download_part_file();
    $state = json_decode((string) @file_get_contents(uo_download_state_file()), true);
    $step = uo_download_step($url, $part, is_array($state) ? $state : [], $hash, $ver, $fetcher);

    if ($step['error'] !== '') {
        return ['code' => 1, 'msg' => $step['error'], 'received' => $step['received'], 'total' => $step['total']]
            + (!empty($step['no_range']) ? ['no_range' => true] : []);
    }
    uo_write_json_locked(uo_download_state_file(), $step['state']);
    if ($step['complete']) {
        return uo_download_finalize($part, $hash, $ver, $owner, $step['total']);
    }
    return [
        'code' => 0, 'done' => false, 'received' => $step['received'], 'total' => $step['total'],
        'msg' => $step['total']
            ? sprintf('已下载 %.1f/%.1f MB', $step['received'] / 1048576, $step['total'] / 1048576)
            : sprintf('已下载 %.1f MB', $step['received'] / 1048576),
    ];
}

/**
 * 续传的一步：只管传输，不碰验签与落位。拆出来是为了能脱离 RSA 上下文直接测——
 * 「换包作废旧进度」「服务端忽略 Range」这两条最容易写错，而它们与安全守卫无关。
 *
 * @param null|callable(string,int,int,resource):array{0:int,1:int|null,2:string} $fetcher
 * @return array{state:array,received:int,total:int|null,error:string,no_range:bool,complete:bool}
 */
function uo_download_step(string $url, string $part, array $state, string $hash, string $ver, ?callable $fetcher = null): array
{
    $fail = static fn(array $st, int $rec, ?int $tot, string $err, bool $noRange = false): array
        => ['state' => $st, 'received' => $rec, 'total' => $tot, 'error' => $err, 'no_range' => $noRange, 'complete' => false];

    $sameTarget = ($state['url'] ?? '') === $url
        && ($state['hash'] ?? '') === $hash
        && ($state['version'] ?? '') === $ver;
    if (!$sameTarget) {
        // 换了目标包：旧的半截文件一律作废，否则会把两个包的字节拼在一起，
        // 而 SHA256 要到最后才发现——那时已经白下了整包。
        @unlink($part);
        $state = ['url' => $url, 'hash' => $hash, 'version' => $ver, 'total' => null, 'started_at' => time()];
    }

    // filesize() 有 stat 缓存：同一进程里连着调两轮（upgrade_download_package 的内部
    // 循环就是这样），不清缓存会一直读到旧字节数，进度永远不动。
    clearstatcache(true, $part);
    $received = is_file($part) ? (int) filesize($part) : 0;
    $total = isset($state['total']) && is_int($state['total']) ? $state['total'] : null;
    // 注入了传输实现＝调用方自带 transport（单元测试），不再对外发 HEAD
    if ($total === null && $fetcher === null) {
        $total = uo_download_total($url);
        $state['total'] = $total;
    }
    if ($total !== null && $received >= $total) {
        $state['received'] = $received;
        return ['state' => $state, 'received' => $received, 'total' => $total, 'error' => '', 'no_range' => false, 'complete' => true];
    }

    $to = $received + UO_DOWNLOAD_CHUNK_BYTES - 1;
    if ($total !== null) $to = min($to, $total - 1);

    $fh = @fopen($part, 'ab');
    if (!$fh) return $fail($state, $received, $total, '无法写入临时文件，storage/upgrade 不可写');
    $fetch = $fetcher ?? 'uo_download_range';
    [$status, $reportedTotal, $err] = $fetch($url, $received, $to, $fh);
    fclose($fh);

    clearstatcache(true, $part);
    $now = is_file($part) ? (int) filesize($part) : 0;
    if ($err !== '' && $status === 0) {
        // 有进展就不算失败：网络慢导致这一段没拉满，下一轮从新 offset 接着来。
        if ($now <= $received) return $fail($state, $now, $total, '下载失败: ' . $err);
        $status = 206;
    }
    if ($status >= 400) {
        return $fail($state, $received, $total, "下载失败: HTTP $status");
    }
    if ($status === 200 && $received > 0) {
        // 服务端忽略 Range、把整包又发了一遍——续传语义已被破坏，必须清零重来，
        // 否则文件里是「前半截 + 完整包」的拼接。
        uo_download_reset_part($part);
        $state['total'] = $total;
        return $fail($state, 0, $total, '服务器不支持断点续传，已重置进度，请重试', true);
    }
    // Content-Range 报出来的长度覆盖 HEAD 的猜测：后者可能是一张错误页的长度。
    if (is_int($reportedTotal) && $reportedTotal > 0 && $reportedTotal !== $total) {
        if ($received > 0 && $total !== null) {
            // 已经下了一部分，远端长度却变了 —— 包被换过，续传的字节不再可信
            uo_download_reset_part($part);
            $state['total'] = $reportedTotal;
            return $fail($state, 0, $reportedTotal, '远端安装包已变更，已重置下载进度，请重试');
        }
        $total = $reportedTotal;
        $state['total'] = $total;
    }

    $state['received'] = $now;
    return [
        'state' => $state, 'received' => $now, 'total' => $total, 'error' => '', 'no_range' => false,
        'complete' => $total !== null && $now >= $total,
    ];
}

/** 清空半截文件。 */
function uo_download_reset_part(string $path): void
{
    $fh = @fopen($path, 'wb');
    if ($fh) fclose($fh);
    clearstatcache(true, $path);
}

/** 收尾：校验 SHA256、落位成 package.zip、写验签上下文。 */
function uo_download_finalize(string $part, string $hash, string $ver, string $owner, ?int $total): array
{
    $actual = hash_file('sha256', $part);
    if (!hash_equals(strtolower($hash), strtolower((string) $actual))) {
        @unlink($part);
        @unlink(uo_download_state_file());
        return ['code' => 1, 'msg' => 'SHA256 校验不通过，包可能损坏或被篡改，已删除'];
    }
    $pkg = uo_dir() . '/package.zip';
    @unlink($pkg);
    if (!@rename($part, $pkg)) {
        return ['code' => 1, 'msg' => '安装包已校验，但无法落位到 package.zip'];
    }
    @unlink(uo_download_state_file());
    if (!uo_write_json_locked(uo_package_meta_file(), [
        'version' => $ver,
        'hash' => 'sha256:' . $hash,
        'verified_at' => time(),
        'owner' => $owner === 'auto' ? 'auto' : 'manual',
    ])) {
        @unlink($pkg);
        return ['code' => 1, 'msg' => '安装包已校验，但验签上下文无法持久化，已拒绝继续'];
    }
    return ['code' => 0, 'done' => true, 'msg' => '下载并校验通过', 'size' => (int) filesize($pkg), 'signed' => true, 'total' => $total];
}

function upgrade_download_package(string $url, string $hash, string $version, string $sig, string $owner = 'manual'): array
{
    // 无头调用方（cron 自动升级、测试）走这里：内部就是反复调 upgrade_download_chunk，
    // 只有一份下载实现。它们不经过网关，所以循环到底即可。
    $guard = 0;
    $lastReceived = -1;
    while (true) {
        $r = upgrade_download_chunk($url, $hash, $version, $sig, $owner);
        if (($r['code'] ?? 1) !== 0) return $r;
        if (!empty($r['done'])) return $r;
        $received = (int) ($r['received'] ?? 0);
        // 连续多轮零进展就停手，别把无限循环留给 cron。
        $guard = $received > $lastReceived ? 0 : $guard + 1;
        if ($guard >= 3) {
            return ['code' => 1, 'msg' => '下载停滞：连续多次未取得新字节，已中止', 'received' => $received];
        }
        $lastReceived = $received;
    }
}
