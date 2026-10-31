<?php

/** CLI-only readiness audit. Production handlers run against synthetic, in-memory data. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Never accept configuration or a database inherited from a running application.
foreach (['ROOT_PATH', 'DB_DRIVER', 'DB_PATH', 'DB_HOST', 'DB_NAME'] as $constant) {
    if (defined($constant)) {
        fwrite(STDERR, "Run this audit in a fresh CLI process\n");
        exit(1);
    }
}
require dirname(__DIR__) . '/bootstrap.php';
if (DB_DRIVER !== 'sqlite' || DB_PATH !== ':memory:') {
    throw new RuntimeException('Audit requires an in-memory database');
}

final class AiAccessAuditProvider
{
    public static int $calls = 0;
    public static bool $fail = false;

    public function chat(mixed ...$arguments): array
    {
        self::$calls++;
        if (self::$fail) {
            return ['success' => false, 'content' => '', 'error' => 'Synthetic provider failure'];
        }
        return ['success' => true, 'content' => 'audit,solar,energy', 'error' => ''];
    }
}

/** Test identities only; the production authentication bootstrap is intentionally not loaded. */
function hasPermission(string $permission): bool
{
    $permissions = $GLOBALS['audit_permissions'] ?? [];
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function aiService(): AiAccessAuditProvider
{
    return new AiAccessAuditProvider();
}

function adminLog(mixed ...$arguments): void
{
    $GLOBALS['audit_admin_log_calls'] = (int) ($GLOBALS['audit_admin_log_calls'] ?? 0) + 1;
}

require ROOT_PATH . '/includes/permissions.php';
require ROOT_PATH . '/includes/Abilities.php';
require ROOT_PATH . '/includes/abilities/cms_basics.php';
require ROOT_PATH . '/includes/abilities/cms_admin.php';

function auditSeed(array $permissions = ['*']): void
{
    $_SESSION = ['admin_id' => 7]; // In-memory test identity, not a real PHP session.
    $GLOBALS['audit_permissions'] = $permissions;
    $GLOBALS['_test_config'] = ['site_name' => 'Audit Site', 'site_lang' => 'zh-CN'];
    AiAccessAuditProvider::$calls = 0;
    AiAccessAuditProvider::$fail = false;
    foreach (['contents', 'channels', 'settings'] as $table) {
        db()->execute('DROP TABLE IF EXISTS ' . $table);
    }
    db()->execute("CREATE TABLE channels (
        id INTEGER PRIMARY KEY, parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT,
        type TEXT DEFAULT 'article', status INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0,
        lang TEXT DEFAULT 'zh-CN')");
    db()->execute("CREATE TABLE contents (
        id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER, type TEXT DEFAULT 'article',
        title TEXT, slug TEXT DEFAULT '', summary TEXT DEFAULT '', content TEXT DEFAULT '',
        tags TEXT DEFAULT '', status INTEGER DEFAULT 0, publish_time INTEGER DEFAULT 0,
        created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0, deleted_at INTEGER,
        is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
        views INTEGER DEFAULT 0, lang TEXT DEFAULT 'zh-CN')");
    db()->execute("CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT,
        `key` TEXT UNIQUE, `value` TEXT, `group` TEXT DEFAULT 'basic', name TEXT DEFAULT '',
        tip TEXT DEFAULT '', sort_order INTEGER DEFAULT 0)");
    db()->execute("INSERT INTO settings (`key`, `value`) VALUES ('site_name', 'Audit Site')");
    db()->execute("INSERT INTO channels (id,name,slug,status) VALUES (1,'Audit news','audit-news',1),(2,'Hidden channel','hidden',0)");
    foreach ([
        [1, 'article', 1, null, 'zh-CN'],
        [2, 'article', 0, null, 'zh-CN'],
        [3, 'case', 0, null, 'zh-CN'],
        [4, 'article', 0, 100, 'zh-CN'],
        [5, 'article', 0, null, 'ja'],
    ] as [$id, $type, $status, $deleted, $lang]) {
        db()->execute('INSERT INTO contents (id,channel_id,type,title,summary,content,tags,status,deleted_at,lang) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$id, 1, $type, 'Audit item ' . $id, 'Old summary', '<p>Audit body</p>', 'old', $status, $deleted, $lang]);
    }
    settingModel()->clearCache();
}

function auditSnapshot(): array
{
    $out = [];
    foreach (['contents', 'channels', 'settings'] as $table) {
        $out[$table] = db()->fetchAll('SELECT * FROM ' . $table . ' ORDER BY id');
    }
    return $out;
}

function auditChanged(array $before, array $after): array
{
    return array_values(array_filter(array_keys($before), static fn(string $table): bool => $before[$table] !== $after[$table]));
}

function auditRejected(array $result, string $expectedErrorPrefix): bool
{
    return !$result['success'] && str_starts_with((string) ($result['error'] ?? ''), $expectedErrorPrefix);
}

$cases = [
    'cms_search_content' => ['keyword' => 'Audit'],
    'cms_list_drafts' => [],
    'cms_get_content' => ['id' => 2],
    'cms_publish_content' => ['id' => 2],
    'cms_create_article_draft' => ['title' => 'Audit new draft', 'channel_id' => 1, 'content' => '<p>New draft</p>'],
    'cms_generate_seo_summary' => ['id' => 1],
    'cms_auto_tag_content' => ['id' => 1],
    'cms_translate_text' => ['text' => 'Audit text', 'target_lang' => 'en'],
    'cms_navigate_admin' => ['query' => 'LOGO'],
    'cms_list_common_settings' => [],
    'cms_get_setting' => ['key' => 'site_name'],
    'cms_update_setting' => ['key' => 'site_name', 'value' => 'Changed audit site'],
    'cms_list_channels' => [],
    'cms_set_content_flags' => ['id' => 1, 'is_top' => 1],
];
$report = [
    'schema' => 1,
    'database' => 'sqlite::memory:',
    'provider' => 'stub; no network calls',
    'production_authentication' => 'not loaded; synthetic role permissions',
    'coverage_limits' => 'Core handlers only; no plugins, hooks, real provider, MySQL, HTTP or token authentication. Not an external-access certification.',
    'external_access_ready' => false,
    'abilities' => [], 'findings' => [], 'execution_errors' => [],
];
foreach (Abilities::catalog() as $ability) {
    $name = $ability['name'];
    if (!array_key_exists($name, $cases)) {
        $report['execution_errors'][] = 'No audit case for registered ability: ' . $name;
        continue;
    }
    auditSeed();
    $before = auditSnapshot();
    $preview = Abilities::previewChange($name, $cases[$name]);
    $afterPreview = auditSnapshot();
    $previewChanged = auditChanged($before, $afterPreview);
    $previewCalls = AiAccessAuditProvider::$calls;
    $input = $preview['preview']['input'] ?? $cases[$name];
    $result = Abilities::execute($name, $input);
    $changed = auditChanged($afterPreview, auditSnapshot());
    if (!$preview['success'] || !$result['success']) {
        $report['execution_errors'][] = ['ability' => $name, 'preview' => $preview['error'] ?? null, 'execute' => $result['error'] ?? null];
    }
    $report['abilities'][] = $ability + [
        'preview_changed_tables' => $previewChanged,
        'execute_changed_tables' => $changed,
        'preview_provider_calls' => $previewCalls,
        'execute_provider_calls' => AiAccessAuditProvider::$calls - $previewCalls,
        'result_success' => $result['success'],
    ];
    if ($previewChanged !== []) {
        $report['findings'][] = ['id' => 'preview_writes', 'ability' => $name];
    }
    if ($ability['tier'] === 'read' && $changed !== []) {
        $report['findings'][] = ['id' => 'read_classified_business_write', 'ability' => $name];
    }
}

// Negative probes report acceptance, not a passing security guarantee.
$probes = [];
auditSeed(['edit_product']);
$result = Abilities::execute('cms_get_content', ['id' => 2]);
$probes['product_editor_reads_article_draft'] = $result['success'] && ($result['output']['status'] ?? null) === 0;
auditSeed(['edit_article']);
$result = Abilities::execute('cms_get_content', ['id' => 4]);
$probes['detail_reads_soft_deleted_content'] = $result['success'];
$result = Abilities::execute('cms_list_drafts', []);
$ids = array_column($result['output'] ?? [], 'id');
$probes['draft_list_includes_other_type'] = in_array(3, $ids, true);
$probes['draft_list_includes_soft_deleted_content'] = in_array(4, $ids, true);
$probes['draft_list_has_no_language_scope'] = in_array(5, $ids, true);
$result = Abilities::execute('cms_list_channels', []);
$probes['channel_list_includes_disabled_channel'] = in_array(2, array_column($result['output'] ?? [], 'id'), true);
$schema = ['type' => 'object', 'additionalProperties' => false,
    'properties' => ['name' => ['type' => 'string', 'maxLength' => 3], 'count' => ['type' => 'integer', 'minimum' => 1]]];
$validator = new ReflectionMethod(Abilities::class, 'validateAgainstSchema');
foreach ([
    'schema_accepts_unknown_property' => ['unexpected' => true],
    'schema_accepts_long_string' => ['name' => 'too long'],
    'schema_accepts_negative_count' => ['count' => -10],
] as $probe => $input) {
    $probes[$probe] = $validator->invoke(null, $input, $schema) === [];
}
auditSeed(['edit_article']);
$result = Abilities::execute('cms_create_article_draft', ['title' => 'Audit unchecked channel', 'channel_id' => 999, 'content' => '<p>Audit</p>']);
$probes['create_draft_accepts_missing_channel_in_fixture'] = $result['success'];
foreach ($probes as $id => $observed) {
    if ($observed) $report['findings'][] = ['id' => $id];
}
$report['negative_probes'] = $probes;

// Controls must reject or preserve data as specified. A failed control invalidates the audit.
$controls = [];
auditSeed();
$_SESSION = [];
$before = auditSnapshot();
$controls['anonymous_execute_denied'] = auditRejected(Abilities::execute('cms_generate_seo_summary', ['id' => 1]), 'Permission denied');
$controls['anonymous_preview_denied'] = auditRejected(Abilities::previewChange('cms_auto_tag_content', ['id' => 1]), 'Permission denied');
$controls['anonymous_has_no_side_effects'] = auditSnapshot() === $before && AiAccessAuditProvider::$calls === 0;

auditSeed([]);
$controls['no_content_permission_denied'] = auditRejected(Abilities::execute('cms_get_content', ['id' => 2]), 'Permission denied');
auditSeed(['edit_product']);
$before = auditSnapshot();
$controls['wrong_module_write_denied'] = auditRejected(Abilities::execute('cms_generate_seo_summary', ['id' => 1]), 'Permission denied');
$controls['wrong_module_write_has_no_side_effects'] = auditSnapshot() === $before && AiAccessAuditProvider::$calls === 0;

auditSeed();
$controls['schema_rejects_string_id'] = auditRejected(Abilities::execute('cms_get_content', ['id' => '2']), 'Input invalid:');
$controls['schema_rejects_missing_id'] = auditRejected(Abilities::execute('cms_get_content', []), 'Input invalid:');
$controls['schema_rejects_invalid_enum'] = auditRejected(Abilities::execute('cms_translate_text', ['text' => 'Audit', 'target_lang' => 'invalid']), 'Input invalid:');
$controls['unknown_ability_denied'] = auditRejected(Abilities::execute('audit_nonexistent', []), 'Unknown ability:');
$controls['schema_failures_have_no_provider_calls'] = AiAccessAuditProvider::$calls === 0;

auditSeed();
AiAccessAuditProvider::$fail = true;
$before = auditSnapshot();
$result = Abilities::previewChange('cms_generate_seo_summary', ['id' => 1]);
$controls['provider_failure_propagated'] = auditRejected($result, 'Synthetic provider failure') && AiAccessAuditProvider::$calls === 1;
$controls['provider_failure_does_not_write'] = auditSnapshot() === $before;
$controls['no_php_session_started'] = session_status() === PHP_SESSION_NONE;
foreach ($controls as $id => $passed) {
    if (!$passed) $report['execution_errors'][] = 'Audit control failed: ' . $id;
}
$report['controls'] = $controls;
$report['audit_complete'] = $report['execution_errors'] === [] && count($report['abilities']) === count($cases);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
exit(!$report['audit_complete'] ? 1 : ($report['findings'] === [] ? 0 : 2));
