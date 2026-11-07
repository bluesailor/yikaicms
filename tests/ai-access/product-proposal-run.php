<?php
/**
 * T05 产品内容提案的行为验证：直接调用 AiMcpTools / AiProposalService，在 s103-* 一次性合成库上跑
 * 工具可见性与读取投影（不外泄价格）、白名单外字段、目标政策、旧版本、幂等、应用（含 SEO metas）、
 * 应用前人工改动 / 发布 / 删除冲突、撤权撤令牌收范围、条件撤销。不起服务、不调用模型。
 *
 *   YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=<临时目录>/s103-product-proposals.sqlite php tests/ai-access/product-proposal-run.php
 *
 * 输出 JSON 报告到标准输出；退出码 0 = 全部通过，1 = 有失败，2 = 环境错误。
 */

declare(strict_types=1);

require __DIR__ . '/proposal-fixture.php';
if (!function_exists('__')) {
    /** 合成环境没有语言包：审核视图里的标签原样返回键名 */
    function __(string $key, array $params = []): string
    {
        return $key;
    }
}

$now = time();
$db = s103Boot();
require_once ROOT_PATH . '/includes/ai-access/AiProposalReview.php';
require_once ROOT_PATH . '/includes/ai-access/AiMcpTools.php';
$p = S103_PREFIX;
$results = [];
$scenario = '';
$check = static function (bool $ok, string $label) use (&$results, &$scenario): void {
    $results[$scenario][] = ($ok ? 'ok   ' : 'FAIL ') . $label;
};
$code = static function (callable $fn): string {
    try {
        $fn();
        return 'none';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};

try {
    s103ResetSchema($db);
    s103Seed($db, $now);
    foreach (['products', 'product_categories', 'metas'] as $table) {
        $db->execute("DROP TABLE IF EXISTS {$p}{$table}");
        foreach (s103CoreTableSql($db->isSqlite(), $table) as $sql) $db->execute($sql);
    }
    $db->execute("INSERT INTO {$p}roles (id, name, permissions, status, created_at) VALUES (5, 'ProductEditor', '[\"ai_access\",\"edit_product\"]', 1, 0)");
    $db->execute("INSERT INTO {$p}users (id, username, password, role_id, status, created_at, updated_at) VALUES (10, 't05-producter', 'x', 5, 1, 0, 0)");
    $db->execute("INSERT INTO {$p}users (id, username, password, role_id, status, created_at, updated_at) VALUES (11, 't05-producter-b', 'x', 5, 1, 0, 0)");
    $tokens = [51 => [10, ['product_read', 'product_content_propose', 'proposal_status']], 52 => [10, ['product_read']], 53 => [7, ['product_read', 'product_content_propose']]];
    foreach ($tokens as $id => [$owner, $scopes]) {
        $db->execute("INSERT INTO {$p}api_tokens (id, admin_id, token_hash, scopes, expires_at, revoked_at) VALUES (?, ?, ?, ?, ?, 0)",
            [$id, $owner, hash('sha256', 't05-token-' . $id), json_encode($scopes), $now + 86400]);
    }
    $db->execute("INSERT INTO {$p}product_categories (id, lang, name, slug, status, created_at) VALUES (1, 'zh-CN', '液压泵', 'pumps', 1, 0)");
} catch (Throwable $e) {
    fwrite(STDERR, 'environment error: ' . $e->getMessage() . "\n");
    exit(2);
}

/** 每个场景重建产品：200 中文草稿、201 中文已发布、202 英文草稿、203 回收站里的草稿；200 带一个 SEO 标题 */
$reset = static function () use ($db, $p, $now): void {
    foreach (['products', 'metas', 'ai_proposal_items', 'ai_proposals', 'ai_change_log'] as $table) $db->execute("DELETE FROM {$p}{$table}");
    $rows = [[200, 'zh-CN', 0, null], [201, 'zh-CN', 1, null], [202, 'en', 0, null], [203, 'zh-CN', 0, $now - 60]];
    foreach ($rows as [$id, $lang, $status, $deleted]) {
        $db->execute("INSERT INTO {$p}products (id, lang, category_id, title, model, specs, summary, content, price, market_price, status, created_at, updated_at, deleted_at)"
            . " VALUES (?, ?, 1, ?, 'HP-200', ?, 'Old summary', '<p>Old body</p>', 99.5, 120, ?, ?, ?, ?)",
            [$id, $lang, 'Pump ' . $id, json_encode([['name' => '压力', 'value' => '25MPa']], JSON_UNESCAPED_UNICODE), $status, $now - 7200, $now - 7200, $deleted]);
    }
    $db->execute("INSERT INTO {$p}metas (owner_type, owner_id, meta_key, meta_value, created_at, updated_at) VALUES ('product', 200, 'seo_title', 'Old SEO', 0, 0)");
};
$producer = new AiAccessAuthContext(10, 51, ['ai_access', 'edit_product'], $tokens[51][1]);
$readOnly = new AiAccessAuthContext(10, 52, ['ai_access', 'edit_product'], $tokens[52][1]);
$articleEditor = new AiAccessAuthContext(7, 53, ['ai_access', 'edit_article'], $tokens[53][1]);
$service = new AiProposalService($db);
$tools = new AiMcpTools($db, $service, static fn (): int => $now);
$read = static fn (int $id): array => $tools->call('product_read', ['id' => $id], $producer);
$item = static fn (int $id, array $fields, ?string $base = null, string $lang = 'zh-CN'): array => ['id' => $id, 'lang' => $lang, 'base_revision' => $base ?? $read($id)['base_revision']] + $fields;
$row = static fn (int $id): array => $db->fetchOne("SELECT * FROM {$p}products WHERE id = ?", [$id]) ?? [];
$meta = static fn (int $id, string $key): ?string => ($m = $db->fetchOne("SELECT meta_value FROM {$p}metas WHERE owner_type = 'product' AND owner_id = ? AND meta_key = ?", [$id, $key])) === null ? null : (string) $m['meta_value'];
$count = static fn (string $table): int => (int) $db->fetchColumn("SELECT COUNT(*) FROM {$p}{$table}");
$propose = static fn (string $key, array $items, ?AiAccessAuthContext $ctx = null): array => $service->proposeProductFromToken($ctx ?? $producer, $key, $items, $now);

$scenario = 'tools';
$reset();
$names = array_column($tools->definitions($producer), 'name');
$check(array_values(array_intersect(['product_list', 'product_read', 'product_content_propose', 'proposal_status'], $names)) === ['product_list', 'product_read', 'product_content_propose', 'proposal_status'],
    '产品编辑 + 产品范围：四个工具可见');
$check(!in_array('product_content_propose', array_column($tools->definitions($readOnly), 'name'), true) && in_array('product_read', array_column($tools->definitions($readOnly), 'name'), true),
    '只有 product_read 范围：能读不能提案');
$check(array_intersect(['product_list', 'product_read', 'product_content_propose'], array_column($tools->definitions($articleEditor), 'name')) === [],
    '有产品范围但账号没有 edit_product：产品工具一个都不可见');
$list = $tools->call('product_list', ['lang' => 'zh-CN'], $producer);
$check(array_column($list['items'], 'id') === [201, 200], '列表按 id 倒序、不含回收站与其他语言');
$check(array_column($tools->call('product_list', ['lang' => 'zh-CN', 'status' => 'draft'], $producer)['items'], 'id') === [200], '按状态筛草稿');
$detail = $read(200);
$json = json_encode([$list, $detail], JSON_UNESCAPED_UNICODE);
$check(!str_contains($json, '99.5') && !str_contains($json, 'price') && !str_contains($json, 'market'), '列表与读取都不返回价格');
$check($detail['facts']['model'] === 'HP-200' && $detail['facts']['category'] === '液压泵' && ($detail['facts']['specs'][0]['value'] ?? '') === '25MPa', '事实字段（型号、分类、参数）在 facts 里只读返回');
$check($detail['seo_title'] === 'Old SEO' && $detail['seo_description'] === '' && $detail['proposable'] === true && preg_match('/^[0-9a-f]{64}$/', $detail['base_revision']) === 1,
    'SEO 取自 metas，草稿可提案，带 base_revision');
$check($read(201)['proposable'] === false, '已发布产品标为不可提案');
$check($code(fn () => $read(203)) === 'ai_mcp_not_found', '回收站里的产品读不到');
$check($code(fn () => $tools->call('product_read', ['id' => 200, 'price' => 1], $producer)) === 'ai_mcp_invalid_arguments', '读取参数不接受未知键');

$scenario = 'policy';
$reset();
$base = $read(200)['base_revision'];
foreach (['title' => 'New name', 'model' => 'HP-999', 'specs' => '[]', 'price' => 1, 'status' => 1, 'category_id' => 2] as $field => $value) {
    $check($code(fn () => $propose('t05-policy-' . $field, [$item(200, ['summary' => 'x', $field => $value], $base)])) === 'ai_proposal_invalid_input', "白名单外字段 {$field} 整次拒绝");
}
$check($code(fn () => $propose('t05-policy-empty', [$item(200, [], $base)])) === 'ai_proposal_invalid_input', '没有任何可写字段拒绝');
$check($code(fn () => $propose('t05-policy-dup1', [$item(200, ['summary' => 'a'], $base), $item(200, ['seo_title' => 'b'], $base)])) === 'ai_proposal_invalid_input', '同一产品出现两次拒绝');
$check($code(fn () => $propose('t05-policy-long', [$item(200, ['seo_description' => str_repeat('长', 501)], $base)])) === 'ai_proposal_invalid_input', 'SEO 描述超长拒绝');
$check($code(fn () => $propose('t05-policy-pub', [$item(201, ['summary' => 'x'])])) === 'ai_proposal_invalid_target', '已发布产品不能提案');
$check($code(fn () => $propose('t05-policy-del', [$item(203, ['summary' => 'x'], $base)])) === 'ai_proposal_invalid_target', '回收站里的产品不能提案');
$check($code(fn () => $propose('t05-policy-lang', [$item(200, ['summary' => 'x'], $base, 'en')])) === 'ai_proposal_invalid_target', '语言与产品不一致拒绝');
$check($code(fn () => $propose('t05-policy-stale', [$item(200, ['summary' => 'x'], str_repeat('a', 64))])) === 'ai_proposal_stale_revision', '旧版本 base_revision 拒绝');
$check($code(fn () => $propose('t05-policy-scope', [$item(200, ['summary' => 'x'], $base)], $readOnly)) === 'ai_proposal_source_denied', '令牌没有提案范围拒绝');
$check($code(fn () => $propose('t05-policy-perm', [$item(200, ['summary' => 'x'], $base)], $articleEditor)) === 'ai_proposal_source_denied', '账号没有 edit_product 拒绝');
$check($count('ai_proposals') === 0 && $count('ai_proposal_items') === 0, '被拒的提交一条都不入队');

$scenario = 'idempotency';
$reset();
$first = $propose('t05-idem-0001', [$item(200, ['summary' => 'AI summary'])]);
$again = $propose('t05-idem-0001', [$item(200, ['summary' => 'AI summary'])]);
$check($first['replayed'] === false && $again['replayed'] === true && $again['proposal_id'] === $first['proposal_id'], '同键同载荷返回原结果');
$check($code(fn () => $propose('t05-idem-0001', [$item(200, ['summary' => 'Different'])])) === 'ai_proposal_idempotency_conflict', '同键不同载荷拒绝');
$check($count('ai_proposal_items') === 1 && $row(200)['summary'] === 'Old summary', '提案只入队，不动产品');

$scenario = 'apply';
$reset();
$before = $row(200);
$z = $propose('t05-apply-0001', [$item(200, ['summary' => '<b>AI</b> summary', 'content' => '<p>AI body</p><script>alert(1)</script>', 'seo_title' => 'AI SEO', 'seo_description' => 'AI description'])]);
$view = (new AiProposalReview($db))->sets(10, true)[0]['items'][0] ?? [];
$check(($view['stale'] ?? true) === false && count($view['fields'] ?? []) === 3 && ($view['content']['changed'] ?? false) === true, '审核视图：三个文本字段对照 + 详情段落对照');
$applied = $service->apply(10, (string) $z['items'][0]['item_id'], $now);
$after = $row(200);
$check($applied['status'] === 'applied' && $applied['content_id'] === 0, '应用成功（结果不是文章，content_id 为 0）');
$check($after['summary'] === 'AI summary' && str_contains((string) $after['content'], 'AI body') && !str_contains((string) $after['content'], 'script'), '摘要去标签、详情净化后写入');
$check($meta(200, 'seo_title') === 'AI SEO' && $meta(200, 'seo_description') === 'AI description', 'SEO 标题更新、SEO 描述新建');
foreach (['title', 'model', 'specs', 'price', 'market_price', 'status', 'category_id', 'lang'] as $field) {
    $check((string) $after[$field] === (string) $before[$field], "事实字段 {$field} 不变");
}
$log = $db->fetchOne("SELECT * FROM {$p}ai_change_log WHERE id = ?", [(int) $applied['log_id']]);
$check(($log['target_type'] ?? '') === 'product' && (int) $log['target_id'] === 200 && ($log['ability'] ?? '') === 'product_content_propose', '变更日志记产品目标');
$check($service->apply(10, (string) $z['items'][0]['item_id'], $now)['replayed'] === true, '重复应用返回原结果');
$check($code(fn () => $service->apply(11, (string) $z['items'][0]['item_id'], $now)) === 'ai_proposal_not_found', '别人不能应用（与不存在同样处理）');

$scenario = 'conflicts';
$reset();
$cases = [
    'human_edit' => [static fn () => $db->execute("UPDATE {$p}products SET summary = 'Human', updated_at = updated_at + 5 WHERE id = 200"), 'product_changed'],
    'seo_edit' => [static fn () => $db->execute("UPDATE {$p}metas SET meta_value = 'Human SEO' WHERE owner_id = 200 AND meta_key = 'seo_title'"), 'product_changed'],
    'published' => [static fn () => $db->execute("UPDATE {$p}products SET status = 1 WHERE id = 200"), 'product_published'],
    'deleted' => [static fn () => $db->execute("UPDATE {$p}products SET deleted_at = ? WHERE id = 200", [$now]), 'product_deleted'],
];
foreach ($cases as $name => [$change, $reason]) {
    $reset();
    $z = $propose('t05-conflict-' . $name, [$item(200, ['summary' => 'AI', 'seo_title' => 'AI SEO'])]);
    $change();
    $snapshot = [$row(200), $meta(200, 'seo_title')];
    $result = $service->apply(10, (string) $z['items'][0]['item_id'], $now);
    $check($result['status'] === 'conflicted' && $result['reason'] === $reason && [$row(200), $meta(200, 'seo_title')] === $snapshot, "{$name}：判冲突（{$reason}），一个字段都不写");
}

$scenario = 'authorization';
$reset();
$z = $propose('t05-auth-revoke', [$item(200, ['summary' => 'AI'])]);
$db->execute("UPDATE {$p}api_tokens SET revoked_at = ? WHERE id = 51", [$now]);
$result = $service->apply(10, (string) $z['items'][0]['item_id'], $now);
$check($result['status'] === 'cancelled' && $result['reason'] === 'source_token_revoked' && $row(200)['summary'] === 'Old summary', '令牌撤销后应用：终止，不写');
$db->execute("UPDATE {$p}api_tokens SET revoked_at = 0 WHERE id = 51");
$z = $propose('t05-auth-perm', [$item(200, ['summary' => 'AI'])]);
$db->execute("UPDATE {$p}roles SET permissions = '[\"ai_access\"]' WHERE id = 5");
$result = $service->apply(10, (string) $z['items'][0]['item_id'], $now);
$check($result['status'] === 'pending' && $result['reason'] === 'owner_permission_removed' && $row(200)['summary'] === 'Old summary', '账号失去 edit_product：保持待审并记原因，不写');
$db->execute("UPDATE {$p}roles SET permissions = '[\"ai_access\",\"edit_product\"]' WHERE id = 5");
$check($service->apply(10, (string) $z['items'][0]['item_id'], $now)['status'] === 'applied', '权限恢复后可以应用');
$reset();
$z = $propose('t05-auth-scope', [$item(200, ['summary' => 'AI'])]);
$db->execute("UPDATE {$p}api_tokens SET scopes = ? WHERE id = 51", [json_encode(['product_read'])]);
$result = $service->apply(10, (string) $z['items'][0]['item_id'], $now);
$check($result['status'] === 'cancelled' && $result['reason'] === 'source_token_scope_removed', '令牌收回提案范围：终止');
$db->execute("UPDATE {$p}api_tokens SET scopes = ? WHERE id = 51", [json_encode($tokens[51][1])]);

$scenario = 'undo';
$reset();
$z = $propose('t05-undo-0001', [$item(200, ['summary' => 'AI', 'seo_title' => 'AI SEO', 'seo_keywords' => 'pump, hydraulic'])]);
$applied = $service->apply(10, (string) $z['items'][0]['item_id'], $now);
$undone = $service->undo(10, (int) $applied['log_id'], $now);
$check($undone['status'] === 'reverted' && $row(200)['summary'] === 'Old summary' && $meta(200, 'seo_title') === 'Old SEO' && $meta(200, 'seo_keywords') === '', '撤销写回改前值（新建的 SEO 关键词回到空）');
$check($db->fetchColumn("SELECT status FROM {$p}ai_proposal_items WHERE public_id = ?", [(string) $z['items'][0]['item_id']]) === 'reverted', '提案项标为已撤销');
$check($service->undo(10, (int) $applied['log_id'], $now)['replayed'] === true, '重复撤销返回原结果');
$reset();
$z = $propose('t05-undo-0002', [$item(200, ['summary' => 'AI'])]);
$applied = $service->apply(10, (string) $z['items'][0]['item_id'], $now);
$db->execute("UPDATE {$p}products SET summary = 'Human after AI', updated_at = updated_at + 5 WHERE id = 200");
$check($code(fn () => $service->undo(10, (int) $applied['log_id'], $now)) === 'ai_proposal_undo_conflict' && $row(200)['summary'] === 'Human after AI', '应用后有人工编辑：撤销冲突，不覆盖');
$reset();
$z = $propose('t05-undo-0003', [$item(200, ['summary' => 'AI'])]);
$applied = $service->apply(10, (string) $z['items'][0]['item_id'], $now);
$db->execute("UPDATE {$p}roles SET permissions = '[\"ai_access\"]' WHERE id = 5");
$check($code(fn () => $service->undo(10, (int) $applied['log_id'], $now)) === 'ai_proposal_actor_denied' && $row(200)['summary'] === 'AI', '撤销时已没有 edit_product：拒绝');
$db->execute("UPDATE {$p}roles SET permissions = '[\"ai_access\",\"edit_product\"]' WHERE id = 5");

$total = 0;
$failed = 0;
foreach ($results as $lines) {
    foreach ($lines as $line) {
        $total++;
        if (str_starts_with($line, 'FAIL')) $failed++;
    }
}
echo json_encode(['driver' => DB_DRIVER, 'php' => PHP_VERSION, 'assertions' => $total, 'failed' => $failed, 'scenarios' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed === 0 ? 0 : 1);
