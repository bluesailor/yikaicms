<?php
/**
 * Blox 头尾模板激活裁决：条件 → 特异性评分 → 取最大。
 *
 * 条件 JSON（blox_templates.conditions 列）：
 *   [ {"main":"any|home|channel|page", "ids":[int...], "exclude":bool}, ... ]
 *
 * 评分（借鉴 Bricks 的特异性模型，用户永远不用手排优先级——更具体的赢）：
 *   0  无条件（兜底默认，仅头尾类型享受）
 *   2  全站 any
 *   8  栏目 channel（ids 空 = 所有栏目页）
 *   9  首页 home
 *   10 单页 page（ids 必填，空 ids 不命中）
 *
 * exclude=true 的条件命中即整个模板出局（一票否决，先于正向评分）。
 * 平票裁决：id 大者（后创建者）赢，保证确定性。
 * 纯函数、无 IO；malformed JSON / 未知 main 静默忽略该条条件。
 */

declare(strict_types=1);

final class BloxAreaResolver
{
    public const SCORE_DEFAULT = 0;
    public const SCORE_ANY = 2;
    public const SCORE_CHANNEL = 8;
    public const SCORE_HOME = 9;
    public const SCORE_PAGE = 10;

    /**
     * 从候选模板行中裁决当前上下文的激活模板。
     *
     * @param array<int,array<string,mixed>> $templates 已发布的同类型模板行（含 id / conditions）
     * @param array{home?:bool,channel_id?:int,page_id?:int} $context
     * @return array<string,mixed>|null 胜出的模板行
     */
    public static function resolve(array $templates, array $context): ?array
    {
        $best = null;
        $bestScore = -1;
        foreach ($templates as $row) {
            if (!is_array($row)) {
                continue;
            }
            $score = self::score(self::parse($row['conditions'] ?? null), $context);
            if ($score === null) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $bestId = (int) ($best['id'] ?? 0);
            if ($score > $bestScore || ($score === $bestScore && $id > $bestId)) {
                $best = $row;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /**
     * 单模板评分。null = 不适用（被 exclude 否决，或有条件但无一命中）。
     *
     * @param array<int,array{main:string,ids:array<int,int>,exclude:bool}> $conditions
     * @param array{home?:bool,channel_id?:int,page_id?:int} $context
     */
    public static function score(array $conditions, array $context): ?int
    {
        if ($conditions === []) {
            return self::SCORE_DEFAULT;
        }

        $isHome = !empty($context['home']);
        $channelId = (int) ($context['channel_id'] ?? 0);
        $pageId = (int) ($context['page_id'] ?? 0);

        $max = null;
        foreach ($conditions as $condition) {
            $hit = match ($condition['main']) {
                'any' => self::SCORE_ANY,
                'home' => $isHome ? self::SCORE_HOME : null,
                'channel' => $channelId > 0
                    && ($condition['ids'] === [] || in_array($channelId, $condition['ids'], true))
                    ? self::SCORE_CHANNEL : null,
                'page' => $pageId > 0
                    && $condition['ids'] !== [] && in_array($pageId, $condition['ids'], true)
                    ? self::SCORE_PAGE : null,
                default => null,
            };
            if ($hit === null) {
                continue;
            }
            if ($condition['exclude']) {
                return null; // 排除条件命中：一票否决
            }
            if ($max === null || $hit > $max) {
                $max = $hit;
            }
        }
        return $max;
    }

    /**
     * 解析并净化 conditions JSON。坏数据静默丢弃（单条粒度），不让一条脏数据拖垮整站头尾。
     *
     * @return array<int,array{main:string,ids:array<int,int>,exclude:bool}>
     */
    public static function parse(mixed $raw): array
    {
        if (is_string($raw) && trim($raw) !== '') {
            try {
                $raw = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $main = trim((string) ($item['main'] ?? ''));
            if (!in_array($main, ['any', 'home', 'channel', 'page'], true)) {
                continue;
            }
            $ids = [];
            foreach (is_array($item['ids'] ?? null) ? $item['ids'] : [] as $id) {
                $id = (int) $id;
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            $out[] = ['main' => $main, 'ids' => $ids, 'exclude' => !empty($item['exclude'])];
        }
        return $out;
    }
}
