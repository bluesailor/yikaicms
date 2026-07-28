<?php
/**
 * SEO 工坊 - 全站 SEO 体检 + 批量修复（专业版）
 *
 * 扫描 contents（文章等）与 channels（单页/栏目）的 SEO 字段，逐项判定问题
 * （缺描述 / 描述过短 / 标题过长 / 缺关键词 / SEO 标题重复），供表格内联批量修复。
 * 自包含，无核心改动；写回仍走白名单表 + 参数化。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/** 允许批量改 SEO 的表白名单（都含 seo_title / seo_keywords / seo_description）。 */
function seo_audit_tables(): array
{
    return [
        'contents' => ['type' => '内容', 'title_col' => 'title'],
        'channels' => ['type' => '页面', 'title_col' => 'name'],
    ];
}

/**
 * 单条的问题码列表。effTitle=生效标题(seo_title||title)。
 * @return string[]
 */
function seo_audit_issues(string $seoTitle, string $seoDesc, string $seoKw, string $fallbackTitle): array
{
    $issues = [];
    $effTitle = $seoTitle !== '' ? $seoTitle : $fallbackTitle;
    $dLen = mb_strlen($seoDesc);
    if ($seoDesc === '') {
        $issues[] = 'no_desc';
    } elseif ($dLen < 60) {
        $issues[] = 'desc_short';
    } elseif ($dLen > 160) {
        $issues[] = 'desc_long';
    }
    if (mb_strlen($effTitle) > 60) {
        $issues[] = 'title_long';
    }
    if ($seoKw === '') {
        $issues[] = 'no_kw';
    }
    return $issues;
}

/** 问题码 → 中文标签 + 颜色档。 */
function seo_audit_issue_meta(): array
{
    return [
        'no_desc'    => ['缺 SEO 描述', 'red'],
        'desc_short' => ['描述过短', 'amber'],
        'desc_long'  => ['描述过长', 'amber'],
        'title_long' => ['标题过长', 'amber'],
        'no_kw'      => ['缺关键词', 'gray'],
        'dup_title'  => ['SEO 标题重复', 'red'],
    ];
}

/**
 * 扫描全站，返回有问题的条目 + 汇总统计。
 * @return array{items:array<int,array>,summary:array<string,int>,total:int,healthy:int}
 */
function seo_audit_scan(int $limit = 500): array
{
    $items = [];
    $titleSeen = [];   // 生效 SEO 标题 → 出现次数（查重）

    // contents
    try {
        $rows = db()->fetchAll(
            'SELECT id, title, seo_title, seo_keywords, seo_description FROM ' . DB_PREFIX . 'contents
             WHERE status = 1 ORDER BY id DESC LIMIT ' . (int) $limit
        );
        foreach ($rows as $r) {
            $items[] = _seo_audit_row('contents', (int) $r['id'], (string) $r['title'],
                (string) ($r['seo_title'] ?? ''), (string) ($r['seo_description'] ?? ''), (string) ($r['seo_keywords'] ?? ''), $titleSeen);
        }
    } catch (\Throwable $e) {
    }

    // channels（页面/栏目：排除外链与系统项）
    try {
        $rows = db()->fetchAll(
            "SELECT id, name, seo_title, seo_keywords, seo_description FROM " . DB_PREFIX . "channels
             WHERE type <> 'link' AND status = 1 ORDER BY id DESC LIMIT " . (int) $limit
        );
        foreach ($rows as $r) {
            $items[] = _seo_audit_row('channels', (int) $r['id'], (string) $r['name'],
                (string) ($r['seo_title'] ?? ''), (string) ($r['seo_description'] ?? ''), (string) ($r['seo_keywords'] ?? ''), $titleSeen);
        }
    } catch (\Throwable $e) {
    }

    // 第二遍：标记重复 SEO 标题
    foreach ($items as &$it) {
        $eff = $it['seo_title'] !== '' ? $it['seo_title'] : $it['title'];
        if ($eff !== '' && ($titleSeen[$eff] ?? 0) > 1) {
            $it['issues'][] = 'dup_title';
        }
    }
    unset($it);

    // 汇总 + 仅保留有问题的（healthy 计数）
    $summary = [];
    $healthy = 0;
    $withIssues = [];
    foreach ($items as $it) {
        if (!$it['issues']) {
            $healthy++;
            continue;
        }
        foreach ($it['issues'] as $code) {
            $summary[$code] = ($summary[$code] ?? 0) + 1;
        }
        $withIssues[] = $it;
    }

    return ['items' => $withIssues, 'summary' => $summary, 'total' => count($items), 'healthy' => $healthy];
}

/** @internal 组装单条并累计标题查重。 */
function _seo_audit_row(string $table, int $id, string $title, string $seoTitle, string $seoDesc, string $seoKw, array &$titleSeen): array
{
    $eff = $seoTitle !== '' ? $seoTitle : $title;
    if ($eff !== '') {
        $titleSeen[$eff] = ($titleSeen[$eff] ?? 0) + 1;
    }
    return [
        'table'           => $table,
        'id'              => $id,
        'title'           => $title,
        'seo_title'       => $seoTitle,
        'seo_description' => $seoDesc,
        'seo_keywords'    => $seoKw,
        'issues'          => seo_audit_issues($seoTitle, $seoDesc, $seoKw, $title),
    ];
}

/**
 * 保存单条 SEO 字段（表白名单）。
 * @return array{0:bool,1:string}
 */
function seo_audit_save(string $table, int $id, string $seoTitle, string $seoDesc, string $seoKw): array
{
    if (!isset(seo_audit_tables()[$table])) {
        return [false, '非法的目标表'];
    }
    if ($id <= 0) {
        return [false, '无效的 ID'];
    }
    db()->update($table, [
        'seo_title'       => trim($seoTitle),
        'seo_description' => trim($seoDesc),
        'seo_keywords'    => trim($seoKw),
    ], 'id = ?', [$id]);
    return [true, '已保存'];
}
