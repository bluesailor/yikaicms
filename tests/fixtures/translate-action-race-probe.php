<?php
/**
 * 后台「AI 翻译 / 重新翻译」写库一步的并发探针（独立进程、完整表结构）。
 *
 * 按 translate_action.php 的顺序：先读源与目标（调模型之前），再执行场景里「另一位管理员的操作」
 * ——生产里它发生在模型请求进行中——最后交给 TranslationWrite::apply()。
 * 打印 JSON：apply 的结果或异常 + 之后的库内状态，由 TranslateActionRaceTest 断言。
 *
 * 用法：php translate-action-race-probe.php <场景名>
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
define('DB_PATH', ':memory:');
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);

require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/TranslationWrite.php';
db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
foreach (['settings', 'channels', 'contents', 'products', 'content_revisions'] as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);

function config(string $key, mixed $default = ''): mixed { return $default; }
function resolveExtFieldOwner(string $type): string { return ''; }
function resolveSlug(string $slug, string $title, string $table): string { return 'page-' . bin2hex(random_bytes(3)); }
function adminLog(string $module, string $action, string $detail = ''): void {}
function recordContentRevision(string $type, int $targetId, string $lang, array $targets, string $summary): void
{
    contentRevisionModel()->record($type, $targetId, $lang, $targets, $summary);
}
final class ExtFields
{
    public static function copyValues(string $owner, int $from, int $to): void {}
    /** @return list<array<string,mixed>> */
    public static function fields(string $owner, bool $onlyEnabled = true): array { return []; }
}

$scenario = (string) ($argv[1] ?? '');
$now = 1800000000;
$product = static fn (array $row): int => (int) productModel()->create($row + ['slug' => 'p-' . bin2hex(random_bytes(3)), 'created_at' => $now, 'updated_at' => $now]);
$table = 'products';
$model = productModel();
$srcPageId = 0;
$targetPageId = 0;
$rival = 0;

if (str_starts_with($scenario, 'page_')) {
    $table = 'channels';
    $model = channelModel();
    $srcId = (int) channelModel()->create(['name' => '关于我们', 'slug' => 'about', 'type' => 'page', 'lang' => 'zh-CN', 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $srcPageId = (int) contentModel()->create(['channel_id' => $srcId, 'title' => '关于我们', 'content' => '<p>公司介绍</p>', 'lang' => 'zh-CN', 'type' => 'page', 'slug' => 'about-page', 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $targetId = (int) channelModel()->create(['name' => 'About', 'slug' => 'about-en', 'type' => 'page', 'lang' => 'en', 'translation_group_id' => $srcId, 'status' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $targetPageId = (int) contentModel()->create(['channel_id' => $targetId, 'title' => 'About', 'content' => '<p>Old</p>', 'lang' => 'en', 'type' => 'page', 'slug' => 'about-page-en', 'translation_group_id' => $srcPageId, 'status' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $race = $scenario === 'page_plain' ? static fn () => null : static fn () => db()->execute("UPDATE yikai_contents SET content = '<p>HUMAN</p>', updated_at = updated_at + 5 WHERE id = ?", [$targetPageId]);
} else {
    $srcId = $product(['title' => '液压泵', 'summary' => '高压', 'content' => '<p>参数说明</p>', 'lang' => 'zh-CN', 'status' => 1, 'views' => 3]);
    if ($scenario === 'recycled_create') {
        $rival = $product(['title' => 'Recycled pump', 'lang' => 'en', 'translation_group_id' => $srcId, 'status' => 0, 'deleted_at' => $now]);
    }
    $targetId = in_array($scenario, ['create_race', 'recycled_create'], true) ? 0
        : $product(['title' => 'Old pump', 'summary' => 'Old', 'content' => '<p>Old</p>', 'lang' => 'en', 'translation_group_id' => $srcId, 'status' => 0, 'views' => 7]);
    $race = match ($scenario) {
        'edit_target' => static fn () => db()->execute("UPDATE yikai_products SET content = '<p>HUMAN</p>', updated_at = updated_at + 5 WHERE id = ?", [$targetId]),
        'publish_target' => static fn () => db()->execute('UPDATE yikai_products SET status = 1, updated_at = updated_at + 5 WHERE id = ?', [$targetId]),
        'delete_target' => static fn () => db()->execute('UPDATE yikai_products SET deleted_at = ? WHERE id = ?', [$now, $targetId]),
        'source_changed' => static fn () => db()->execute("UPDATE yikai_products SET title = '液压泵 V2', updated_at = updated_at + 5 WHERE id = ?", [$srcId]),
        'views_bump' => static fn () => db()->execute('UPDATE yikai_products SET views = views + 1 WHERE id IN (?, ?)', [$srcId, $targetId]),
        'create_race' => static function () use ($srcId, $product, &$rival): void {
            $rival = $product(['title' => 'Rival pump', 'content' => '<p>Rival</p>', 'lang' => 'en', 'translation_group_id' => $srcId, 'status' => 0]);
        },
        'plain', 'recycled_create' => static fn () => null,
        default => throw new InvalidArgumentException('unknown scenario ' . $scenario),
    };
}
db()->execute("UPDATE yikai_{$table} SET translation_group_id = id WHERE id = ?", [$srcId]);
if ($srcPageId > 0) db()->execute('UPDATE yikai_contents SET translation_group_id = id WHERE id = ?', [$srcPageId]);
// 已有译文按「机器写入、没人改过」登记（T04）：这里测的是并发，不是受保护译文
if ($targetId > 0) {
    $summaryKey = $table === 'channels' ? 'description' : 'summary';
    $titleField = $table === 'channels' ? 'name' : 'title';
    (new TranslationState(db()))->recordMachine($table, $targetId, $srcId, 'en',
        TranslationState::rowFingerprint($model->find($srcId), $titleField, $summaryKey, $srcPageId > 0 ? contentModel()->find($srcPageId) : null),
        TranslationState::rowFingerprint($model->find($targetId), $titleField, $summaryKey, $targetPageId > 0 ? contentModel()->find($targetPageId) : null), $now);
}

// 调模型之前读到的状态（与 translate_action.php 相同）
$titleKey = $table === 'channels' ? 'name' : 'title';
$read = [
    'table' => $table, 'model' => $model, 'lang' => 'en', 'group_id' => $srcId,
    'src' => $model->find($srcId), 'src_page' => $srcPageId > 0 ? contentModel()->find($srcPageId) : null,
    'target' => $targetId > 0 ? $model->find($targetId) : null,
    'target_page' => $targetPageId > 0 ? contentModel()->find($targetPageId) : null,
    'title_key' => $titleKey, 'summary_key' => $table === 'channels' ? 'description' : 'summary', 'body_field' => 'content',
];
$translated = ['title' => 'Hydraulic pump', 'summary' => 'High pressure', 'content' => '<p>Specifications</p>'];
$newData = $read['src'];
unset($newData['id']);
$newData = ['lang' => 'en', 'translation_group_id' => $srcId, $titleKey => 'Hydraulic pump', 'content' => '<p>Specifications</p>', 'status' => 0, 'slug' => 'new-en'] + $newData;

$race(); // 模型请求进行中，另一位管理员的操作

try {
    $result = ['ok' => (new TranslationWrite(db()))->apply($read, $translated, $newData)];
} catch (Throwable $error) {
    $result = ['error' => get_class($error) . ': ' . $error->getMessage()];
}

$rows = static fn (string $t): array => db()->fetchAll("SELECT * FROM yikai_{$t} ORDER BY id");
echo json_encode($result + [
    'in_transaction' => db()->getPdo()->inTransaction(),
    'src' => $srcId,
    'target' => $targetId,
    'target_page' => $targetPageId,
    'rival' => $rival,
    'products' => $rows('products'),
    'channels' => $rows('channels'),
    'contents' => $rows('contents'),
    'revisions' => $rows('content_revisions'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
