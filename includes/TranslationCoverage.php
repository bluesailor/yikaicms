<?php
/**
 * 译文覆盖情况：逐块读默认语言的内容与各语言译文，按 TranslationState 的状态给每一格定性。
 * 「译文状态」页与交付检查清单共用这一份判断（路线图 CORE-02），不另起一套状态。
 *
 * 内容种类（KINDS）：产品；内容（文章、案例等，不含单页的正文行）；栏目（含单页，单页的正文在栏目下的正文行里）；
 * 单页（栏目里 type=page 的那部分，翻译工作中心单列）；普通栏目（type<>'page'，交付检查单列，2.3.1）；产品分类。
 * 一格（$cells[lang]）：['id' => 译文 id（0=缺失）, 'status' => TranslationState::status() 或 null, 'draft' => 未发布]
 * 「未发布」只看这一行自己的 status：前台各入口（product.php、detail.php、page.php、产品分类查询）都以 status = 1 为准，
 * 审阅状态（已审 / 人改过）与是否发布是两回事，一格里分开给出。
 * 一行的标记（$flags）：missing / draft / outdated / proposal / untracked / ready（有已审、可直接发布的草稿译文），只要有一种语言符合就为 true。
 */

declare(strict_types=1);

require_once __DIR__ . '/TranslationState.php';

final class TranslationCoverage
{
    public const FLAGS = ['missing', 'draft', 'outdated', 'proposal', 'untracked', 'ready'];
    /** 表、标题列、摘要列、有没有回收站、额外条件、是否有单页正文行 */
    public const KINDS = [
        'products' => ['table' => 'products', 'title' => 'title', 'summary' => 'summary', 'trash' => true, 'where' => '', 'pages' => false],
        'contents' => ['table' => 'contents', 'title' => 'title', 'summary' => 'summary', 'trash' => true, 'where' => "type <> 'page'", 'pages' => false],
        'channels' => ['table' => 'channels', 'title' => 'name', 'summary' => 'description', 'trash' => false, 'where' => '', 'pages' => true],
        'pages' => ['table' => 'channels', 'title' => 'name', 'summary' => 'description', 'trash' => false, 'where' => "type = 'page'", 'pages' => true],
        'sections' => ['table' => 'channels', 'title' => 'name', 'summary' => 'description', 'trash' => false, 'where' => "type <> 'page'", 'pages' => false],
        'categories' => ['table' => 'product_categories', 'title' => 'name', 'summary' => 'description', 'trash' => false, 'where' => '', 'pages' => false],
    ];
    /** 编辑接下来该做的事，按先后：补译 → 审阅待审译文 → 更新过期译文 → 发布 → 核对来历不明的译文 */
    public const ACTIONS = ['translate', 'review', 'update', 'publish', 'check'];
    private const CHUNK = 100;
    /** 轻量扫描（只取标题、状态与内容校验值）每块的行数 */
    private const LIGHT_CHUNK = 400;
    /** 指纹缓存的格式号：TranslationState 的指纹算法或 TRACKED 字段变了就加一，旧缓存自动作废 */
    private const PRINT_CACHE_VERSION = 1;
    /** @var array<string,array<int,array{0:string,1:string}>> 表 => 行 id => [内容校验值, rowPrint] */
    private array $prints = [];
    /** @var array<string,true> 本次请求里改动过、要写回缓存文件的表 */
    private array $printsDirty = [];

    /** @param bool $light false = 总是整行扫描（核对用；正常使用不要传） */
    public function __construct(private Database $db, private bool $light = true)
    {
    }

    /**
     * @param list<string> $langs 目标语言（不含默认语言）
     * @param bool $publishedOnly 只看已发布的源内容（交付检查：没上线的内容不需要译文）
     * @param Closure(array<string,mixed>, array<string,array{id:int,status:?array<string,mixed>,draft:bool}>, array<string,bool>):void $visit
     * @param string $kind self::KINDS 的键
     */
    public function scan(string $defaultLang, array $langs, Closure $visit, string $keyword = '', bool $publishedOnly = false, string $kind = 'products'): void
    {
        if ($langs === []) return;
        $spec = self::KINDS[$kind] ?? throw new InvalidArgumentException('unknown translation coverage kind');
        $table = DB_PREFIX . $spec['table'];
        $live = $spec['trash'] ? ' AND deleted_at IS NULL' : '';
        $where = 'lang = ?' . $live . ($spec['where'] !== '' ? ' AND ' . $spec['where'] : '') . ($publishedOnly ? ' AND status = 1' : '');
        $params = [$defaultLang];
        if ($keyword !== '') {
            $where .= " AND {$spec['title']} LIKE ?";
            $params[] = '%' . $keyword . '%';
        }
        $states = new TranslationState($this->db);
        // 程序已覆盖、数据库还没升级时没有来源追踪表：照常算缺失与未发布，全部按未追踪处理
        $tracked = $this->db->tableExists('translation_links');
        $signature = $spec['pages'] || !$this->light ? null : $this->signatureSql($spec);
        if ($signature !== null) {
            $this->scanLight($spec, $signature, $where, $params, $live, $langs, $visit, $states, $tracked);
            return;
        }
        // 按 id 往下翻（不用 OFFSET：越往后越慢，大表上是平方级的开销）
        for ($before = PHP_INT_MAX; ; ) {
            $sources = $this->db->fetchAll("SELECT * FROM {$table} WHERE {$where} AND id < ? ORDER BY id DESC LIMIT " . self::CHUNK, array_merge($params, [$before]));
            if ($sources === []) break;
            $before = (int) $sources[count($sources) - 1]['id'];
            $groups = [];
            foreach ($sources as $source) $groups[(int) ($source['translation_group_id'] ?: $source['id'])] = (int) $source['id'];
            $targets = $this->db->fetchAll("SELECT * FROM {$table} WHERE translation_group_id IN (" . implode(',', array_fill(0, count($groups), '?')) . ')' . $live
                . ' AND lang IN (' . implode(',', array_fill(0, count($langs), '?')) . ')', array_merge(array_keys($groups), $langs));
            $links = $tracked ? $states->links($spec['table'], array_map(static fn (array $row): int => (int) $row['id'], $targets)) : [];
            $byGroup = [];
            foreach ($targets as $target) {
                $groupId = (int) $target['translation_group_id'];
                if (($groups[$groupId] ?? 0) !== (int) $target['id']) $byGroup[$groupId][(string) $target['lang']] ??= $target;
            }
            foreach ($sources as $source) {
                $groupId = (int) ($source['translation_group_id'] ?: $source['id']);
                $sourceFp = TranslationState::rowPrint($source, $spec['title'], $spec['summary'], $this->pageBody($spec['pages'], $source));
                $cells = [];
                $flags = array_fill_keys(self::FLAGS, false);
                foreach ($langs as $lang) {
                    $target = $byGroup[$groupId][$lang] ?? null;
                    if ($target === null) {
                        $cells[$lang] = ['id' => 0, 'status' => null, 'draft' => false];
                        $flags['missing'] = true;
                        continue;
                    }
                    $status = TranslationState::status($links[(int) $target['id']] ?? null, $sourceFp,
                        TranslationState::rowPrint($target, $spec['title'], $spec['summary'], $this->pageBody($spec['pages'], $target)));
                    $draft = (int) ($target['status'] ?? 0) === 0;
                    $cells[$lang] = ['id' => (int) $target['id'], 'status' => $status, 'draft' => $draft];
                    $flags['draft'] = $flags['draft'] || $draft;
                    $flags['outdated'] = $flags['outdated'] || $status['outdated'];
                    $flags['proposal'] = $flags['proposal'] || $status['proposal'];
                    $flags['untracked'] = $flags['untracked'] || $status['state'] === 'untracked';
                    $flags['ready'] = $flags['ready'] || ((int) ($source['status'] ?? 0) === 1 && self::ready($cells[$lang]));
                }
                $visit($source + ['_title' => (string) ($source[$spec['title']] ?? '')], $cells, $flags);
            }
        }
    }

    /**
     * 大站上的扫描（2.3.0）：状态页每次打开都要给全部原文定性，原先整行读出（含正文）再算指纹，
     * 3000 篇文章约 1.1 秒。这里每行只取标题、状态和数据库算好的内容校验值；指纹按「校验值没变就沿用」缓存，
     * 只有内容变过的行才整行读出重算。校验值由可翻译字段的当前内容算出，不依赖 updated_at，结果与整行扫描逐项一致。
     * 回调拿到的 $source 只有 id、lang、translation_group_id、status、标题列与 _title。
     * @param array{table:string,title:string,summary:string,trash:bool,where:string,pages:bool} $spec
     * @param list<mixed> $params @param list<string> $langs
     */
    private function scanLight(array $spec, string $signature, string $where, array $params, string $live, array $langs, Closure $visit, TranslationState $states, bool $tracked): void
    {
        $table = DB_PREFIX . $spec['table'];
        $columns = "id, lang, translation_group_id, status, {$spec['title']}, {$signature} AS _sig";
        $langMarks = implode(',', array_fill(0, count($langs), '?'));
        for ($before = PHP_INT_MAX; ; ) {
            $sources = $this->db->fetchAll("SELECT {$columns} FROM {$table} WHERE {$where} AND id < ? ORDER BY id DESC LIMIT " . self::LIGHT_CHUNK, array_merge($params, [$before]));
            if ($sources === []) break;
            $before = (int) $sources[count($sources) - 1]['id'];
            $groups = [];
            foreach ($sources as $source) $groups[(int) ($source['translation_group_id'] ?: $source['id'])] = (int) $source['id'];
            $targets = $this->db->fetchAll("SELECT {$columns} FROM {$table} WHERE translation_group_id IN (" . implode(',', array_fill(0, count($groups), '?')) . ')' . $live
                . " AND lang IN ({$langMarks})", array_merge(array_keys($groups), $langs));
            $links = $tracked ? $states->links($spec['table'], array_map(static fn (array $row): int => (int) $row['id'], $targets)) : [];
            $byGroup = [];
            foreach ($targets as $target) {
                $groupId = (int) $target['translation_group_id'];
                if (($groups[$groupId] ?? 0) !== (int) $target['id']) $byGroup[$groupId][(string) $target['lang']] ??= $target;
            }
            // 只有「有来历记录的译文」和它的原文需要指纹；缺失、未追踪的格子不用
            $need = [];
            foreach ($sources as $source) {
                $groupId = (int) ($source['translation_group_id'] ?: $source['id']);
                foreach ($byGroup[$groupId] ?? [] as $target) {
                    $link = $links[(int) $target['id']] ?? null;
                    if ($link === null || (string) $link['state'] === 'untracked') continue;
                    $need[(int) $target['id']] = (string) $target['_sig'];
                    $need[(int) $source['id']] = (string) $source['_sig'];
                }
            }
            $prints = $this->printsFor($spec, $need);
            foreach ($sources as $source) {
                $groupId = (int) ($source['translation_group_id'] ?: $source['id']);
                $cells = [];
                $flags = array_fill_keys(self::FLAGS, false);
                foreach ($langs as $lang) {
                    $target = $byGroup[$groupId][$lang] ?? null;
                    if ($target === null) {
                        $cells[$lang] = ['id' => 0, 'status' => null, 'draft' => false];
                        $flags['missing'] = true;
                        continue;
                    }
                    $status = TranslationState::status($links[(int) $target['id']] ?? null, $prints[(int) $source['id']] ?? '', $prints[(int) $target['id']] ?? '');
                    $draft = (int) ($target['status'] ?? 0) === 0;
                    $cells[$lang] = ['id' => (int) $target['id'], 'status' => $status, 'draft' => $draft];
                    $flags['draft'] = $flags['draft'] || $draft;
                    $flags['outdated'] = $flags['outdated'] || $status['outdated'];
                    $flags['proposal'] = $flags['proposal'] || $status['proposal'];
                    $flags['untracked'] = $flags['untracked'] || $status['state'] === 'untracked';
                    $flags['ready'] = $flags['ready'] || ((int) ($source['status'] ?? 0) === 1 && self::ready($cells[$lang]));
                }
                unset($source['_sig']);
                $visit($source + ['_title' => (string) ($source[$spec['title']] ?? '')], $cells, $flags);
            }
        }
        $this->savePrints($spec['table']);
    }

    /**
     * 数据库里算「可翻译字段当前内容」校验值的表达式；这张表或这个数据库做不了时返回 null（退回整行扫描）。
     * 覆盖 TranslationState 取指纹时可能读到的所有列：标题列、摘要列、TRACKED 里这张表有的列、决定正文取哪一列的 content_type / blocks_data。
     * @param array{table:string,title:string,summary:string,trash:bool,where:string,pages:bool} $spec
     */
    private function signatureSql(array $spec): ?string
    {
        try {
            $sample = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . $spec['table'] . ' LIMIT 1');
            if ($sample === null) return null;   // 空表：没什么可省的
            $columns = array_values(array_unique(array_filter(
                array_merge([$spec['title'], $spec['summary'], 'content_type', 'blocks_data'], TranslationState::TRACKED),
                static fn (string $column): bool => array_key_exists($column, $sample) && preg_match('/^[a-z_]+$/', $column) === 1
            )));
            $parts = array_map(static fn (string $column): string => "COALESCE({$column}, '')", $columns);
            if ($this->db->isMysql()) return "MD5(CONCAT_WS('|~|', " . implode(', ', $parts) . '))';
            if ($this->db->isSqlite()) {
                $pdo = $this->db->getPdo();
                // SQLite 没有内置 md5：注册一个（连接级，重复注册无害）。PHP 8.5 弃用了 PDO::sqliteCreateFunction()，
                // 新写法只在 Pdo\Sqlite 连接上有；拿不到就退回整行扫描（只少一个提速，结果相同）
                // 不写类名：Psalm 按 PHP 8.2 分析，不认识 8.4 才有的 Pdo\Sqlite（2.3.0 CI 查出）
                $md5 = static fn (mixed $value): string => md5((string) $value);
                $create = [$pdo, 'createFunction'];
                if (is_callable($create)) {
                    if (!call_user_func($create, 'yk_md5', $md5, 1)) return null;
                } elseif (PHP_VERSION_ID < 80500 && method_exists($pdo, 'sqliteCreateFunction')) {
                    if (!$pdo->sqliteCreateFunction('yk_md5', $md5, 1)) return null;
                } else {
                    return null;
                }
                return 'yk_md5(' . implode(" || '|~|' || ", $parts) . ')';
            }
        } catch (Throwable) {
            // 驱动不支持：退回整行扫描
        }
        return null;
    }

    /**
     * 这些行的 rowPrint。校验值与缓存里的一致就沿用；不一致或没有的整行读出重算。
     * @param array{table:string,title:string,summary:string,trash:bool,where:string,pages:bool} $spec
     * @param array<int,string> $need 行 id => 当前内容校验值
     * @return array<int,string> 行 id => rowPrint
     */
    private function printsFor(array $spec, array $need): array
    {
        if ($need === []) return [];
        $name = $spec['table'];
        $this->prints[$name] ??= $this->loadPrints($name);
        $out = [];
        $miss = [];
        foreach ($need as $id => $sig) {
            $hit = $this->prints[$name][$id] ?? null;
            if ($hit !== null && $hit[0] === $sig) $out[$id] = $hit[1];
            else $miss[] = $id;
        }
        foreach (array_chunk($miss, 200) as $ids) {
            $rows = $this->db->fetchAll('SELECT * FROM ' . DB_PREFIX . $name . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $out[$id] = TranslationState::rowPrint($row, $spec['title'], $spec['summary']);
                $this->prints[$name][$id] = [$need[$id], $out[$id]];
                $this->printsDirty[$name] = true;
            }
        }
        return $out;
    }

    private function printsFile(string $table): ?string
    {
        if (!defined('STORAGE_PATH')) return null;
        return STORAGE_PATH . '/cache/translation-prints-' . self::PRINT_CACHE_VERSION . '-' . DB_PREFIX . $table . '.cache';
    }

    /** @return array<int,array{0:string,1:string}> */
    private function loadPrints(string $table): array
    {
        $file = $this->printsFile($table);
        if ($file === null || !is_file($file)) return [];
        $data = @unserialize((string) @file_get_contents($file), ['allowed_classes' => false]);
        return is_array($data) ? $data : [];
    }

    /** 写回缓存文件（先写临时文件再改名，读的一方不会读到半截）。写不了就算了，下次重算 */
    private function savePrints(string $table): void
    {
        if (!isset($this->printsDirty[$table])) return;
        unset($this->printsDirty[$table]);
        $file = $this->printsFile($table);
        if ($file === null) return;
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, serialize($this->prints[$table] ?? []), LOCK_EX) === false || !@rename($temp, $file)) @unlink($temp);
    }

    /**
     * 状态页的一页：各筛选的计数（覆盖全部原文）、符合当前筛选的总数、当前页的行。
     * @param list<string> $langs @param string $filter 'all' 或 self::FLAGS 之一
     * @return array{totals:array<string,int>,matched:int,rows:list<array{id:int,title:string,live:bool,cells:array<string,array{id:int,status:?array<string,mixed>,draft:bool}>}>}
     */
    public function page(string $defaultLang, array $langs, string $kind, string $filter, string $keyword, int $page, int $perPage): array
    {
        $totals = array_fill_keys(['all', ...self::FLAGS], 0);
        $rows = [];
        $matched = 0;
        $this->scan($defaultLang, $langs, static function (array $source, array $cells, array $flags) use (&$rows, &$totals, &$matched, $filter, $page, $perPage): void {
            $flags['all'] = true;
            foreach (array_keys($totals) as $name) if ($flags[$name] ?? false) $totals[$name]++;
            if (!($flags[$filter] ?? false)) return;
            $matched++;
            if ($matched > ($page - 1) * $perPage && $matched <= $page * $perPage) {
                $rows[] = ['id' => (int) $source['id'], 'title' => (string) $source['_title'], 'live' => (int) ($source['status'] ?? 0) === 1, 'cells' => $cells];
            }
        }, $keyword, false, $kind);
        return ['totals' => $totals, 'matched' => $matched, 'rows' => $rows];
    }

    /**
     * 这一格是不是「已审、可以直接发布的草稿」：有人审过，审过之后原文和译文都没改，也没有待审的新译文。
     * 批量发布只收这样的译文（另外还要求原文已发布，由调用方看原文那一行）。
     * @param array{id:int,status:?array<string,mixed>,draft:bool} $cell
     */
    public static function ready(array $cell): bool
    {
        $status = $cell['status'];
        return $cell['id'] > 0 && $cell['draft'] && $status !== null
            && $status['reviewed'] && !$status['outdated'] && !$status['edited'] && !$status['proposal'];
    }

    /**
     * 这一格接下来该做什么（翻译工作中心「下一步」）：self::ACTIONS 之一，没有要做的返回空串。
     * 先把内容弄对再发布：有待审译文先审，原文改过先更新，然后才是发布；已发布但来历不明的提示核对。
     * @param array{id:int,status:?array<string,mixed>,draft:bool} $cell
     */
    public static function nextAction(array $cell): string
    {
        $status = $cell['status'];
        if ($cell['id'] === 0 || $status === null) return 'translate';
        if ($status['proposal']) return 'review';
        if ($status['outdated']) return 'update';
        if ($cell['draft']) return 'publish';
        if ($status['state'] === 'untracked') return 'check';
        return '';
    }

    /**
     * 单页栏目的正文行（指纹要把正文算进去，与编辑页、写库层同一口径）。其它情况返回 null。
     * @param bool $pages 这一类内容有没有单页正文行 @param array<string,mixed> $row @return array<string,mixed>|null
     */
    private function pageBody(bool $pages, array $row): ?array
    {
        if (!$pages || ($row['type'] ?? '') !== 'page') return null;
        return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'contents WHERE channel_id = ? ORDER BY id DESC LIMIT 1', [(int) $row['id']]);
    }

    /**
     * 每种语言还要处理的数量（交付检查用）：缺译文、有译文但未发布、原文改过后过期。
     * 来历不明（untracked）的旧译文不算过期——不知道它对应哪一版原文，只能由人标为已审。
     * @param list<string> $langs @return array<string,array{missing:int,draft:int,outdated:int}>
     */
    public function gapsByLanguage(string $defaultLang, array $langs, string $kind = 'products'): array
    {
        return $this->gaps($defaultLang, $langs, $kind)['gaps'];
    }

    /**
     * 同 gapsByLanguage，另给出第一处缺口所在的原文 id（交付检查的直达链接用，2.3.1；顺序与翻译工作中心相同，新建的在前）；没有缺口时 first = 0。一次扫描算完
     * @param list<string> $langs @return array{gaps:array<string,array{missing:int,draft:int,outdated:int}>,first:int}
     */
    public function gaps(string $defaultLang, array $langs, string $kind = 'products'): array
    {
        $gaps = array_fill_keys($langs, ['missing' => 0, 'draft' => 0, 'outdated' => 0]);
        $first = 0;
        $this->scan($defaultLang, $langs, static function (array $source, array $cells) use (&$gaps, &$first): void {
            foreach ($cells as $lang => $cell) {
                $type = $cell['id'] === 0 ? 'missing' : ($cell['draft'] ? 'draft' : ($cell['status'] !== null && $cell['status']['outdated'] ? 'outdated' : ''));
                if ($type === '') continue;
                $gaps[$lang][$type]++;
                if ($first === 0) $first = (int) ($source['id'] ?? 0);
            }
        }, '', true, $kind);
        return ['gaps' => $gaps, 'first' => $first];
    }
}
