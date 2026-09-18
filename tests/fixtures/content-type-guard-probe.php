<?php
/**
 * requireContentRowsOfType() 的行为探针（独立进程运行）。
 *
 * permissions.php 依赖 hasPermission / requirePermission / error 等一串后台运行时函数，
 * 在主测试进程里它们可能已被别的用例以不同语义加载。这里在干净进程中装最小桩，
 * 把守卫的判定结果以 JSON 打印出来，由 ContentTypeGuardTest 断言。
 *
 * 用法：php content-type-guard-probe.php edit_article,delete_article
 *（逗号分隔；Windows 的 escapeshellarg 会吃掉 JSON 里的引号，所以不用 JSON 传参）
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));
if (!defined('DB_DRIVER'))  define('DB_DRIVER', 'sqlite');
if (!defined('DB_PATH'))    define('DB_PATH', ':memory:');
if (!defined('DB_PREFIX'))  define('DB_PREFIX', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('DEBUG'))      define('DEBUG', false);

require_once ROOT_PATH . '/config/database.php';

/** 被测账号持有的权限键 */
$GLOBALS['probe_permissions'] = array_values(array_filter(array_map('trim', explode(',', (string) ($argv[1] ?? '')))));

/** 守卫拒绝时抛出的信号（生产里是 403 JSON 或提示页后 exit） */
final class ProbeDenied extends RuntimeException
{
}

function hasPermission(string $permission): bool
{
    $held = $GLOBALS['probe_permissions'];
    return in_array('*', $held, true) || in_array($permission, $held, true);
}

function requirePermission(string $permission): void
{
    if (!hasPermission($permission)) {
        throw new ProbeDenied('missing:' . $permission);
    }
}

function isAjax(): bool
{
    return true;
}

function error(string $message, int $code = 400): void
{
    throw new ProbeDenied($message . ':' . $code);
}

function __(string $key, array $params = []): string
{
    return $key;
}

function e(?string $value): string
{
    return (string) $value;
}

/** permissions.php 只用到 find()，这里给个最小模型桩（与 Model::find 一样过滤软删行） */
function contentModel(): object
{
    return new class {
        public function find(int $id): ?array
        {
            return db()->fetchOne('SELECT * FROM contents WHERE id = ? AND deleted_at IS NULL', [$id]);
        }
    };
}

/** 自定义内容模型登记表的桩：只有 faq 是登记过的 */
function contentModelModel(): object
{
    return new class {
        /** @return list<string> */
        public function keys(): array
        {
            return ['faq'];
        }
    };
}

require_once ROOT_PATH . '/includes/permissions.php';

$pdo = db()->getPdo();
$pdo->exec('CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, title TEXT, status INTEGER DEFAULT 1, deleted_at INTEGER NULL)');
$pdo->exec("INSERT INTO contents (id, type, title) VALUES (1, 'article', 'A1'), (2, 'article', 'A2'), (3, 'case', 'C1'), (4, 'page', 'P1')");
// 回收站里的文章：守卫必须当它不存在，而不是放行后让 find() 返回 null
$pdo->exec("INSERT INTO contents (id, type, title, deleted_at) VALUES (5, 'article', 'A3 已删', 1789000000)");

/** @param callable():mixed $probe */
function outcome(callable $probe): array
{
    try {
        return ['ok' => true, 'value' => $probe()];
    } catch (ProbeDenied $e) {
        return ['ok' => false, 'denied' => $e->getMessage()];
    }
}

echo json_encode([
    'own_type'        => outcome(fn () => requireContentRowsOfType([1, 2], 'article')),
    'duplicate_ids'   => outcome(fn () => requireContentRowsOfType([1, 1, '1'], 'article')),
    'mixed_batch'     => outcome(fn () => requireContentRowsOfType([1, 3], 'article')),
    'other_type'      => outcome(fn () => requireContentRowsOfType([3], 'article')),
    'page_type'       => outcome(fn () => requireContentRowsOfType([4], 'article')),
    'missing_id'      => outcome(fn () => requireContentRowsOfType([99], 'article')),
    'empty_batch'     => outcome(fn () => requireContentRowsOfType([], 'article')),
    'zero_and_junk'   => outcome(fn () => requireContentRowsOfType([0, '', 'abc'], 'article')),
    'delete_mode'     => outcome(fn () => requireContentRowsOfType([1], 'article', 'delete')),
    'row_own_type'    => outcome(fn () => requireContentRowOfType(1, 'article')['title'] ?? null),
    'row_other_type'  => outcome(fn () => requireContentRowOfType(3, 'article')['title'] ?? null),
    'row_missing'     => outcome(fn () => requireContentRowOfType(99, 'article')['title'] ?? null),
    'case_entry'      => outcome(fn () => requireContentRowsOfType([3], 'case')),

    // 回收站里的行：批量跳过（幂等），单条给受控错误而不是 TypeError（复审 R06）
    'trashed_batch'   => outcome(fn () => requireContentRowsOfType([1, 5], 'article')),
    'trashed_row'     => outcome(fn () => requireContentRowOfType(5, 'article')['title'] ?? null),
    'trashed_delete'  => outcome(fn () => requireContentRowsOfType([5], 'article', 'delete')),

    // 翻译创建：按源记录的真实类型判权（复审 R01）
    'tr_article'      => outcome(fn () => requireTranslationPermission('contents', ['type' => 'article'], 'article') ?? 'allowed'),
    'tr_case_bound'   => outcome(fn () => requireTranslationPermission('contents', ['type' => 'case'], 'article') ?? 'allowed'),
    'tr_case_shared'  => outcome(fn () => requireTranslationPermission('contents', ['type' => 'case'], '') ?? 'allowed'),
    'tr_page_shared'  => outcome(fn () => requireTranslationPermission('contents', ['type' => 'page'], '') ?? 'allowed'),
    'tr_products'     => outcome(fn () => requireTranslationPermission('products', ['id' => 1], '') ?? 'allowed'),
    'tr_channels'     => outcome(fn () => requireTranslationPermission('channels', ['id' => 1], '') ?? 'allowed'),
    'tr_unknown_tbl'  => outcome(fn () => requireTranslationPermission('widgets', ['id' => 1], '') ?? 'allowed'),

    // 类型目录：任意字符串不再当成"自定义模型"放行（复审 R02）
    'type_builtin'    => outcome(fn () => isRegisteredContentType('article')),
    'type_custom'     => outcome(fn () => isRegisteredContentType('faq')),
    'type_injection'  => outcome(fn () => isRegisteredContentType('<svg onload=x>')),
    'type_unknown'    => outcome(fn () => isRegisteredContentType('totally-made-up')),
    'perm_injection'  => outcome(fn () => requireContentEditPerm('<svg onload=x>') ?? 'allowed'),
    'perm_custom'     => outcome(fn () => requireContentEditPerm('faq') ?? 'allowed'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
