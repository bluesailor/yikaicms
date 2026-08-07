<?php
/**
 * 产品导入 - 分批写入
 * 由 admin.php 根据 ?handler=import 引入（已通过 plugin_page.php 完成 auth）
 *
 * Request JSON:
 *   file_id, mapping, strategy("skip"|"update"), lang, batch, batch_size
 */

if (!defined('ROOT_PATH')) exit;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['code' => 1, 'msg' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

verifyCsrf(); // 写端点必须 CSRF（JSON 请求走 X-CSRF-TOKEN 头）

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['code' => 1, 'msg' => '请求数据无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fileId    = (string) ($input['file_id'] ?? '');
$mapping   = $input['mapping'] ?? [];
$strategy  = (string) ($input['strategy'] ?? 'skip');
$inputLang = (string) ($input['lang'] ?? '');
$batch     = (int) ($input['batch'] ?? 0);
$batchSize = (int) ($input['batch_size'] ?? 50);

if (!preg_match('/^[a-f0-9]{16}$/', $fileId)) {
    echo json_encode(['code' => 1, 'msg' => '无效的 file_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$extDir   = ROOT_PATH . '/storage/temp/import_' . $fileId;
$metaFile = $extDir . '/_meta.json';

if (!is_dir($extDir) || !file_exists($metaFile)) {
    echo json_encode(['code' => 1, 'msg' => '临时文件已过期，请重新上传'], JSON_UNESCAPED_UNICODE);
    exit;
}

$meta = json_decode(file_get_contents($metaFile), true);
if (!$meta) {
    echo json_encode(['code' => 1, 'msg' => '元数据已损坏'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($meta['owner_sid'] ?? '') !== session_id()) {
    echo json_encode(['code' => 1, 'msg' => '临时文件不属于当前会话，请重新上传'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csvFile = null;
foreach (glob($extDir . '/*') as $f) {
    if (basename($f) === '_meta.json') continue;
    $csvFile = $f;
    break;
}

if (!$csvFile || !is_file($csvFile)) {
    echo json_encode(['code' => 1, 'msg' => '源文件丢失'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($strategy, ['skip', 'update'], true)) $strategy = 'skip';
if ($inputLang === '') $inputLang = function_exists('siteLang') ? siteLang() : 'zh-CN';
if ($batchSize < 1 || $batchSize > 200) $batchSize = 50;

$fileHeaders = $meta['headers'];

$reversed = [];
foreach ($mapping as $srcCol => $dstField) {
    if ($dstField !== '' && $dstField !== '_ignore') {
        $reversed[$dstField] = $srcCol;
    }
}

$ext     = strtolower(pathinfo($csvFile, PATHINFO_EXTENSION));
$allRows = [];

if ($ext === 'csv') {
    $fh = fopen($csvFile, 'r');
    if ($fh) {
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);
        $lineNum = 0;
        while (($cols = fgetcsv($fh)) !== false) {
            if ($lineNum > 0) $allRows[] = $cols;
            $lineNum++;
        }
        fclose($fh);
    }
} else {
    $allRows = parseXlsxAllRows($csvFile, count($fileHeaders));
}

$allRows = array_filter($allRows, fn($r) => count(array_filter($r, fn($c) => trim((string) ($c ?? '')) !== '')) > 0);
$allRows = array_values($allRows);

if (empty($allRows)) {
    echo json_encode(['code' => 1, 'msg' => '文件中无有效数据行'], JSON_UNESCAPED_UNICODE);
    exit;
}

$totalRows = count($allRows);
$endRow    = min($batch + $batchSize, $totalRows);
$batchRows = array_slice($allRows, $batch, $batchSize);

[$catSlugMap, $catNameMap] = buildCategoryMaps($inputLang);
[$brandSlugMap, $brandNameMap] = buildBrandMaps();

$created = 0; $updated = 0; $skipped = 0;
$errors = [];

foreach ($batchRows as $rowIdx => $cols) {
    $rowNum = $batch + $rowIdx + 1;
    $data   = [];

    foreach ($reversed as $dstField => $srcCol) {
        $colIdx = array_search($srcCol, $fileHeaders);
        if ($colIdx === false) continue;
        $val = trim((string) ($cols[$colIdx] ?? ''));
        if ($val === '') continue;
        $data[$dstField] = $val;
    }

    if (empty($data['title'])) {
        $errors[] = ['row' => $rowNum, 'msg' => '产品名称不能为空'];
        continue;
    }

    $title = $data['title'];
    $slug  = $data['slug'] ?? '';
    $slugExplicit = $slug !== ''; // 用户显式给的 slug 撞车才走 skip/update 策略语义
    if ($slug === '') {
        if (function_exists('generateSlug')) {
            $slug = generateSlug($title);
        } else {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
        }
        if ($slug === '') $slug = 'p-' . substr(md5($title . uniqid()), 0, 8);
    }

    $record = [
        'title'        => $title,
        'slug'         => $slug,
        'lang'         => $data['lang'] ?? $inputLang,
        'subtitle'     => $data['subtitle'] ?? '',
        'model'        => $data['model'] ?? '',
        'cover'        => $data['cover'] ?? '',
        'summary'      => $data['summary'] ?? '',
        'content'      => $data['content'] ?? '',
        'price'        => (float) ($data['price'] ?? 0),
        'market_price' => (float) ($data['market_price'] ?? 0),
        'material'     => $data['material'] ?? '',
        'scene'        => $data['scene'] ?? '',
        'status'       => isset($data['status']) && $data['status'] !== '' ? (int) $data['status'] : 1,
        'is_top'       => (int) ($data['is_top'] ?? 0),
        'is_recommend' => (int) ($data['is_recommend'] ?? 0),
        'is_hot'       => (int) ($data['is_hot'] ?? 0),
        'is_new'       => (int) ($data['is_new'] ?? 0),
        'sort_order'   => (int) ($data['sort_order'] ?? 0),
        'updated_at'   => time(),
    ];

    if (isset($data['product_type']) && in_array($data['product_type'], ['standard', 'custom'], true)) {
        $record['product_type'] = $data['product_type'];
    }

    $catId = 0;
    if (!empty($data['category'])) {
        $catSlug = function_exists('slugify') ? slugify($data['category']) : strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $data['category']), '-'));
        $catId = $catSlugMap[$catSlug] ?? $catNameMap[$data['category']] ?? 0;
    }
    $record['category_id'] = $catId;

    $brandId = 0;
    if (!empty($data['brand'])) {
        $brandSlug = function_exists('slugify') ? slugify($data['brand']) : strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $data['brand']), '-'));
        $brandId = $brandSlugMap[$brandSlug] ?? $brandNameMap[$data['brand']] ?? 0;
    }
    $record['brand_id'] = $brandId;

    if (!empty($data['images'])) {
        $v = $data['images'];
        if ($v[0] === '[') {
            $record['images'] = $v;
        } else {
            $imgs = array_filter(array_map('trim', explode(',', $v)), fn($i) => $i !== '');
            $record['images'] = json_encode(array_values($imgs), JSON_UNESCAPED_UNICODE);
        }
    }

    if (!empty($data['specs'])) {
        $v = $data['specs'];
        if ($v[0] === '[' || $v[0] === '{') {
            $record['specs'] = $v;
        } else {
            $lines = preg_split('/\r\n|\r|\n/', $v);
            $specs = [];
            foreach ($lines as $sl) {
                $sl = trim($sl);
                if ($sl === '') continue;
                $parts = explode(':', $sl, 2);
                $key = trim($parts[0]);
                $val = trim($parts[1] ?? '');
                if ($key !== '') $specs[] = ['key' => $key, 'value' => $val];
            }
            $record['specs'] = json_encode($specs, JSON_UNESCAPED_UNICODE);
        }
    }

    $p = DB_PREFIX;
    // products 是多语言 + 软删表：slug 无唯一约束。必须限定 lang（否则英文导入会
    // 覆盖同 slug 的日文产品）并排除 deleted_at（否则会"更新"已删产品或误判跳过）。
    $exist = db()->fetchOne(
        "SELECT id, title FROM {$p}products WHERE slug = ? AND lang = ? AND deleted_at IS NULL LIMIT 1",
        [$slug, $record['lang']]
    );

    // 自动生成的 slug 撞上"别的产品"（标题不同）时不是重复导入，是 slug 生成截断
    // 撞车（generateSlug 取中文前几字，同前缀产品名必撞）——追加后缀取唯一，
    // 避免静默跳过丢数据。同标题命中才按 skip/update 策略处理。
    if ($exist && !$slugExplicit && (string) $exist['title'] !== $title) {
        // 沿 base-2, base-3… 找【空位】或【同标题行】（后者=重导时自己占的槽位，
        // 命中则交给 skip/update 策略——否则每次重导都会开新副本，幂等被破坏）
        $base = $slug;
        $resolved = false;
        for ($n = 2; $n <= 50; $n++) {
            $candidate = $base . '-' . $n;
            $hit = db()->fetchOne(
                "SELECT id, title FROM {$p}products WHERE slug = ? AND lang = ? AND deleted_at IS NULL LIMIT 1",
                [$candidate, $record['lang']]
            );
            if (!$hit) {
                $slug = $candidate;
                $record['slug'] = $candidate;
                $exist = null;
                $resolved = true;
                break;
            }
            if ((string) $hit['title'] === $title) {
                $slug = $candidate;
                $record['slug'] = $candidate;
                $exist = $hit;
                $resolved = true;
                break;
            }
        }
        if (!$resolved) {
            $errors[] = ['row' => $rowNum, 'msg' => 'slug 冲突无法自动解决：' . $base];
            continue;
        }
    }

    if ($exist) {
        if ($strategy === 'skip') { $skipped++; continue; }
        $pid = (int) $exist['id'];
        productModel()->updateById($pid, $record);
        if (function_exists('do_action')) do_action('after_save_product', $pid, $record, true);
        $updated++;
    } else {
        $record['created_at'] = time();
        $record['admin_id'] = (int) ($_SESSION['admin_id'] ?? 0);
        $pid = productModel()->create($record);
        if (function_exists('do_action')) do_action('after_save_product', $pid, $record, false);
        $created++;
    }

    if (!empty($data['tags'])) {
        $tagNames = array_filter(array_map('trim', explode(',', $data['tags'])), fn($t) => $t !== '');
        if ($tagNames) syncProductTags($pid, $tagNames);
    }
}

$processed = $batch + count($batchRows);
$nextBatch = ($processed < $totalRows) ? $batch + $batchSize : -1;
$done = $nextBatch === -1;

if ($done) {
    adminLog('product', 'import', "批量导入产品：新增{$created}条，更新{$updated}条，跳过{$skipped}条");
    foreach (glob($extDir . '/*') as $f) unlink($f);
    rmdir($extDir);
}

echo json_encode([
    'code' => 0,
    'data' => compact('totalRows', 'processed', 'created', 'updated', 'skipped', 'errors') + [
        'next_batch' => $nextBatch,
        'done'       => $done,
    ],
], JSON_UNESCAPED_UNICODE);

// ── helpers ──

function buildCategoryMaps(string $lang): array
{
    $rows = db()->fetchAll("SELECT id, name, slug FROM " . DB_PREFIX . "product_categories WHERE lang = ?", [$lang]);
    $slugMap = []; $nameMap = [];
    foreach ($rows as $r) {
        $slugMap[$r['slug']] = (int) $r['id'];
        $nameMap[$r['name']] = (int) $r['id'];
    }
    return [$slugMap, $nameMap];
}

function buildBrandMaps(): array
{
    $rows = db()->fetchAll("SELECT id, name, slug FROM " . DB_PREFIX . "brands");
    $slugMap = []; $nameMap = [];
    foreach ($rows as $r) {
        $slugMap[$r['slug']] = (int) $r['id'];
        $nameMap[$r['name']] = (int) $r['id'];
    }
    return [$slugMap, $nameMap];
}

function syncProductTags(int $productId, array $tagNames): void
{
    $p = DB_PREFIX;
    $lang = function_exists('siteLang') ? siteLang() : 'zh-CN';
    foreach ($tagNames as $name) {
        $name = trim($name);
        if ($name === '') continue;
        $slug = function_exists('slugify') ? slugify($name) : strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
        if ($slug === '') continue;

        $existing = db()->fetchOne("SELECT id FROM {$p}product_tags WHERE slug = ? LIMIT 1", [$slug]);
        if ($existing) {
            $tagId = (int) $existing['id'];
        } else {
            $tagId = (int) db()->insert('product_tags', [
                'group_name' => '默认', 'name' => $name, 'slug' => $slug,
                'lang' => $lang, 'status' => 1, 'sort_order' => 0,
            ]);
        }

        // 关联表为复合键、无 id 列——按键列查重
        $mapped = db()->fetchOne("SELECT product_id FROM {$p}product_tag_map WHERE product_id = ? AND tag_id = ? LIMIT 1", [$productId, $tagId]);
        if (!$mapped) {
            db()->insert('product_tag_map', ['product_id' => $productId, 'tag_id' => $tagId]);
        }
    }
}

function parseXlsxAllRows(string $path, int $headerCount): array
{
    $rows = [];
    if (!class_exists('ZipArchive')) return $rows;

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $rows;

    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetXml) { $zip->close(); return $rows; }

    $sharedStrings = [];
    if ($sharedStringsXml) {
        $ssXml = new SimpleXMLElement($sharedStringsXml);
        foreach ($ssXml->si as $si) {
            $t = '';
            foreach ($si->t as $tNode) { $t .= (string) $tNode; }
            $sharedStrings[] = $t;
        }
    }

    $sheet = new SimpleXMLElement($sheetXml);
    $ns = $sheet->getNamespaces(true);
    $mainNs = $ns[''] ?? '';
    $sheet->registerXPathNamespace('s', $mainNs);
    $rowElements = $sheet->xpath('//s:sheetData/s:row');
    if (empty($rowElements)) { $zip->close(); return $rows; }

    $isFirst = true;
    foreach ($rowElements as $rowEl) {
        if ($isFirst) { $isFirst = false; continue; }
        $cols = [];
        foreach ($rowEl->c as $c) {
            $ref = (string) $c['r'];
            $colIndex = preg_replace('/[0-9]/', '', $ref);
            $colNum = 0;
            for ($i = 0, $len = strlen($colIndex); $i < $len; $i++) {
                $colNum = $colNum * 26 + (ord($colIndex[$i]) - ord('A') + 1);
            }
            $colNum--;
            $type = (string) ($c['t'] ?? '');
            $value = (string) ($c->v ?? '');
            if ($type === 's' && $value !== '') $value = $sharedStrings[(int) $value] ?? $value;
            $cols[$colNum] = $value;
        }
        $row = [];
        for ($i = 0; $i < $headerCount; $i++) {
            $row[$i] = $cols[$i] ?? '';
        }
        if (count(array_filter($row, fn($c) => trim($c) !== '')) > 0) {
            $rows[] = $row;
        }
    }
    $zip->close();
    return $rows;
}
