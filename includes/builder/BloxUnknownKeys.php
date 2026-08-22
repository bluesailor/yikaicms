<?php
/**
 * Unknown Blox Data Key 的 dry-run 观测器（v1.18.6）。
 *
 * 目标策略是「只保留 controls 声明的键 + 保留键，丢弃未知字段」，但存量客户
 * 文档里可能躺着历史键/插件键，直接丢弃是全报告兼容风险最大的一步。所以
 * v1.18.6 只记录不丢弃：保存管线把每个元素 data 里未声明的键聚合写入
 * storage/logs/blox-unknown-keys.json，观察一个周期后再在 v1.19 决定
 * 强制策略与插件命名空间豁免。
 *
 * 记录失败静默——观测器绝不能影响保存本身。
 */

declare(strict_types=1);

final class BloxUnknownKeys
{
    /** data 里合法但不来自 controls() 的核心保留键 */
    public const RESERVED = ['children', 'template', '_global_style', '_global_style_snapshot'];

    /** @var array<string,array<string,int>> type => key => count（进程内聚合，shutdown 落盘） */
    private static array $pending = [];
    private static bool $flushRegistered = false;

    /**
     * @param list<string> $declaredKeys controls() 声明的键
     * @param array<string,mixed> $data
     */
    public static function observe(string $elementType, array $declaredKeys, array $data): void
    {
        $known = array_flip(array_merge($declaredKeys, self::RESERVED));
        foreach (array_keys($data) as $key) {
            $key = (string) $key;
            if ($key === '' || isset($known[$key])) {
                continue;
            }
            $type = $elementType !== '' ? $elementType : '(unknown)';
            self::$pending[$type][$key] = (self::$pending[$type][$key] ?? 0) + 1;
        }
        if (self::$pending !== [] && !self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    /** 聚合写盘（计数累加进已有文件）；无 ROOT_PATH/不可写时静默放弃 */
    public static function flush(): void
    {
        if (self::$pending === [] || !defined('ROOT_PATH')) {
            return;
        }
        $dir = ROOT_PATH . '/storage/logs';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $file = $dir . '/blox-unknown-keys.json';
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return;
            }
            $existing = json_decode((string) stream_get_contents($fh), true);
            $report = is_array($existing) ? $existing : ['_note' => 'Blox 未声明数据键观测（dry-run，不影响数据）', 'keys' => []];
            foreach (self::$pending as $type => $keys) {
                foreach ($keys as $key => $count) {
                    $report['keys'][$type][$key] = (int) ($report['keys'][$type][$key] ?? 0) + $count;
                }
            }
            $report['_updated'] = date('Y-m-d H:i:s');
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            flock($fh, LOCK_UN);
            self::$pending = [];
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }
    }

    /**
     * 测试用：读取并清空进程内聚合
     * @psalm-suppress PossiblyUnusedMethod 调用方在 tests/（不在 Psalm projectFiles 内）
     */
    public static function drain(): array
    {
        $out = self::$pending;
        self::$pending = [];
        return $out;
    }
}
