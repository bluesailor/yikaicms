<?php
/**
 * 官方远程修复（2.0.3）：服务商签名的「修复配方」，随更新检查下发，站点验签后按白名单步骤执行。
 *
 * 动机：老站升级后数据库缺一列、某个设置被写坏、某条迁移中途失败——以前只能请站长发后台密码
 * 或 FTP 给我们手工修。现在站长在「升级」页打开「允许服务商远程升级与修复本站」后，我们在
 * 更新服务器控制台为这个站签一份配方，站点下一次检查更新（每小时一次）时取到并执行，结果随
 * 下一次检查回报。没打开授权的站，配方在验签之前就被忽略。
 *
 * 规范串：`repair1|<domain>|<install>|<id>|<recipe 的 sha256>|<issued_at>|<expires_at>`
 * 用发版私钥签、内置公钥验（与升级包、升级指令同一把钥匙）；配方原文以 JSON 字符串下发，
 * 哈希按原文字节算，不依赖任何一侧的 JSON 规范化。
 *
 * 配方能做的事刻意很窄（即使私钥泄露，也比伪造升级包能做的少得多；更重要的是对站长说得清）：
 *   - sql        一条 UPDATE / INSERT / REPLACE / DELETE / ALTER TABLE / CREATE TABLE / CREATE INDEX；
 *                表名一律写 {p}name；不得触碰账号、会员、订单、询盘、日志等表，不得出现注释、分号、
 *                文件读写与延时函数
 *   - setting    改一个设置项；密钥、授权、升级开关类设置不能改
 *   - migration  重跑一条指定迁移，或补跑全部待执行迁移
 *   - cache_clear 清缓存
 * 另有可选的 when（一条 SELECT，结果为 0 / 空即「无需修复」）与适用版本范围。
 * 执行前备份涉及的表到 storage/backups，结果写入本地记录并邮件告知订阅了升级通知的站长。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class RemoteRepair
{
    /** 配方最长有效期：服务器会在站点回报结果前反复下发同一份配方，有效期内都能执行。 */
    public const MAX_TTL = 604800;

    private const LOG_KEY = 'remote_repair_log';
    private const LOG_MAX = 30;
    /** 一次检查最多执行几份，防止一次回访堆积太多改动。 */
    private const MAX_PER_CHECK = 3;
    private const MAX_STEPS = 20;
    private const MAX_RECIPE_BYTES = 32768;

    /** 配方不能碰的表（含插件表名里出现这些词的，如 shop_orders）。 */
    private const SENSITIVE_TABLE = '/(^|_)(users?|roles?|members?|admin_logs?|dologin_links?|mail_logs?|forms?|orders?|payments?|customers?|addresses?|settings)($|_)/';

    /** 不能改的设置：密钥类之外，还有授权、升级与远程访问开关（配方不能给自己扩权）。 */
    private const PROTECTED_SETTING = '/^(license|managed_upgrade|auto_upgrade|support_access|remote_repair|update_mail|update_channel|admin_|login_|cron_|install_)/';

    /**
     * 处理 check 响应里的 repairs 段。只在站长授权了远程升级与修复时调用。
     *
     * @return int 本次执行（含判定为无需修复 / 不适用）的配方数
     */
    public static function process(mixed $repairs): int
    {
        if (!is_array($repairs) || $repairs === []) {
            return 0;
        }
        if (!function_exists('license_pubkey')) {
            require_once ROOT_PATH . '/includes/License.php';
        }
        $done = 0;
        foreach (array_slice(array_values($repairs), 0, self::MAX_PER_CHECK) as $item) {
            $verified = self::verifyWith($item, license_pubkey());
            if ($verified === null || self::seen($verified['id'])) {
                continue;
            }
            $result = self::run($verified['id'], $verified['recipe']);
            self::record($verified['id'], $verified['recipe'], $result);
            $done++;
        }
        return $done;
    }

    /**
     * 验签并解析一份配方；任何一项不合规都返回 null。
     *
     * @return array{id:string, recipe:array<string,mixed>}|null
     */
    public static function verifyWith(mixed $item, string $publicKeyPem): ?array
    {
        if (!is_array($item) || !function_exists('openssl_verify')) {
            return null;
        }
        $id = (string) ($item['id'] ?? '');
        $domain = (string) ($item['domain'] ?? '');
        $install = (string) ($item['install'] ?? '');
        $json = $item['recipe'] ?? null;
        $issued = (int) ($item['issued_at'] ?? 0);
        $expires = (int) ($item['expires_at'] ?? 0);
        $sig = base64_decode((string) ($item['sig'] ?? ''), true);
        if (preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/D', $id) !== 1 || $domain === '' || $install === ''
            || !is_string($json) || $json === '' || strlen($json) > self::MAX_RECIPE_BYTES || $sig === false || $sig === '') {
            return null;
        }
        $now = time();
        if ($issued > $now + 300 || $expires <= $now || $expires - $issued > self::MAX_TTL) {
            return null;
        }
        require_once __DIR__ . '/UpgradeDirective.php';
        require_once __DIR__ . '/InstallIdentity.php';
        $mine = InstallIdentity::id();
        if (!UpgradeDirective::domainMatches($domain) || $mine === '' || !hash_equals($mine, $install)) {
            return null;
        }
        $canonical = 'repair1|' . $domain . '|' . $install . '|' . $id . '|' . hash('sha256', $json) . '|' . $issued . '|' . $expires;
        if (openssl_verify($canonical, $sig, $publicKeyPem, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        $recipe = json_decode($json, true);
        return is_array($recipe) ? ['id' => $id, 'recipe' => $recipe] : null;
    }

    /**
     * 执行一份已验签的配方。
     *
     * @param array<string,mixed> $recipe
     * @return array{status:string, msg:string, backup:string}
     */
    public static function run(string $id, array $recipe): array
    {
        $steps = $recipe['steps'] ?? null;
        if (!is_array($steps) || $steps === [] || count($steps) > self::MAX_STEPS) {
            return ['status' => 'rejected', 'msg' => 'invalid steps', 'backup' => ''];
        }
        foreach (array_values($steps) as $i => $step) {
            $problem = self::stepProblem($step);
            if ($problem !== null) {
                return ['status' => 'rejected', 'msg' => 'step ' . ($i + 1) . ': ' . $problem, 'backup' => ''];
            }
        }
        if (!self::versionApplies($recipe['versions'] ?? null)) {
            return ['status' => 'not_applicable', 'msg' => defined('CMS_VERSION') ? CMS_VERSION : '', 'backup' => ''];
        }
        $when = $recipe['when'] ?? null;
        if ($when !== null) {
            $problem = is_string($when) ? self::sqlProblem($when, true) : 'when must be a SELECT';
            if ($problem !== null) {
                return ['status' => 'rejected', 'msg' => 'when: ' . $problem, 'backup' => ''];
            }
            try {
                $needed = db()->fetchColumn(self::expand($when));
            } catch (Throwable $e) {
                return ['status' => 'failed', 'msg' => 'when: ' . self::scrub($e->getMessage()), 'backup' => ''];
            }
            if ($needed === null || $needed === false || $needed === '' || $needed === '0' || $needed === 0) {
                return ['status' => 'not_needed', 'msg' => '', 'backup' => ''];
            }
        }

        try {
            $backup = self::backup($id, $steps);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'msg' => 'backup: ' . self::scrub($e->getMessage()), 'backup' => ''];
        }

        foreach (array_values($steps) as $i => $step) {
            try {
                self::apply($step);
            } catch (Throwable $e) {
                return ['status' => 'failed', 'msg' => 'step ' . ($i + 1) . ': ' . self::scrub($e->getMessage()), 'backup' => $backup];
            }
        }
        return ['status' => 'ok', 'msg' => '', 'backup' => $backup];
    }

    /**
     * 本地执行记录（新的在前）。
     *
     * @return list<array{id:string, title:string, status:string, msg:string, at:int, backup:string}>
     */
    public static function log(): array
    {
        $rows = json_decode((string) settingModel()->get(self::LOG_KEY, ''), true);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $out[] = [
                    'id' => $row['id'],
                    'title' => (string) ($row['title'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'msg' => (string) ($row['msg'] ?? ''),
                    'at' => (int) ($row['at'] ?? 0),
                    'backup' => (string) ($row['backup'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * 随检查更新回报的结果：最近 10 份配方的 id:状态，外加最近一次失败的原因。
     *
     * @return array<string,string>
     */
    public static function reportParams(): array
    {
        $log = array_slice(self::log(), 0, 10);
        if ($log === []) {
            return [];
        }
        $out = ['repairs' => implode(',', array_map(static fn(array $r): string => $r['id'] . ':' . $r['status'], $log))];
        foreach ($log as $row) {
            if ($row['status'] === 'failed' || $row['status'] === 'rejected') {
                $out['repair_fail'] = mb_substr($row['id'] . ': ' . $row['msg'], 0, 200);
                break;
            }
        }
        return $out;
    }

    /** 一条步骤哪里不合规；合规返回 null。 */
    public static function stepProblem(mixed $step): ?string
    {
        if (!is_array($step)) {
            return 'not an object';
        }
        switch ((string) ($step['type'] ?? '')) {
            case 'sql':
                if (!is_string($step['sql'] ?? null)) {
                    return 'sql missing';
                }
                if (isset($step['sqlite']) && (!is_string($step['sqlite']) || ($p = self::sqlProblem($step['sqlite'], false)) !== null)) {
                    return 'sqlite: ' . ($p ?? 'not a string');
                }
                return self::sqlProblem($step['sql'], false);
            case 'setting':
                $key = (string) ($step['key'] ?? '');
                if (preg_match('/^[a-z0-9_]{1,64}$/D', $key) !== 1 || !is_scalar($step['value'] ?? null)) {
                    return 'invalid setting';
                }
                if (isSensitiveSettingKey($key) || preg_match(self::PROTECTED_SETTING, $key) === 1) {
                    return 'protected setting';
                }
                return null;
            case 'migration':
                $mid = $step['id'] ?? '';
                return is_string($mid) && ($mid === '' || preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $mid) === 1) ? null : 'invalid migration id';
            case 'cache_clear':
                return null;
        }
        return 'unknown step type';
    }

    /**
     * SQL 白名单检查；合规返回 null。表名必须写成 {p}name，执行时换成本站前缀。
     */
    public static function sqlProblem(string $sql, bool $select): ?string
    {
        $sql = trim($sql);
        if ($sql === '' || strlen($sql) > 8000) {
            return 'empty or too long';
        }
        // 先去掉字符串字面量再查结构：值里的 #fff、'sleep' 之类不算
        $bare = preg_replace('/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"/s', "''", $sql);
        if (!is_string($bare) || preg_match('/\'\'\'|[^\']\'[^\']|"/', ' ' . $bare . ' ') === 1) {
            return 'unbalanced quotes';
        }
        // 单条语句、无注释：分号、注释都可能把第二条语句藏进去
        if (preg_match('/;|--|#|\/\*/', $bare) === 1) {
            return 'semicolons and comments are not allowed';
        }
        if (preg_match('/\b(INTO\s+(OUT|DUMP)FILE|LOAD_FILE|LOAD\s+DATA|SLEEP|BENCHMARK|GET_LOCK|ATTACH|DETACH|PRAGMA|GRANT|REVOKE|SET\s+PASSWORD|INFORMATION_SCHEMA|PERFORMANCE_SCHEMA|SQLITE_MASTER|MYSQL\s*\.)\b/i', $bare) === 1) {
            return 'forbidden keyword';
        }
        $head = $select
            ? '/^SELECT\s/i'
            : '/^(UPDATE\s+\{p\}|INSERT\s+INTO\s+\{p\}|REPLACE\s+INTO\s+\{p\}|DELETE\s+FROM\s+\{p\}|ALTER\s+TABLE\s+\{p\}|CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+\{p\}|CREATE\s+(UNIQUE\s+)?INDEX\s+\w+\s+ON\s+\{p\})/i';
        if (preg_match($head, $sql) !== 1) {
            return $select ? 'must be a SELECT' : 'statement type not allowed';
        }
        if (preg_match_all('/\{p\}([a-z0-9_]+)/', $bare, $m) < 1) {
            return 'tables must be written as {p}name';
        }
        // 结构变更（ALTER / CREATE）不读数据，可以作用于任何表；读写数据的语句不能碰敏感表
        $isDdl = !$select && preg_match('/^(ALTER|CREATE)\b/i', $sql) === 1;
        if (!$isDdl) {
            foreach ($m[1] as $table) {
                if (preg_match(self::SENSITIVE_TABLE, $table) === 1) {
                    return 'table ' . $table . ' is off limits';
                }
            }
        }
        return null;
    }

    private static function seen(string $id): bool
    {
        foreach (self::log() as $row) {
            if ($row['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    private static function versionApplies(mixed $range): bool
    {
        if ($range === null) {
            return true;
        }
        if (!is_array($range)) {
            return false;
        }
        $current = explode('-', defined('CMS_VERSION') ? CMS_VERSION : '0', 2)[0];
        $min = (string) ($range['min'] ?? '');
        $max = (string) ($range['max'] ?? '');
        return ($min === '' || version_compare($current, $min, '>='))
            && ($max === '' || version_compare($current, $max, '<='));
    }

    private static function expand(string $sql): string
    {
        return str_replace('{p}', DB_PREFIX, $sql);
    }

    /** @param array<string,mixed> $step */
    private static function apply(array $step): void
    {
        switch ((string) $step['type']) {
            case 'sql':
                $sql = (string) $step['sql'];
                if (db()->isSqlite()) {
                    if (isset($step['sqlite'])) {
                        $sql = (string) $step['sqlite'];
                    } else {
                        require_once __DIR__ . '/Migrator.php';
                        $converted = _sqlToSqlite($sql);
                        if ($converted === null) {
                            return;   // SQLite 不需要（如 ADD INDEX）
                        }
                        $sql = $converted;
                    }
                }
                try {
                    db()->execute(self::expand($sql));
                } catch (Throwable $e) {
                    require_once __DIR__ . '/Migrator.php';
                    if (!Migrator::isIdempotentSqlError($e->getMessage())) {
                        throw $e;
                    }
                }
                return;
            case 'setting':
                settingModel()->set((string) $step['key'], (string) $step['value'], 'system');
                return;
            case 'migration':
                self::migrate((string) ($step['id'] ?? ''));
                return;
            case 'cache_clear':
                if (function_exists('cacheClear')) {
                    cacheClear();
                }
                if (class_exists('HtmlCache')) {
                    HtmlCache::invalidate();
                }
                settingModel()->clearCache();
                return;
        }
    }

    /** 重跑一条迁移（$id 非空，不论是否已标记执行），或补跑全部待执行迁移。 */
    private static function migrate(string $id): void
    {
        require_once __DIR__ . '/Migrator.php';
        if (!Migrator::beginRun('remote-repair')) {
            throw new RuntimeException('another migration run is in progress');
        }
        try {
            $all = Migrator::loadAll();
            $targets = [];
            foreach ($all as $mid => $migration) {
                if ($id !== '' ? (string) $mid === $id : !Migrator::isApplied($migration)) {
                    $targets[] = $migration;
                }
            }
            if ($id !== '' && $targets === []) {
                throw new RuntimeException('migration ' . $id . ' not found');
            }
            foreach ($targets as $migration) {
                $r = Migrator::runOne($migration);
                if (!$r['ok']) {
                    throw new RuntimeException((string) ($migration['id'] ?? '') . ': ' . $r['message']);
                }
            }
        } finally {
            Migrator::endRun();
        }
    }

    /**
     * 执行前备份：只动表的配方备份那几张表；有迁移步骤时备份全部本站表。
     *
     * @param array<int|string,mixed> $steps
     */
    private static function backup(string $id, array $steps): string
    {
        require_once __DIR__ . '/Backup.php';
        $existing = Backup::listPrefixedTables();
        $tables = [];
        foreach ($steps as $step) {
            $type = (string) ($step['type'] ?? '');
            if ($type === 'migration') {
                $tables = $existing;
                break;
            }
            if ($type === 'setting') {
                $tables[] = DB_PREFIX . 'settings';
            }
            if ($type === 'sql' && preg_match_all('/\{p\}([a-z0-9_]+)/', (string) $step['sql'], $m) > 0) {
                foreach ($m[1] as $table) {
                    $tables[] = DB_PREFIX . $table;
                }
            }
        }
        $tables = array_values(array_intersect(array_unique($tables), $existing));
        if ($tables === []) {
            return '';
        }
        $file = 'repair_' . $id . '_' . date('Ymd_His') . '.sql';
        Backup::writeToBackupsDir(Backup::generateSql($tables), $file);
        return $file;
    }

    /**
     * 回报到服务器、写进日志的报错不带数据：把引号里的值换成省略号
     * （如 Duplicate entry 'someone@example.com' 里的邮箱）。
     */
    private static function scrub(string $message): string
    {
        $message = (string) preg_replace("/'[^']*'/", "'…'", $message);
        $message = (string) preg_replace('/"[^"]*"/', '"…"', $message);
        return mb_substr($message, 0, 300);
    }

    /**
     * @param array<string,mixed> $recipe
     * @param array{status:string, msg:string, backup:string} $result
     */
    private static function record(string $id, array $recipe, array $result): void
    {
        $title = mb_substr(trim((string) ($recipe['title'] ?? $id)), 0, 120);
        $log = self::log();
        array_unshift($log, ['id' => $id, 'title' => $title, 'at' => time()] + $result);
        settingModel()->set(self::LOG_KEY, (string) json_encode(array_slice($log, 0, self::LOG_MAX), JSON_UNESCAPED_UNICODE), 'system');

        try {
            adminLogModel()->log([
                'admin_id' => 0,
                'admin_name' => 'remote-repair',
                'module' => 'repair',
                'action' => $result['status'],
                'description' => '官方远程修复 ' . $id . '：' . $title . ($result['msg'] !== '' ? '（' . $result['msg'] . '）' : ''),
                'url' => '', 'method' => 'CRON', 'request_data' => '', 'ip' => '', 'user_agent' => '',
                'created_at' => time(),
            ]);
        } catch (Throwable) {
            // 操作日志写不进不影响修复结果的记录
        }
        if ($result['status'] === 'ok' || $result['status'] === 'failed') {
            self::notify($id, $title, $result);
        }
    }

    /** @param array{status:string, msg:string, backup:string} $result */
    private static function notify(string $id, string $title, array $result): void
    {
        try {
            require_once __DIR__ . '/UpdateMailSubscription.php';
            $sub = UpdateMailSubscription::current();
            if (!$sub['on'] || !function_exists('sendMail')) {
                return;
            }
            $site = (string) config('site_name', 'YikaiCMS');
            $vars = ['site' => $site, 'title' => $title, 'id' => $id, 'msg' => $result['msg'], 'backup' => $result['backup'] !== '' ? $result['backup'] : '-'];
            $subject = __('repair_mail_subject_' . $result['status'], $vars);
            $body = __('repair_mail_body_' . $result['status'], $vars);
            sendMail($sub['email'], $subject, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
        } catch (Throwable $e) {
            error_log('[RemoteRepair] notify failed: ' . $e->getMessage());
        }
    }
}
