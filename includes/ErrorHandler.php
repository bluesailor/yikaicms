<?php
/**
 * YikaiCMS - 全局错误自检测（借鉴 WordPress WP_DEBUG_LOG）
 *
 * 由 includes/functions.php 顶部安装，覆盖前后台所有入口；客户升级后自动生效，
 * 无需改 config.php。日志写 storage/logs/error-Ym.log（按月切分，保留 3 个月），
 * 后台「系统信息 → 错误日志」可查看/清空。
 *
 * 记录策略（与 DEBUG 解耦，这是和 WP 的关键差异）：
 *   - 未捕获异常 / 致命错误 / E_ERROR / E_WARNING 级：无论 DEBUG 与否【始终】落盘——
 *     客户站不开 DEBUG 也能事后取证（如发布文章撞上数据库缺列的 PDOException）。
 *   - E_NOTICE / E_DEPRECATED 级：仅 DEBUG=true 时记录，避免生产日志被刷屏。
 *   - 屏幕显示仍由 DEBUG（display_errors）控制，行为与从前一致。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 防止直接访问
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

final class ErrorHandler
{
    private static bool $installed = false;

    /** 日志保留月数（含当月） */
    private const KEEP_MONTHS = 3;

    /** 单条日志 message 上限，防止超长 SQL/HTML 把日志撑爆 */
    private const MSG_LIMIT = 2000;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        self::$installed = true;

        set_error_handler([self::class, 'onError']);
        set_exception_handler([self::class, 'onException']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    /** 当月日志文件绝对路径 */
    public static function file(): string
    {
        return self::dir() . '/error-' . date('Ym') . '.log';
    }

    private static function dir(): string
    {
        return ROOT_PATH . '/storage/logs';
    }

    /** 现有日志文件列表（新月在前），供后台查看页选择 */
    public static function listFiles(): array
    {
        $files = glob(self::dir() . '/error-*.log') ?: [];
        rsort($files);
        return array_map('basename', $files);
    }

    /**
     * 写一条日志。$context 传异常对象可附带堆栈。
     * 任何写入失败都静默——记日志本身绝不能把站点搞挂。
     */
    public static function log(string $level, string $message, string $file = '', int $line = 0, ?\Throwable $e = null): void
    {
        try {
            $dir = self::dir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $path = self::file();
            if (!is_file($path)) {
                self::prune();
            }

            $msg = mb_substr($message, 0, self::MSG_LIMIT);
            $uri = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? ($_SERVER['argv'][0] ?? '-'));
            $who = isset($_SESSION['admin_id']) ? ('admin#' . $_SESSION['admin_id']) : '-';

            $entry = sprintf(
                "[%s] [%s] %s at %s:%d {%s} {%s}\n",
                date('Y-m-d H:i:s'),
                $level,
                str_replace(["\r", "\n"], ' ', $msg),
                $file,
                $line,
                $uri,
                $who
            );
            if ($e !== null) {
                foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 12) as $t) {
                    $entry .= "    " . $t . "\n";
                }
            }
            @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $ignored) {
            // 静默
        }
    }

    /** 删除超过保留期的月度日志（仅在新月首次建档时触发，开销可忽略） */
    private static function prune(): void
    {
        $cut = date('Ym', strtotime('-' . (self::KEEP_MONTHS - 1) . ' months'));
        foreach (glob(self::dir() . '/error-*.log') ?: [] as $f) {
            if (preg_match('/error-(\d{6})\.log$/', $f, $m) && $m[1] < $cut) {
                @unlink($f);
            }
        }
    }

    /**
     * set_error_handler 回调：非致命错误（warning/notice 等）。
     * 返回值由 PHP 内核消费（true=吞掉，false=交回内建处理器）。
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public static function onError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        // @ 抑制符（PHP 8 下 error_reporting() 返回固定掩码 4437 而非 0）：尊重抑制，不记
        $er = error_reporting();
        if ($er !== 0 && !($er & $severity) && ($er & E_ERROR)) {
            return true;
        }

        $always = E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_WARNING | E_USER_WARNING;
        if (($severity & $always) || (defined('DEBUG') && DEBUG)) {
            self::log(self::levelName($severity), $message, $file, $line);
        }

        // DEBUG 时交回 PHP 内建处理器上屏显示；生产环境已记录，直接吞掉
        return !(defined('DEBUG') && DEBUG);
    }

    /** set_exception_handler 回调：未捕获异常（发布内容撞 SQL 错就落在这里） */
    public static function onException(\Throwable $e): void
    {
        self::log(
            'ERROR',
            get_class($e) . ': ' . $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e
        );
        self::respond(get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    }

    /** register_shutdown_function 回调：致命错误兜底（parse error / OOM / 调未定义函数等） */
    public static function onShutdown(): void
    {
        $err = error_get_last();
        if ($err === null || !($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
            return;
        }
        self::log(self::levelName($err['type']), $err['message'], $err['file'], $err['line']);
        if (!headers_sent()) {
            self::respond($err['message'] . ' at ' . $err['file'] . ':' . $err['line'], false);
        }
    }

    /**
     * 面向用户的出错响应。AJAX 回 JSON（与 error() 助手同构），页面回友好提示；
     * DEBUG 时带具体错误，生产只提示去后台看日志。
     */
    private static function respond(string $detail, bool $exit = true): void
    {
        $debug = defined('DEBUG') && DEBUG;
        $msg = $debug
            ? $detail
            : '服务器内部错误，详情已记录到错误日志（后台 → 系统信息 → 错误日志）';

        if (PHP_SAPI === 'cli') {
            file_put_contents('php://stderr', "[YikaiCMS] $detail\n");
            if ($exit) {
                exit(1);
            }
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
        }

        if (self::wantsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['code' => 500, 'msg' => $msg, 'data' => null], JSON_UNESCAPED_UNICODE);
        } else {
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>500</title></head><body style="font-family:system-ui;padding:60px 24px;text-align:center;color:#374151">'
                . '<h1 style="font-size:48px;margin:0 0 8px">500</h1>'
                . '<p>' . htmlspecialchars($msg, ENT_QUOTES) . '</p>'
                . '</body></html>';
        }
        if ($exit) {
            exit;
        }
    }

    /**
     * 请求是否期望 JSON。除标准头外，后台的 POST 一律视为 AJAX——
     * 编辑器等页面用原生 fetch 提交表单不带 X-Requested-With，
     * 回 HTML 会让前端报「JSON解析失败」看不到真实错误提示。
     */
    private static function wantsJson(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                && str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/admin/'));
    }

    private static function levelName(int $severity): string
    {
        return match (true) {
            (bool) ($severity & (E_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) => 'FATAL',
            (bool) ($severity & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'WARNING',
            (bool) ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) => 'DEPRECATED',
            default => 'NOTICE',
        };
    }
}
