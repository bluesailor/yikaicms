<?php
/**
 * S5-03 单页内容提案的行为验证：直接调用 AiProposalService，在 s103-* 一次性合成库上跑
 * 权限、未知字段、缺失/重复 ID、版本冲突、幂等重试、故障回滚与整页原子性；S5-04 加安全撤销、
 * 受保护字段与跨进程并发（编辑器保存与应用争同一把栏目锁）。不起服务、不调用模型。
 *
 *   YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=<临时目录>/s103-page-proposals.sqlite php tests/ai-access/page-proposal-run.php
 *   （YK_S103_DRIVER=mysql 加 YK_S103_MYSQL_* 时在一次性 MySQL 库上跑同一套）
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
    $db->execute("DROP TABLE IF EXISTS {$p}blox_page_drafts");
    foreach (s103CoreTableSql($db->isSqlite(), 'blox_page_drafts') as $sql) $db->execute($sql);
    $db->execute("INSERT INTO {$p}roles (id, name, permissions, status, created_at) VALUES (4, 'PageEditor', '[\"ai_access\",\"blox_edit\",\"edit_page\"]', 1, 0)");
    $db->execute("INSERT INTO {$p}users (id, username, password, role_id, status, created_at, updated_at) VALUES (9, 's503-pager', 'x', 4, 1, 0, 0)");
    $tokens = [41 => [9, ['page_read', 'page_content_propose', 'proposal_status']], 42 => [9, ['page_read']], 43 => [7, ['page_content_propose']]];
    foreach ($tokens as $id => [$owner, $scopes]) {
        $db->execute("INSERT INTO {$p}api_tokens (id, admin_id, token_hash, scopes, expires_at, revoked_at) VALUES (?, ?, ?, ?, ?, 0)",
            [$id, $owner, hash('sha256', 's503-token-' . $id), json_encode($scopes), $now + 86400]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'environment error: ' . $e->getMessage() . "\n");
    exit(2);
}

$pager = new AiAccessAuthContext(9, 41, ['ai_access', 'blox_edit', 'edit_page'], $tokens[41][1]);
$noScope = new AiAccessAuthContext(9, 42, ['ai_access', 'blox_edit', 'edit_page'], $tokens[42][1]);
$articleEditor = new AiAccessAuthContext(7, 43, ['ai_access', 'edit_article'], $tokens[43][1]);
$service = new AiProposalService($db);

$elements = [
    ['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'Old title', 'level' => 'h2', 'color' => '#ff0000', '_conditions' => [['field' => 'x']],
        '_interactions' => [['trigger' => 'click', 'action' => 'toggle']], '_custom_css' => '.h{color:blue}', 'loop_field' => 'title']],
    ['id' => 't1', 'type' => 'text', 'data' => ['html' => '<p>Old body</p>', 'typography_role' => 'body']],
    ['id' => 'i1', 'type' => 'image', 'data' => ['src' => '/uploads/a.jpg', 'alt' => 'Old alt', 'click_action' => 'none', '_custom_attributes' => [['name' => 'data-x', 'value' => '1']]]],
    ['id' => 'code1', 'type' => 'code', 'data' => ['code' => '<script>keep()</script>']],
    ['id' => 'bound', 'type' => 'heading', 'data' => ['text' => 'Fallback', 'site_field' => 'site_name']],
    ['id' => 'dup', 'type' => 'heading', 'data' => ['text' => 'First']],
    ['id' => 'dup', 'type' => 'heading', 'data' => ['text' => 'Second']],
    ['type' => 'heading', 'data' => ['text' => 'No id']],
    ['id' => 'loop', 'type' => 'container', 'data' => ['_query' => ['source' => 'article'], 'children' => [['id' => 'tpl1', 'type' => 'heading', 'data' => ['text' => 'Template']]]]],
];
$doc = static fn(array $elements): string => json_encode(['schema' => 1, 'settings' => ['custom_css' => 'body{color:red}'],
    'sections' => [['id' => 's1', 'settings' => ['_custom_css' => '.x{}', 'padding' => '40px'], 'columns' => [['id' => 'c1', 'settings' => ['width' => 50], 'elements' => $elements]]]]],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
foreach ([[30, 'zh-CN', 1, ''], [31, 'zh-CN', 1, '<p>Legacy</p>'], [32, 'zh-CN', 0, ''], [33, 'en', 1, ''], [34, 'zh-CN', 1, '']] as [$id, $lang, $status, $content]) {
    $db->execute("INSERT INTO {$p}channels (id, lang, name, slug, type, status, content, created_at, updated_at) VALUES (?, ?, ?, ?, 'page', ?, ?, 0, 0)",
        [$id, $lang, 'S503 page ' . $id, 's503-' . $id, $status, $content]);
}
foreach ([30, 32, 33] as $id) $db->execute("INSERT INTO {$p}blox_page_drafts (page_id, draft_data) VALUES (?, ?)", [$id, $doc($elements)]);
// 30 与 34 都有已发布快照；34 没有草稿（从已发布文档开始提案）
foreach ([30, 34] as $id) {
    $db->execute("INSERT INTO {$p}contents (lang, channel_id, type, title, summary, content, tags, status, publish_time, created_at, updated_at, admin_id, content_type, blocks_data)"
        . " VALUES ('zh-CN', ?, 'article', 'S503 page', '', '<h2>Old title</h2>', '', 1, ?, ?, ?, 1, 'blocks', ?)", [$id, $now - 60, $now - 60, $now - 60, $doc($elements)]);
}

$source = new AiPageDocumentSource($db);
$revision = static fn(int $id): string => $source->load((array) $source->page($id))['base_revision'];
$draft = static fn(int $id): ?string => ($v = $db->fetchColumn("SELECT draft_data FROM {$p}blox_page_drafts WHERE page_id = ?", [$id])) === false || $v === null ? null : (string) $v;
$published = static fn(int $id): array => $db->fetchAll("SELECT * FROM {$p}contents WHERE channel_id = ? ORDER BY id", [$id]);
$channel = static fn(int $id): ?array => $db->fetchOne("SELECT * FROM {$p}channels WHERE id = ?", [$id]);
$count = static fn(string $table): int => (int) $db->fetchColumn("SELECT COUNT(*) FROM {$p}{$table}");
$item = static fn(string $public): ?array => $db->fetchOne("SELECT * FROM {$p}ai_proposal_items WHERE public_id = ?", [$public]);
$args = static fn(int $page, string $base, array $changes, string $lang = 'zh-CN'): array => ['page_id' => $page, 'lang' => $lang, 'base_revision' => $base, 'changes' => $changes];
$change = static fn(string $id, string $field, string $value): array => ['element_id' => $id, 'field' => $field, 'value' => $value];

$scenario = '01 提交：净化、幂等与不写页面';
$base30 = $revision(30);
$draftBefore = $draft(30);
$main = [$change('h1', 'text', 'New title'), $change('t1', 'html', '<p onclick="steal()">New <b>body</b><script>bad()</script></p>'), $change('i1', 'alt', "New\n alt")];
$a = $service->proposePageFromToken($pager, 's503-main-0001', $args(30, $base30, $main), $now);
$check(($a['replayed'] ?? null) === false && ($a['items'][0]['op'] ?? '') === 'page_edit' && ($a['items'][0]['status'] ?? '') === 'pending', '提交成功：一页一项，状态 pending');
$row = $item((string) $a['items'][0]['item_id']);
$input = json_decode((string) ($row['input_json'] ?? ''), true);
$check(!str_contains((string) $row['input_json'], 'script') && !str_contains((string) $row['input_json'], 'onclick') && ($input['changes'][2]['value'] ?? '') === 'New alt',
    '入库前已净化：富文本去脚本与事件属性，替代文字折叠空白');
$check((int) $row['target_id'] === 30 && $row['base_revision'] === $base30 && $row['contract_version'] === 'page_content.v1', '提案项记录页面编号、基线修订号与契约版本');
$check($draft(30) === $draftBefore, '提交不改页面草稿');
$b = $service->proposePageFromToken($pager, 's503-main-0001', $args(30, $base30, $main), $now);
$check(($b['replayed'] ?? null) === true && $b['proposal_id'] === $a['proposal_id'] && $b['items'][0]['item_id'] === $a['items'][0]['item_id'], '同键同载荷重试：回放同一提案');
$check($code(fn() => $service->proposePageFromToken($pager, 's503-main-0001', $args(30, $base30, [$change('h1', 'text', 'Other')]), $now)) === 'ai_proposal_idempotency_conflict', '同键不同载荷：幂等冲突');
$check($code(fn() => $service->proposePageFromToken($pager, 's503-art-0001', $args(30, $base30, $main), $now)) === 'none', '不同幂等键可再交一份（各自独立审核）');

$scenario = '02 权限与范围';
$proposals = $count('ai_proposals');
$check($code(fn() => $service->proposePageFromToken($noScope, 's503-perm-0001', $args(30, $base30, $main), $now)) === 'ai_proposal_source_denied', '令牌没有 page_content_propose 范围：拒绝');
$check($code(fn() => $service->proposePageFromToken($articleEditor, 's503-perm-0002', $args(30, $base30, $main), $now)) === 'ai_proposal_source_denied', '账号只有文章权限（无 blox_edit/edit_page）：拒绝');
$db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 4", ['["ai_access","edit_page","blox_content_only"]']);
$check($code(fn() => $service->proposePageFromToken($pager, 's503-perm-0003', $args(30, $base30, $main), $now)) === 'ai_proposal_source_denied', '只有 blox_content_only 不算授权：拒绝');
$db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 4", ['["ai_access","blox_edit","edit_page"]']);
$check($count('ai_proposals') === $proposals, '被拒的提交不留提案记录');

$scenario = '03 未知字段与非法值';
$invalid = [
    '链接字段（推迟）' => $args(30, $base30, [$change('h1', 'url', '/x')]),
    '图片地址（推迟）' => $args(30, $base30, [$change('i1', 'src', '/b.jpg')]),
    '变更项多出样式键' => $args(30, $base30, [$change('h1', 'text', 'x') + ['color' => '#000']]),
    '顶层多出键' => $args(30, $base30, [$change('h1', 'text', 'x')]) + ['publish' => true],
    '动态标签' => $args(30, $base30, [$change('h1', 'text', 'Hi {{site.name}}')]),
    '服务端标签' => $args(30, $base30, [$change('t1', 'html', '<p>{yk:field name=x /}</p>')]),
    '同一元素同一字段两次' => $args(30, $base30, [$change('h1', 'text', 'a'), $change('h1', 'text', 'b')]),
    '修订号格式不对' => $args(30, 'html-' . str_repeat('a', 59), [$change('h1', 'text', 'x')]),
    '元素 ID 格式不对' => $args(30, $base30, [$change('bad id!', 'text', 'x')]),
    '超过 50 项' => $args(30, $base30, array_map(static fn(int $i): array => ['element_id' => 'e' . $i, 'field' => 'text', 'value' => 'x'], range(1, 51))),
];
foreach ($invalid as $label => $case) {
    $check($code(fn() => $service->proposePageFromToken($pager, 's503-inv-' . substr(md5($label), 0, 10), $case, $now)) === 'ai_proposal_invalid_input', $label . '：ai_proposal_invalid_input');
}

$scenario = '04 目标校验：ID、类型、绑定、页面状态';
$targets = [
    '不存在的元素' => $args(30, $base30, [$change('nope', 'text', 'x')]),
    '重复 ID' => $args(30, $base30, [$change('dup', 'text', 'x')]),
    '区块 ID 不是元素' => $args(30, $base30, [$change('s1', 'text', 'x')]),
    '循环模板内的元素' => $args(30, $base30, [$change('tpl1', 'text', 'x')]),
    '绑定站点数据的标题' => $args(30, $base30, [$change('bound', 'text', 'x')]),
    '字段与类型不符（标题改 alt）' => $args(30, $base30, [$change('h1', 'alt', 'x')]),
    '代码元素' => $args(30, $base30, [$change('code1', 'text', 'x')]),
    '标题超长' => $args(30, $base30, [$change('h1', 'text', str_repeat('长', 2001))]),
    '停用页面' => $args(32, $revision(32), [$change('h1', 'text', 'x')]),
    '纯 HTML 老页' => $args(31, str_repeat('a', 64), [$change('h1', 'text', 'x')]),
    '语言与页面不符' => $args(33, $revision(33), [$change('h1', 'text', 'x')], 'zh-CN'),
    '未启用的语言' => $args(30, $base30, [$change('h1', 'text', 'x')], 'fr'),
    '一项不合格整份拒绝' => $args(30, $base30, [$change('h1', 'text', 'ok'), $change('nope', 'text', 'x')]),
];
$proposals = $count('ai_proposals');
foreach ($targets as $label => $case) {
    $check($code(fn() => $service->proposePageFromToken($pager, 's503-tgt-' . substr(md5($label), 0, 10), $case, $now)) === 'ai_proposal_invalid_target', $label . '：ai_proposal_invalid_target');
}
$check($code(fn() => $service->proposePageFromToken($pager, 's503-stale-0001', $args(30, str_repeat('0', 64), [$change('h1', 'text', 'x')]), $now)) === 'ai_proposal_stale_revision', '基线不是当前修订号：ai_proposal_stale_revision');
$check($count('ai_proposals') === $proposals && $draft(30) === $draftBefore, '被拒的提交不留记录、不改页面');

$scenario = '05 应用：整页原子写入草稿，其余一律不变';
$publishedBefore = $published(30);
$channelBefore = $channel(30);
$view = (new AiProposalReview($db))->sets(9, true);
$pageView = null;
foreach ($view as $set) foreach ($set['items'] as $v) if ($v['item']['public_id'] === $a['items'][0]['item_id']) $pageView = $v;
$check($pageView !== null && count($pageView['fields']) === 3 && $pageView['fields'][0]['before'] === 'Old title' && $pageView['fields'][0]['after'] === 'New title'
    && $pageView['stale'] === false && str_starts_with((string) $pageView['fields'][1]['label'], 't1 · '), '审核视图：逐元素“当前 → 提案”，未过期');
$applied = $service->apply(9, (string) $a['items'][0]['item_id'], $now);
$check($applied['status'] === 'applied' && $applied['content_id'] === 0 && $applied['revision'] === $revision(30) && $applied['revision'] !== $base30, '应用成功：返回新修订号，结果不是文章编号');
$after = json_decode((string) $draft(30), true);
$original = json_decode($draftBefore, true);
$h = $after['sections'][0]['columns'][0]['elements'];
$check($h[0]['data']['text'] === 'New title' && $h[1]['data']['html'] === '<p>New <b>body</b></p>' && $h[2]['data']['alt'] === 'New alt', '三个字段按提案写入（富文本为净化后的值）');
$restored = $after;
$restored['sections'][0]['columns'][0]['elements'][0]['data']['text'] = 'Old title';
$restored['sections'][0]['columns'][0]['elements'][1]['data']['html'] = '<p>Old body</p>';
$restored['sections'][0]['columns'][0]['elements'][2]['data']['alt'] = 'Old alt';
$check($restored === $original, '其余全部不变：页面/区块/列设置、自定义 CSS、可见条件、交互、自定义属性、循环绑定、代码元素、站点绑定、重复与缺失 ID、循环模板');
$check($published(30) === $publishedBefore && $channel(30) === $channelBefore, '已发布快照（contents）与栏目行不变');
$log = $db->fetchOne("SELECT * FROM {$p}ai_change_log WHERE id = ?", [$applied['log_id']]);
$before = json_decode((string) ($log['before_json'] ?? ''), true);
$check(($log['target_type'] ?? '') === 'page' && (int) $log['target_id'] === 30 && $log['operation'] === 'page_edit' && $log['after_revision'] === $applied['revision']
    && ($before['h1']['text'] ?? '') === 'Old title' && ($before['t1']['html'] ?? '') === '<p>Old body</p>', '变更日志：页面目标、改前值与应用后修订号');
$again = $service->apply(9, (string) $a['items'][0]['item_id'], $now);
$check($again['status'] === 'applied' && $again['replayed'] === true && $again['log_id'] === $applied['log_id'], '重复应用：回放，不写第二次');

$scenario = '06 版本冲突：人工修改之后拒绝';
$base = $revision(30);
$x = $service->proposePageFromToken($pager, 's503-conf-0001', $args(30, $base, [$change('h1', 'text', 'AI title')]), $now);
$y = $service->proposePageFromToken($pager, 's503-conf-0002', $args(30, $base, [$change('i1', 'alt', 'AI alt')]), $now);
$human = json_decode((string) $draft(30), true);
$human['sections'][0]['columns'][0]['elements'][1]['data']['html'] = '<p>Human edit</p>';
$db->execute("UPDATE {$p}blox_page_drafts SET draft_data = ? WHERE page_id = 30", [json_encode($human, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$humanDraft = $draft(30);
$stale = null;
foreach ((new AiProposalReview($db))->sets(9, true) as $set) foreach ($set['items'] as $v) if ($v['item']['public_id'] === $x['items'][0]['item_id']) $stale = $v['stale'];
$check($stale === true, '审核视图提前提示：页面已变');
$r = $service->apply(9, (string) $x['items'][0]['item_id'], $now);
$check($r['status'] === 'conflicted' && $r['reason'] === 'page_changed' && $draft(30) === $humanDraft, '人工改过：应用判冲突 page_changed，人工内容原样保留');
$check($code(fn() => $service->proposePageFromToken($pager, 's503-conf-0003', $args(30, $base, [$change('h1', 'text', 'x')]), $now)) === 'ai_proposal_stale_revision', '用旧修订号再提交：拒绝');
$base = $revision(30);
$c1 = $service->proposePageFromToken($pager, 's503-race-0001', $args(30, $base, [$change('h1', 'text', 'First wins')]), $now);
$c2 = $service->proposePageFromToken($pager, 's503-race-0002', $args(30, $base, [$change('i1', 'alt', 'Second loses')]), $now);
$first = $service->apply(9, (string) $c1['items'][0]['item_id'], $now);
$second = $service->apply(9, (string) $c2['items'][0]['item_id'], $now);
$check($first['status'] === 'applied' && $second['status'] === 'conflicted' && $second['reason'] === 'page_changed', '同一基线的两份提案：先应用的成功，后一份冲突，不会悄悄合并');
$check(json_decode((string) $draft(30), true)['sections'][0]['columns'][0]['elements'][2]['data']['alt'] === 'New alt', '冲突的那份没有写入任何字段');
$service->reject(9, (string) $y['items'][0]['item_id'], $now);
$check(($item((string) $y['items'][0]['item_id'])['status'] ?? '') === 'rejected' && $draft(30) !== null, '待审项可拒绝，拒绝不碰页面');

$scenario = '07 故障回滚：每个检查点失败都整体不生效';
$base = $revision(30);
$fault = $service->proposePageFromToken($pager, 's503-fault-0001', $args(30, $base, [$change('h1', 'text', 'Fault title'), $change('i1', 'alt', 'Fault alt')]), $now);
$faultId = (string) $fault['items'][0]['item_id'];
$draftNow = $draft(30);
$logs = $count('ai_change_log');
foreach (['apply.locked', 'apply.page_written', 'apply.before_log', 'apply.before_commit'] as $point) {
    $broken = new AiProposalService($db, static function (string $at) use ($point): void {
        if ($at === $point) throw new RuntimeException('injected failure');
    });
    $err = $code(fn() => $broken->apply(9, $faultId, $now));
    $check($err === 'ai_proposal_unavailable' && $draft(30) === $draftNow && ($item($faultId)['status'] ?? '') === 'pending' && $count('ai_change_log') === $logs && !$db->getPdo()->inTransaction(),
        "{$point} 失败：草稿、提案状态与变更日志都回到应用前");
}
$ok = $service->apply(9, $faultId, $now);
$elementsNow = json_decode((string) $draft(30), true)['sections'][0]['columns'][0]['elements'];
$check($ok['status'] === 'applied' && $elementsNow[0]['data']['text'] === 'Fault title' && $elementsNow[2]['data']['alt'] === 'Fault alt', '故障排除后同一提案可正常应用（两项同时生效）');

$scenario = '08 来源失效与发布快照';
$base = $revision(30);
$perm = $service->proposePageFromToken($pager, 's503-src-0001', $args(30, $base, [$change('h1', 'text', 'After permission')]), $now);
$db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 4", ['["ai_access","edit_page"]']);
$draftNow = $draft(30);
$r = $service->apply(9, (string) $perm['items'][0]['item_id'], $now);
$check($r['status'] === 'pending' && $r['reason'] === 'owner_permission_removed' && $draft(30) === $draftNow, '账号失去 blox_edit：保持待审并记原因，页面不变');
$db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 4", ['["ai_access","blox_edit","edit_page"]']);
$db->execute("UPDATE {$p}api_tokens SET scopes = ? WHERE id = 41", ['["page_read","proposal_status"]']);
$r = $service->apply(9, (string) $perm['items'][0]['item_id'], $now);
$check($r['status'] === 'cancelled' && $r['reason'] === 'source_token_scope_removed' && $draft(30) === $draftNow, '令牌去掉提案范围：提案取消，页面不变');
$db->execute("UPDATE {$p}api_tokens SET scopes = ? WHERE id = 41", [json_encode($tokens[41][1])]);
$check($code(fn() => $service->apply(7, (string) $perm['items'][0]['item_id'], $now)) === 'ai_proposal_not_found', '别人的提案不能应用（与不存在相同）');
$pub34 = $published(34);
$base34 = $revision(34);
$np = $service->proposePageFromToken($pager, 's503-pub-0001', $args(34, $base34, [$change('h1', 'text', 'Draft from published')]), $now);
$r = $service->apply(9, (string) $np['items'][0]['item_id'], $now);
$new34 = json_decode((string) $draft(34), true);
$check($r['status'] === 'applied' && $new34['sections'][0]['columns'][0]['elements'][0]['data']['text'] === 'Draft from published' && $published(34) === $pub34 && $r['revision'] === $revision(34),
    '只有已发布版本的页面：新建草稿行，已发布快照不变');

$scenario = '09 安全撤销：只撤 AI 那一版，人工修改与已上线一律不覆盖';
$freshBase = $revision(30);
$freshDraft = $draft(30);
$pub30 = $published(30);
$u = $service->proposePageFromToken($pager, 's503-undo-0001', $args(30, $freshBase, [$change('h1', 'text', 'Undo me'), $change('i1', 'alt', 'Undo alt')]), $now);
$ua = $service->apply(9, (string) $u['items'][0]['item_id'], $now);
$logs = $count('ai_change_log');
foreach (['undo.locked', 'undo.page_written', 'undo.before_commit'] as $point) {
    $broken = new AiProposalService($db, static function (string $at) use ($point): void {
        if ($at === $point) throw new RuntimeException('injected failure');
    });
    $err = $code(fn() => $broken->undo(9, (int) $ua['log_id'], $now));
    $log = $db->fetchOne("SELECT undone FROM {$p}ai_change_log WHERE id = ?", [$ua['log_id']]);
    $check($err === 'ai_proposal_unavailable' && $revision(30) === $ua['revision'] && (int) $log['undone'] === 0 && ($item((string) $u['items'][0]['item_id'])['status'] ?? '') === 'applied' && !$db->getPdo()->inTransaction(),
        "撤销在 {$point} 失败：草稿、日志与提案状态都保持应用后的样子");
}
$db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 4", ['["ai_access","edit_page","edit_article"]']);
$check($code(fn() => $service->undo(9, (int) $ua['log_id'], $now)) === 'ai_proposal_actor_denied' && $revision(30) === $ua['revision'], '撤销人失去 blox_edit：拒绝，页面不变');
$db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 4", ['["ai_access","blox_edit","edit_page"]']);
$check($code(fn() => $service->undo(7, (int) $ua['log_id'], $now)) === 'ai_proposal_not_found', '别人的变更不能撤销');
$undone = $service->undo(9, (int) $ua['log_id'], $now);
$check($undone['status'] === 'reverted' && $draft(30) === $freshDraft && $revision(30) === $freshBase, '撤销成功：草稿逐字节回到应用前，修订号也回到应用前');
$check($published(30) === $pub30 && ($item((string) $u['items'][0]['item_id'])['status'] ?? '') === 'reverted' && $count('ai_change_log') === $logs, '已发布快照不变；提案项标为已撤销；不新增日志行');
$check(($service->undo(9, (int) $ua['log_id'], $now)['replayed'] ?? false) === true && $draft(30) === $freshDraft, '重复撤销：回放，不写第二次');

$v = $service->proposePageFromToken($pager, 's503-undo-0002', $args(30, $revision(30), [$change('h1', 'text', 'AI then human')]), $now);
$va = $service->apply(9, (string) $v['items'][0]['item_id'], $now);
$edited = json_decode((string) $draft(30), true);
$edited['sections'][0]['columns'][0]['elements'][1]['data']['html'] = '<p>Human after AI</p>';
$db->execute("UPDATE {$p}blox_page_drafts SET draft_data = ? WHERE page_id = 30", [json_encode($edited, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$humanDraft = $draft(30);
$check($code(fn() => $service->undo(9, (int) $va['log_id'], $now)) === 'ai_proposal_undo_conflict' && $draft(30) === $humanDraft, 'AI 之后有人工修改：撤销冲突，人工内容保留');

$w = $service->proposePageFromToken($pager, 's503-undo-0003', $args(30, $revision(30), [$change('h1', 'text', 'AI went live')]), $now);
$wa = $service->apply(9, (string) $w['items'][0]['item_id'], $now);
$db->execute("UPDATE {$p}contents SET blocks_data = ? WHERE channel_id = 30", [(string) $draft(30)]);
$liveDraft = $draft(30);
$check($code(fn() => $service->undo(9, (int) $wa['log_id'], $now)) === 'ai_proposal_undo_conflict' && $draft(30) === $liveDraft, 'AI 版本已发布：撤销冲突（只撤草稿会让后台与前台不一致）');
$db->execute("UPDATE {$p}contents SET blocks_data = ? WHERE channel_id = 30", [(string) $pub30[0]['blocks_data']]);

$x = $service->proposePageFromToken($pager, 's503-undo-0004', $args(30, $revision(30), [$change('i1', 'alt', 'Page deleted later')]), $now);
$xa = $service->apply(9, (string) $x['items'][0]['item_id'], $now);
$db->execute("UPDATE {$p}channels SET type = 'list' WHERE id = 30");
$check($code(fn() => $service->undo(9, (int) $xa['log_id'], $now)) === 'ai_proposal_undo_conflict', '页面已不是单页（删除或改类型）：撤销冲突');
$db->execute("UPDATE {$p}channels SET type = 'page' WHERE id = 30");
$articleLog = $db->fetchOne("SELECT id FROM {$p}ai_change_log WHERE target_type = 'page' ORDER BY id DESC LIMIT 1");
$db->execute("UPDATE {$p}ai_change_log SET target_type = 'widget' WHERE id = ?", [(int) $articleLog['id']]);
$check($code(fn() => $service->undo(9, (int) $articleLog['id'], $now)) === 'ai_proposal_undo_unsupported', '未知目标类型：拒绝，绝不按文章编号回写');
$db->execute("UPDATE {$p}ai_change_log SET target_type = 'page' WHERE id = ?", [(int) $articleLog['id']]);

$scenario = '10 私有预览：只在内存里算提案后的文档';
$review = new AiProposalReview($db);
$y = $service->proposePageFromToken($pager, 's503-prev-0001', $args(30, $revision(30), [$change('h1', 'text', 'Preview title')]), $now);
$draftNow = $draft(30);
$preview = $review->previewDocument(9, (string) $y['items'][0]['item_id']);
$pdoc = json_decode((string) ($preview['json'] ?? ''), true);
$check(($preview['page_id'] ?? 0) === 30 && ($pdoc['sections'][0]['columns'][0]['elements'][0]['data']['text'] ?? '') === 'Preview title', '预览文档 = 当前草稿 + 提案改动');
$check($draft(30) === $draftNow && ($item((string) $y['items'][0]['item_id'])['status'] ?? '') === 'pending', '预览不写库、不改提案状态');
$check($review->previewDocument(7, (string) $y['items'][0]['item_id']) === null, '别人的提案不能预览');
$applied = $review->previewDocument(9, (string) $a['items'][0]['item_id']);
$check($applied === null, '已处理的提案不再预览（看草稿请进构建器）');
$edited = json_decode((string) $draft(30), true);
$edited['sections'][0]['columns'][0]['elements'][2]['data']['alt'] = 'Human edit before preview';
$db->execute("UPDATE {$p}blox_page_drafts SET draft_data = ? WHERE page_id = 30", [json_encode($edited, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$check($review->previewDocument(9, (string) $y['items'][0]['item_id']) === null, '页面已变：不预览（应用也必然冲突）');

$scenario = '11 跨进程并发：编辑器保存与应用争同一把栏目锁';
$tmp = sys_get_temp_dir() . '/s504-' . bin2hex(random_bytes(4));
mkdir($tmp);
$parallel = static function (array $jobs) use ($tmp, $now): array {
    $procs = [];
    foreach ($jobs as $name => $job) {
        $job += ['now' => $now, 'signal' => $tmp . '/sig-' . $name];
        $jobs[$name] = $job;
        if (isset($job['after'])) {
            $deadline = microtime(true) + 20;
            while (!is_file($jobs[$job['after']]['signal']) && microtime(true) < $deadline) usleep(2000);
        }
        $procs[$name] = proc_open([PHP_BINARY, __DIR__ . '/proposal-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[$name . ':pipes'] = $pipes;
    }
    $out = [];
    foreach (array_keys($jobs) as $name) {
        $pipes = $procs[$name . ':pipes'];
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($procs[$name]);
        $decoded = json_decode(trim($stdout), true);
        $out[$name] = is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'worker_crash', 'stderr' => substr($stderr, 0, 300)];
    }
    return $out;
};
$editorJson = static function (string $alt) use ($draft): string {
    $d = json_decode((string) $draft(30), true);
    $d['sections'][0]['columns'][0]['elements'][2]['data']['alt'] = $alt;
    return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$base = $revision(30);
$z = $service->proposePageFromToken($pager, 's504-race-0001', $args(30, $base, [$change('h1', 'text', 'AI during editor save')]), $now);
$r = $parallel([
    'editor' => ['action' => 'editor_save_page', 'page' => 30, 'base' => $base, 'json' => $editorJson('Editor saved first'), 'hold_ms' => 1500],
    'ai' => ['action' => 'apply', 'admin' => 9, 'item' => (string) $z['items'][0]['item_id'], 'after' => 'editor'],
]);
$elements = json_decode((string) $draft(30), true)['sections'][0]['columns'][0]['elements'];
$check(($r['editor']['result']['committed'] ?? false) === true && ($r['ai']['result']['status'] ?? '') === 'conflicted' && ($r['ai']['result']['reason'] ?? '') === 'page_changed'
    && (float) ($r['ai']['finished'] ?? 0) >= (float) ($r['editor']['finished'] ?? INF) - 0.05,
    '编辑器持锁保存时应用：等锁，读到新修订号后判冲突（不覆盖）');
$check($elements[2]['data']['alt'] === 'Editor saved first' && $elements[0]['data']['text'] !== 'AI during editor save', '最终草稿是编辑器的版本，AI 字段未写入');

$base = $revision(30);
$z2 = $service->proposePageFromToken($pager, 's504-race-0002', $args(30, $base, [$change('h1', 'text', 'AI holds the lock')]), $now);
$r = $parallel([
    'ai' => ['action' => 'apply', 'admin' => 9, 'item' => (string) $z2['items'][0]['item_id'], 'hold_point' => 'apply.page_written', 'hold_ms' => 1500],
    'editor' => ['action' => 'editor_save_page', 'page' => 30, 'base' => $base, 'json' => $editorJson('Editor with stale base'), 'after' => 'ai'],
]);
$elements = json_decode((string) $draft(30), true)['sections'][0]['columns'][0]['elements'];
$check(($r['ai']['result']['status'] ?? '') === 'applied' && ($r['editor']['result']['conflict'] ?? false) === true
    && (float) ($r['editor']['finished'] ?? 0) >= (float) ($r['ai']['finished'] ?? INF) - 0.05,
    '应用持锁时编辑器保存：等锁，读到 AI 的新修订号后拒绝旧基线保存');
$check($elements[0]['data']['text'] === 'AI holds the lock' && $elements[2]['data']['alt'] !== 'Editor with stale base' && $revision(30) === ($r['ai']['result']['revision'] ?? ''),
    '最终草稿是 AI 应用的版本，修订号与应用结果一致');
foreach (glob($tmp . '/*') ?: [] as $f) unlink($f);
rmdir($tmp);

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
