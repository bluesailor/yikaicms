<?php
/**
 * 详情模板影响范围预览（第四轮，只读）。
 *
 * 回答"如果现在发布这份草稿，它会影响哪些内容、各自是什么结果"：
 * - 范围：只取草稿纳入规则覆盖的内容（与发布检查同一套粗筛），判定逐条交给同一个 resolver；
 * - 分组：本模板胜出（含输出主题默认）/ 同级并列 / 条件包含但由其它模板胜出 / 被排除规则排除；
 * - 有界：按 ID 游标分页、行数与时间有上限；没检查完的计数只代表已检查部分，不写成总数；
 * - 实例：每组最多几条，按页一次批量取标题与链接，不取正文；没有内容权限就不给预览。
 */

declare(strict_types=1);

final class DetailTemplateImpactPreview
{
    public const SAMPLE_LIMIT = 5;
    public const GROUPS = ['won', 'conflicted', 'lost', 'excluded'];
    private const FETCH_SIZE = 100;

    /**
     * 草稿对单条内容的影响分组（纯函数）。
     *
     * @param string $match DetailTemplateProvider::draftMatchFor() 的结果
     * @param string $verdict DetailTemplateProvider::verdictFor() 的结果
     * @return string 'won' | 'conflicted' | 'lost' | 'excluded' | 'untouched'
     */
    public static function group(string $match, string $verdict): string
    {
        if ($match === 'excluded') {
            return 'excluded';
        }
        if ($match !== 'matched') {
            return 'untouched';
        }
        return match ($verdict) {
            'won', 'native' => 'won',
            'conflicted' => 'conflicted',
            default => 'lost',
        };
    }

    /**
     * 扫描一页。$afterId 之后继续，最多 $rowLimit 行、$seconds 秒。
     *
     * @param array<string,mixed>|null $draftScope 草稿的有效条件
     * @return array<string,mixed>
     */
    public static function scan(string $contentType, int $templateId, ?array $draftScope, int $afterId, int $rowLimit, float $seconds): array
    {
        $published = DetailTemplateProvider::candidates($contentType);
        $candidates = DetailTemplateProvider::injectDraft($published, $contentType, $templateId, $draftScope ?? []);
        $scope = DetailTemplateResolver::normalizeScope($draftScope ?? []);
        $domain = DetailTemplatePublishGuard::domainFor($contentType, [$draftScope]);
        // 客户端带回上一页的指纹：条件、已发布候选或相关内容一变，前面累计的计数就不能再用
        $fingerprint = hash('sha256', json_encode([
            $contentType,
            $templateId,
            DetailTemplatePublishGuard::conditionSignature($draftScope),
            DetailTemplatePublishGuard::candidateMarks($published),
            DetailTemplatePublishGuard::contentStats($contentType, $domain['langs']),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $page = [
            'fingerprint' => $fingerprint,
            'total' => 0,
            'scanned' => 0,
            'next' => max(0, $afterId),
            'complete' => true,
            'counts' => array_fill_keys(self::GROUPS, 0),
            'samples' => array_fill_keys(self::GROUPS, []),
        ];
        $query = DetailTemplatePublishGuard::domainQuery($contentType, $domain);
        if ($query === null) {
            return $page;
        }
        [$from, $where, $params] = $query;
        $page['total'] = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $where, $params);
        $columns = $contentType === 'product' ? 'id, lang, category_id, status' : 'id, lang, channel_id, status, type';

        $deadline = microtime(true) + max(0.1, $seconds);
        $rowLimit = max(1, $rowLimit);
        $exhausted = false;
        $sampleRows = [];
        while (!$exhausted) {
            $rows = db()->fetchAll(
                'SELECT ' . $columns . ' FROM ' . $from . ' WHERE ' . $where . ' AND id > ? ORDER BY id ASC LIMIT ' . self::FETCH_SIZE,
                array_merge($params, [$page['next']])
            );
            if ($rows === []) {
                $exhausted = true;
                break;
            }
            foreach ($rows as $row) {
                $context = DetailTemplateProvider::context($contentType, $row);
                $precondition = DetailTemplateProvider::preconditionFor($scope, $contentType, $context);
                $match = DetailTemplateProvider::draftMatchFor($contentType, $templateId, $draftScope ?? [], $context, $precondition);
                $verdict = DetailTemplateProvider::verdictFor($precondition, $templateId, DetailTemplateResolver::resolve($candidates, $context));
                $group = self::group($match, $verdict);
                if ($group !== 'untouched') {
                    $page['counts'][$group]++;
                    if (count($page['samples'][$group]) < self::SAMPLE_LIMIT) {
                        $page['samples'][$group][] = (int) $row['id'];
                        $sampleRows[(int) $row['id']] = true;
                    }
                }
                $page['next'] = (int) $row['id'];
                $page['scanned']++;
                if ($page['scanned'] >= $rowLimit || microtime(true) >= $deadline) {
                    break 2;
                }
            }
            if (count($rows) < self::FETCH_SIZE) {
                $exhausted = true;
            }
        }
        if (!$exhausted) {
            $more = db()->fetchColumn(
                'SELECT id FROM ' . $from . ' WHERE ' . $where . ' AND id > ? ORDER BY id ASC LIMIT 1',
                array_merge($params, [$page['next']])
            );
            $exhausted = $more === false || $more === null;
        }
        $page['complete'] = $exhausted;

        $details = self::sampleDetails($contentType, array_keys($sampleRows));
        foreach (self::GROUPS as $group) {
            $page['samples'][$group] = array_values(array_filter(array_map(
                static fn (int $id): ?array => $details[$id] ?? null,
                $page['samples'][$group]
            )));
        }

        return $page;
    }

    /**
     * 前台规范链接：沿用站点自己的链接函数，只把语言前缀换成内容自身的语言
     * （后台请求的"当前语言"不是内容语言，直接调用会给非默认语言内容生成错误地址）。
     *
     * @param array<string,mixed> $row 含 id/slug/lang/type 与分类或栏目 slug
     */
    public static function contentLink(string $contentType, array $row): string
    {
        $lang = (string) ($row['lang'] ?? '');
        $url = $contentType === 'product' ? productUrl($row) : contentUrl($row);
        if (isDynamicUrlMode()) {
            return langUrl($url, $lang);
        }
        $current = langPrefix();
        if ($current !== '' && str_starts_with($url, $current . '/')) {
            $url = substr($url, strlen($current));
        }
        return langPrefix($lang) . $url;
    }

    /**
     * 实例的标题与链接：一页一次批量查询，不取正文。
     *
     * @param list<int> $ids
     * @return array<int,array{id:int,title:string,lang:string,published:bool,url:string}>
     */
    private static function sampleDetails(string $contentType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $contentType === 'product'
            ? db()->fetchAll(
                'SELECT p.id, p.title, p.slug, p.lang, p.status, pc.slug AS category_slug FROM ' . DB_PREFIX . 'products p'
                . ' LEFT JOIN ' . DB_PREFIX . 'product_categories pc ON pc.id = p.category_id WHERE p.id IN (' . $in . ')',
                $ids
            )
            : db()->fetchAll(
                'SELECT c.id, c.title, c.slug, c.lang, c.status, c.type, ch.slug AS channel_slug, ch.type AS channel_type FROM ' . DB_PREFIX . 'contents c'
                . ' LEFT JOIN ' . DB_PREFIX . 'channels ch ON ch.id = c.channel_id WHERE c.id IN (' . $in . ')',
                $ids
            );

        $details = [];
        foreach ($rows as $row) {
            $published = (int) ($row['status'] ?? 0) === 1;
            $details[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'title' => (string) ($row['title'] ?? ''),
                'lang' => (string) ($row['lang'] ?? ''),
                'published' => $published,
                // 未发布内容前台不可访问：不给链接，免得点进去是 404
                'url' => $published ? self::contentLink($contentType, $row) : '',
            ];
        }
        return $details;
    }
}
