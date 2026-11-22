<?php
/**
 * 批量翻译任务的行为探针（独立进程、完整表结构、假模型）。
 * 每个场景重建数据，打印 JSON，由 TranslationBatchesTest 断言。
 *
 * 用法：php translation-batches-probe.php <场景名>
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
require ROOT_PATH . '/includes/TranslationBatches.php';
db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
foreach (['settings', 'channels', 'contents', 'products', 'content_revisions'] as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);

$GLOBALS['probe_config'] = ['site_lang' => 'zh-CN'];
function config(string $key, mixed $default = ''): mixed { return $GLOBALS['probe_config'][$key] ?? $default; }
function findTranslatedChannelId(int $channelId, string $lang): int { return 0; }
function resolveExtFieldOwner(string $type): string { return ''; }
function adminLog(string $module, string $action, string $detail = ''): void {}
function recordContentRevision(string $type, int $targetId, string $lang, array $targets, string $summary): void {}
final class ExtFields
{
    public static function copyValues(string $owner, int $from, int $to): void {}
    /** @return list<array<string,mixed>> */
    public static function fields(string $owner, bool $onlyEnabled = true): array { return []; }
}

$now = 1800000000;
$product = static fn (string $title, string $lang = 'zh-CN', int $group = 0): int => (int) productModel()->create([
    'title' => $title, 'summary' => '摘要', 'content' => '<p>正文</p>', 'slug' => 'p-' . bin2hex(random_bytes(3)),
    'lang' => $lang, 'translation_group_id' => $group, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
]);
$calls = [];
/** 每次调用记一笔；$hook 可在「模型请求进行中」做别的事或抛错 */
$generator = static function (?Closure $hook = null) use (&$calls): Closure {
    return static function (array $source, string $lang) use (&$calls, $hook): array {
        $calls[] = $source['title'] . '→' . $lang;
        if ($hook !== null) $hook($source, $lang);
        return ['title' => "[{$lang}] Pump", 'summary' => 'Summary', 'content' => '<p>Body</p>'];
    };
};
$items = static fn (int $batch): array => db()->fetchAll('SELECT source_id, lang, status, attempts, target_id, error, lease_until FROM yikai_translation_batch_items WHERE batch_id = ? ORDER BY id', [$batch]);
$out = [];

switch ((string) ($argv[1] ?? '')) {
    case 'happy':
        $a = $product('液压泵');
        $b = $product('齿轮泵');
        $svc = new TranslationBatches(db(), $generator());
        $created = $svc->create(1, [$a, $b], ['en', 'ja', 'zh-CN'], 'zh-CN', $now);
        $out['created'] = $created;
        $out['counts'] = $svc->step($created['batch_id'], 30, static fn (): int => $now);
        $out['batch'] = $svc->batch($created['batch_id'])['status'];
        $out['items'] = $items($created['batch_id']);
        $out['translations'] = db()->fetchAll("SELECT title, lang, status, slug, translation_group_id FROM yikai_products WHERE lang <> 'zh-CN' ORDER BY id");
        $out['groups'] = [$a, $b];
        // 再跑一次：全部完成后不再调模型、不再写
        $svc->step($created['batch_id'], 30, static fn (): int => $now);
        break;

    case 'resume_after_crash':
        // 上一个进程生成完、还没写库就被杀了：结果在库里，租约已过期
        $a = $product('液压泵');
        $svc = new TranslationBatches(db(), $generator(static function (): void { throw new RuntimeException('model must not be called'); }));
        $created = $svc->create(1, [$a], ['en'], 'zh-CN', $now);
        $src = productModel()->find($a);
        db()->execute('UPDATE yikai_translation_batch_items SET status = ?, attempts = 1, result_json = ?, source_fp = ?, lease_until = ?',
            ['generated', json_encode(['title' => 'Saved earlier', 'summary' => 'S', 'content' => '<p>B</p>']), TranslationWrite::fingerprint($src), $now - 1]);
        $out['counts'] = $svc->step($created['batch_id'], 30, static fn (): int => $now);
        $out['items'] = $items($created['batch_id']);
        $out['translations'] = db()->fetchAll("SELECT title FROM yikai_products WHERE lang = 'en'");
        break;

    case 'lease':
        $a = $product('液压泵');
        $svc = new TranslationBatches(db(), $generator());
        $created = $svc->create(1, [$a], ['en'], 'zh-CN', $now);
        // 另一个页面刚领走，还在调模型
        db()->execute("UPDATE yikai_translation_batch_items SET status = 'generating', attempts = 1, lease_until = ?", [$now + 100]);
        $out['while_leased'] = $svc->step($created['batch_id'], 30, static fn (): int => $now);
        $out['calls_while_leased'] = count($calls);
        // 那个请求死了，租约过期
        $out['after_expiry'] = $svc->step($created['batch_id'], 30, static fn (): int => $now + 400);
        $out['items'] = $items($created['batch_id']);
        break;

    case 'failures':
        $a = $product('液压泵');
        $svc = new TranslationBatches(db(), $generator(static function (): void { throw new RuntimeException('provider 503'); }));
        $created = $svc->create(1, [$a], ['en'], 'zh-CN', $now);
        $out['after'] = [];
        // 第 1 次失败后等 60 秒、第 2 次后等 120 秒；等待期内的 step 不调模型
        foreach ([1, 30, 62, 100, 62 + 121, 400] as $offset) {
            $svc->step($created['batch_id'], 30, static fn (): int => $now + $offset);
            $out['after'][] = $items($created['batch_id'])[0] + ['calls' => count($calls)];
        }
        $out['log'] = array_column(array_reverse($svc->events($created['batch_id'])), 'event');
        $out['batch'] = $svc->batch($created['batch_id'])['status'];
        $out['retried'] = $svc->retry($created['batch_id'], $now + 10);
        $out['after_retry'] = $items($created['batch_id'])[0];
        $out['batch_after_retry'] = $svc->batch($created['batch_id'])['status'];
        break;

    case 'source_changed':
        $a = $product('液压泵');
        $first = true;
        $svc = new TranslationBatches(db(), $generator(static function () use ($a, &$first): void {
            if ($first) db()->execute("UPDATE yikai_products SET title = '液压泵 V2' WHERE id = ?", [$a]);
            $first = false;
        }));
        $created = $svc->create(1, [$a], ['en'], 'zh-CN', $now);
        $svc->step($created['batch_id'], 30, static fn (): int => $now);
        $out['after_change'] = $items($created['batch_id'])[0];
        $out['en_rows_after_change'] = (int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_products WHERE lang = 'en'");
        $svc->retry($created['batch_id'], $now + 1);
        $svc->step($created['batch_id'], 30, static fn (): int => $now + 2);
        $out['after_retry'] = $items($created['batch_id'])[0];
        $out['calls'] = $calls;
        break;

    case 'created_elsewhere':
        $a = $product('液压泵');
        $svc = new TranslationBatches(db(), $generator(static function () use ($a, $product): void {
            $product('Pump by hand', 'en', $a);
        }));
        $created = $svc->create(1, [$a], ['en'], 'zh-CN', $now);
        $svc->step($created['batch_id'], 30, static fn (): int => $now);
        $out['items'] = $items($created['batch_id']);
        $out['en_rows'] = db()->fetchAll("SELECT id, title FROM yikai_products WHERE lang = 'en'");
        break;

    case 'create_rules':
        $a = $product('液压泵');
        $b = $product('齿轮泵');
        $product('Gear pump', 'en', $b);
        $english = $product('Only English', 'en');
        $svc = new TranslationBatches(db(), $generator());
        $first = $svc->create(1, [$a], ['en'], 'zh-CN', $now);
        $second = $svc->create(1, [$a, $b, $english, 999], ['en', 'ja'], 'zh-CN', $now);
        $out['second'] = $second;
        $out['second_items'] = $items($second['batch_id']);
        $out['first_items'] = $items($first['batch_id']);
        try {
            $svc->create(1, [$a], ['zh-CN'], 'zh-CN', $now);
            $out['empty'] = 'accepted';
        } catch (InvalidArgumentException $e) {
            $out['empty'] = $e->getMessage();
        }
        break;

    case 'pause_cancel':
        $a = $product('液压泵');
        $b = $product('齿轮泵');
        $svc = new TranslationBatches(db(), $generator());
        $created = $svc->create(1, [$a, $b], ['en'], 'zh-CN', $now);
        $svc->pause($created['batch_id'], $now);
        $out['paused_step'] = $svc->step($created['batch_id'], 30, static fn (): int => $now);
        $out['calls_paused'] = count($calls);
        $svc->resume($created['batch_id'], $now);
        $out['resumed_status'] = $svc->batch($created['batch_id'])['status'];
        $svc->cancel($created['batch_id'], $now);
        $out['cancelled_step'] = $svc->step($created['batch_id'], 30, static fn (): int => $now);
        $out['calls_cancelled'] = count($calls);
        $out['batch'] = $svc->batch($created['batch_id'])['status'];
        break;

    case 'drain':
        // CORE-01 后台执行：只推进 running 批次，按批次号顺序；暂停的不动
        $svc = new TranslationBatches(db(), $generator());
        $one = $svc->create(1, [$product('液压泵')], ['en'], 'zh-CN', $now)['batch_id'];
        $two = $svc->create(1, [$product('齿轮泵')], ['en'], 'zh-CN', $now)['batch_id'];
        $three = $svc->create(1, [$product('叶片泵')], ['en', 'ja'], 'zh-CN', $now)['batch_id'];
        $svc->pause($two, $now);
        $out['drained'] = $svc->drain(30, static fn (): int => $now);
        $out['order'] = array_keys($out['drained']) === [$one, $three];
        $out['statuses'] = [$svc->batch($one)['status'], $svc->batch($two)['status'], $svc->batch($three)['status']];
        $out['calls'] = count($calls);
        $out['again'] = $svc->drain(30, static fn (): int => $now);
        $out['memory'] = [
            TranslationBatches::memoryTight(100 * 1024 ** 2, '128M'),
            TranslationBatches::memoryTight(110 * 1024 ** 2, '128M'),
            TranslationBatches::memoryTight(PHP_INT_MAX, '-1'),
            TranslationBatches::memoryTight(800 * 1024 ** 2, '1G'),
            TranslationBatches::memoryTight(950 * 1024 ** 2, '1G'),
        ];
        break;

    case 'budget':
        // 每批调用上限：到线就把这一项放回去、整批暂停；继续时给剩下的项再分配额度
        $GLOBALS['probe_config']['translate_batch_max_calls'] = 2;
        $svc = new TranslationBatches(db(), $generator());
        $created = $svc->create(1, [$product('液压泵'), $product('齿轮泵'), $product('叶片泵')], ['en'], 'zh-CN', $now);
        $id = $created['batch_id'];
        $out['first'] = $svc->step($id, 30, static fn (): int => $now);
        $row = $svc->batch($id);
        $out['paused'] = [$row['status'], $row['note'], (int) $row['calls'], (int) $row['max_calls'], count($calls)];
        $out['released'] = array_map(static fn (array $i): array => [$i['status'], (int) $i['attempts'], (int) $i['lease_until']], $items($id));
        $svc->resume($id, $now + 5);
        $row = $svc->batch($id);
        $out['resumed'] = [$row['status'], $row['note'], (int) $row['max_calls']];
        $out['second'] = $svc->step($id, 30, static fn (): int => $now + 6);
        $out['done'] = [$svc->batch($id)['status'], (int) $svc->batch($id)['calls'], count($calls)];
        $out['log'] = array_column(array_reverse($svc->events($id)), 'event');
        $GLOBALS['probe_config']['translate_batch_max_calls'] = 0;
        $out['default_cap'] = (int) $svc->batch($svc->create(1, [$product('柱塞泵')], ['en', 'ja'], 'zh-CN', $now)['batch_id'])['max_calls'];
        break;

    case 'purge':
        // 结果保留期限：只删过期的已完成 / 已取消批次，连同项与日志；进行中、暂停的不动；已写进站点的译文不受影响
        $svc = new TranslationBatches(db(), $generator());
        $old = $svc->create(1, [$product('液压泵')], ['en'], 'zh-CN', $now)['batch_id'];
        $svc->step($old, 30, static fn (): int => $now);
        $cancelled = $svc->create(1, [$product('齿轮泵')], ['en'], 'zh-CN', $now)['batch_id'];
        $svc->cancel($cancelled, $now);
        $paused = $svc->create(1, [$product('叶片泵')], ['en'], 'zh-CN', $now)['batch_id'];
        $svc->pause($paused, $now);
        $fresh = $svc->create(1, [$product('柱塞泵')], ['en'], 'zh-CN', $now + 29 * 86400)['batch_id'];
        $svc->step($fresh, 30, static fn (): int => $now + 29 * 86400);
        $translations = (int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_products WHERE lang = 'en'");
        $out['purged'] = $svc->purge($now + 31 * 86400);
        $out['left'] = array_map('intval', array_column(db()->fetchAll('SELECT id FROM yikai_translation_batches ORDER BY id'), 'id'));
        $out['expected_left'] = [$paused, $fresh];
        $out['orphans'] = (int) db()->fetchColumn('SELECT COUNT(*) FROM yikai_translation_batch_items WHERE batch_id IN (?, ?)', [$old, $cancelled])
            + (int) db()->fetchColumn('SELECT COUNT(*) FROM yikai_translation_batch_events WHERE batch_id IN (?, ?)', [$old, $cancelled]);
        $out['translations_kept'] = $translations === (int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_products WHERE lang = 'en'") && $translations === 2;
        $out['again'] = $svc->purge($now + 31 * 86400);
        break;

    case 'cancel_during_generation':
    case 'cancel_then_provider_error':
        // 评审 F03：模型请求进行中，另一个页面取消了整批；之后模型返回结果（或报错）
        $a = $product('液压泵');
        $b = $product('齿轮泵');
        $svc = null;
        $batchId = 0;
        $fails = $argv[1] === 'cancel_then_provider_error';
        $svc = new TranslationBatches(db(), $generator(static function () use (&$svc, &$batchId, $now, $fails): void {
            $svc->cancel($batchId, $now);
            if ($fails) throw new RuntimeException('provider 503');
        }));
        $created = $svc->create(1, [$a, $b], ['en'], 'zh-CN', $now);
        $batchId = $created['batch_id'];
        $out['counts'] = $svc->step($batchId, 30, static fn (): int => $now);
        $out['items'] = $items($batchId);
        $out['batch'] = $svc->batch($batchId)['status'];
        $out['en_rows'] = (int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_products WHERE lang = 'en'");
        $out['links'] = (int) db()->fetchColumn('SELECT COUNT(*) FROM yikai_translation_links');
        // 再跑也不复活
        $out['again'] = $svc->step($batchId, 30, static fn (): int => $now + 400);
        break;

    case 'stale_lease_holder':
        // 评审 F03：本请求调模型太久，租约过期，另一个页面重新领走了这一项（尝试次数 +1、新租约）；之后旧结果才回来
        $a = $product('液压泵');
        $rival = [];
        $svc = new TranslationBatches(db(), $generator(static function () use ($now, &$rival): void {
            db()->execute("UPDATE yikai_translation_batch_items SET attempts = attempts + 1, lease_until = ?, updated_at = ? WHERE status = 'generating'", [$now + 900, $now + 600]);
            $rival = db()->fetchOne('SELECT status, attempts, lease_until FROM yikai_translation_batch_items');
        }));
        $created = $svc->create(1, [$a], ['en'], 'zh-CN', $now);
        $svc->step($created['batch_id'], 30, static fn (): int => $now);
        $out['rival'] = $rival;
        $out['items'] = $items($created['batch_id']);
        $out['result_saved'] = db()->fetchColumn('SELECT result_json FROM yikai_translation_batch_items');
        $out['en_rows'] = (int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_products WHERE lang = 'en'");
        break;

    case 'cancel_after_apply':
        // 已经写进去的项不被取消改掉（它们是普通草稿）
        $a = $product('液压泵');
        $svc = new TranslationBatches(db(), $generator());
        $created = $svc->create(1, [$a], ['en'], 'zh-CN', $now);
        $svc->step($created['batch_id'], 30, static fn (): int => $now);
        $svc->cancel($created['batch_id'], $now + 1);
        $out['items'] = $items($created['batch_id']);
        $out['en_rows'] = (int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_products WHERE lang = 'en'");
        break;

    default:
        fwrite(STDERR, "unknown scenario\n");
        exit(2);
}
$out['calls'] ??= $calls;
$out['in_transaction'] = db()->getPdo()->inTransaction();
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
