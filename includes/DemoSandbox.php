<?php
/**
 * Yikai CMS - 演示沙盒（demo_mode = 2）
 *
 * 与只读演示模式（demo_mode = 1，后台所有 POST 被拦）不同，沙盒模式让访客真正
 * 动手改：发文章、传图、拖 Blox——然后由快照把站点拉回原样。
 *
 *   快照 = storage/demo/snapshot.sql（全部前缀表）+ storage/demo/uploads/（上传目录镜像）
 *   重置 = 恢复 SQL → 镜像回 uploads → 清页面/文件缓存 → 钉住 demo_mode=2 与 cron_token
 *
 * 触发入口：后台 setting_demo.php 一键按钮 / cron.php?token=..&task=demo_reset /
 * CLI demo:reset / 定时任务 demo_reset（间隔见设置 demo_reset_interval）。
 *
 * 沙盒下仍被拦截的后台页面见 protectedPages()：升级、插件/主题安装、安全设置、
 * 用户与密码、数据库恢复——这些要么会把演示站锁死，要么等不到下一次重置就已破坏。
 */
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition 直连访问本文件时的守卫；Psalm 按包含顺序已认定 ROOT_PATH 有定义。 */
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

final class DemoSandbox
{
    public const MODE_OFF = '0';
    public const MODE_READONLY = '1';
    public const MODE_SANDBOX = '2';

    public const DEFAULT_INTERVAL = 3600;
    public const MIN_INTERVAL = 300;

    /** 重置期间清空 uploads 时保留的文件名 */
    private const KEEP_IN_UPLOADS = ['.htaccess', '.gitkeep', 'index.html', 'index.php', 'web.config'];

    private static ?string $dir = null;
    private static ?string $uploadsDir = null;

    // ─────────────────────────────────────────────────────
    // 模式
    // ─────────────────────────────────────────────────────

    public static function mode(): string
    {
        try {
            $v = (string) config('demo_mode', self::MODE_OFF);
        } catch (\Throwable $e) {
            return self::MODE_OFF;
        }
        return in_array($v, [self::MODE_READONLY, self::MODE_SANDBOX], true) ? $v : self::MODE_OFF;
    }

    public static function isSandbox(): bool
    {
        return self::mode() === self::MODE_SANDBOX;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod 与 isSandbox() 成对的公开判定 API。
     * 后台三态 UI 走的是 mode() 直接比较（一次取值判三档，比连调两个 is* 更清楚），
     * 这里保留给插件与后续消费方，别因为「暂时没人调」就删掉半边。
     */
    public static function isReadonly(): bool
    {
        return self::mode() === self::MODE_READONLY;
    }

    /** 是否为受支持的三态之一。normalizeMode() 负责收敛，本方法负责校验。 */
    public static function isValidMode(mixed $raw): bool
    {
        $value = is_string($raw) || is_int($raw) ? (string) $raw : '';
        return in_array($value, [self::MODE_OFF, self::MODE_READONLY, self::MODE_SANDBOX], true);
    }

    /**
     * 把任意外来输入收敛成三态之一。非法值一律落到 OFF——
     * 宁可把演示站误判成「正常站」被后台拦下，也不要把正常站误判成沙盒去跑重置。
     */
    public static function normalizeMode(mixed $raw): string
    {
        $value = is_string($raw) || is_int($raw) ? (string) $raw : '';
        return in_array($value, [self::MODE_READONLY, self::MODE_SANDBOX], true) ? $value : self::MODE_OFF;
    }

    /**
     * 模式的可读名称。全系统（后台 UI / CLI / yk info）都从这里取，
     * 避免各处再写 `=== '1' ? 'ON' : 'off'` 这种只认两态的判断——
     * info.php 曾因此把沙盒站报成「off」。
     */
    public static function modeLabel(?string $mode = null): string
    {
        return match (self::normalizeMode($mode ?? self::mode())) {
            self::MODE_READONLY => __('dm_mode_readonly'),
            self::MODE_SANDBOX => __('dm_mode_sandbox'),
            default => __('dm_mode_off'),
        };
    }

    /**
     * 沙盒模式下依旧拦截 POST 的后台页面（basename）。
     * @return list<string>
     */
    public static function protectedPages(): array
    {
        return [
            'upgrade.php', 'upgrade_online.php',      // 升级会改文件、跑迁移
            'plugin.php', 'theme.php',                // 安装/卸载/上传 = 写代码文件
            'setting_security.php', 'site_health.php', // 改 .htaccess / 探针修复
            'user.php', 'profile.php',                // 改密码会把其他访客锁在门外
            'database.php',                           // 任意 SQL 恢复 / 清表
            'license.php', 'cron.php',                // 远程授权 / 站长口令与计划任务
            // 下面这些页面会把授权信息、SMTP/API 密钥显示出来，或直接触发外部服务调用。
            // 公开演示账号连 GET 都不该看到，所以拦的是整页而不是只拦提交。
            'setting_email.php', 'setting_ai.php', 'setting_translate.php', 'setting_api.php',
            'setting_channel_translate.php', 'setting_product_cat_translate.php',
            'api_ai.php', 'api_ai_agent.php', 'api_ai_apply.php', 'api_ai_undo.php',
        ];
    }

    public static function isProtectedPage(string $page): bool
    {
        return in_array(basename($page), self::protectedPages(), true);
    }

    /**
     * 站长口令：与 cron token 同源。切换演示模式、更新快照都要带它。
     *
     * 为什么不能只靠后台权限：公开演示站的超管账号密码本身就是公开的
     * （demo/demo 之类），`requirePermission('*')` 对访客等于不设防。
     * 口令只有能读库或能登 shell 的人拿得到，这才是「站长」与「演示超管」的分界。
     */
    public static function ownerTokenMatches(string $given): bool
    {
        // 先 trim 再判空：纯空白的口令不该还去查一次库
        $given = trim($given);
        if ($given === '') {
            return false;
        }
        if (!class_exists('Cron')) {
            require_once ROOT_PATH . '/includes/Cron.php';
        }
        return hash_equals(Cron::token(), $given);
    }

    // ─────────────────────────────────────────────────────
    // 目录
    // ─────────────────────────────────────────────────────

    public static function dir(): string
    {
        return self::$dir ?? ROOT_PATH . '/storage/demo';
    }

    /** @psalm-suppress PossiblyUnusedMethod 测试用：重定向快照目录 */
    public static function setDir(?string $dir): void
    {
        self::$dir = $dir === null ? null : rtrim($dir, '/\\');
    }

    public static function uploadsDir(): string
    {
        if (self::$uploadsDir !== null) {
            return self::$uploadsDir;
        }
        return rtrim(defined('UPLOADS_PATH') ? (string) UPLOADS_PATH : ROOT_PATH . '/uploads', '/\\');
    }

    /** @psalm-suppress PossiblyUnusedMethod 测试用：重定向 uploads 目录 */
    public static function setUploadsDir(?string $dir): void
    {
        self::$uploadsDir = $dir === null ? null : rtrim($dir, '/\\');
    }

    public static function snapshotSqlPath(): string
    {
        return self::dir() . '/snapshot.sql';
    }

    public static function snapshotUploadsPath(): string
    {
        return self::dir() . '/uploads';
    }

    public static function hasSnapshot(): bool
    {
        return is_file(self::snapshotSqlPath());
    }

    // ─────────────────────────────────────────────────────
    // 快照
    // ─────────────────────────────────────────────────────

    /**
     * 把当前库 + uploads 存为快照（覆盖旧快照）。
     * @return array{tables:int,sql_bytes:int,files:int,created_at:string}
     */
    public static function snapshot(): array
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建快照目录：' . $dir);
        }
        self::ensureGuardFiles($dir);

        // 惰性引入：顶层 require 会让 Psalm 认定被包含文件的 ROOT_PATH 守卫恒真而报
        // ParadoxicalCondition；这两个类也只有快照/重置两条路径用得到。
        require_once ROOT_PATH . '/includes/Backup.php';

        $tables = Backup::listPrefixedTables();
        if (empty($tables)) {
            throw new \RuntimeException('未找到任何 ' . DB_PREFIX . ' 前缀表');
        }
        $sql = Backup::generateSql($tables, true, true);

        // 先写临时文件再改名：快照写一半时来了重置，不会读到残缺 SQL。
        $tmp = self::snapshotSqlPath() . '.tmp';
        if (file_put_contents($tmp, $sql) === false) {
            throw new \RuntimeException('快照 SQL 写入失败');
        }
        if (!@rename($tmp, self::snapshotSqlPath())) {
            @unlink($tmp);
            throw new \RuntimeException('快照 SQL 落盘失败');
        }

        $files = self::mirrorDir(self::uploadsDir(), self::snapshotUploadsPath(), []);

        $manifest = [
            'created_at' => date('Y-m-d H:i:s'),
            'tables' => count($tables),
            'sql_bytes' => strlen($sql),
            'files' => $files,
        ];
        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $manifest;
    }

    /** @return array<string,mixed>|null */
    public static function manifest(): ?array
    {
        $file = self::dir() . '/manifest.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    // ─────────────────────────────────────────────────────
    // 重置
    // ─────────────────────────────────────────────────────

    /**
     * 从快照恢复。返回统计；快照缺失或 SQL 有错时抛异常。
     * @return array{statements:int,files:int,cache:int,trigger:string,at:string,ms:int}
     */
    public static function reset(string $trigger = 'manual'): array
    {
        if (!self::hasSnapshot()) {
            throw new \RuntimeException('尚未创建快照，先执行 demo:snapshot');
        }

        $dir = self::dir();
        $lockFile = $dir . '/reset.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            throw new \RuntimeException('另一个重置正在进行中');
        }

        $t0 = microtime(true);
        try {
            // 重置前记住要钉住的两个值：cron token 不能随库变（站长收藏的重置链接会失效），
            // demo_mode 必须保持沙盒（快照可能是在切换模式之前拍的）。
            $cronToken = '';
            try {
                $cronToken = (string) settingModel()->get('cron_token', '');
            } catch (\Throwable $e) {
            }

            require_once ROOT_PATH . '/includes/DatabaseMaintenance.php';

            $sql = (string) file_get_contents(self::snapshotSqlPath());
            $result = DatabaseMaintenance::restoreSql($sql);
            if (!empty($result['errors'])) {
                throw new \RuntimeException('恢复 SQL 出错：' . implode(' | ', $result['errors']));
            }

            self::pinSettings($cronToken);

            $files = self::mirrorDir(self::snapshotUploadsPath(), self::uploadsDir(), self::KEEP_IN_UPLOADS);
            $cache = self::clearCaches();

            $summary = [
                'statements' => (int) $result['statements'],
                'files' => $files,
                'cache' => $cache,
                'trigger' => $trigger,
                'at' => date('Y-m-d H:i:s'),
                'ms' => (int) round((microtime(true) - $t0) * 1000),
            ];
            file_put_contents($dir . '/last-reset.json', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return $summary;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,mixed>|null */
    public static function lastReset(): ?array
    {
        $file = self::dir() . '/last-reset.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    public static function interval(): int
    {
        try {
            $v = (int) config('demo_reset_interval', self::DEFAULT_INTERVAL);
        } catch (\Throwable $e) {
            $v = self::DEFAULT_INTERVAL;
        }
        return max(self::MIN_INTERVAL, $v);
    }

    /** 沙盒下定时任务；未开启沙盒时是一次早退。 */
    public static function registerCron(): void
    {
        if (!class_exists('Cron')) {
            return;
        }
        Cron::register('demo_reset', __('cron_demo_reset'), self::interval(), static function (): string {
            if (!self::isSandbox()) {
                return __('cron_demo_reset_skipped');
            }
            $r = self::reset('cron');
            return str_replace([':n', ':files'], [(string) $r['statements'], (string) $r['files']], __('cron_demo_reset_done'));
        });
    }

    // ─────────────────────────────────────────────────────
    // 内部
    // ─────────────────────────────────────────────────────

    private static function pinSettings(string $cronToken): void
    {
        $model = settingModel();
        $model->set('demo_mode', self::MODE_SANDBOX);
        if ($cronToken !== '') {
            $model->set('cron_token', $cronToken, 'cron');
        }
    }

    private static function clearCaches(): int
    {
        $n = 0;
        if (!class_exists('HtmlCache')) {
            $f = ROOT_PATH . '/includes/HtmlCache.php';
            if (is_file($f)) {
                require_once $f;
            }
        }
        if (class_exists('HtmlCache')) {
            try {
                $n += HtmlCache::invalidate();
            } catch (\Throwable $e) {
            }
        }
        $cacheDir = ROOT_PATH . '/storage/cache';
        if (is_dir($cacheDir)) {
            $n += self::purgeDir($cacheDir, ['.gitkeep', '.htaccess']);
        }
        return $n;
    }

    /**
     * 让 $dst 与 $src 一致：先清空 $dst（保留 $keep），再递归复制。返回复制的文件数。
     * 快照目录不存在时视为空源（重置后 uploads 即被清空）。
     * @param list<string> $keep
     */
    private static function mirrorDir(string $src, string $dst, array $keep): int
    {
        if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) {
            throw new \RuntimeException('无法创建目录：' . $dst);
        }
        self::purgeDir($dst, $keep);
        if (!is_dir($src)) {
            return 0;
        }
        return self::copyTree($src, $dst);
    }

    /** @param list<string> $keep 顶层要保留的文件名 */
    private static function purgeDir(string $dir, array $keep): int
    {
        $removed = 0;
        $items = @scandir($dir);
        if ($items === false) {
            return 0;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..' || in_array($name, $keep, true)) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_link($path) || is_file($path)) {
                if (@unlink($path)) {
                    $removed++;
                }
            } elseif (is_dir($path)) {
                $removed += self::purgeDir($path, []);
                @rmdir($path);
            }
        }
        return $removed;
    }

    private static function copyTree(string $src, string $dst): int
    {
        $copied = 0;
        $items = @scandir($src);
        if ($items === false) {
            return 0;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $from = $src . '/' . $name;
            $to = $dst . '/' . $name;
            if (is_link($from)) {
                continue; // 快照与恢复都不跟随符号链接
            }
            if (is_dir($from)) {
                if (!is_dir($to)) {
                    @mkdir($to, 0755, true);
                }
                $copied += self::copyTree($from, $to);
            } elseif (is_file($from)) {
                if (@copy($from, $to)) {
                    $copied++;
                }
            }
        }
        return $copied;
    }

    /** 快照目录禁止 web 直读（storage/ 本已被挡，这里是双保险） */
    private static function ensureGuardFiles(string $dir): void
    {
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
    }
}
