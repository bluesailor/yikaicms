<?php
/**
 * 下载分类：新增 slug 列（伪静态 URL）+ 回填。
 *
 * 前端下载分类导航从 ?cat=id 升级为伪静态 /download/{slug}.html（与产品/新闻一致）。
 * download_categories 原本没有 slug 列，这里新增；并按名称给现有分类生成 slug。
 * 无 slug 的分类前端会回退到 ?cat=id，不影响使用。
 */

declare(strict_types=1);

return [
    'id'    => '20260722_download_category_slug',
    'title' => '下载分类：新增 slug 列（伪静态）+ 回填',
    'desc'  => '为 yikai_download_categories 新增 slug 列，前端下载分类支持 /download/{slug}.html 伪静态；并按名称回填现有分类的 slug（无 slug 者前端自动回退 ?cat=id）。',
    'check' => function (): bool {
        return _columnExists('download_categories', 'slug');
    },
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "download_categories` ADD COLUMN `slug` varchar(150) DEFAULT ''",
    ],
    'php' => function (): string {
        $rows = db()->fetchAll("SELECT id, name, slug FROM " . DB_PREFIX . "download_categories");
        $n = 0;
        foreach ($rows as $r) {
            if (!empty($r['slug'])) continue;
            $slug = resolveSlug('', (string) $r['name'], 'download_categories', (int) $r['id']);
            db()->execute("UPDATE " . DB_PREFIX . "download_categories SET slug = ? WHERE id = ?", [$slug, (int) $r['id']]);
            $n++;
        }
        return "回填 slug：{$n} 条";
    },
];
