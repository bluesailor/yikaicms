<?php

declare(strict_types=1);

/**
 * AI 外部访问审计（规划 §6.4）：每个外部请求一行。只记协议观测与结果码，
 * 不记 Authorization、令牌明文/摘要、工具参数或正文。
 */
final class AiAccessLog
{
    public const KEEP_DAYS = 30;
    public const PRUNE_BATCH = 5000;
    private const PRUNE_MAX_BATCHES = 50;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string,mixed> $trace AiMcpServer::$trace
     */
    public function record(string $requestId, array $trace, string $ip, int $durationMs, int $now): void
    {
        $text = static fn(mixed $value, int $max): string => is_scalar($value) ? substr(preg_replace('/[^\x20-\x7E]/', '?', (string) $value) ?? '', 0, $max) : '';
        $result = $trace['tool_result'] ?? $trace['auth'] ?? (isset($trace['error']) ? 'rpc_' . $trace['error'] : 'ok');
        $this->db->insert('api_access_log', [
            'request_id' => $text($requestId, 32),
            'created_at' => $now,
            'admin_id' => (int) ($trace['actor'] ?? 0),
            'token_id' => (int) ($trace['token_id'] ?? 0),
            'client' => $text($trace['client'] ?? '', 80),
            'protocol' => $text($trace['meta_version'] ?? $trace['header_version'] ?? '', 12),
            'method' => $text($trace['method'] ?? $trace['http'] ?? '', 40),
            'tool' => $text($trace['tool'] ?? '', 64),
            'http_status' => (int) ($trace['status'] ?? 0),
            'result' => $text($result, 64),
            'target' => $text($trace['target'] ?? '', 100),
            'duration_ms' => max(0, $durationMs),
            'ip' => $text($ip, 45),
        ]);
    }

    /**
     * 按批删除过期日志，删到没有过期行为止；每批是短语句，整轮有批数与时间上限，由每小时的 cron 调用。
     * 上限（50 批 × 5000 行/小时）远高于限流允许的写入量，积压一轮就能清完。
     */
    public function prune(int $now, float $budgetSeconds = 10.0): int
    {
        $table = DB_PREFIX . 'api_access_log';
        $cutoff = $now - self::KEEP_DAYS * 86400;
        $sql = $this->db->isSqlite()
            ? "DELETE FROM {$table} WHERE id IN (SELECT id FROM {$table} WHERE created_at < ? ORDER BY id LIMIT " . self::PRUNE_BATCH . ')'
            : "DELETE FROM {$table} WHERE created_at < ? ORDER BY id LIMIT " . self::PRUNE_BATCH;
        $deadline = microtime(true) + $budgetSeconds;
        $removed = 0;
        for ($batch = 0; $batch < self::PRUNE_MAX_BATCHES; $batch++) {
            $count = $this->db->execute($sql, [$cutoff]);
            $removed += $count;
            if ($count < self::PRUNE_BATCH || microtime(true) >= $deadline) break;
        }
        return $removed;
    }
}
