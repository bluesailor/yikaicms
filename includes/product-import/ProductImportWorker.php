<?php
/**
 * 产品导入的执行者（2.3.0 后台执行）。确认导入后，任务由这里推进，不再依赖页面开着：
 *   - 定时任务 product_import（includes/Cron.php，每分钟，每次 45 秒）；
 *   - 建任务、重试、撤销后立即触发一次（Cron::kick，不等结果），跑完一段还有活就再触发下一段；
 *   - 命令行 php bin/yikai.php product-import:run（大批量）；
 *   - 页面开着时也会顺手推进一小段（没配定时任务、站点地址也没配时的兜底）。
 * 不管谁来跑，同一个任务同一时刻只有一个执行者（ProductImportJobs::lock，进程退出自动释放）。
 * 每写一款就存一次进度；中断后下一个执行者接着做，已写进库的行按标记认领（见 ProductImportRunner）。
 *
 * 撤销也要执行权：任务正在别处跑时，撤销只留一个请求（.undo），执行者做完手上这一款就停下来撤销。
 * 执行前确认建任务的人仍是启用的超级管理员（产品导入只给超级管理员，所有者 2026-11-20 定）；不是就停下（blocked）。
 */

declare(strict_types=1);

require_once __DIR__ . '/ProductImportPackage.php';
require_once __DIR__ . '/ProductImportJobs.php';
require_once __DIR__ . '/ProductImportRunner.php';
require_once dirname(__DIR__) . '/TranslationBatches.php';   // 复用内存护栏 memoryTight()

final class ProductImportWorker
{
    /** 页面顺手推进时每次最多跑多久（秒） */
    public const PAGE_SLICE = 10;
    /** 定时任务 / 立即触发每次的预算（秒） */
    public const BACKGROUND_SLICE = 45;
    /** 进度存盘的最短间隔（秒）；改库前要记的原值不受这个限制，马上存 */
    private const SAVE_EVERY = 1;

    /** @var Closure(int): bool 建任务的人现在还能不能用产品导入 */
    private Closure $mayRun;
    /** @var null|Closure(string, int): void 测试用：每写进库一行之后调用 */
    private ?Closure $afterWrite = null;

    /** @param null|Closure(int): bool $mayRun 测试注入；默认查账号与角色 */
    public function __construct(private ProductImportJobs $jobs, private string $siteRoot, ?Closure $mayRun = null)
    {
        $this->mayRun = $mayRun ?? self::isActiveSuperAdmin(...);
    }

    /**
     * @param null|Closure(string, int): void $afterWrite
     * @psalm-suppress PossiblyUnusedMethod 测试注入：单元测试与 MySQL 验收夹具用它在写库之后模拟中断
     */
    public function onWrite(?Closure $afterWrite): void
    {
        $this->afterWrite = $afterWrite;
    }

    /**
     * 推进一个任务，最多 $budgetSeconds 秒。
     * result：busy（别的执行者拿着）、gone（任务没了）、legacy（升级前的任务，要在页面上点「继续」才会跑）、
     *         ran（推进了或做完了）、undone（撤销完了）、blocked（建任务的人已不能导入）、idle（没什么可做）。
     * @return array{result:string,progress:bool,job:?array<string,mixed>}
     */
    public function runJob(string $id, float $budgetSeconds, string $via = 'cron'): array
    {
        $lock = $this->jobs->lock($id);
        if ($lock === null) {
            return ['result' => 'busy', 'progress' => false, 'job' => $this->jobs->load($id, false)];
        }
        try {
            $job = $this->jobs->load($id);
            if ($job === null) {
                $this->jobs->clearActive($id);
                $this->jobs->clearUndo($id);
                return ['result' => 'gone', 'progress' => false, 'job' => null];
            }
            if ($this->jobs->undoRequested($id) || $job['status'] === 'undoing') {
                $this->undoLocked($job);
                return ['result' => 'undone', 'progress' => true, 'job' => $job];
            }
            if ((int) ($job['v'] ?? 1) < ProductImportRunner::VERSION) {
                $this->jobs->clearActive($id);
                return ['result' => 'legacy', 'progress' => false, 'job' => $job];
            }
            if (!in_array($job['status'], ['queued', 'ready', 'running'], true)) {
                $this->jobs->clearActive($id);
                return ['result' => 'idle', 'progress' => false, 'job' => $job];
            }
            if (!($this->mayRun)((int) $job['admin_id'])) {
                $job['status'] = 'blocked';
                $job['blocked_reason'] = 'admin_not_allowed';
                unset($job['owner']);
                $this->jobs->save($job);
                $this->jobs->clearActive($id);
                return ['result' => 'blocked', 'progress' => false, 'job' => $job];
            }
            if (!is_dir((string) $job['package'])) {
                $job['status'] = 'blocked';
                $job['blocked_reason'] = 'package_gone';
                unset($job['owner']);
                $this->jobs->save($job);
                $this->jobs->clearActive($id);
                return ['result' => 'blocked', 'progress' => false, 'job' => $job];
            }
            return $this->advance($job, $budgetSeconds, $via);
        } finally {
            $this->jobs->release($lock);
        }
    }

    /**
     * 后台：依次推进所有还有活的任务，直到时间用完或内存吃紧。
     * @return array{jobs:int,progress:bool,remaining:bool}
     */
    public function drain(float $budgetSeconds, string $via = 'cron'): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $touched = 0;
        $progress = false;
        foreach ($this->jobs->active() as $id) {
            $left = $deadline - microtime(true);
            if ($left <= 1 || TranslationBatches::memoryTight()) break;
            $result = $this->runJob($id, $left, $via);
            if ($result['result'] !== 'busy') $touched++;
            $progress = $progress || $result['progress'];
        }
        return ['jobs' => $touched, 'progress' => $progress, 'remaining' => $this->jobs->active() !== []];
    }

    /**
     * 撤销。拿得到执行权就当场撤销；任务正在别处跑，就留下请求，由执行者停下来撤销。
     * @return array{result:string,job:?array<string,mixed>} result：undone / queued / busy / not_undoable / gone
     */
    public function undo(string $id): array
    {
        $lock = $this->jobs->lock($id);
        if ($lock === null) {
            $job = $this->jobs->load($id, false);
            if ($job === null) return ['result' => 'gone', 'job' => null];
            if (!self::undoable($job)) return ['result' => 'not_undoable', 'job' => $job];
            $this->jobs->requestUndo($id);
            return ['result' => 'queued', 'job' => $job];
        }
        try {
            $job = $this->jobs->load($id);
            if ($job === null) return ['result' => 'gone', 'job' => null];
            if (!self::undoable($job)) return ['result' => 'not_undoable', 'job' => $job];
            $this->undoLocked($job);
            return ['result' => 'undone', 'job' => $job];
        } finally {
            $this->jobs->release($lock);
        }
    }

    /**
     * 失败的行重新排队，交给后台。正在执行时不改（返回 busy）。
     * @return array{result:string,job:?array<string,mixed>} result：queued / nothing / busy / gone
     */
    public function retry(string $id): array
    {
        $lock = $this->jobs->lock($id);
        if ($lock === null) return ['result' => 'busy', 'job' => $this->jobs->load($id, false)];
        try {
            $job = $this->jobs->load($id);
            if ($job === null) return ['result' => 'gone', 'job' => null];
            if ($job['status'] !== 'done' || ProductImportRunner::retryFailed($job) === 0) return ['result' => 'nothing', 'job' => $job];
            unset($job['verify']);
            $job['v'] = max((int) ($job['v'] ?? 1), ProductImportRunner::VERSION);
            $this->jobs->save($job);
            $this->jobs->markActive($id);
            return ['result' => 'queued', 'job' => $job];
        } finally {
            $this->jobs->release($lock);
        }
    }

    /**
     * 升级前建的、没跑完的任务：站长在页面上点「继续」后才交给后台。
     * 那时的任务没有「写库前先存原值」和行标记：续跑时新建的行按导入键找回（legacy_resume），
     * 中断那一批里被更新过的产品，原值已经丢了（见升级说明的兼容限制）。
     * @return array{result:string,job:?array<string,mixed>} result：queued / busy / gone / not_resumable
     */
    public function resumeLegacy(string $id): array
    {
        $lock = $this->jobs->lock($id);
        if ($lock === null) return ['result' => 'busy', 'job' => $this->jobs->load($id, false)];
        try {
            $job = $this->jobs->load($id);
            if ($job === null) return ['result' => 'gone', 'job' => null];
            if ((int) ($job['v'] ?? 1) >= ProductImportRunner::VERSION || !in_array($job['status'], ['ready', 'running'], true)) {
                return ['result' => 'not_resumable', 'job' => $job];
            }
            $job['v'] = ProductImportRunner::VERSION;
            $job['legacy_resume'] = true;
            $job['categories_done'] = $job['category_ids'] !== [];
            $this->jobs->save($job);
            $this->jobs->markActive($id);
            return ['result' => 'queued', 'job' => $job];
        } finally {
            $this->jobs->release($lock);
        }
    }

    /** @param array<string,mixed> $job */
    public static function undoable(array $job): bool
    {
        return in_array($job['status'], ['done', 'running', 'queued', 'undoing', 'blocked'], true);
    }

    /** 这个账号现在是不是启用的超级管理员（账号与角色都启用，角色含 *） */
    public static function isActiveSuperAdmin(int $adminId): bool
    {
        if ($adminId <= 0) return false;
        $row = db()->fetchOne('SELECT u.status AS user_status, r.status AS role_status, r.permissions FROM ' . DB_PREFIX . 'users u JOIN '
            . DB_PREFIX . 'roles r ON r.id = u.role_id WHERE u.id = ?', [$adminId]);
        if ($row === null || (int) $row['user_status'] !== 1 || (int) $row['role_status'] !== 1) return false;
        $permissions = json_decode((string) $row['permissions'], true);
        return is_array($permissions) && in_array('*', $permissions, true);
    }

    /**
     * 持有执行权时推进：每次一款，每款后存进度；时间到、内存吃紧、有人要撤销就停。
     * @param array<string,mixed> $job
     * @return array{result:string,progress:bool,job:?array<string,mixed>}
     */
    private function advance(array $job, float $budgetSeconds, string $via): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $id = (string) $job['id'];
        $job['owner'] = ['via' => $via, 'pid' => getmypid(), 'since' => time()];
        $this->jobs->save($job);
        $runner = new ProductImportRunner(ProductImportPackage::fromDirectory((string) $job['package'], $this->siteRoot), $this->siteRoot);
        $runner->onWrite($this->afterWrite);
        $lastSave = microtime(true);
        $checkpoint = function (array $current, bool $now) use (&$lastSave): void {
            if (!$now && microtime(true) - $lastSave < self::SAVE_EVERY) return;
            $this->jobs->save($current);
            $lastSave = microtime(true);
        };
        $snapshot = static fn (array $j): array => [$j['status'], ProductImportRunner::counts($j), $j['cursor'], $j['categories_done'] ?? null];
        $before = $snapshot($job);
        while (in_array($job['status'], ['queued', 'ready', 'running'], true)) {
            if (microtime(true) >= $deadline || TranslationBatches::memoryTight() || $this->jobs->undoRequested($id)) break;
            $runner->run($job, 1, $checkpoint);
        }
        $progress = $snapshot($job) !== $before;
        if ($job['status'] === 'done' && !isset($job['verify'])) {
            $job['verify'] = $runner->verify($job);
            $c = $job['counts'];
            self::log($job, 'import', "产品导入任务 {$id}：新增 {$c['created']}，更新 {$c['updated']}，跳过 {$c['skipped']}，失败 {$c['failed']}，有错 {$c['error']}");
        }
        unset($job['owner']);
        $this->jobs->save($job);
        if ($this->jobs->undoRequested($id)) {
            $this->undoLocked($job);
            return ['result' => 'undone', 'progress' => true, 'job' => $job];
        }
        if ($job['status'] === 'done') $this->jobs->clearActive($id);
        return ['result' => 'ran', 'progress' => $progress, 'job' => $job];
    }

    /** 持有执行权时撤销。先记「撤销中」存盘：撤到一半中断，下一个执行者接着撤（撤销可以重复执行） @param array<string,mixed> $job */
    private function undoLocked(array &$job): void
    {
        $id = (string) $job['id'];
        $job['status'] = 'undoing';
        unset($job['owner']);
        $this->jobs->save($job);
        $package = is_dir((string) $job['package']) ? (string) $job['package'] : sys_get_temp_dir();
        $runner = new ProductImportRunner(ProductImportPackage::fromDirectory($package, $this->siteRoot), $this->siteRoot);
        $conflicts = $runner->undo($job);
        $this->jobs->save($job);
        $this->jobs->clearUndo($id);
        $this->jobs->clearActive($id);
        self::log($job, 'import', '撤销产品导入任务 ' . $id . '，未恢复 ' . count($conflicts) . ' 款');
    }

    /** 后台没有登录会话：日志记在建任务的人名下 @param array<string,mixed> $job */
    private static function log(array $job, string $action, string $detail): void
    {
        if (!empty($_SESSION['admin_id'])) {
            if (function_exists('adminLog')) adminLog('product', $action, $detail);
            return;
        }
        if (!function_exists('adminLogModel')) return;
        try {
            adminLogModel()->log([
                'admin_id' => (int) $job['admin_id'], 'admin_name' => '', 'module' => 'product', 'action' => $action, 'description' => $detail,
                'url' => 'product-import worker', 'method' => PHP_SAPI === 'cli' ? 'CLI' : 'CRON', 'request_data' => '', 'ip' => '', 'user_agent' => '', 'created_at' => time(),
            ]);
        } catch (Throwable) {
            // 日志写不进去不影响导入
        }
    }
}
