<?php
/** Targeted preview/routing regression, disposable loopback SQLite only. */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/includes/init.php';
if (PHP_SAPI !== 'cli' || DB_DRIVER !== 'sqlite'
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')
    || !preg_match('#^http://127\.0\.0\.1:[0-9]+$#D', SITE_URL)) {
    throw new RuntimeException('Disposable smoke site required');
}

$db = db();
$now = time();
$pageId = 9001;
$translatedId = 9002;
$productId = 9001;
$caseId = 9001;
$afterUi = in_array('--verify-after-ui', $argv, true);
if (!$afterUi) {
foreach (['channels' => $pageId, 'products' => $productId, 'contents' => $caseId] as $table => $id) {
    if ($db->fetchOne('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE id = ?', [$id])) {
        throw new RuntimeException('Fixture ID in use; use a fresh smoke site');
    }
}

foreach ([$pageId => 'zh-CN', $translatedId => 'en'] as $id => $language) {
    $db->insert('channels', ['id' => $id, 'parent_id' => 0, 'type' => 'page',
        'name' => 'CU 草稿预览 ' . $language, 'slug' => 'cu-draft-' . $language,
        'content' => '<p>CU_LEGACY_FALLBACK</p>', 'status' => 0, 'lang' => $language,
        'redirect_type' => 'none', 'created_at' => $now, 'updated_at' => $now]);
    $document = ['schema' => 1, 'settings' => ['page_title_hidden' => true], 'sections' => [[
        'id' => 'cu-section-' . $id, 'settings' => ['padding' => 'md'], 'columns' => [[
            'id' => 'cu-column-' . $id, 'span' => 12, 'elements' => [
                ['id' => 'cu-heading-' . $id, 'type' => 'heading', 'data' => ['text' => 'CU_PRIVATE_BUILDER_' . $language, 'level' => 'h1']],
                ['id' => 'cu-text-' . $id, 'type' => 'text', 'data' => ['html' => '<p>草稿正文 <a href="/product.html">查看产品列表</a></p>']],
                ['id' => 'cu-button-' . $id, 'type' => 'button', 'data' => ['text' => '返回首页', 'url' => '/']],
            ],
        ]],
    ]]];
    PageBloxDocument::saveDraft($id, json_encode($document, JSON_THROW_ON_ERROR), '', 1);
}
$db->insert('products', ['id' => $productId, 'title' => 'CU 规格回归草稿', 'category_id' => 1,
    'slug' => 'cu-spec-draft', 'model' => 'CU-84', 'summary' => '仅用于隔离站界面验证',
    'content' => '<p>CU_PRIVATE_PRODUCT</p>', 'lang' => 'zh-CN', 'status' => 0,
    'specs' => json_encode(['型号' => 'CU-84', '尺寸' => '120 × 80 × 35 mm', '材质' => '铝合金', '重量' => '0.8 kg', '产地' => '中国'], JSON_UNESCAPED_UNICODE),
    'created_at' => $now, 'updated_at' => $now]);
$caseChannel = (int) $db->fetchColumn("SELECT id FROM " . DB_PREFIX . "channels WHERE type = 'case' AND lang = 'zh-CN' LIMIT 1");
$db->insert('contents', ['id' => $caseId, 'channel_id' => $caseChannel, 'type' => 'case',
    'title' => 'CU 控制台案例路由验收', 'content' => '<p>CU_CASE_BODY</p>', 'lang' => 'zh-CN',
    'status' => 0, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('users', ['username' => 'preview_denied', 'password' => password_hash('Preview@Test123', PASSWORD_DEFAULT),
    'role_id' => 2, 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
}

$designer = $db->fetchOne('SELECT id FROM ' . DB_PREFIX . 'users WHERE username = ?', ['preview_designer']);
if (!$designer) {
    $db->insert('roles', ['id' => 9002, 'name' => 'CU Blox designer', 'permissions' => '["blox_edit","edit_page"]', 'status' => 1, 'created_at' => $now]);
    $db->insert('users', ['username' => 'preview_designer', 'password' => password_hash('Preview@Test123', PASSWORD_DEFAULT), 'role_id' => 9002, 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('channels', ['id' => 9003, 'parent_id' => 0, 'type' => 'list', 'name' => 'CU disabled landing', 'slug' => 'cu-disabled-landing', 'status' => 0, 'lang' => 'zh-CN', 'created_at' => $now, 'updated_at' => $now]);
    ChannelBloxDocument::saveDraft(9003, json_encode(['schema' => 1, 'settings' => [], 'sections' => [[
        'id' => 'cu-landing', 'settings' => [], 'columns' => [['span' => 12, 'elements' => [[
            'type' => 'heading', 'data' => ['text' => 'CU_PRIVATE_LANDING', 'level' => 'h1'],
        ]]]],
    ]]], JSON_THROW_ON_ERROR), '', 1);
}

$results = [];
$jars = [];
$request = static function (string $actor, string $path, ?array $post = null) use (&$jars): array {
    $jars[$actor] ??= tempnam(sys_get_temp_dir(), 'cu-preview-');
    $ch = curl_init(SITE_URL . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25,
        CURLOPT_COOKIEFILE => $jars[$actor], CURLOPT_COOKIEJAR => $jars[$actor]]);
    if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException(curl_error($ch));
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, $headerSize), substr($raw, 0, $headerSize)];
};
$check = static function (bool $ok, string $label) use (&$results): void {
    $results[] = ['pass' => $ok, 'label' => $label];
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
};
$login = static function (string $actor, string $username, string $password) use ($request, $check): void {
    [, $body] = $request($actor, '/admin/login.php');
    preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $body, $token);
    if (!$token) preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $body, $token);
    [$code] = $request($actor, '/admin/login.php', ['username' => $username, 'password' => $password, '_token' => $token[1] ?? '']);
    $check($code === 302, $actor . ' real login');
};
$snapshot = static function () use ($db): string {
    $rows = [];
    foreach (['channels', 'products', 'contents', 'blox_page_drafts'] as $table) {
        $rows[$table] = $db->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' ORDER BY id');
    }
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
};

try {
    $login('admin', 'admin', 'smoke@Test123');
    $login('denied', 'preview_denied', 'Preview@Test123');
    $login('designer', 'preview_designer', 'Preview@Test123');
    $before = $snapshot();
    foreach ([$pageId => 'zh-CN', $translatedId => 'en'] as $id => $language) {
        $expectedHeading = $afterUi && $id === $pageId ? '【CU实测草稿】预览修复已回读' : 'CU_PRIVATE_BUILDER_' . $language;
        $path = '/admin/draft_preview.php?type=channels&id=' . $id;
        [$code, $body, $headers] = $request('admin', $path);
        $check($code === 200 && str_contains($body, $expectedHeading), 'disabled Builder draft rendered: ' . $language);
        $check(str_contains($body, 'data-testid="admin-draft-preview"') && str_contains(strtolower($headers), 'no-store') && str_contains(strtolower($headers), 'noindex'), 'private/no-store/noindex: ' . $language);
        $check(!str_contains($body, 'data-testid="blox-draft-preview-bar"'), 'one private preview banner: ' . $language);
        $check(!str_contains($body, 'CU_LEGACY_FALLBACK') && str_contains($body, 'href="/product.html"'), 'Builder overrides legacy HTML and preserves links: ' . $language);
        foreach (['anon', 'denied'] as $actor) {
            [$blockedCode, $blocked] = $request($actor, $path);
            $check(!str_contains($blocked, $expectedHeading) && ($blockedCode === 302 || str_contains($blocked, __('perm_denied'))), $actor . ' cannot read page draft: ' . $language);
        }
        [$publicCode, $publicBody] = $request('anon', '/page.php?id=' . $id . '&preview=draft&blox_draft=page:' . $id);
        $check($publicCode === 404 && !str_contains($publicBody, $expectedHeading), 'query flags alone cannot expose disabled draft: ' . $language);
    }
    [, $tampered] = $request('admin', '/admin/draft_preview.php?type=channels&id=' . $pageId . '&blox_draft=page:' . $translatedId);
    $check(str_contains($tampered, $afterUi ? '【CU实测草稿】预览修复已回读' : 'CU_PRIVATE_BUILDER_zh-CN') && !str_contains($tampered, 'CU_PRIVATE_BUILDER_en'), 'caller cannot switch draft identity via query');
    [$code, $body] = $request('admin', '/admin/draft_preview.php?type=products&id=' . $productId);
    $check($code === 200 && str_contains($body, 'CU_PRIVATE_PRODUCT') && str_contains($body, '120 × 80 × 35 mm') && str_contains($body, '0.8 kg'), 'product preview body and Unicode specs');
    if ($afterUi) $check(str_contains($body, '-20 ～ +60 °C') && str_contains($body, 'USB-C / RS-485') && str_contains(html_entity_decode($body, ENT_QUOTES, 'UTF-8'), "连接方式 O'Reilly"), 'UI-added Unicode and quoted specs persisted');
    foreach (['anon', 'denied'] as $actor) {
        [$code, $body] = $request($actor, '/admin/draft_preview.php?type=products&id=' . $productId);
        $check(!str_contains($body, 'CU_PRIVATE_PRODUCT') && ($code === 302 || str_contains($body, __('perm_denied'))), $actor . ' cannot read product draft');
    }
    [, $body] = $request('admin', '/admin/product.php');
    $check(str_contains($body, '/admin/draft_preview.php?type=products&amp;id=' . $productId), 'product list links to protected draft preview');
    [, $body] = $request('admin', '/admin/');
    $check(str_contains($body, '/admin/content_edit.php?id=' . $caseId) && !str_contains($body, '/admin/article_edit.php?id=' . $caseId), 'dashboard uses case editor');
    [$code, $body] = $request('admin', '/admin/content_edit.php?id=' . $caseId);
    $check($code === 200 && str_contains($body, 'CU_CASE_BODY'), 'case editor opens correctly');
    [$code, $body] = $request('denied', '/admin/content_edit.php?id=' . $caseId);
    $check(str_contains($body, __('perm_denied')) && !str_contains($body, 'CU_CASE_BODY'), 'article-only account still denied case editing');
    [, $body] = $request('admin', '/admin/blox_editor.php?id=' . $pageId);
    $check(str_contains($body, '/admin/draft_preview.php?type=channels&amp;id=' . $pageId), 'editor fallback preview uses protected endpoint');
    $check($before === $snapshot(), 'preview GETs do not publish or mutate content/drafts');
    [$code, $body] = $request('designer', '/admin/draft_preview.php?type=channels&id=' . $pageId);
    $check($code === 200 && str_contains($body, $afterUi ? '【CU实测草稿】预览修复已回读' : 'CU_PRIVATE_BUILDER_zh-CN'), 'Blox designer can preview ordinary page without superadmin');
    [$code, $body] = $request('designer', '/admin/draft_preview.php?type=channels&id=9003');
    $check($code === 200 && str_contains($body, 'CU_PRIVATE_LANDING'), 'Blox designer retains landing-page preview without channel admin');
    [, $body] = $request('denied', '/admin/draft_preview.php?type=channels&id=9003');
    $check(str_contains($body, __('perm_denied')) && !str_contains($body, 'CU_PRIVATE_LANDING'), 'article-only account cannot preview landing draft');
    $check($before === $snapshot(), 'designer previews do not mutate content/drafts');
} finally {
    foreach ($jars as $jar) if (is_file($jar)) unlink($jar);
}

$out = ['results' => $results, 'passed' => count(array_filter($results, static fn(array $r): bool => $r['pass'])), 'total' => count($results),
    'fixtures' => ['page' => $pageId, 'translated_page' => $translatedId, 'product' => $productId, 'case' => $caseId]];
file_put_contents(ROOT_PATH . '/storage/admin-preview-regression.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
exit($out['passed'] === $out['total'] ? 0 : 1);
