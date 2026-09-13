<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable smoke site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';
require ROOT_PATH . '/includes/HtmlCache.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
$action = $argv[1] ?? '';
$id = (int) ($argv[2] ?? 0);
if ($action === 'products') {
    $rows = productModel()->getList(0, 3, 0, ['lang' => 'zh-CN']);
    echo json_encode(array_map(static fn(array $row): array => [
        'id' => $row['id'], 'title' => $row['title'], 'cover' => $row['cover'], 'views' => $row['views'], 'url' => productPrettyUrl($row),
    ], $rows), JSON_THROW_ON_ERROR);
} elseif ($action === 'preview-products') {
    $rows = [];
    foreach (['zh-CN', 'en', 'ja'] as $language) {
        $items = productModel()->getList(0, 1, 0, ['lang' => $language]);
        if ($items === [] || $items[0]['lang'] !== $language) throw new RuntimeException('Translated demo product required');
        $labels = require ROOT_PATH . '/lang/' . $language . '.php';
        $rows[] = ['id' => $items[0]['id'], 'title' => $items[0]['title'], 'language' => $language, 'submit' => $labels['product_btn_submit_inq']];
    }
    echo json_encode($rows, JSON_THROW_ON_ERROR);
} elseif ($action === 'pair') {
    $product = productModel()->getList(0, 1, 0, ['lang' => 'zh-CN'])[0];
    $document = BloxDocumentPipeline::decode(ProductTemplateDocument::seed('zh-CN'));
    $document['settings']['product_template']['ids'] = [(int) $product['id']];
    $json = json_encode($document, JSON_THROW_ON_ERROR);
    $selected = bloxTemplateModel()->createDraft('product-detail', 'TB-R2 native selected', $json);
    bloxTemplateModel()->publishDraft($selected);
    $document['sections'][0]['columns'][1]['elements'][0]['data']['level'] = 'h2';
    bloxTemplateModel()->updateDraft($selected, json_encode($document, JSON_THROW_ON_ERROR), []);
    $document['settings']['product_template']['mode'] = 'all';
    $global = bloxTemplateModel()->createDraft('product-detail', 'TB-R2 native global', json_encode($document, JSON_THROW_ON_ERROR));
    bloxTemplateModel()->publishDraft($global);
    HtmlCache::invalidate();
    echo json_encode(['selected' => $selected, 'global' => $global], JSON_THROW_ON_ERROR);
} elseif (in_array($action, ['read', 'restore'], true)) {
    $row = bloxTemplateModel()->findForExport($id);
    // 仍以「详情模板 + TB-R2 前缀」为护栏；TASK-008 的诊断用例也需要读文章模板，
    // 因此允许 article-detail（护栏不变，只是不再只认产品）。
    if (!in_array($row['type'] ?? '', ['product-detail', 'article-detail'], true) || !str_starts_with($row['name'], 'TB-R2 ')) {
        throw new RuntimeException('Test template required');
    }
    if ($action === 'read') echo json_encode($row, JSON_THROW_ON_ERROR);
    else { db()->delete('blox_templates', 'id = ?', [$id]); HtmlCache::invalidate(); }
} elseif ($action === 'scope') {
    // 直接写入 TB-R2 测试模板的草稿条件（如 native、缺失引用），并经真实文档管道归一化
    $row = bloxTemplateModel()->findForExport($id);
    if (!in_array($row['type'] ?? '', ['product-detail', 'article-detail'], true) || !str_starts_with((string) ($row['name'] ?? ''), 'TB-R2 ')) {
        throw new RuntimeException('Test template required');
    }
    $document = BloxDocumentPipeline::decode((string) $row['draft_data']);
    $document['settings']['detail_template'] = json_decode((string) ($argv[3] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $json = BloxDocumentPipeline::process(json_encode($document, JSON_THROW_ON_ERROR), 'tpl' . $id)['json'];
    bloxTemplateModel()->updateDraft($id, $json, []);
    echo $json;
} elseif ($action === 'publish-scope') {
    // 建一个只含 v2 条件的已发布测试模板（作为"其它已发布候选"）
    $scope = json_decode((string) ($argv[3] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $name = 'TB-R2 ' . preg_replace('/[^A-Za-z0-9 _-]/', '', (string) ($argv[4] ?? 'scope'));
    $type = ($scope['content_type'] ?? '') === 'article' ? 'article-detail' : 'product-detail';
    $seed = $type === 'article-detail' ? ArticleTemplateDocument::seed((string) $scope['lang']) : ProductTemplateDocument::seed((string) $scope['lang']);
    $document = BloxDocumentPipeline::decode($seed);
    unset($document['settings']['product_template']);
    $document['settings']['detail_template'] = $scope;
    $json = BloxDocumentPipeline::process(json_encode($document, JSON_THROW_ON_ERROR), 'template')['json'];
    $newId = bloxTemplateModel()->createDraft($type, $name, $json);
    bloxTemplateModel()->publishDraft($newId);
    HtmlCache::invalidate();
    echo json_encode(['id' => $newId], JSON_THROW_ON_ERROR);
} elseif ($action === 'setting') {
    // 发布检查的行数上限只在隔离站调小，用来覆盖"必须完成分页检查"的路径
    $key = (string) ($argv[3] ?? '');
    if (!in_array($key, ['blox_detail_publish_sync_rows', 'blox_detail_publish_page_rows'], true)) {
        throw new RuntimeException('Unsupported setting');
    }
    if (($argv[4] ?? '') === '') {
        db()->execute('DELETE FROM ' . DB_PREFIX . 'settings WHERE "key" = ?', [$key]);
    } else {
        settingModel()->set($key, (string) $argv[4], 'blox');
    }
    echo 'ok';
} elseif ($action === 'limited-user') {
    // 只有 Blox 全站设计权限、没有产品/文章编辑权限的后台账号（诊断权限用例）
    db()->execute('DELETE FROM ' . DB_PREFIX . "users WHERE username = 'tbr2_limited'");
    db()->execute('DELETE FROM ' . DB_PREFIX . "roles WHERE name = 'TB-R2 limited'");
    if (($argv[2] ?? '') !== 'remove') {
        $now = time();
        $roleId = (int) db()->insert('roles', ['name' => 'TB-R2 limited', 'permissions' => json_encode(['blox_global']), 'status' => 1, 'created_at' => $now]);
        db()->insert('users', [
            'username' => 'tbr2_limited', 'password' => password_hash('Limited@Test123', PASSWORD_BCRYPT), 'nickname' => 'tbr2_limited',
            'email' => 'tbr2@t.local', 'role_id' => $roleId, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        echo json_encode(['username' => 'tbr2_limited', 'password' => 'Limited@Test123'], JSON_THROW_ON_ERROR);
    }
} else throw new RuntimeException('Invalid action');
