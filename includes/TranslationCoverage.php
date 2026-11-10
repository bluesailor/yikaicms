<?php
/**
 * 产品译文覆盖情况：逐块读默认语言的产品与各语言译文，按 TranslationState 的状态给每一格定性。
 * 「译文状态」页与交付检查清单共用这一份判断（路线图 CORE-02），不另起一套状态。
 *
 * 一格（$cells[lang]）：['id' => 译文 id（0=缺失）, 'status' => TranslationState::status() 或 null, 'draft' => 未发布]
 * 一行的标记（$flags）：missing / draft / outdated / proposal / untracked，只要有一种语言符合就为 true。
 */

declare(strict_types=1);

require_once __DIR__ . '/TranslationState.php';

final class TranslationCoverage
{
    public const FLAGS = ['missing', 'draft', 'outdated', 'proposal', 'untracked'];
    private const CHUNK = 100;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param list<string> $langs 目标语言（不含默认语言）
     * @param bool $publishedOnly 只看已发布的源产品（交付检查：没上线的产品不需要译文）
     * @param Closure(array<string,mixed>, array<string,array{id:int,status:?array<string,mixed>,draft:bool}>, array<string,bool>):void $visit
     */
    public function scan(string $defaultLang, array $langs, Closure $visit, string $keyword = '', bool $publishedOnly = false): void
    {
        if ($langs === []) return;
        $p = DB_PREFIX;
        $where = 'lang = ? AND deleted_at IS NULL' . ($publishedOnly ? ' AND status = 1' : '');
        $params = [$defaultLang];
        if ($keyword !== '') {
            $where .= ' AND title LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        $states = new TranslationState($this->db);
        // 程序已覆盖、数据库还没升级时没有来源追踪表：照常算缺失与未发布，全部按未追踪处理
        $tracked = $this->db->tableExists('translation_links');
        for ($offset = 0; ; $offset += self::CHUNK) {
            $sources = $this->db->fetchAll("SELECT * FROM {$p}products WHERE {$where} ORDER BY id DESC LIMIT " . self::CHUNK . " OFFSET {$offset}", $params);
            if ($sources === []) break;
            $groups = [];
            foreach ($sources as $source) $groups[(int) ($source['translation_group_id'] ?: $source['id'])] = (int) $source['id'];
            $targets = $this->db->fetchAll("SELECT * FROM {$p}products WHERE deleted_at IS NULL AND translation_group_id IN (" . implode(',', array_fill(0, count($groups), '?')) . ')'
                . ' AND lang IN (' . implode(',', array_fill(0, count($langs), '?')) . ')', array_merge(array_keys($groups), $langs));
            $links = $tracked ? $states->links('products', array_map(static fn (array $row): int => (int) $row['id'], $targets)) : [];
            $byGroup = [];
            foreach ($targets as $target) {
                $groupId = (int) $target['translation_group_id'];
                if (($groups[$groupId] ?? 0) !== (int) $target['id']) $byGroup[$groupId][(string) $target['lang']] ??= $target;
            }
            foreach ($sources as $source) {
                $groupId = (int) ($source['translation_group_id'] ?: $source['id']);
                $sourceFp = TranslationState::rowPrint($source, 'title', 'summary');
                $cells = [];
                $flags = array_fill_keys(self::FLAGS, false);
                foreach ($langs as $lang) {
                    $target = $byGroup[$groupId][$lang] ?? null;
                    if ($target === null) {
                        $cells[$lang] = ['id' => 0, 'status' => null, 'draft' => false];
                        $flags['missing'] = true;
                        continue;
                    }
                    $status = TranslationState::status($links[(int) $target['id']] ?? null, $sourceFp, TranslationState::rowPrint($target, 'title', 'summary'));
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
     * 每种语言还要处理的数量（交付检查用）：缺译文、有译文但未发布、原文改过后过期。
     * 来历不明（untracked）的旧译文不算过期——不知道它对应哪一版原文，只能由人标为已审。
     * @param list<string> $langs @return array<string,array{missing:int,draft:int,outdated:int}>
     */
    public function gapsByLanguage(string $defaultLang, array $langs): array
    {
        $gaps = array_fill_keys($langs, ['missing' => 0, 'draft' => 0, 'outdated' => 0]);
        $this->scan($defaultLang, $langs, static function (array $source, array $cells) use (&$gaps): void {
            foreach ($cells as $lang => $cell) {
                if ($cell['id'] === 0) $gaps[$lang]['missing']++;
                elseif ($cell['draft']) $gaps[$lang]['draft']++;
                elseif ($cell['status'] !== null && $cell['status']['outdated']) $gaps[$lang]['outdated']++;
            }
        }, '', true);
        return $gaps;
    }
}
