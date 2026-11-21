<?php
/**
 * 产品导入任务记录：storage/product-import/jobs/<id>.json。
 * 一批导入一个任务，记每行结果、新建了什么、更新前的原值——页面关了回来接着看，也靠它撤销。
 *
 * 2.3.0 起（后台执行）：
 * - <id>.lock：执行权。同一时刻只有一个进程（页面请求、定时任务、命令行）能跑或撤销这个任务；
 *   用 flock，进程退出（包括被杀、崩溃）时操作系统自动释放，不会留下「永远在跑」的锁。
 * - <id>.active：还有活要干（排队、执行中、撤销中）。后台执行器只看这些标记，不逐个读任务文件。
 * - <id>.undo：撤销请求。任务正在别处执行时，撤销不能插进去写库，先留这个标记，执行者在两行之间看到就停下来撤销。
 * - <id>.updated/<产品 id>.json：被更新的产品的原值（撤销用）。这部分可能很大（整段详情），单独存，
 *   每写一款只写这一款的文件；主文件因此每次存盘都很小。升级前的任务原值在主文件里（updated），照样能读。
 * - 只有持有执行权的进程写任务文件；页面看进度只读。
 */

declare(strict_types=1);

final class ProductImportJobs
{
    /** @var array<string,string> 已写过的原值文件 => 内容 hash（同一进程里没变的不重写） */
    private array $written = [];

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

    /**
     * @param bool $full false = 只要进度与结果（页面看进度用），不读原值文件
     * @return array<string,mixed>|null
     */
    public function load(string $id, bool $full = true): ?array
    {
        if (!self::validId($id) || !is_file($this->file($id))) {
            return null;
        }
        $job = null;
        // Windows 上写的一方改名的一瞬间读不到：稍等重试
        for ($try = 0; $try < 20 && !is_array($job); $try++) {
            if ($try > 0) usleep(25_000);
            $raw = @file_get_contents($this->file($id));
            $job = $raw === false ? null : json_decode($raw, true);
        }
        if (!is_array($job)) {
            return null;
        }
        if ($full && !empty($job['updated_split'])) {
            $job['updated'] = [];
            foreach ((array) ($job['updated_ids'] ?? []) as $pid) {
                $record = json_decode((string) @file_get_contents($this->updatedFile($id, (int) $pid)), true);
                if (is_array($record)) $job['updated'][(int) $pid] = $record;
            }
        }
        return $job;
    }

    /** @param array<string,mixed> $job */
    public function save(array $job): void
    {
        $id = (string) ($job['id'] ?? '');
        if (!self::validId($id)) {
            throw new InvalidArgumentException('bad job id');
        }
        $this->prepare();
        // 新任务（v2）：原值拆到单独的文件里；升级前的任务保持原样，免得一半在主文件、一半在外面
        if ((int) ($job['v'] ?? 1) >= 2) {
            $updated = (array) ($job['updated'] ?? []);
            foreach ($updated as $pid => $record) {
                $file = $this->updatedFile($id, (int) $pid);
                $body = (string) json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $hash = md5($body);
                if (($this->written[$file] ?? '') === $hash) continue;
                if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
                $this->atomicWrite($file, $body);
                $this->written[$file] = $hash;
            }
            $job['updated'] = [];
            $job['updated_ids'] = array_map('intval', array_keys($updated));
            $job['updated_split'] = true;
        }
        $job['updated_at'] = time();
        $this->atomicWrite($this->file($id), (string) json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /** @return list<array<string,mixed>> 最近的在前，只带摘要 */
    public function recent(int $limit = 20): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        rsort($files);
        $list = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $job = json_decode((string) @file_get_contents($file), true);
            if (is_array($job)) {
                $summary = array_intersect_key($job, array_flip(['id', 'name', 'status', 'created_at', 'updated_at', 'counts', 'lang', 'v']));
                $summary['busy'] = $this->busy((string) $job['id']);
                $list[] = $summary;
            }
        }
        return $list;
    }

    // ── 执行权 ──

    /**
     * 拿执行权；别人拿着就立刻返回 null（不等）。拿到的句柄交给 release()，进程退出时也会自动释放。
     * @return resource|null
     */
    public function lock(string $id)
    {
        if (!self::validId($id)) return null;
        $this->prepare();
        $handle = @fopen($this->dir . '/' . $id . '.lock', 'c');
        if ($handle === false) return null;
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource|null $handle */
    public function release($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** 这个任务现在有没有进程拿着执行权（页面显示「后台执行中」用） */
    public function busy(string $id): bool
    {
        if (!self::validId($id) || !is_file($this->dir . '/' . $id . '.lock')) return false;
        $handle = $this->lock($id);
        if ($handle === null) return true;
        $this->release($handle);
        return false;
    }

    // ── 标记 ──

    public function markActive(string $id): void
    {
        $this->touchMarker($id, 'active');
    }

    public function clearActive(string $id): void
    {
        if (self::validId($id)) @unlink($this->dir . '/' . $id . '.active');
    }

    /** @return list<string> 还有活要干的任务，先建的先做 */
    public function active(): array
    {
        $ids = [];
        foreach (glob($this->dir . '/*.active') ?: [] as $file) {
            $id = basename($file, '.active');
            if (self::validId($id)) $ids[] = $id;
        }
        sort($ids);
        return $ids;
    }

    public function requestUndo(string $id): void
    {
        $this->touchMarker($id, 'undo');
        $this->markActive($id);
    }

    public function undoRequested(string $id): bool
    {
        return self::validId($id) && is_file($this->dir . '/' . $id . '.undo');
    }

    public function clearUndo(string $id): void
    {
        if (self::validId($id)) @unlink($this->dir . '/' . $id . '.undo');
    }

    private function touchMarker(string $id, string $kind): void
    {
        if (!self::validId($id)) throw new InvalidArgumentException('bad job id');
        $this->prepare();
        @file_put_contents($this->dir . '/' . $id . '.' . $kind, (string) time());
    }

    private function prepare(): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
            // 任务里有更新前的原值、站内路径：不给网页直接读
            file_put_contents(dirname($this->dir) . '/.htaccess', "Require all denied\nDeny from all\n");
        }
    }

    /** 先写临时文件再改名；Windows 上目标正被别的进程读着时改名会失败，稍等重试 */
    private function atomicWrite(string $file, string $body): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $body, LOCK_EX) === false) {
            throw new RuntimeException('job_save_failed');
        }
        for ($try = 0; $try < 40; $try++) {
            if (@rename($tmp, $file)) return;
            usleep(25_000);
        }
        @unlink($tmp);
        throw new RuntimeException('job_save_failed');
    }

    private function file(string $id): string
    {
        return $this->dir . '/' . $id . '.json';
    }

    private function updatedFile(string $id, int $productId): string
    {
        return $this->dir . '/' . $id . '.updated/' . $productId . '.json';
    }
}
