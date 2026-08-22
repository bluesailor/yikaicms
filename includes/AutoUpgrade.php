<?php
/**
 * YikaiCMS —— 自动升级（v1.18.6）。
 *
 * 无人值守地跑与后台「在线升级」**完全相同**的那条管道（includes/UpgradeRunner.php），
 * 不另写一套：升级是最不该有两份实现的地方。
 *
 * ## 为什么是「拉」不是「推」
 * 站点定期回访 update 服务器的 check.php（installs 清单就是这么来的）。批量升级指令
 * 顺着这条既有通道下发，站点自己取走执行——**站点因此不需要对外开任何端点**，
 * NAT / 防火墙后面照常工作，也不存在「远程触发升级」这个攻击面。
 * 代价只是最长一个 cron 周期的延迟。
 *
 * ## 安全网（每一条都对应真实事故）
 *  - 维护窗口：默认凌晨，避开访问高峰。2026-08-22 cile.cn 白天升级把 PHP-FPM 拖死一小时。
 *  - 升级前自动备份数据库（v1.18.5 起 prepare 阶段自带）。
 *  - 事务化：覆盖前逐文件快照 + rollback.json。
 *  - 升级后健康自检，不通过**自动回滚**——无人值守时没人来救场，必须自己退回去。
 *  - 并发锁：一次只允许一个升级在跑，cron 重入直接退出。
 *  - 结果落盘，下次回访时回报服务器。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class AutoUpgrade
{
    /** 单次 cron 内最多覆盖多少文件；剩余的下一轮继续（状态机支持断点续传）。 */
    private const BATCH_LIMIT = 400;

    /** 历史保留条数。 */
    private const LOG_MAX = 20;

    public static function enabled(): bool
    {
        return (string) config('auto_upgrade_enabled', '0') === '1';
    }

    /** 'security' = 只自动装安全更新（默认）；'stable' = 所有正式版。 */
    public static function scope(): string
    {
        return (string) config('auto_upgrade_scope', 'security') === 'stable' ? 'stable' : 'security';
    }

    /**
     * 维护窗口 "HH:MM-HH:MM"，按站点时区判断；跨零点（如 23:00-02:00）也支持。
     * 窗口非法时回退默认，不让配置错误变成「永远不升级」或「随时升级」。
     */
    public static function inWindow(?int $now = null): bool
    {
        $raw = trim((string) config('auto_upgrade_window', '03:00-05:00'));
        if (preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $raw, $m) !== 1) {
            $raw = '03:00-05:00';
            preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $raw, $m);
        }
        $start = ((int) $m[1]) * 60 + (int) $m[2];
        $end = ((int) $m[3]) * 60 + (int) $m[4];
        $now ??= time();
        $cur = (int) date('G', $now) * 60 + (int) date('i', $now);
        return $start <= $end ? ($cur >= $start && $cur < $end) : ($cur >= $start || $cur < $end);
    }

    /** @return array<int, array<string, mixed>> 最近的升级历史（新的在前） */
    public static function log(): array
    {
        $raw = (string) config('auto_upgrade_log', '');
        if ($raw === '') {
            return [];
        }
        $list = json_decode($raw, true);
        return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    }

    private static function logAdd(string $result, string $msg, string $from, string $to): void
    {
        $log = self::log();
        array_unshift($log, [
            'time' => date('Y-m-d H:i:s'),
            'result' => $result,          // ok | rolled_back | failed | skipped
            'from' => $from,
            'to' => $to,
            'msg' => mb_substr($msg, 0, 400),
        ]);
        settingModel()->set('auto_upgrade_log', json_encode(array_slice($log, 0, self::LOG_MAX), JSON_UNESCAPED_UNICODE), 'system');
        // 单独存一份「最近结果」，回访时回报服务器用（不必解析整个日志）
        settingModel()->set('auto_upgrade_last_result', $result, 'system');
        settingModel()->set('auto_upgrade_last_at', (string) time(), 'system');
        settingModel()->set('auto_upgrade_last_to', $to, 'system');
    }

    /** @var resource|null 持锁期间的文件句柄 */
    private static $lockHandle = null;

    /**
     * 并发锁。用 flock 而不是设置表的「先读后写」——后者两个 cron 能同时读到「没锁」
     * 再一起写入，等于没锁（codex 审计 P1-2）。flock 是内核级原子操作，共享主机通用。
     *
     * 锁文件放 storage/upgrade/，与升级状态同目录：状态被清理时锁也一并清掉。
     */
    private static function lock(): bool
    {
        $dir = uo_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $fh = @fopen($dir . '/auto_upgrade.lock', 'c+');
        if ($fh === false) {
            return false;
        }
        // 非阻塞：拿不到就是别人正在跑，直接退出让下一次 cron 再来
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }
        // 记录持锁时间供人工排查；进程被 kill 时 flock 由内核释放，不会留死锁
        // （这正是它比设置表标记好的地方——设置表标记要靠 TTL 猜，猜早了会撞车）
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) time());
        fflush($fh);
        self::$lockHandle = $fh;
        return true;
    }

    private static function unlock(): void
    {
        if (self::$lockHandle !== null) {
            @flock(self::$lockHandle, LOCK_UN);
            @fclose(self::$lockHandle);
            self::$lockHandle = null;
        }
    }

    /**
     * 问更新服务器：有没有该升的版本？顺带上报本站状态（供服务器控制台展示）。
     *
     * @return array<string, mixed>|null 服务器返回的 data 段；不可达返回 null
     */
    public static function check(): ?array
    {
        require_once ROOT_PATH . '/includes/UpdateChannel.php';
        $q = [
            'version' => defined('CMS_VERSION') ? CMS_VERSION : '',
            'channel' => UpdateChannel::current(),
            'domain' => (string) ($_SERVER['HTTP_HOST'] ?? config('site_url', '')),
            'site_name' => (string) config('site_name', ''),
            'php' => PHP_VERSION,
            // 自动升级状态随回访上报：服务器据此在控制台标注哪些站可以批量下发
            'auto' => self::enabled() ? '1' : '0',
            'auto_scope' => self::scope(),
            'auto_window' => (string) config('auto_upgrade_window', '03:00-05:00'),
            'auto_result' => (string) config('auto_upgrade_last_result', ''),
            'auto_at' => (string) config('auto_upgrade_last_at', ''),
            't' => (string) time(),
        ];
        $url = 'https://update.yikaicms.com/api/update/check.php?' . http_build_query($q);
        $ctx = stream_context_create([
            'http' => ['timeout' => 20, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false && function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true]);
            $resp = curl_exec($ch);
            curl_close($ch);
        }
        $d = $resp ? json_decode((string) $resp, true) : null;
        if (!is_array($d) || ($d['code'] ?? 1) !== 0 || !is_array($d['data'] ?? null)) {
            return null;
        }
        return $d['data'];
    }

    /**
     * 本次是否该升。三条路径任一成立即可：
     *   1) 服务器下发了合法签名指令（控制台批量下发；不受维护窗口限制——是人点的）
     *   2) 安全更新，且本站开了自动升级
     *   3) 任意正式版，且本站范围设为 stable
     *
     * @param array<string, mixed> $data check 返回的数据段
     * @return array{0: bool, 1: string} [该升?, 原因]
     */
    public static function shouldRun(array $data): array
    {
        if (empty($data['has_update'])) {
            return [false, 'no update'];
        }
        $to = (string) ($data['latest_version'] ?? '');

        require_once ROOT_PATH . '/includes/UpgradeDirective.php';
        if (UpgradeDirective::verify($data['directive'] ?? null, $to) === true) {
            return [true, 'directive'];   // 控制台指令：立即执行，不等窗口
        }

        if (!self::enabled()) {
            return [false, 'auto upgrade disabled'];
        }
        if (!self::inWindow()) {
            return [false, 'outside maintenance window'];
        }
        $isSecurity = (string) ($data['level'] ?? '') === 'security';
        if (self::scope() === 'security' && !$isSecurity) {
            return [false, 'not a security release (scope=security)'];
        }
        return [true, self::scope() === 'stable' ? 'stable release' : 'security release'];
    }

    /**
     * 跑一次自动升级。cron 与后台「立即检查并升级」共用。
     *
     * @param bool $force 忽略开关与维护窗口（后台手动触发）
     * @return string 给 cron 日志看的一句话
     */
    public static function run(bool $force = false): string
    {
        require_once ROOT_PATH . '/includes/UpgradeRunner.php';

        // 锁必须罩住**整个事务**，包括「查本地未完成状态」这一步：
        // 否则两个 cron 会同时判定「有事务要续」，一起去推同一个游标。
        if (!self::lock()) {
            return 'skipped: 上一次升级仍在进行（或未超过锁定期）';
        }

        try {
            // ---- 第一优先级：本地有未完成的升级事务就直接续跑，**不问服务器** ----
            // 这一步必须在 check()/shouldRun() 之前。config/version.php 本身就是包里的
            // 普通文件，第一轮覆盖后站点版本号已变成新版 → 服务器回「无更新」→ 判定拒绝
            // → 续跑分支永远到不了，站点永久停在新旧混合状态。（codex 审计 P0-1）
            // 事务自带 from/to/backup，因此也不受维护窗口、指令 nonce、服务器新版本影响。
            $pending = self::pendingTransaction();
            if ($pending !== null) {
                return self::applyRemaining(
                    $pending['backup'],
                    $pending['total'],
                    $pending['done'],
                    $pending['from'],
                    $pending['to']
                ) . ' (resume)';
            }

            $from = defined('CMS_VERSION') ? CMS_VERSION : '';
            $data = self::check();
            if ($data === null) {
                return 'skipped: 更新服务器不可达';
            }

            if ($force) {
                $why = 'manual';
                if (empty($data['has_update'])) {
                    return 'no update';
                }
            } else {
                [$go, $why] = self::shouldRun($data);
                if (!$go) {
                    return 'skipped: ' . $why;
                }
            }

            $to = (string) ($data['latest_version'] ?? '');
            try {
                return self::doUpgrade($data, $from, $to) . ' (' . $why . ')';
            } catch (\Throwable $e) {
                self::logAdd('failed', '异常：' . $e->getMessage(), $from, $to);
                return 'failed: ' . $e->getMessage();
            }
        } finally {
            self::unlock();
        }
    }

    /**
     * 本地有没有未完成的升级事务？有则返回它自带的全部上下文。
     *
     * 关键是**不依赖任何外部状态**：from/to/backup/total 全部来自事务自身，
     * 所以窗口关了、服务器发了新版、当前 CMS_VERSION 已被覆盖，都不影响续跑。
     *
     * @return array{backup: string, total: int, done: int, from: string, to: string}|null
     */
    private static function pendingTransaction(): ?array
    {
        $to = (string) config('auto_upgrade_target', '');
        if ($to === '' || !is_file(uo_state_file()) || !is_file(uo_dir() . '/package.zip')) {
            return null;
        }
        try {
            $st = UpgradeApplyState::read(uo_state_file());
        } catch (\Throwable $e) {
            return null;   // 状态坏了就当没有，走完整流程重来
        }
        if (UpgradeApplyState::isComplete($st)) {
            return null;
        }
        return [
            'backup' => (string) ($st['backup'] ?? ''),
            'total' => (int) ($st['total'] ?? 0),
            'done' => (int) ($st['next_offset'] ?? 0),
            'from' => (string) config('auto_upgrade_from', ''),
            'to' => $to,
        ];
    }

    /**
     * 真正的升级流程：下载 → 覆盖 → 收尾 → 迁移 → 健康自检（不过则回滚）。
     *
     * @param array<string, mixed> $data
     */
    private static function doUpgrade(array $data, string $from, string $to): string
    {
        // 注：续跑分支在 run() 里、check() 之前处理（见 pendingTransaction）。
        // 走到这里说明是一次全新的升级事务。

        // ---- 下载（内含 SHA256 + RSA 验签；地址白名单只认官方 packages 目录）----
        $useDelta = is_array($data['delta'] ?? null) && !empty($data['delta']['download_url']);
        $dl = $useDelta ? $data['delta'] : $data;
        $r = upgrade_download_package(
            (string) ($dl['download_url'] ?? ''),
            (string) ($dl['hash'] ?? ''),
            $to,
            (string) ($dl['sig'] ?? '')
        );
        if (($r['code'] ?? 1) !== 0) {
            self::logAdd('failed', '下载失败：' . ($r['msg'] ?? ''), $from, $to);
            return 'failed: download';
        }

        // ---- 准备（备份 config + 数据库 + 建条目清单）----
        $pre = upgrade_prepare();
        if (($pre['code'] ?? 1) !== 0) {
            self::logAdd('failed', '准备失败：' . ($pre['msg'] ?? ''), $from, $to);
            return 'failed: prepare';
        }
        // 无人值守升级要求**数据库备份必须成功**：迁移会改表结构，而文件回滚恢复不了
        // 数据库。没有可用备份就等于没有退路——宁可不升。（codex 审计 P0-3）
        if (trim((string) ($pre['db_backup'] ?? '')) === '') {
            self::logAdd(
                'failed',
                '数据库备份未成功，已中止升级（' . ((string) ($pre['db_backup_error'] ?? '原因未知')) . '）',
                $from,
                $to
            );
            return 'failed: no database backup';
        }

        // 记下本轮事务上下文：续跑时全靠它，不依赖当时的 CMS_VERSION 与服务器状态
        settingModel()->saveBatch([
            'auto_upgrade_target' => $to,
            'auto_upgrade_from' => $from,
        ]);

        return self::applyRemaining(
            (string) ($pre['backup'] ?? ''),
            (int) ($pre['total'] ?? 0),
            0,
            $from,
            $to
        );
    }

    /**
     * 从 $startedAt 起继续覆盖，直到本轮到量或全部完成；完成则收尾 + 自检 + 迁移。
     *
     * 抽成独立方法是为了让「首次」和「续跑」走同一段代码——续跑路径要是另写一份，
     * 两边迟早漂移，而这是无人值守跑的代码，漂移了没人会立刻发现。
     */
    private static function applyRemaining(string $backup, int $total, int $startedAt, string $from, string $to): string
    {
        // ---- 分批覆盖：服务端游标推进，单轮封顶，避免一次请求跑太久被网关掐断 ----
        $done = $startedAt;
        $thisRound = 0;
        $guard = 0;
        while ($done < $total && $guard++ < 200) {
            $bt = upgrade_batch(null);
            if (($bt['code'] ?? 1) !== 0) {
                self::logAdd('failed', '覆盖失败：' . ($bt['msg'] ?? ''), $from, $to);
                return 'failed: batch';
            }
            // 有文件没写进去就立刻停：upgrade_batch 为了不被单个不可写文件卡死，会
            // 累计 errors 但照常推进游标。人工升级时用户看得见失败清单可以补救；无人
            // 值守没人看，继续推下去就是「缺文件却记成功」。（codex 审计 P0-2）
            // 快照失败 = 自动回滚失去依据。人工升级可以带着告警继续（用户自己判断），
            // 无人值守不行：真出事时没有可回滚的旧版本。
            if ((int) ($bt['snapshot_failed'] ?? 0) > 0) {
                return self::abortAndRollback(
                    $backup,
                    '覆盖前快照失败 ' . (int) $bt['snapshot_failed'] . ' 个文件，无人值守升级失去回滚依据',
                    $from,
                    $to
                );
            }
            if (!empty($bt['errors'])) {
                return self::abortAndRollback(
                    $backup,
                    '覆盖失败 ' . count((array) $bt['errors']) . ' 个文件：'
                        . implode('; ', array_slice((array) $bt['errors'], 0, 5)),
                    $from,
                    $to
                );
            }
            $prev = $done;
            $done = (int) ($bt['next'] ?? $done);
            if ($done <= $prev) {
                // 游标没前进：再循环下去就是死循环，果断退出留给人工排查
                self::logAdd('failed', '覆盖游标未推进（' . $done . '/' . $total . '）', $from, $to);
                return 'failed: cursor stalled';
            }
            $thisRound += $done - $prev;
            if ($thisRound >= self::BATCH_LIMIT && $done < $total) {
                // 本轮到量：留给下一次 cron 续跑（状态机记着游标，上面的续跑分支接手）
                return 'in progress: ' . $done . '/' . $total . ' 已覆盖，下一轮继续';
            }
        }

        // ---- 收尾 + 健康自检 ----
        $fin = upgrade_finalize('自动升级');
        // code=2 表示「完成了但有文件失败」。人工升级会把清单显示给用户处理；
        // 无人值守必须当失败处理，否则站点带着缺失文件被记为升级成功。
        if ((int) ($fin['code'] ?? 1) !== 0) {
            return self::abortAndRollback(
                $backup,
                '收尾未干净：' . ($fin['msg'] ?? '') . (!empty($fin['errors'])
                    ? '（' . implode('; ', array_slice((array) $fin['errors'], 0, 5)) . '）' : ''),
                $from,
                $to
            );
        }
        $health = is_array($fin['health'] ?? null) ? $fin['health'] : ['ok' => true];
        if (empty($health['ok'])) {
            // 无人值守，没人来救场：自己退回去
            $bad = implode('、', array_column(
                array_filter((array) ($health['checks'] ?? []), static fn($c) => empty($c['ok'])),
                'file'
            ));
            return self::abortAndRollback($backup, '健康自检未通过' . ($bad !== '' ? '（' . $bad . '）' : ''), $from, $to);
        }

        // ---- 数据库迁移（新代码可能要求新表结构，必须跑完）----
        // 任一条失败就中止：留下「新代码 + 半完成 schema」比升级失败危险得多。
        // 文件可以回滚，数据库靠 prepare 阶段那份 database.sql（上面已强制要求成功）。
        [$migrated, $migrateError] = self::runMigrations();
        if ($migrateError !== '') {
            return self::abortAndRollback(
                $backup,
                '数据库迁移失败：' . $migrateError . '（文件已回滚；数据库请用 '
                    . 'storage/backups/' . $backup . '/database.sql 恢复）',
                $from,
                $to
            );
        }

        settingModel()->set('auto_upgrade_target', '', 'system');   // 本轮结束，续跑标记作废

        self::logAdd(
            'ok',
            '升级完成，覆盖 ' . (int) ($fin['copied'] ?? 0) . ' 个文件'
                . ($migrated > 0 ? '，应用 ' . $migrated . ' 条数据库迁移' : '')
                . (!empty($fin['errors']) ? '，' . count($fin['errors']) . ' 个文件未能覆盖' : ''),
            $from,
            (string) ($fin['new_version'] ?? $to)
        );
        try {
            adminLog('upgrade', 'auto_upgrade', "自动升级 {$from} → {$to}：成功");
        } catch (\Throwable $e) {
        }
        return 'upgraded ' . $from . ' → ' . ($fin['new_version'] ?? $to);
    }

    /**
     * 跑掉所有待应用的迁移。**首条失败即停**并把错误报出去。
     *
     * 与后台升级页「单条失败继续」的口径不同，这是刻意的：人工升级时用户看得见
     * 失败并能当场判断，无人值守时继续跑只会让 schema 停在更难描述的中间态。
     *
     * @return array{0: int, 1: string} [已应用条数, 错误信息（空串=全部成功）]
     */
    private static function runMigrations(): array
    {
        require_once ROOT_PATH . '/includes/Migrator.php';
        $n = 0;
        foreach (Migrator::loadAll() as $m) {
            if (Migrator::isApplied($m)) {
                continue;
            }
            $id = (string) ($m['id'] ?? '?');
            // ⚠ Migrator::runOne() **不抛异常**：失败时返回 ['ok' => false, 'message' => ...]。
            // 只 catch 异常的话迁移失败会被完全忽略——2026-08-23 故障注入测试抓到的真 bug，
            // 而源码契约断言当时是全绿的。两种失败形态都要接住。
            $err = '';
            try {
                $res = Migrator::runOne($m);
                if (!is_array($res) || empty($res['ok'])) {
                    $err = (string) ($res['message'] ?? '未知失败');
                }
            } catch (\Throwable $e) {
                $err = $e->getMessage();
            }
            if ($err !== '') {
                try {
                    adminLog('upgrade', 'auto_migrate_fail', $id . '：' . $err);
                } catch (\Throwable $e2) {
                }
                return [$n, $id . '：' . $err];
            }
            $n++;
        }
        return [$n, ''];
    }

    /**
     * 中止本次升级：回滚文件、清事务标记、记失败。
     *
     * 回滚本身也可能失败（快照缺失、目录不可写）——那种情况必须在日志里说清楚，
     * 因为站点此刻处于最糟的状态，需要人工介入。
     */
    private static function abortAndRollback(string $backup, string $why, string $from, string $to): string
    {
        $rb = $backup !== '' ? upgrade_rollback($backup) : ['code' => 1, 'msg' => '没有可用的备份目录'];
        $ok = ((int) ($rb['code'] ?? 1)) === 0;
        settingModel()->set('auto_upgrade_target', '', 'system');
        self::logAdd(
            $ok ? 'rolled_back' : 'failed',
            $why . '；' . ($ok
                ? '已回滚到升级前'
                : '⚠ 回滚也失败了（' . ($rb['msg'] ?? '') . '），站点可能处于新旧混合状态，需人工介入'),
            $from,
            $to
        );
        return $ok ? 'rolled back: ' . $why : 'failed (rollback failed): ' . $why;
    }

    /** 注册 cron 任务（挂核心的 cron_register）。每小时看一次，窗口判断在任务体内。 */
    public static function register(): void
    {
        if (!class_exists('Cron')) {
            return;
        }
        Cron::register('auto_upgrade', __('cron_auto_upgrade'), 3600, static function (): string {
            return self::run(false);
        });
    }
}
