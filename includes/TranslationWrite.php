<?php
/**
 * 后台「AI 翻译 / 重新翻译」的写库一步（translate_action.php 在模型返回之后调用）。
 *
 * 模型请求可能要一两分钟，期间别的管理员可能改了译文、发布或删除了它，也可能改了原文，
 * 或者同时点了一次「创建译文」。这里在写库事务里锁定并重读这些行，与调模型前读到的比对：
 * 有任何变化就整笔不写，返回冲突代码，让调用方把生成结果交还给用户，而不是覆盖较新的内容。
 */

declare(strict_types=1);

final class TranslationWrite
{
    /** 前台访问随时会 +1 的计数列：不算内容改动 */
    private const VOLATILE = ['views', 'likes', 'download_count'];

    public function __construct(private Database $db)
    {
    }

    /** 整行指纹（去掉计数列）。null 表示「当时没有这一行」。 */
    public static function fingerprint(?array $row): string
    {
        if ($row === null) return '';
        $row = array_diff_key($row, array_flip(self::VOLATILE));
        ksort($row);
        $values = array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $row);
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * @param array{table:string,model:Model,lang:string,group_id:int,src:array<string,mixed>,src_page:?array<string,mixed>,target:?array<string,mixed>,target_page:?array<string,mixed>,title_key:string,summary_key:string,body_field:string} $read
     *        调模型之前读到的状态
     * @param array<string,mixed> $translated translateContentFields() 的结果
     * @param array<string,mixed> $newData 目标语言不存在时要新建的整行
     * @return array{id:int,conflict:string} conflict 为空表示已写入
     */
    public function apply(array $read, array $translated, array $newData): array
    {
        $table = $read['table'];
        $model = $read['model'];
        $src = $read['src'];
        $pageContent = $read['src_page'];
        $toLang = $read['lang'];
        $titleKey = $read['title_key'];
        $summKey = $read['summary_key'];
        $bodyField = $read['body_field'];
        $srcId = (int) $src['id'];

        $this->db->beginTransaction();
        try {
            $current = $this->recheck($read);
            if ($current['conflict'] !== '') {
                $this->db->rollback();
                return ['id' => $current['id'], 'conflict' => $current['conflict']];
            }
            // 历史版本存写入前一刻（已锁定）的内容，而不是调模型前读到的
            $target = $current['target'];
            if ($target) {
                $changes = [$titleKey => $translated['title'], $summKey => $translated['summary']];
                if (isset($translated['secondary_summary'])) $changes['summary'] = $translated['secondary_summary'];
                foreach ($translated as $field => $value) {
                    if (!in_array($field, ['title', 'summary', 'content'], true) && array_key_exists($field, $target)) $changes[$field] = $value;
                }
                if (array_key_exists($bodyField, $target)) $changes[$bodyField] = $translated['content'];
                if (array_key_exists('status', $target)) $changes['status'] = 0;
                if (array_key_exists('updated_at', $target)) $changes['updated_at'] = time();
                $revisionType = match ($table) {
                    'contents' => (string) ($src['type'] ?? 'article'),
                    'products' => 'product',
                    'channels' => 'page',
                    'jobs' => 'job',
                    'downloads' => 'download',
                    default => $table,
                };
                $this->recordRevision($revisionType, (int) $target['id'], $toLang, [['table' => $table, 'id' => (int) $target['id'], 'fields' => array_intersect_key($target, $changes)]], (string) ($target[$titleKey] ?? ''));
                $model->updateById((int) $target['id'], $changes);
                $newId = (int) $target['id'];
            } else $newId = (int) $model->create($newData);
            if ($pageContent) {
                $translatedPage = $pageContent;
                unset($translatedPage['id']);
                $translatedPage['channel_id'] = $newId;
                $translatedPage['lang'] = $toLang;
                $translatedPage['translation_group_id'] = (int) ($pageContent['translation_group_id'] ?: $pageContent['id']);
                $translatedPage['title'] = $translated['title'];
                $translatedPage['summary'] = $translated['summary'];
                $translatedPage[$bodyField] = $translated['content'];
                $translatedPage['status'] = 0;
                $translatedPage['slug'] = resolveSlug('', (string) $translated['title'], 'contents');
                $translatedPage['created_at'] = time();
                $translatedPage['updated_at'] = time();
                $existingPage = $current['target_page'];
                if ($existingPage) {
                    $pageChanges = ['title' => $translated['title'], 'summary' => $translated['summary'], $bodyField => $translated['content'], 'status' => 0, 'updated_at' => time()];
                    $this->recordRevision('page', $newId, $toLang, [['table' => 'contents', 'id' => (int) $existingPage['id'], 'fields' => array_intersect_key($existingPage, $pageChanges)]], (string) $existingPage['title']);
                    contentModel()->updateById((int) $existingPage['id'], $pageChanges);
                } else contentModel()->create($translatedPage);
            }
            // 扩展字段值随新语言版本带过去（2.0.4；之后各语言各自维护）
            $extOwner = match ($table) {
                'products' => 'product',
                'contents' => resolveExtFieldOwner((string) ($src['type'] ?? '')),
                'channels' => 'channel',
                'product_categories' => 'product_category',
                default => '',
            };
            if ($extOwner !== '' && !$target) ExtFields::copyValues($extOwner, $srcId, $newId);
            adminLog('translate', 'create', "为 {$table}#{$srcId} 创建 {$toLang} 翻译 → #{$newId}");
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
        return ['id' => $newId, 'conflict' => ''];
    }

    /**
     * 锁定并重读调模型前读过的行。源行先锁：同一组的两次「创建译文」在这里排队，
     * 后到的一次会看到先到的那一行。
     * @return array{conflict:string,id:int,target:?array<string,mixed>,target_page:?array<string,mixed>}
     */
    private function recheck(array $read): array
    {
        $table = $read['table'];
        $before = $read['target'];
        $none = static fn (string $conflict, int $id = 0): array => ['conflict' => $conflict, 'id' => $id, 'target' => null, 'target_page' => null];

        $src = $this->db->lockRow($table, (int) $read['src']['id']);
        if ($src === null || !empty($src['deleted_at']) || self::fingerprint($src) !== self::fingerprint($read['src'])) return $none('source_changed');
        if ($read['src_page'] !== null) {
            $srcPage = $this->db->lockRow('contents', (int) $read['src_page']['id']);
            if ($srcPage === null || self::fingerprint($srcPage) !== self::fingerprint($read['src_page'])) return $none('source_changed');
        }

        if ($before === null) {
            // 回收站里的旧译文不算（重译时它本来就会另建一行）
            $live = array_key_exists('deleted_at', $src) ? ' AND deleted_at IS NULL' : '';
            $existing = $this->db->fetchOne('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE translation_group_id = ? AND lang = ?' . $live . ' LIMIT 1', [$read['group_id'], $read['lang']]);
            return $existing ? $none('target_exists', (int) $existing['id']) : $none('');
        }

        $id = (int) $before['id'];
        $target = $this->db->lockRow($table, $id);
        if ($target === null || !empty($target['deleted_at'])) return $none('target_deleted', $id);
        if (self::fingerprint($target) !== self::fingerprint($before)) {
            $published = (int) ($target['status'] ?? 0) !== 0 && (int) ($target['status'] ?? 0) !== (int) ($before['status'] ?? 0);
            return $none($published ? 'target_published' : 'target_changed', $id);
        }
        $page = null;
        if ($read['src_page'] !== null) {
            $page = $this->db->fetchOne('SELECT id FROM ' . DB_PREFIX . 'contents WHERE channel_id = ? ORDER BY id DESC LIMIT 1', [$id]);
            $page = $page ? $this->db->lockRow('contents', (int) $page['id']) : null;
            if (self::fingerprint($page) !== self::fingerprint($read['target_page'])) return $none('target_changed', $id);
        }
        return ['conflict' => '', 'id' => $id, 'target' => $target, 'target_page' => $page];
    }

    /**
     * 写一版历史。用会抛错的 recordStrict：它在本事务里，失败要整笔回滚，
     * 而不是像 record() 那样吞掉异常、让后面的语句落在事务外。
     * @param list<array{table:string,id:int,fields:array<string,mixed>}> $targets
     */
    private function recordRevision(string $type, int $targetId, string $lang, array $targets, string $summary): void
    {
        contentRevisionModel()->recordStrict($type, $targetId, $lang, $targets, $summary,
            (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_username'] ?? ''));
    }
}
