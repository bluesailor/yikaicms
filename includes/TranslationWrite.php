<?php
/**
 * 后台「AI 翻译 / 重新翻译」的写库一步（translate_action.php 在模型返回之后调用）。
 *
 * 模型请求可能要一两分钟，期间别的管理员可能改了译文、发布或删除了它，也可能改了原文，
 * 或者同时点了一次「创建译文」。这里在写库事务里锁定并重读这些行，与调模型前读到的比对：
 * 有任何变化就整笔不写，返回冲突代码，让调用方把生成结果交还给用户，而不是覆盖较新的内容。
 */

declare(strict_types=1);

require_once __DIR__ . '/TranslationState.php';

final class TranslationWrite
{
    /** 前台访问随时会 +1 的计数列：不算内容改动 */
    private const VOLATILE = ['views', 'likes', 'download_count'];

    public function __construct(private Database $db)
    {
    }

    /**
     * 交给模型的字段：标题、摘要按各表的列名取，正文取正文列（单页取栏目下的正文行）。
     * @param array<string,mixed> $src @param array<string,mixed> $bodySource @return array<string,mixed>
     */
    public static function source(array $src, string $titleKey, string $summKey, array $bodySource, string $bodyField): array
    {
        $source = $src;
        $source['title'] = (string) ($src[$titleKey] ?? '');
        $source['summary'] = (string) ($src[$summKey] ?? '');
        if ($summKey !== 'summary' && array_key_exists('summary', $src)) $source['secondary_summary'] = (string) $src['summary'];
        $source['content'] = (string) ($bodySource[$bodyField] ?? '');
        return $source;
    }

    /**
     * 调模型翻译一条记录。整条有时间预算：单次服务商请求最长 60 秒，预算不够就不再发下一次。
     * @param array<string,mixed> $source self::source() 的结果 @return array<string,mixed>
     */
    public static function generate(array $source, string $toLang, float $budgetSeconds = 120): array
    {
        require_once ROOT_PATH . '/includes/AiService.php';
        require_once ROOT_PATH . '/includes/content_translation.php';
        if (function_exists('set_time_limit')) @set_time_limit((int) $budgetSeconds + 60);
        $deadline = microtime(true) + $budgetSeconds;
        $model = trim((string) config('ai_translation_model', ''));
        $ai = $model !== '' ? new AiService(null, null, $model) : aiService();
        $chat = static function (string $prompt, string $system = '', float $temperature = 0.7, bool $jsonObject = false) use ($deadline, $ai): array {
            if (microtime(true) + 61 >= $deadline) throw new RuntimeException('Translation time budget exceeded. The existing draft has not been changed.');
            return $ai->chat($prompt, $system, $temperature, $jsonObject);
        };
        return translateContentFields($source, $toLang, $chat, true);
    }

    /**
     * 目标语言还没有时要新建的整行：拷贝源行，换上译文。
     * @param array<string,mixed> $src @param array<string,mixed> $translated @return array<string,mixed>
     */
    public static function newRow(string $table, array $src, array $translated, string $toLang, int $groupId, string $titleKey, string $summKey, string $bodyField): array
    {
        $newData = $src;
        unset($newData['id']);
        $newData['lang'] = $toLang;
        $newData['translation_group_id'] = $groupId;
        $newData[$titleKey] = $translated['title'];
        $newData[$summKey] = $translated['summary'];
        if (isset($translated['secondary_summary'])) $newData['summary'] = $translated['secondary_summary'];
        foreach ($translated as $field => $value) {
            if ($field !== 'title' && $field !== 'summary' && $field !== 'content' && array_key_exists($field, $src)) $newData[$field] = $value;
        }
        if (array_key_exists($bodyField, $src)) $newData[$bodyField] = $translated['content'];
        $newData['created_at'] = time();
        if (array_key_exists('updated_at', $src)) $newData['updated_at'] = time();

        // 机器翻译先落草稿：整行拷贝会连 status 一起继承，源是已发布时译文就直接上线了，
        // 没有任何人看过那段 AI 译文。发布必须是明确的下一步动作（复审 R01）。
        if (array_key_exists('status', $newData)) {
            $newData['status'] = 0;
        }

        // slug 加语言后缀避免 uk_slug 冲突（仅当源有 slug 字段且非空）
        if (!empty($newData['slug'])) {
            $newData['slug'] = $newData['slug'] . '-' . $toLang;
            // 防御：万一 slug-{lang} 已存在（如先前手工建过），加随机后缀
            if (db()->fetchOne('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE slug = ?', [$newData['slug']])) {
                $newData['slug'] .= '-' . substr(md5((string) microtime(true)), 0, 6);
            }
        }

        // 内容/产品类有 channel_id —— 重映射到目标语言频道（如已存在）
        if (isset($newData['channel_id']) && (int) $newData['channel_id'] > 0) {
            $mapped = findTranslatedChannelId((int) $newData['channel_id'], $toLang);
            if ($mapped > 0) $newData['channel_id'] = $mapped;
        }
        if ($table === 'channels' && (int) ($newData['parent_id'] ?? 0) > 0) {
            $mappedParent = findTranslatedChannelId((int) $newData['parent_id'], $toLang);
            if ($mappedParent > 0) $newData['parent_id'] = $mappedParent;
        }

        // SEO 字段同步翻译（如存在）
        if (!isset($translated['seo_title']) && array_key_exists('seo_title', $newData) && $newData['seo_title']) {
            $newData['seo_title'] = $translated['title'];
        }
        if (!isset($translated['seo_description']) && array_key_exists('seo_description', $newData) && $newData['seo_description']) {
            $newData['seo_description'] = $translated['summary'];
        }
        return $newData;
    }

    /**
     * 译文字段 → 目标行的列：标题、摘要按各表的列名，正文写正文列，其余同名列照写。
     * @param array<string,mixed> $translated @param array<string,mixed> $target @return array<string,mixed>
     */
    public static function changes(array $translated, array $target, string $titleKey, string $summKey, string $bodyField): array
    {
        $changes = [];
        if (array_key_exists('title', $translated)) $changes[$titleKey] = $translated['title'];
        if (array_key_exists('summary', $translated)) $changes[$summKey] = $translated['summary'];
        if (isset($translated['secondary_summary'])) $changes['summary'] = $translated['secondary_summary'];
        foreach ($translated as $field => $value) {
            if (!in_array($field, ['title', 'summary', 'content', 'secondary_summary'], true) && array_key_exists($field, $target)) $changes[$field] = $value;
        }
        if (array_key_exists('content', $translated) && array_key_exists($bodyField, $target)) $changes[$bodyField] = $translated['content'];
        return $changes;
    }

    /**
     * 受保护的译文：新译文只存为待审结果，不动内容（T04）。同样先锁行重读，期间有变化照样报冲突。
     * @param array<string,mixed> $read 同 apply() @param array<string,mixed> $translated
     * @return array{id:int,conflict:string}
     */
    public function propose(array $read, array $translated): array
    {
        $this->db->beginTransaction();
        try {
            $current = $this->recheck($read);
            $target = $current['target'];
            if ($current['conflict'] !== '' || $target === null) {
                $this->db->rollback();
                return ['id' => $current['id'], 'conflict' => $current['conflict'] !== '' ? $current['conflict'] : 'target_deleted'];
            }
            $id = (int) $target['id'];
            (new TranslationState($this->db))->propose($read['table'], $id, (int) $read['src']['id'], $read['lang'], $translated, self::sourceFingerprint($read),
                TranslationState::rowFingerprint($target, $read['title_key'], $read['summary_key'], $current['target_page']), time());
            adminLog('translate', 'propose', "为 {$read['table']}#{$id} 生成待审 {$read['lang']} 译文");
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
        return ['id' => $id, 'conflict' => ''];
    }

    /**
     * 逐字段采用待审结果。待审生成以来译文被改过就整笔不写，请人重新生成。
     * 不改发布状态：这是人看过之后的决定。写完链接记为已审，对应生成待审结果时的原文。
     * @param array{table:string,model:Model,target_id:int,title_key:string,summary_key:string,has_page:bool} $ctx
     * @param list<string> $fields 采用的字段（source() 形状里的键）
     * @return string 冲突代码；空串表示已写入
     */
    public function accept(array $ctx, array $fields, int $adminId): string
    {
        require_once ROOT_PATH . '/includes/content_translation.php';
        $table = $ctx['table'];
        $id = $ctx['target_id'];
        $titleKey = $ctx['title_key'];
        $summKey = $ctx['summary_key'];
        $states = new TranslationState($this->db);
        $this->db->beginTransaction();
        try {
            $target = $this->db->lockRow($table, $id);
            if ($target === null || !empty($target['deleted_at'])) return $this->abort('target_deleted');
            $page = $ctx['has_page'] ? $this->pageRow($id, true) : null;
            $link = $states->link($table, $id);
            $proposal = json_decode((string) ($link['proposal_json'] ?? ''), true);
            if ($link === null || !is_array($proposal)) return $this->abort('no_proposal');
            if (TranslationState::rowFingerprint($target, $titleKey, $summKey, $page) !== (string) $link['proposal_target_fp']) return $this->abort('target_changed');
            $chosen = array_intersect_key($proposal, array_flip(array_intersect($fields, TranslationState::TRACKED)));
            if ($chosen === []) return $this->abort('nothing_selected');

            $bodyField = translationBodyField($page ?? $target);
            $changes = self::changes($page !== null ? array_diff_key($chosen, ['content' => true]) : $chosen, $target, $titleKey, $summKey, $bodyField);
            if ($changes !== [] && array_key_exists('updated_at', $target)) $changes['updated_at'] = time();
            if ($changes !== []) {
                $this->recordRevision(self::revisionType($table, $target), $id, (string) ($target['lang'] ?? ''),
                    [['table' => $table, 'id' => $id, 'fields' => array_intersect_key($target, $changes)]], (string) ($target[$titleKey] ?? ''));
                $ctx['model']->updateById($id, $changes);
            }
            if ($page !== null) {
                $pageChanges = array_filter(['title' => $chosen['title'] ?? null, 'summary' => $chosen['summary'] ?? null, $bodyField => $chosen['content'] ?? null],
                    static fn (mixed $value): bool => $value !== null);
                if ($pageChanges !== []) {
                    $this->recordRevision('page', $id, (string) ($page['lang'] ?? ''), [['table' => 'contents', 'id' => (int) $page['id'], 'fields' => array_intersect_key($page, $pageChanges)]], (string) $page['title']);
                    contentModel()->updateById((int) $page['id'], $pageChanges + ['updated_at' => time()]);
                }
            }
            $after = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE id = ?', [$id]) ?? $target;
            $states->markReviewed($table, $id, (int) $link['source_id'], (string) $link['lang'], (string) $link['proposal_source_fp'],
                TranslationState::rowFingerprint($after, $titleKey, $summKey, $page !== null ? $this->pageRow($id, false) : null), $adminId, time());
            $states->discardProposal($table, $id, time());
            adminLog('translate', 'accept', "{$table}#{$id} 采用待审译文字段：" . implode(',', array_keys($chosen)));
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
        return '';
    }

    /** @param array<string,mixed> $read 同 apply()：源（单页含正文行）的可翻译字段指纹 */
    public static function sourceFingerprint(array $read): string
    {
        return TranslationState::rowFingerprint($read['src'], $read['title_key'], $read['summary_key'], $read['src_page']);
    }

    /** @param array<string,mixed> $read */
    private function targetFingerprint(array $read, int $id): string
    {
        $row = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . $read['table'] . ' WHERE id = ?', [$id]) ?? [];
        return TranslationState::rowFingerprint($row, $read['title_key'], $read['summary_key'], $read['src_page'] !== null ? $this->pageRow($id, false) : null);
    }

    /** @return array<string,mixed>|null 单页栏目下的正文行 */
    private function pageRow(int $channelId, bool $lock): ?array
    {
        $page = $this->db->fetchOne('SELECT id FROM ' . DB_PREFIX . 'contents WHERE channel_id = ? ORDER BY id DESC LIMIT 1', [$channelId]);
        if (!$page) return null;
        return $lock ? $this->db->lockRow('contents', (int) $page['id']) : $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'contents WHERE id = ?', [(int) $page['id']]);
    }

    private function abort(string $conflict): string
    {
        $this->db->rollback();
        return $conflict;
    }

    /** @param array<string,mixed> $row */
    private static function revisionType(string $table, array $row): string
    {
        return match ($table) {
            'contents' => (string) ($row['type'] ?? 'article'),
            'products' => 'product',
            'channels' => 'page',
            'jobs' => 'job',
            'downloads' => 'download',
            default => $table,
        };
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
     * @param bool $draftOnly 已发布的译文也不覆盖（批量更新用：不把在线内容改回草稿）
     * @return array{id:int,conflict:string} conflict 为空表示已写入；target_protected 表示应转为待审结果
     */
    public function apply(array $read, array $translated, array $newData, bool $draftOnly = false): array
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
                // 人改过、审过或来历不明的译文不整份覆盖（T04）：交给调用方转成待审结果
                $state = TranslationState::status((new TranslationState($this->db))->link($table, (int) $target['id']),
                    self::sourceFingerprint($read), TranslationState::rowFingerprint($target, $titleKey, $summKey, $current['target_page']));
                if ($state['protected'] || ($draftOnly && (int) ($target['status'] ?? 0) !== 0)) {
                    $this->db->rollback();
                    return ['id' => (int) $target['id'], 'conflict' => 'target_protected'];
                }
                $changes = self::changes($translated, $target, $titleKey, $summKey, $bodyField);
                if (array_key_exists('status', $target)) $changes['status'] = 0;
                if (array_key_exists('updated_at', $target)) $changes['updated_at'] = time();
                $this->recordRevision(self::revisionType($table, $src),(int) $target['id'], $toLang, [['table' => $table, 'id' => (int) $target['id'], 'fields' => array_intersect_key($target, $changes)]], (string) ($target[$titleKey] ?? ''));
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
            (new TranslationState($this->db))->recordMachine($table, $newId, $srcId, $toLang, self::sourceFingerprint($read), $this->targetFingerprint($read, $newId), time());
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
