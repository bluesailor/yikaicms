<?php
/**
 * YikaiCMS — 定时任务调度器。
 *
 * 站点用系统 crontab / 宝塔计划任务定时请求 `cron.php?token=<token>`（或 CLI `yikai cron:run`），
 * 本调度器按各任务的间隔判断是否到点、到点即执行并记录结果。任务状态（上次运行/状态/耗时）
 * 存 settings，运行历史存 storage/cron/history.json（环形，最多 100 条）。
 *
 * 内置任务：定时内容上线、回收站清理、数据库备份。
 * 插件/站点可在 `cron_register` action 里调 Cron::register() 增加任务。
 */

declare(strict_types=1);

final class Cron
{
    /** @var array<string, array{label: string, interval: int, handler: callable}> */
    private static array $tasks = [];
    private static bool $booted = false;

    private const HISTORY_MAX = 100;

    /**
     * 注册任务。$interval 为最小间隔秒数；handler 返回结果字符串，抛异常即视为失败。
     */
    public static function register(string $name, string $label, int $interval, callable $handler): void
    {
        self::$tasks[$name] = ['label' => $label, 'interval' => max(30, $interval), 'handler' => $handler];
    }

    /** 注册内置任务并广播 cron_register（幂等） */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::register('publish_sweep', __('cron_publish_sweep'), 60, function (): string {
            $n = contentModel()->promoteDue();
            return $n > 0 ? str_replace(':n', (string) $n, __('cron_published_n')) : __('cron_nothing_due');
        });

        self::register('recycle_purge', __('cron_recycle_purge'), 86400, function (): string {
            $days = (int) config('recycle_keep_days', 30);
            $total = 0;
            foreach ([contentModel(), productModel(), albumModel(), downloadModel(), jobModel()] as $m) {
                $total += $m->purgeTrashedOlderThan($days);
            }
            return str_replace([':n', ':days'], [(string) $total, (string) $days], __('cron_purged_n'));
        });

        self::register('backup', __('cron_backup'), 86400, function (): string {
            require_once ROOT_PATH . '/includes/Backup.php';
            $tables = Backup::listPrefixedTables();
            $sql = Backup::generateSql($tables, true, true);
            $path = Backup::writeToBackupsDir($sql, 'auto_' . date('Ymd_His') . '.sql');
            $kept = self::pruneBackups((int) config('backup_keep', 7));
            return str_replace([':file', ':n'], [basename($path), (string) $kept], __('cron_backup_done'));
        });

        if (function_exists('do_action')) {
            do_action('cron_register');
        }
    }

    /**
     * 运行所有到点任务（$force=true 时无视间隔全部运行）。
     * @return array<int, array{name: string, ran: bool, ok: bool, msg: string, ms: int}>
     */
    public static function runDue(bool $force = false): array
    {
        self::boot();
        $now = time();
        $results = [];
        foreach (self::$tasks as $name => $task) {
            $last = (int) settingModel()->get("cron_{$name}_last", '0');
            if (!$force && $last > 0 && ($now - $last) < $task['interval']) {
                $results[] = ['name' => $name, 'ran' => false, 'ok' => true, 'msg' => 'skipped (未到点)', 'ms' => 0];
                continue;
            }
            $results[] = self::execute($name, $task) + ['ran' => true];
        }
        return $results;
    }

    /** 手动运行单个任务（后台「立即运行」用），无视间隔 */
    public static function runOne(string $name): array
    {
        self::boot();
        if (!isset(self::$tasks[$name])) {
            return ['name' => $name, 'ok' => false, 'msg' => '任务不存在', 'ms' => 0, 'ran' => false];
        }
        return self::execute($name, self::$tasks[$name]) + ['ran' => true];
    }

    /** @return array{name: string, ok: bool, msg: string, ms: int} */
    private static function execute(string $name, array $task): array
    {
        $t0 = microtime(true);
        try {
            $msg = (string) ($task['handler'])();
            $ok = true;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $ok = false;
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);

        settingModel()->set("cron_{$name}_last", (string) time(), 'cron');
        settingModel()->set("cron_{$name}_status", $ok ? 'ok' : 'fail', 'cron');
        settingModel()->set("cron_{$name}_msg", mb_substr($msg, 0, 300), 'cron');
        settingModel()->set("cron_{$name}_ms", (string) $ms, 'cron');
        self::appendHistory(['name' => $name, 'at' => time(), 'ok' => $ok, 'msg' => $msg, 'ms' => $ms]);

        return ['name' => $name, 'ok' => $ok, 'msg' => $msg, 'ms' => $ms];
    }

    /** 任务列表 + 当前状态，供后台展示 */
    public static function tasks(): array
    {
        self::boot();
        $out = [];
        foreach (self::$tasks as $name => $task) {
            $last = (int) settingModel()->get("cron_{$name}_last", '0');
            $out[] = [
                'name'     => $name,
                'label'    => $task['label'],
                'interval' => $task['interval'],
                'last'     => $last,
                'status'   => (string) settingModel()->get("cron_{$name}_status", ''),
                'msg'      => (string) settingModel()->get("cron_{$name}_msg", ''),
                'ms'       => (int) settingModel()->get("cron_{$name}_ms", '0'),
                'next'     => $last > 0 ? $last + $task['interval'] : 0,
            ];
        }
        return $out;
    }

    /** 运行历史（最近在前） */
    public static function history(): array
    {
        $file = self::historyFile();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? array_reverse($data) : [];
    }

    /** cron.php 的访问令牌；不存在则生成 */
    public static function token(): string
    {
        $t = (string) settingModel()->get('cron_token', '');
        if ($t === '') {
            $t = bin2hex(random_bytes(16));
            settingModel()->set('cron_token', $t, 'cron');
        }
        return $t;
    }

    private static function appendHistory(array $entry): void
    {
        $file = self::historyFile();
        $dir = dirname($file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $data = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        $data[] = $entry;
        if (count($data) > self::HISTORY_MAX) {
            $data = array_slice($data, -self::HISTORY_MAX);
        }
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private static function historyFile(): string
    {
        return ROOT_PATH . '/storage/cron/history.json';
    }

    /** 只保留最近 $keep 份 auto_*.sql 备份，返回保留数 */
    private static function pruneBackups(int $keep): int
    {
        $files = glob(ROOT_PATH . '/storage/backups/auto_*.sql') ?: [];
        rsort($files); // 文件名带时间戳，字典序倒序即最新在前
        foreach (array_slice($files, max(0, $keep)) as $old) {
            @unlink($old);
        }
        return min(count($files), max(0, $keep));
    }
}
