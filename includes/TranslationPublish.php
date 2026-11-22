<?php
/**
 * 批量发布已审的草稿译文（2.3.0 CORE-02 第二部分）。
 *
 * 只发布同时满足这些条件的译文：自己是草稿；有人审过（translation_links.state = reviewed）；
 * 审过之后原文没改、译文没改；没有待审的新译文；原文已发布。其余一律跳过并说明原因，不报错。
 * 每一条都在行锁里按「此刻」的内容重新判断——页面是几分钟前打开的，期间有人改过就不发。
 * 发布走各类内容自己的模型（触发缓存与静态文件失效），和后台列表页的发布是同一条路。
 */

declare(strict_types=1);

require_once __DIR__ . '/TranslationCoverage.php';

final class TranslationPublish
{
    /** 跳过原因 */
    public const SKIP = ['not_found', 'already_published', 'not_reviewed', 'outdated', 'edited', 'proposal', 'source_unpublished'];
    /** 一次最多处理的条数 */
    public const LIMIT = 500;

    /** @var Closure(string, array<string,mixed>, int): void 把一条译文置为已发布（种类、当前行、时间） */
    private Closure $publisher;

    /** @param null|Closure(string, array<string,mixed>, int): void $publisher 测试注入；默认走模型 */
    public function __construct(private Database $db, ?Closure $publisher = null)
    {
        $this->publisher = $publisher ?? self::modelPublisher(...);
    }

    /**
     * @param string $kind TranslationCoverage::KINDS 的键
     * @param list<int> $targetIds 译文行的 id
     * @param list<string> $langs 已启用的目标语言（不含默认语言）
     * @return array{published:list<int>,skipped:array<int,string>}
     */
    public function publishReviewed(string $kind, array $targetIds, string $defaultLang, array $langs, int $now): array
    {
        $spec = TranslationCoverage::KINDS[$kind] ?? throw new InvalidArgumentException('unknown translation coverage kind');
        $targetIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $targetIds), static fn (int $id): bool => $id > 0))), 0, self::LIMIT);
        $published = [];
        $skipped = [];
        foreach ($targetIds as $id) {
            $this->db->beginTransaction();
            try {
                $reason = $this->check($spec, $id, $defaultLang, $langs);
                if ($reason === '') {
                    $row = (array) $this->row($spec, $id);
                    ($this->publisher)($kind, $row, $now);
                }
                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->getPdo()->inTransaction()) $this->db->rollback();
                throw $error;
            }
            if ($reason === '') $published[] = $id;
            else $skipped[$id] = $reason;
        }
        return ['published' => $published, 'skipped' => $skipped];
    }

    /**
     * 锁住这条译文，按此刻的内容判断能不能发布。返回空串 = 可以；否则是 self::SKIP 之一。
     * @param array{table:string,title:string,summary:string,trash:bool,where:string,pages:bool} $spec
     * @param list<string> $langs
     */
    private function check(array $spec, int $id, string $defaultLang, array $langs): string
    {
        if ($this->db->lockRow($spec['table'], $id) === null) return 'not_found';
        $target = $this->row($spec, $id);
        if ($target === null || !in_array((string) $target['lang'], $langs, true)) return 'not_found';
        if ((int) ($target['status'] ?? 0) !== 0) return 'already_published';

        $groupId = (int) $target['translation_group_id'];
        $source = $groupId > 0 ? $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . $spec['table'] . ' WHERE lang = ? AND (id = ? OR translation_group_id = ?) AND id <> ?'
            . ($spec['trash'] ? ' AND deleted_at IS NULL' : '') . ' ORDER BY CASE WHEN id = ? THEN 0 ELSE 1 END, id LIMIT 1', [$defaultLang, $groupId, $groupId, $id, $groupId]) : null;
        if ($source === null) return 'not_found';

        $link = $this->db->tableExists('translation_links') ? (new TranslationState($this->db))->link($spec['table'], $id) : null;
        $status = TranslationState::status($link,
            TranslationState::rowPrint($source, $spec['title'], $spec['summary'], $this->pageBody($spec, $source)),
            TranslationState::rowPrint($target, $spec['title'], $spec['summary'], $this->pageBody($spec, $target)));
        if ($status['proposal']) return 'proposal';
        if (!$status['reviewed']) return 'not_reviewed';
        if ($status['outdated']) return 'outdated';
        if ($status['edited']) return 'edited';
        if ((int) ($source['status'] ?? 0) !== 1) return 'source_unpublished';
        return '';
    }

    /** @param array{table:string,title:string,summary:string,trash:bool,where:string,pages:bool} $spec @return array<string,mixed>|null */
    private function row(array $spec, int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . $spec['table'] . ' WHERE id = ?'
            . ($spec['trash'] ? ' AND deleted_at IS NULL' : '') . ($spec['where'] !== '' ? ' AND ' . $spec['where'] : ''), [$id]);
    }

    /** @param array{table:string,title:string,summary:string,trash:bool,where:string,pages:bool} $spec @param array<string,mixed> $row @return array<string,mixed>|null */
    private function pageBody(array $spec, array $row): ?array
    {
        if (!$spec['pages'] || ($row['type'] ?? '') !== 'page') return null;
        return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'contents WHERE channel_id = ? ORDER BY id DESC LIMIT 1', [(int) $row['id']]);
    }

    /** 默认的发布动作：与后台列表页「发布」同一条路 @param array<string,mixed> $row */
    private static function modelPublisher(string $kind, array $row, int $now): void
    {
        $id = (int) $row['id'];
        if ($kind === 'products') {
            require_once ROOT_PATH . '/includes/ScheduledPublish.php';
            productModel()->updateById($id, ['status' => 1, 'updated_at' => $now]);
            ScheduledPublish::setProductTime($id, 0);   // 已上架，不再定时
        } elseif ($kind === 'contents') {
            $data = ['status' => 1, 'updated_at' => $now];
            // 定时文章被立即发布：未来的发布时间改为现在
            if ((int) ($row['publish_time'] ?? 0) > $now) $data['publish_time'] = $now;
            contentModel()->updateById($id, $data);
        } elseif ($kind === 'pages' || $kind === 'channels') {
            channelModel()->updateById($id, ['status' => 1, 'updated_at' => $now]);
        } else {
            productCategoryModel()->updateById($id, ['status' => 1]);
        }
    }
}
