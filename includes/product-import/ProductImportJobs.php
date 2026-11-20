<?php
/**
 * 产品导入任务记录：storage/product-import/jobs/<id>.json。
 * 一批导入一个任务，记每行结果、新建了什么、更新前的原值——页面关了回来接着看，也靠它撤销。
 */

declare(strict_types=1);

final class ProductImportJobs
{
    public function __construct(private string $dir)
    {
    }

    public static function newId(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/D', $id);
    }

    /** @return array<string,mixed>|null */
    public function load(string $id): ?array
    {
        if (!self::validId($id) || !is_file($this->file($id))) {
            return null;
        }
        $job = json_decode((string) file_get_contents($this->file($id)), true);
        return is_array($job) ? $job : null;
    }

    /** @param array<string,mixed> $job */
    public function save(array $job): void
    {
        $id = (string) ($job['id'] ?? '');
        if (!self::validId($id)) {
            throw new InvalidArgumentException('bad job id');
        }
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
            // 任务里有更新前的原值、站内路径：不给网页直接读
            file_put_contents(dirname($this->dir) . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $job['updated_at'] = time();
        $tmp = $this->file($id) . '.tmp';
        file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
        rename($tmp, $this->file($id));
    }

    /** @return list<array<string,mixed>> 最近的在前，只带摘要 */
    public function recent(int $limit = 20): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        rsort($files);
        $list = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $job = json_decode((string) file_get_contents($file), true);
            if (is_array($job)) {
                $list[] = array_intersect_key($job, array_flip(['id', 'name', 'status', 'created_at', 'updated_at', 'counts', 'lang']));
            }
        }
        return $list;
    }

    private function file(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }
}
