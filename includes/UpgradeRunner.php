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
    //   favicon.ico  图标工坊「一键应用到本站」写的就是它，被盖回去等于插件功能失效
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
function uo_zip_entries(ZipArchive $zip, string $prefix): array
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
    return $out;
}

/** 分批覆盖的进度状态文件路径 */
function uo_state_file(): string { return uo_dir() . '/apply_state.json'; }

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

function upgrade_prepare(): array
{
    $pkg = uo_dir() . '/package.zip';
    if (!is_file($pkg)) return ['code' => 1, 'msg' => '未找到已下载的安装包，请先执行下载'];
    if (!class_exists('ZipArchive')) return ['code' => 1, 'msg' => '缺少 ZipArchive 扩展'];

    // 备份 config.php + 记录旧版本（轻量、稳妥；完整代码回滚依赖主机备份）
    $oldVer = defined('CMS_VERSION') ? CMS_VERSION : 'unknown';
    $bakDir = ROOT_PATH . '/storage/backups/pre-upgrade-' . $oldVer . '-' . date('YmdHis');
    if (!is_dir($bakDir)) {
        @mkdir($bakDir, 0755, true);
    }
    @copy(ROOT_PATH . '/config/config.php', $bakDir . '/config.php');

    // 数据库自动备份（v1.18.6）：文件快照管代码，这份 SQL 管「迁移改表之后」
    // 的事故兜底——两者合起来才是完整的升级前状态。失败不阻断升级
    // （备份是保护层，不该把升级打挂），但响应里明示，前端提醒手动备份。
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

    @file_put_contents($bakDir . '/INFO.txt', "升级前版本: $oldVer\n时间: " . date('Y-m-d H:i:s') . "\n"
        . ($dbBackupNote !== '' ? "数据库备份: {$dbBackupNote}\n" : "数据库备份: 失败（{$dbBackupError}）\n"));

    $zip = new ZipArchive();
    if ($zip->open($pkg) !== true) return ['code' => 1, 'msg' => '安装包打开失败'];
    // zip-slip 防护：条目名越界则中止
    $unsafe = zipUnsafeEntry($zip);
    if ($unsafe !== null) { $zip->close(); return ['code' => 1, 'msg' => '安装包含非法路径条目，已中止：' . $unsafe]; }
    // zip bomb 防护。限值比主题/插件宽：全量包本身就有 8000+ 文件（v1.18.4 实测 8400）
    $violation = zipResourceViolation($zip, 30000, 524_288_000, 31_457_280);
    if ($violation !== null) { $zip->close(); return ['code' => 1, 'msg' => '安装包未通过资源安全检查，已中止：' . $violation]; }

    // 判定增量 / 全量 + 定位包内前缀（不解压，只读条目名/manifest）
    $deleted = []; $from = ''; $to = '';
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
        // 因此在改动任何文件之前，先核对 from 就是本站当前版本。（codex 审计 P2-2）
        $current = defined('CMS_VERSION') ? (string) CMS_VERSION : '';
        if ($from !== '' && $current !== '' && $from !== $current) {
            $zip->close();
            return ['code' => 1, 'msg' => '增量包基线不匹配（包适用于 v' . $from
                . '，本站为 v' . $current . '），已中止，未改动任何文件'];
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
        'errors' => [], 'from' => $from, 'to' => $to,
        // 事务化升级：apply_batch 覆盖前把旧文件快照到 backups/<backup>/files/，
        // 全新写入的文件记入 created——两者合起来就是完整的文件级回滚清单
        'created' => [],
    ];
    try {
        UpgradeApplyState::write(uo_state_file(), $state);
    } catch (RuntimeException) {
        return ['code' => 1, 'msg' => __('upgrade_apply_state_write_failed')];
    }
    return [
        'code' => 0, 'mode' => $mode, 'total' => count($entries), 'backup' => basename($bakDir),
        'db_backup' => $dbBackupNote, 'db_backup_error' => $dbBackupError,
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
                    // 覆盖前快照旧文件（同一文件只快照第一次的版本）；全新文件记 created。
                    // 人工升级：快照失败不中止——回滚只是尽力保障，用户看得见告警可自行判断。
                    // 无人值守：快照是自动回滚的**唯一依据**，失败等于安全网没了，所以单独
                    // 计数（snapshot_failed），由 AutoUpgrade 当致命错误处理。两种语义分开记。
                    if (is_file($d)) {
                        $snap = $bakFilesDir . '/' . $rel;
                        if (!is_file($snap)) {
                            $sd = dirname($snap);
                            $okDir = is_dir($sd) || @mkdir($sd, 0755, true) || is_dir($sd);
                            if (!$okDir || !@copy($d, $snap)) {
                                $snapFails++;
                            }
                        }
                    } else {
                        $created[] = $rel;
                    }
                    if (@file_put_contents($d, $data) !== false) $copied++; else $errors[] = "写入失败: $rel";
                }
            } finally {
                $zip->close();
            }
            $state['created'] = $created;

            $state['done'] = (int) ($state['done'] ?? 0) + $copied;
            $state['next_offset'] = $end;
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

function upgrade_finalize(string $note = ''): array
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

    // 删除清单（仅增量有）：拒绝绝对路径/越界/受保护路径，仅删普通文件。
    // 删除前先把文件挪进快照目录——删除也要可回滚
    $bakFilesDir = ROOT_PATH . '/storage/backups/' . basename((string) ($state['backup'] ?? '')) . '/files';
    $deletedCount = 0;
    $deletedRels = [];
    foreach ((array) ($state['deleted'] ?? []) as $rel) {
        $rel = (string) $rel;
        if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) continue;
        if (uo_is_protected($rel)) continue;
        $p = ROOT_PATH . '/' . $rel;
        if (!is_file($p)) continue;
        $snap = $bakFilesDir . '/' . $rel;
        if (!is_file($snap)) {
            $sd = dirname($snap);
            if (is_dir($sd) || @mkdir($sd, 0755, true) || is_dir($sd)) @copy($p, $snap);
        }
        if (@unlink($p)) { $deletedCount++; $deletedRels[] = $rel; }
    }
    $patch = uo_patch_config_version();

    // 回滚清单落盘（state 文件马上要清，回滚所需信息随备份目录持久化）
    @file_put_contents(
        dirname($bakFilesDir) . '/rollback.json',
        json_encode([
            'from' => (string) ($state['from'] ?? ''),
            'to' => (string) ($state['to'] ?? ''),
            'created' => array_values(array_unique((array) ($state['created'] ?? []))),
            'deleted' => $deletedRels,
            'time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

    // 清理临时（本版本不再产生 extracted/ 目录；顺手清理旧版本可能残留的）
    uo_rrmdir(uo_dir() . '/extracted');
    @unlink(uo_dir() . '/package.zip');
    @unlink($sf);

    $errors = $state['errors'] ?? [];
    $copied = (int) ($state['done'] ?? $state['total'] ?? 0);
    $mode   = $state['mode'] ?? 'full';
    // 失败清单写入持久日志（收尾已删状态文件，名单本会丢失）；供事后排查/手动补文件。
    if (!empty($errors)) {
        $verPair = $mode === 'delta' ? "{$state['from']}→{$state['to']}" : ('→' . ($state['to'] ?? ''));
        @file_put_contents(
            uo_dir() . '/upgrade-failures.log',
            '[' . date('Y-m-d H:i:s') . "] {$verPair} 覆盖 {$copied}，失败 " . count($errors) . "：\n  - " . implode("\n  - ", $errors) . "\n",
            FILE_APPEND
        );
    }
    $failNote = empty($errors) ? '' : ('，失败 ' . count($errors) . '：' . implode('; ', array_slice($errors, 0, 10)));
    try { adminLog('upgrade', 'online_apply', ($mode === 'delta' ? "增量升级 {$state['from']}→{$state['to']}" : '在线升级') . "：覆盖 {$copied} / 删 {$deletedCount}，config补丁:{$patch}{$failNote}"); } catch (\Throwable $e) {}

    $newVer = '';
    $vf = @file_get_contents(ROOT_PATH . '/config/version.php');
    if ($vf && preg_match("/CMS_VERSION'\\s*,\\s*'([^']+)'/", $vf, $m)) $newVer = $m[1];

    // 还有几条迁移要跑？多数版本一条都没有，那就不该把人赶去「数据库升级」页
    // 只为看一句「全部已应用」。注意这里读的是刚覆盖上去的**新** migrations/。
    $pending = null;
    try {
        require_once ROOT_PATH . '/includes/Migrator.php';
        $pending = 0;
        foreach (Migrator::loadAll() as $mg) {
            if (!Migrator::isApplied($mg)) $pending++;
        }
    } catch (Throwable $e) {
        $pending = null;   // 数不出来就按老路子跳，宁可多跳一趟也别漏掉迁移
    }

    // 记下这次升级的落点，供「升级完成」页展示（版本号、时间、更新说明）。
    // 说明文字来自更新服务器，渲染时一律 e() 转义。
    try {
        settingModel()->set('last_upgrade_from', (string) ($state['from'] ?? ''), 'system');
        settingModel()->set('last_upgrade_to', $newVer ?: (string) ($state['to'] ?? ''), 'system');
        settingModel()->set('last_upgrade_at', (string) time(), 'system');
        settingModel()->set('last_upgrade_note', mb_substr(trim($note), 0, 4000), 'system');
    } catch (Throwable $e) {
        // 记不上不影响升级本身
    }

    // 升级后健康自检：核心文件语法 + 版本号可读。失败 = 疑似新旧代码混合状态，
    // 前端据此提供「一键回滚到升级前」按钮（从 backups/<backup>/files 快照恢复）
    $health = uo_health_check();

    return [
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

    // 1) 恢复快照（被覆盖 + 被删除的文件都在里面）
    [$restored, $restoreErrors] = uo_copy_tree($filesDir, ROOT_PATH);

    // 2) 移除升级新建的文件（旧版没有它们；防越界与受保护路径）
    $removed = 0;
    foreach ((array) ($rb['created'] ?? []) as $rel) {
        $rel = (string) $rel;
        if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) continue;
        if (uo_is_protected($rel)) continue;
        if (is_file(ROOT_PATH . '/' . $rel) && @unlink(ROOT_PATH . '/' . $rel)) $removed++;
    }

    // 3) 清理升级中间态，避免半途状态被后续误用
    @unlink(uo_state_file());
    @unlink(uo_dir() . '/package.zip');

    $health = uo_health_check();
    try { adminLog('upgrade', 'online_rollback', "在线升级回滚（{$backup}）：恢复 {$restored}，移除新建 {$removed}，恢复失败 " . count($restoreErrors)); } catch (\Throwable $e) {}
    return [
        'code' => empty($restoreErrors) ? 0 : 2,
        'msg' => "回滚完成：恢复 {$restored} 个文件，移除新建 {$removed} 个"
            . (empty($restoreErrors) ? '' : ('，' . count($restoreErrors) . ' 个恢复失败：' . implode('; ', array_slice($restoreErrors, 0, 10)))),
        'restored' => $restored,
        'removed' => $removed,
        'errors' => array_slice($restoreErrors, 0, 20),
        'health' => $health,
    ];
    }

function upgrade_download_package(string $url, string $hash, string $version, string $sig): array
{
    
    $hash = strtolower((string) preg_replace('/^sha256:/i', '', $hash));
    $ver = trim($version);
    $sig = trim($sig);
    if (!preg_match('#^https://update\.yikaicms\.com/packages/[A-Za-z0-9._-]+\.zip$#', $url)) {
        return ['code' => 1, 'msg' => '下载地址不合法，仅允许官方 packages 目录'];
    }
    if (strlen($hash) !== 64) return ['code' => 1, 'msg' => '缺少有效的 SHA256 校验值，拒绝升级'];
    if (!is_dir(uo_dir())) {
        @mkdir(uo_dir(), 0755, true);
    }
    $pkg = uo_dir() . '/package.zip';
    @unlink($pkg);
    if ($sig === '') return ['code' => 1, 'msg' => '升级包缺少 RSA 签名，拒绝升级'];
    if (!uo_verify_sig($ver, 'sha256:' . $hash, $sig)) {
        return ['code' => 1, 'msg' => 'RSA 签名校验失败，拒绝升级'];
    }
    [$ok, $err] = uo_download($url, $pkg);
    if (!$ok) return ['code' => 1, 'msg' => $err];
    $actual = hash_file('sha256', $pkg);
    if (!hash_equals(strtolower($hash), strtolower((string) $actual))) {
        @unlink($pkg);
        return ['code' => 1, 'msg' => 'SHA256 校验不通过，包可能损坏或被篡改，已删除'];
    }
    return ['code' => 0, 'msg' => '下载并校验通过', 'size' => filesize($pkg), 'signed' => true];
    }
