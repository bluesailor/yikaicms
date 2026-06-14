<?php
/**
 * 命令：import
 *   从 CSV 批量导入产品或文章（按 slug 幂等）。
 *
 *   用法：
 *     bin/yikai import products <file.csv> [--update] [--dry-run]
 *     bin/yikai import articles <file.csv> [--update] [--dry-run]
 *
 *   选项：
 *     --update    slug 已存在时更新（默认跳过）
 *     --dry-run   只解析与校验、不写库
 *
 *   CSV 第一行为表头（列名大小写不敏感）：
 *     产品(products)：title, slug, category, cover, summary, content,
 *                     price, model, status, sort_order
 *                     （category 填产品分类的 slug；status 默认 1=上架）
 *     文章(articles)：title, slug, channel, cover, summary, content,
 *                     status, publish_time
 *                     （channel 填所属栏目 slug；publish_time 支持
 *                      "2024-01-02" 或 Unix 时间戳；status 默认 1=发布）
 *
 *   说明：title 必填；slug 留空则按 title 生成（含中文则退化为 type-哈希）。
 *         category/channel 按 slug 查不到时记 0 并告警，仍会导入。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

CLI::register('import', '从 CSV 批量导入产品/文章（按 slug 幂等）', function (array $args, array $opts): int {
    $type = $args[0] ?? '';
    $file = $args[1] ?? '';

    if (!in_array($type, ['products', 'articles'], true) || $file === '') {
        CLI::err('用法: bin/yikai import <products|articles> <file.csv> [--update] [--dry-run]');
        return 1;
    }
    if (!is_file($file)) {
        CLI::err("文件不存在: {$file}");
        return 1;
    }

    $update = !empty($opts['update']);
    $dryRun = !empty($opts['dry-run']);
    $lang   = function_exists('siteLang') ? siteLang() : 'zh-CN';
    $p      = DB_PREFIX;

    // 读 CSV
    $fh = fopen($file, 'r');
    if (!$fh) {
        CLI::err("无法打开文件: {$file}");
        return 1;
    }
    $header = fgetcsv($fh);
    if (!$header) {
        CLI::err('CSV 为空或无表头');
        fclose($fh);
        return 1;
    }
    $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);

    // slug 生成（优先用站点的 slug 函数，含中文则退化）
    $mkSlug = function (string $title, string $type): string {
        if (function_exists('generateSlug')) {
            $s = (string) generateSlug($title);
        } elseif (function_exists('slugify')) {
            $s = (string) slugify($title);
        } else {
            $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
        }
        return $s !== '' ? $s : $type . '-' . substr(md5($title), 0, 8);
    };

    // 解析时间
    $parseTime = function ($v): int {
        $v = trim((string) $v);
        if ($v === '') return time();
        if (ctype_digit($v)) return (int) $v;
        $t = strtotime($v);
        return $t ?: time();
    };

    $catCache = [];
    $resolveId = function (string $table, string $slug) use (&$catCache, $p): int {
        $slug = trim($slug);
        if ($slug === '') return 0;
        $ck = $table . ':' . $slug;
        if (isset($catCache[$ck])) return $catCache[$ck];
        $row = db()->fetchOne("SELECT id FROM {$p}{$table} WHERE slug = ? LIMIT 1", [$slug]);
        return $catCache[$ck] = $row ? (int) $row['id'] : 0;
    };

    $created = 0; $updated = 0; $skipped = 0; $warned = 0; $line = 1;

    while (($cols = fgetcsv($fh)) !== false) {
        $line++;
        if (count(array_filter($cols, fn($c) => trim((string) $c) !== '')) === 0) continue; // 空行
        $row = [];
        foreach ($header as $i => $key) {
            $row[$key] = isset($cols[$i]) ? (string) $cols[$i] : '';
        }
        $title = trim($row['title'] ?? '');
        if ($title === '') {
            CLI::out("  第{$line}行: title 为空，跳过");
            $skipped++;
            continue;
        }
        $slug = trim($row['slug'] ?? '') ?: $mkSlug($title, $type === 'products' ? 'p' : 'a');

        if ($type === 'products') {
            $existTable = 'products';
            $catId = $resolveId('product_categories', $row['category'] ?? '');
            if ($catId === 0 && trim($row['category'] ?? '') !== '') { CLI::out("  第{$line}行: 找不到产品分类 '{$row['category']}'，category_id=0"); $warned++; }
            $data = [
                'category_id' => $catId,
                'title'       => $title,
                'slug'        => $slug,
                'lang'        => $lang,
                'cover'       => trim($row['cover'] ?? ''),
                'summary'     => $row['summary'] ?? '',
                'content'     => $row['content'] ?? '',
                'price'       => (float) ($row['price'] ?? 0),
                'model'       => trim($row['model'] ?? ''),
                'status'      => isset($row['status']) && $row['status'] !== '' ? (int) $row['status'] : 1,
                'sort_order'  => (int) ($row['sort_order'] ?? 0),
                'updated_at'  => time(),
            ];
            $model = productModel();
        } else {
            $existTable = 'contents';
            $chId = $resolveId('channels', $row['channel'] ?? '');
            if ($chId === 0 && trim($row['channel'] ?? '') !== '') { CLI::out("  第{$line}行: 找不到栏目 '{$row['channel']}'，channel_id=0"); $warned++; }
            $ts = $parseTime($row['publish_time'] ?? '');
            $data = [
                'channel_id'   => $chId,
                'type'         => 'article',
                'title'        => $title,
                'slug'         => $slug,
                'lang'         => $lang,
                'cover'        => trim($row['cover'] ?? ''),
                'summary'      => $row['summary'] ?? '',
                'content'      => $row['content'] ?? '',
                'status'       => isset($row['status']) && $row['status'] !== '' ? (int) $row['status'] : 1,
                'publish_time' => $ts,
                'updated_at'   => time(),
            ];
            $model = contentModel();
        }

        $exist = db()->fetchOne("SELECT id FROM {$p}{$existTable} WHERE slug = ? LIMIT 1", [$slug]);

        if ($exist) {
            if (!$update) { $skipped++; continue; }
            if (!$dryRun) $model->updateById((int) $exist['id'], $data);
            $updated++;
        } else {
            $data['created_at'] = $data['publish_time'] ?? time();
            if (!$dryRun) $model->create($data);
            $created++;
        }
    }
    fclose($fh);

    $tag = $dryRun ? '[dry-run] ' : '';
    CLI::info("{$tag}导入完成（{$type}）：新增 {$created}，更新 {$updated}，跳过 {$skipped}，告警 {$warned}");
    return 0;
});
