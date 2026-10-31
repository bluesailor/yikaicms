<?php
/**
 * S1-04：重建 s103-* 合成测试库并签发测试令牌，供 mcp-dev-router.php 与真实客户端连接验证。
 *
 *   YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=<s103-*.sqlite> php tests/ai-access/mcp-dev-seed.php --token-dir=<目录>
 *   ……同样的环境变量 php tests/ai-access/mcp-dev-seed.php --revoke=29
 *
 * 令牌明文只写入 --token-dir 下的文件（editor.token = 令牌 29，用户 7，六个工具；reader.token = 令牌 39，
 * 用户 8，仅 site_info 与读取工具），不打印到输出；库里只存 SHA-256 摘要。
 */

declare(strict_types=1);

require __DIR__ . '/proposal-fixture.php';

$options = getopt('', ['token-dir:', 'revoke:', 'pages']);
$db = s103Boot();
$p = S103_PREFIX;

if (isset($options['revoke'])) {
    $db->execute("UPDATE {$p}api_tokens SET revoked_at = ? WHERE id = ?", [time(), (int) $options['revoke']]);
    echo "revoked token " . (int) $options['revoke'] . "\n";
    exit(0);
}

$dir = (string) ($options['token-dir'] ?? '');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "--token-dir=<existing directory> is required\n");
    exit(2);
}

$now = time();
s103ResetSchema($db);
s103Seed($db, $now);
// 长文：多字节正文超过一个分段，用于验证 UTF-8 安全的分段读取。
$long = '<p>' . str_repeat('长文测试段落。', 8000) . '</p>';
$db->execute("INSERT INTO {$p}contents (id, lang, channel_id, type, title, summary, content, tags, status, publish_time, created_at, updated_at, admin_id)"
    . " VALUES (104, 'zh-CN', 10, 'article', 'Long article', '', ?, '', 1, ?, ?, ?, 1)", [$long, $now - 600, $now - 600, $now - 600]);
$db->execute("INSERT INTO {$p}settings (`group`, `key`, `value`) VALUES ('s103', 'site_name', 'S104 Synthetic Site')");

$tokens = [
    'editor' => [29, 7, AiAccessAuthContext::TOOLS],
    'reader' => [39, 8, ['site_info', 'article_channel_list', 'article_list', 'article_read']],
];
foreach ($tokens as $file => [$id, $owner, $scopes]) {
    $secret = 'ykat_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $db->execute("INSERT INTO {$p}api_tokens (id, admin_id, token_hash, scopes, expires_at, revoked_at) VALUES (?, ?, ?, ?, ?, 0)",
        [$id, $owner, hash('sha256', $secret), json_encode($scopes), $now + 86400]);
    file_put_contents($dir . '/' . $file . '.token', $secret);
}
if (array_key_exists('pages', $options)) {
    // S5-02 真实客户端：单页只读（--pages）。pager.token = 令牌 79，用户 10，角色 5（ai_access + blox_edit + edit_page），范围 site_info + page_read。
    // 编号避开 mcp-protocol-run 场景 09 自建的数据；协议断言不带 --pages，结果不受影响。
    foreach (s103CoreTableSql($db->isSqlite(), 'blox_page_drafts') as $sql) $db->execute(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql));
    $db->execute("INSERT INTO {$p}roles (id, name, permissions, status, created_at) VALUES (5, 'PageEditor', '[\"ai_access\",\"blox_edit\",\"edit_page\"]', 1, 0)");
    $db->execute("INSERT INTO {$p}users (id, username, password, role_id, status, created_at, updated_at) VALUES (10, 's502-pager', 'x', 5, 1, 0, 0)");
    $doc = static fn(array $elements): string => json_encode(['schema' => 1, 'settings' => ['custom_css' => 'SECRET_DOC_CSS'],
        'sections' => [['id' => 's1', 'settings' => [], 'columns' => [['id' => 'c1', 'elements' => $elements]]]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $about = [
        ['id' => 'title', 'type' => 'heading', 'data' => ['text' => '关于我们', 'url' => 'javascript:alert(1)']],
        ['id' => 'story', 'type' => 'text', 'data' => ['html' => '<p onclick="steal()">我们成立于 2010 年。<script>SECRET_SCRIPT()</script></p>']],
        ['id' => 'team', 'type' => 'image', 'data' => ['src' => '/uploads/team.jpg', 'alt' => '团队合影']],
        ['id' => 'embed', 'type' => 'code', 'data' => ['code' => 'SECRET_CODE']],
        ['type' => 'heading', 'data' => ['text' => '没有 ID 的标题']],
        ['id' => 'cta', 'type' => 'button', 'data' => ['text' => '联系我们', 'url' => '/contact']],
        ['id' => 'cta', 'type' => 'button', 'data' => ['text' => '重复 ID', 'url' => '/again']],
    ];
    $many = [];
    for ($i = 0; $i < 130; $i++) $many[] = ['id' => 'item' . $i, 'type' => 'heading', 'data' => ['text' => '条目 ' . $i]];
    foreach ([[20, 'about-s502', 1, $about], [21, 'catalog-s502', 1, $many], [22, 'hidden-s502', 0, $about]] as [$id, $slug, $status, $elements]) {
        $db->execute("INSERT INTO {$p}channels (id, lang, name, slug, type, status, created_at, updated_at) VALUES (?, 'zh-CN', ?, ?, 'page', ?, 0, 0)", [$id, 'S502 ' . $slug, $slug, $status]);
        $db->execute("INSERT INTO {$p}blox_page_drafts (page_id, draft_data) VALUES (?, ?)", [$id, $doc($elements)]);
    }
    $secret = 'ykat_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $db->execute("INSERT INTO {$p}api_tokens (id, admin_id, token_hash, scopes, expires_at, revoked_at) VALUES (79, 10, ?, ?, ?, 0)",
        [hash('sha256', $secret), json_encode(['site_info', 'page_read']), $now + 86400]);
    file_put_contents($dir . '/pager.token', $secret);
}
echo "seeded; tokens written to " . $dir . "\n";
