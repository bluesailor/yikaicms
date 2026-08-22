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

    /** 锁的有效期：超过这么久视为上次跑挂了，允许重新开始。 */
    private const LOCK_TTL = 1800;

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

    /** 并发锁：拿到返回 true。 */
    private static function lock(): bool
    {
        $at = (int) config('auto_upgrade_lock_at', '0');
        if ($at > 0 && time() - $at < self::LOCK_TTL) {
            return false;
        }
        settingModel()->set('auto_upgrade_lock_at', (string) time(), 'system');
        return true;
    }

    private static function unlock(): void
    {
        settingModel()->set('auto_upgrade_lock_at', '0', 'system');
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
        if (!self::lock()) {
            return 'skipped: 上一次升级仍在进行（或未超过锁定期）';
        }

        try {
            $msg = self::doUpgrade($data, $from, $to);
        } catch (\Throwable $e) {
            self::logAdd('failed', '异常：' . $e->getMessage(), $from, $to);
            self::unlock();
            return 'failed: ' . $e->getMessage();
        }
        self::unlock();
        return $msg . ' (' . $why . ')';
    }

    /**
     * 真正的升级流程：下载 → 覆盖 → 收尾 → 迁移 → 健康自检（不过则回滚）。
     *
     * @param array<string, mixed> $data
     */
    private static function doUpgrade(array $data, string $from, string $to): string
    {
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
        $backup = (string) ($pre['backup'] ?? '');
        $total = (int) ($pre['total'] ?? 0);

        // ---- 分批覆盖：服务端游标推进，单轮封顶，避免一次请求跑太久被网关掐断 ----
        $done = 0;
        $guard = 0;
        while ($done < $total && $guard++ < 200) {
            $bt = upgrade_batch(null);
            if (($bt['code'] ?? 1) !== 0) {
                self::logAdd('failed', '覆盖失败：' . ($bt['msg'] ?? ''), $from, $to);
                return 'failed: batch';
            }
            $done = (int) ($bt['next'] ?? $done);
            if ($done >= self::BATCH_LIMIT && $done < $total) {
                // 本轮到量：留给下一次 cron 续跑（状态机记着游标）
                return 'in progress: ' . $done . '/' . $total . ' 已覆盖，下一轮继续';
            }
        }

        // ---- 收尾 + 健康自检 ----
        $fin = upgrade_finalize('自动升级');
        $health = is_array($fin['health'] ?? null) ? $fin['health'] : ['ok' => true];
        if (empty($health['ok'])) {
            // 无人值守，没人来救场：自己退回去
            $rb = upgrade_rollback($backup);
            $ok = ($rb['code'] ?? 1) === 0;
            self::logAdd(
                'rolled_back',
                '健康自检未通过，已' . ($ok ? '回滚到升级前' : '尝试回滚但失败：' . ($rb['msg'] ?? '')),
                $from,
                $to
            );
            return 'rolled back: health check failed';
        }

        // ---- 数据库迁移（新代码可能要求新表结构，必须跑完）----
        $migrated = self::runMigrations();

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

    /** 跑掉所有待应用的迁移，返回条数。单条失败不中止——与后台升级页口径一致。 */
    private static function runMigrations(): int
    {
        require_once ROOT_PATH . '/includes/Migrator.php';
        $n = 0;
        foreach (Migrator::loadAll() as $m) {
            if (Migrator::isApplied($m)) {
                continue;
            }
            try {
                Migrator::runOne($m);
                $n++;
            } catch (\Throwable $e) {
                // 记录但继续：一条迁移失败不该把后续全部卡住
                try {
                    adminLog('upgrade', 'auto_migrate_fail', (string) ($m['id'] ?? '?') . '：' . $e->getMessage());
                } catch (\Throwable $e2) {
                }
            }
        }
        return $n;
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
