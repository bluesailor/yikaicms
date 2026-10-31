<?php

declare(strict_types=1);

/**
 * AI 外部访问的分钟级限流（规划 §6.4）。计数存数据库，多 worker 共享；存储不可用时**拒绝**（503），
 * 不沿用旧公开 API“限流存储失败就放行”的语义。
 */
final class AiAccessThrottle
{
    public const READ_PER_MINUTE = 60;
    public const PROPOSE_PER_MINUTE = 10;
    public const AUTH_FAIL_PER_MINUTE = 20;
    private const WINDOW = 60;
    private const KEEP_SECONDS = 3600;

    public function __construct(private Database $db)
    {
    }

    /** 计一次并返回是否仍在限额内。自增与读回在同一事务里，并发 worker 不会丢计数。 */
    public function hit(string $bucket, int $limit, int $now): bool
    {
        $window = $now - $now % self::WINDOW;
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->increment(self::bucket($bucket), $window) <= $limit;
            } catch (PDOException $error) {
                // InnoDB 并发插入同一新唯一键可能互为死锁/锁等待；重试一次，仍失败按不可用处理。
                if ($attempt === 0 && in_array((string) $error->getCode(), ['40001', 'HY000'], true)) continue;
                throw new RuntimeException('ai_access_unavailable', 503);
            } catch (Throwable) {
                throw new RuntimeException('ai_access_unavailable', 503);
            }
        }
    }

    /** 来源 IP 的失败计数桶（凭据错误、非 HTTPS 请求共用）。只限制失败本身，不挡同一来源的有效令牌。 */
    public static function failBucket(string $ip): string
    {
        return 'ip:' . $ip . ':authfail';
    }

    public static function retryAfter(int $now): int
    {
        return self::WINDOW - $now % self::WINDOW;
    }

    /** 删除一小时前的窗口；由 cron 调用。 */
    public function prune(int $now): int
    {
        return $this->db->execute('DELETE FROM ' . DB_PREFIX . 'api_rate_limits WHERE window_start < ?', [$now - self::KEEP_SECONDS]);
    }

    private function increment(string $bucket, int $window): int
    {
        if ($this->db->getPdo()->inTransaction()) throw new RuntimeException('ai_access_unavailable', 503);
        $table = DB_PREFIX . 'api_rate_limits';
        $this->db->beginTransaction();
        try {
            if ($this->db->isSqlite()) {
                $this->db->execute("INSERT INTO {$table} (bucket, window_start, hits) VALUES (?, ?, 1) ON CONFLICT(bucket, window_start) DO UPDATE SET hits = hits + 1", [$bucket, $window]);
            } else {
                $this->db->execute("INSERT INTO {$table} (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1", [$bucket, $window]);
            }
            // 本事务持有该行写锁，读回的就是含本次在内的精确计数。
            $hits = (int) $this->db->fetchColumn("SELECT hits FROM {$table} WHERE bucket = ? AND window_start = ?", [$bucket, $window]);
            $this->db->commit();
            return $hits;
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** 桶名可能含 IP；超长时取摘要，保证落在列宽内且不同来源不撞名。 */
    private static function bucket(string $bucket): string
    {
        return strlen($bucket) <= 100 ? $bucket : substr($bucket, 0, 35) . ':' . hash('sha256', $bucket);
    }
}
