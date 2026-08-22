<?php
/**
 * SEO 助手 - 内链建议 + 基石内容（专业版）
 *
 * 两件事：
 *   1) 基石内容：标记站点最想让搜索引擎排上去的少数几篇。被标记的内容在内链
 *      建议里优先推荐，形成「其它文章 → 基石内容」的权重汇聚。
 *   2) 内链建议：写作时告诉你「正文里提到了 X，站内正好有一篇讲 X 的，建议加链接」。
 *
 * 为什么用「提及检测」而不是相似度打分：中文没有空格分词，做 TF-IDF / 余弦相似度
 * 要先有分词器（本插件的立身之本是「不依赖外部服务、离线可用」，塞一个词库进来
 * 代价太大）。而且相似度给出的是「这两篇有点像」——用户还得自己想在哪儿加链接；
 * 提及检测给的是「第 3 段这个词可以链过去」，直接可执行。
 *
 * 匹配词表来自候选文章的标题与 SEO 关键词：这些本就是人工挑过的、有意义的词，
 * 比机器切出来的碎片准得多。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/redirects.php';   // 复用 seo_is_pro()

/** 基石内容表（裸名——insert/update/delete/tableExists 会自动加前缀）。 */
function seo_cornerstone_table(): string
{
    return DB_PREFIX . 'seo_cornerstone';
}

/** 惰性建表（幂等，驱动感知）。 */
function seo_cornerstone_ensure_table(): void
{
    $t = seo_cornerstone_table();
    if (db()->isSqlite()) {
        db()->execute('CREATE TABLE IF NOT EXISTS "' . $t . '" (
            "content_id" INTEGER PRIMARY KEY,
            "created_at" INTEGER NOT NULL DEFAULT 0
        )');
        return;
    }
    db()->execute('CREATE TABLE IF NOT EXISTS `' . $t . '` (
        `content_id` int(11) UNSIGNED NOT NULL,
        `created_at` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`content_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * 基石内容 id 集合。
 *
 * @return array<int, int>
 */
function seo_cornerstone_ids(): array
{
    try {
        if (!db()->tableExists('seo_cornerstone')) {
            return [];
        }
        $rows = db()->fetchAll('SELECT content_id FROM ' . seo_cornerstone_table());
        return array_map('intval', array_column($rows, 'content_id'));
    } catch (\Throwable $e) {
        return [];
    }
}

/** 标记 / 取消基石内容。返回标记后的状态。 */
function seo_cornerstone_toggle(int $contentId): bool
{
    seo_cornerstone_ensure_table();
    $exists = db()->fetchOne(
        'SELECT content_id FROM ' . seo_cornerstone_table() . ' WHERE content_id = ?',
        [$contentId]
    );
    if ($exists) {
        db()->delete('seo_cornerstone', 'content_id = ?', [$contentId]);
        return false;
    }
    db()->insert('seo_cornerstone', ['content_id' => $contentId, 'created_at' => time()]);
    return true;
}

/**
 * 某条内容的语言（取不到则回退站点默认语言）。
 *
 * 内链必须同语言：中文文章链到日文页面对读者和搜索引擎都是噪音。
 */
function seo_content_lang(int $contentId): string
{
    if ($contentId > 0) {
        try {
            $row = db()->fetchOne(
                'SELECT lang FROM ' . DB_PREFIX . 'contents WHERE id = ?',
                [$contentId]
            );
            $lang = trim((string) ($row['lang'] ?? ''));
            if ($lang !== '') {
                return $lang;
            }
        } catch (\Throwable $e) {
        }
    }
    return function_exists('siteLang') ? (string) siteLang() : 'zh-CN';
}

/**
 * 内链候选：已发布内容的标题、SEO 关键词与 URL。
 *
 * 只取同语言的内容——多语言站里各语言是独立的内容树，跨语言互链毫无意义
 * （实测未过滤时，中文正文里的「技术支持」会被建议链到日文 FAQ 页）。
 *
 * @return array<int, array{id: int, title: string, url: string, terms: list<string>, cornerstone: bool}>
 */
function seo_link_candidates(int $excludeId = 0, int $limit = 300, ?string $lang = null): array
{
    $lang ??= seo_content_lang($excludeId);
    $cornerstone = array_flip(seo_cornerstone_ids());
    $out = [];
    try {
        $rows = db()->fetchAll(
            // c.type 必须 select，否则 contentUrl() 生成 404 地址（见 lib.php 同处注释）
            'SELECT c.id, c.title, c.slug, c.type, c.channel_id, c.seo_keywords,
                    ch.slug AS channel_slug, ch.type AS channel_type
             FROM ' . DB_PREFIX . 'contents c
             LEFT JOIN ' . DB_PREFIX . 'channels ch ON c.channel_id = ch.id
             WHERE c.status = 1 AND c.deleted_at IS NULL AND c.lang = ?
             ORDER BY c.publish_time DESC, c.id DESC
             LIMIT ' . (int) $limit,
            [$lang]
        );
    } catch (\Throwable $e) {
        return [];
    }

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id === 0 || $id === $excludeId) {
            continue;
        }
        $title = trim((string) ($row['title'] ?? ''));
        if ($title === '') {
            continue;
        }

        // 词表 = 标题 + SEO 关键词（人工挑过的词，比机器切的碎片准）
        $terms = [$title];
        foreach (preg_split('/[,，;；|]/u', (string) ($row['seo_keywords'] ?? '')) ?: [] as $kw) {
            $kw = trim($kw);
            // 一两个字的词满篇都是（「的」「公司」），链上去只会制造噪音
            if ($kw !== '' && mb_strlen($kw) >= 3) {
                $terms[] = $kw;
            }
        }

        try {
            $url = contentUrl($row);
        } catch (\Throwable $e) {
            continue;
        }

        $out[] = [
            'id' => $id,
            'title' => $title,
            'url' => $url,
            'terms' => array_values(array_unique($terms)),
            'cornerstone' => isset($cornerstone[$id]),
        ];
    }
    return $out;
}

/**
 * 在正文里找可加内链的位置。
 *
 * @param string $html 正文 HTML
 * @param int    $excludeId 当前内容 id（不自荐）
 * @return array<int, array{id: int, title: string, url: string, term: string, snippet: string, cornerstone: bool}>
 */
function seo_link_suggestions(string $html, int $excludeId = 0, int $max = 12): array
{
    $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    if ($text === '') {
        return [];
    }

    // 已经链出去的目标不再建议（正文里已有 <a href="…">）
    $linked = [];
    if (preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $href) {
            $linked[rtrim(parse_url($href, PHP_URL_PATH) ?: $href, '/')] = true;
        }
    }

    $suggestions = [];
    foreach (seo_link_candidates($excludeId) as $cand) {
        if (isset($linked[rtrim($cand['url'], '/')])) {
            continue;
        }
        foreach ($cand['terms'] as $term) {
            if (mb_strlen($term) < 3) {
                continue;
            }
            $pos = mb_stripos($text, $term);
            if ($pos === false) {
                continue;
            }
            // 给出上下文，让人一眼看出该在哪句话上加链接
            $start = max(0, $pos - 20);
            $snippet = mb_substr($text, $start, mb_strlen($term) + 50);
            $suggestions[] = [
                'id' => $cand['id'],
                'title' => $cand['title'],
                'url' => $cand['url'],
                'term' => $term,
                'snippet' => ($start > 0 ? '…' : '') . $snippet . '…',
                'cornerstone' => $cand['cornerstone'],
            ];
            break;   // 一个目标给一条就够，不刷屏
        }
    }

    // 基石内容排前面：内链的意义就是把权重汇到它们身上
    usort($suggestions, static function (array $a, array $b): int {
        return ($b['cornerstone'] <=> $a['cornerstone']) ?: strcmp($a['title'], $b['title']);
    });

    return array_slice($suggestions, 0, $max);
}

/**
 * 基石内容列表（带标题与 URL），供后台管理页展示。
 *
 * @return array<int, array<string, mixed>>
 */
function seo_cornerstone_list(): array
{
    $ids = seo_cornerstone_ids();
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    try {
        $rows = db()->fetchAll(
            'SELECT c.id, c.title, c.slug, c.type, c.channel_id,
                    ch.slug AS channel_slug, ch.type AS channel_type
             FROM ' . DB_PREFIX . 'contents c
             LEFT JOIN ' . DB_PREFIX . 'channels ch ON c.channel_id = ch.id
             WHERE c.id IN (' . $in . ')'
        );
    } catch (\Throwable $e) {
        return [];
    }
    $out = [];
    $inbound = seo_inbound_counts(array_map(static fn(array $r): string => contentUrl($r), $rows));
    foreach ($rows as $row) {
        $url = contentUrl($row);
        $row['url'] = $url;
        // 有多少篇文章链向它——基石内容有没有真的被汇聚，看这个数
        $row['inbound'] = $inbound[rtrim($url, '/')] ?? 0;
        $out[] = $row;
    }
    usort($out, static fn(array $a, array $b): int => ($a['inbound'] <=> $b['inbound']));
    return $out;
}

/**
 * 统计每个 URL 被正文链入的次数。
 *
 * 一次扫全站正文而不是逐个 URL 查一遍：基石内容通常只有几篇，但正文可能上千条，
 * 逐个 LIKE 会把库打穿。这里只取 content 列做内存匹配，够用且可控。
 *
 * @param array<int, string> $urls
 * @return array<string, int> 归一化后的 URL → 链入次数
 */
function seo_inbound_counts(array $urls): array
{
    $counts = [];
    foreach ($urls as $u) {
        $counts[rtrim($u, '/')] = 0;
    }
    if (!$counts) {
        return [];
    }
    try {
        $rows = db()->fetchAll(
            'SELECT content FROM ' . DB_PREFIX . 'contents
             WHERE status = 1 AND deleted_at IS NULL LIMIT 2000'
        );
    } catch (\Throwable $e) {
        return $counts;
    }
    foreach ($rows as $row) {
        $body = (string) ($row['content'] ?? '');
        if ($body === '') {
            continue;
        }
        foreach ($counts as $url => $_) {
            // href="…" 里出现即算一次链入；用 strpos 而非正则，几千次调用差别明显
            if ($url !== '' && strpos($body, $url) !== false) {
                $counts[$url]++;
            }
        }
    }
    return $counts;
}
