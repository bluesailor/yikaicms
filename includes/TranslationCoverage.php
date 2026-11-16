<?php
/**
 * 译文覆盖情况：逐块读默认语言的内容与各语言译文，按 TranslationState 的状态给每一格定性。
 * 「译文状态」页与交付检查清单共用这一份判断（路线图 CORE-02），不另起一套状态。
 *
 * 三类内容（KINDS）：产品；内容（文章、案例等，不含单页的正文行）；栏目（含单页，单页的正文在栏目下的正文行里）。
 * 一格（$cells[lang]）：['id' => 译文 id（0=缺失）, 'status' => TranslationState::status() 或 null, 'draft' => 未发布]
 * 一行的标记（$flags）：missing / draft / outdated / proposal / untracked，只要有一种语言符合就为 true。
 */

declare(strict_types=1);

require_once __DIR__ . '/TranslationState.php';

final class TranslationCoverage
{
    public const FLAGS = ['missing', 'draft', 'outdated', 'proposal', 'untracked'];
    /** 表、标题列、摘要列、有没有回收站、额外条件、是否有单页正文行 */
    public const KINDS = [
        'products' => ['table' => 'products', 'title' => 'title', 'summary' => 'summary', 'trash' => true, 'where' => '', 'pages' => false],
        'contents' => ['table' => 'contents', 'title' => 'title', 'summary' => 'summary', 'trash' => true, 'where' => "type <> 'page'", 'pages' => false],
        'channels' => ['table' => 'channels', 'title' => 'name', 'summary' => 'description', 'trash' => false, 'where' => '', 'pages' => true],
    ];
    private const CHUNK = 100;

    public function __construct(private Database $db)
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
        for ($offset = 0; ; $offset += self::CHUNK) {
            $sources = $this->db->fetchAll("SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT " . self::CHUNK . " OFFSET {$offset}", $params);
            if ($sources === []) break;
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
                }
                $visit($source, $cells, $flags);
            }
        }
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
        $gaps = array_fill_keys($langs, ['missing' => 0, 'draft' => 0, 'outdated' => 0]);
        $this->scan($defaultLang, $langs, static function (array $source, array $cells) use (&$gaps): void {
            foreach ($cells as $lang => $cell) {
                if ($cell['id'] === 0) $gaps[$lang]['missing']++;
                elseif ($cell['draft']) $gaps[$lang]['draft']++;
                elseif ($cell['status'] !== null && $cell['status']['outdated']) $gaps[$lang]['outdated']++;
            }
        }, '', true, $kind);
        return $gaps;
    }
}
