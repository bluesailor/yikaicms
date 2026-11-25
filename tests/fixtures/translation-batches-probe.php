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
/** 每次调用记一笔；$hook 可在「模型请求进行中」做别的事或抛错。假模型每项发 1 次请求：先过额度（与真实 AiService 的请求钩子一样） */
$generator = static function (?Closure $hook = null) use (&$calls): Closure {
    return static function (array $source, string $lang, ?AiRequestContext $context = null) use (&$calls, $hook): array {
        $context?->beforeRequest();
        $calls[] = $source['title'] . '→' . $lang;
        if ($hook !== null) $hook($source, $lang);
        return ['title' => "[{$lang}] Pump", 'summary' => 'Summary', 'content' => '<p>Body</p>'];
    };
};
$items = static fn (int $batch): array => db()->fetchAll('SELECT source_id, lang, status, attempts, target_id, error, lease_until FROM yikai_translation_batch_items WHERE batch_id = ? ORDER BY id', [$batch]);
$out = [];

// 用到真实 AiService 的场景：起一个本地模拟服务商（php -S），以它自己的请求日志为准
if (in_array((string) ($argv[1] ?? ''), ['requests', 'request_failures'], true)) {
    require_once ROOT_PATH . '/includes/AiService.php';
    require_once ROOT_PATH . '/includes/i18n/LanguageRegistry.php';
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $log = tempnam(sys_get_temp_dir(), 'ykmock');
    $serverLog = tempnam(sys_get_temp_dir(), 'ykmocksrv');   // 内置服务器的访问日志，不能混进探针的 JSON 输出
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, ROOT_PATH . '/tests/fixtures/ai-mock-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes, null, ['AI_MOCK_LOG' => $log] + getenv());
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) usleep(50000);
    $mockRequests = static function () use ($log): array {
        clearstatcache(true, $log);
        return array_values(array_filter(array_map(static fn (string $l): mixed => json_decode($l, true), file($log, FILE_IGNORE_NEW_LINES) ?: [])));
    };
    $realGenerator = static fn (string $path): Closure => static fn (array $source, string $lang, AiRequestContext $context): array
        => TranslationWrite::generateWith(new AiService('deepseek', 'test-key', 'mock', 'http://127.0.0.1:' . $port . $path), $source, $lang, 120, $context);
    $requestPrompts = static fn (array $rows): array => array_map(static function (array $r): string {
        $messages = $r['body']['messages'] ?? [];
        return str_starts_with((string) (end($messages)['content'] ?? ''), 'Proofread') ? 'review' : 'translate';
    }, $rows);
    $row = static fn (int $id): array => array_map('intval', array_intersect_key(db()->fetchOne('SELECT calls, requests, max_calls, requests_known FROM yikai_translation_batches WHERE id = ?', [$id]), ['calls' => 1, 'requests' => 1, 'max_calls' => 1, 'requests_known' => 1]))
        + ['status' => (string) db()->fetchColumn('SELECT status FROM yikai_translation_batches WHERE id = ?', [$id]), 'note' => (string) db()->fetchColumn('SELECT note FROM yikai_translation_batches WHERE id = ?', [$id])];
    $partial = static fn (int $id): int => count(AiRequestContext::decodeReplies((string) db()->fetchColumn('SELECT partial_json FROM yikai_translation_batch_items WHERE batch_id = ?', [$id])));
    $control = static function (?array $faults) use ($log): void {
        if ($faults === null) @unlink($log . '.control');
        else file_put_contents($log . '.control', json_encode($faults));
    };
    register_shutdown_function(static function () use ($server, $log, $serverLog): void {
        proc_terminate($server);
        proc_close($server);
        foreach ([$log, $log . '.control', $serverLog] as $file) @unlink($file);
    });
}

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

    case 'requests':
        // 2.3.1：按真正发出的 HTTP 请求计额度。真实 AiService + 本地模拟服务商（php -S），以服务商自己的请求日志为准
        // 1) 额度 1、短标题、默认校对：服务商只收到 1 次请求，批次在校对前暂停，什么都没写
        $GLOBALS['probe_config']['translate_batch_max_calls'] = 1;
        $svc = new TranslationBatches(db(), $realGenerator('/translate/v1'));
        $id = $svc->create(1, [$product('液压泵')], ['en'], 'zh-CN', $now)['batch_id'];
        $svc->step($id, 30, static fn (): int => $now);
        $out['short_paused'] = ['mock' => $requestPrompts($mockRequests()), 'batch' => $row($id), 'item' => $items($id)[0]['status'],
            'attempts' => (int) $items($id)[0]['attempts'], 'saved_replies' => $partial($id),
            'en_rows' => (int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_products WHERE lang = 'en'")];
        // 提高额度后继续：首轮译文重放、不再发，只发校对
        $svc->resume($id, $now + 1);
        $svc->step($id, 30, static fn (): int => $now + 2);
        $out['short_done'] = ['mock' => $requestPrompts($mockRequests()), 'batch' => $row($id), 'item' => $items($id)[0]['status'],
            'saved_replies' => $partial($id), 'en_title' => (string) db()->fetchColumn("SELECT title FROM yikai_products WHERE lang = 'en'")];

        // 2) 长描述（多段）：额度 3 → 暂停 → 继续，每个请求只发一次；批次的请求数 = 服务商收到的请求数
        file_put_contents($log, '');
        $GLOBALS['probe_config']['translate_batch_max_calls'] = 3;
        $long = $product('回转支承');
        db()->execute('UPDATE yikai_products SET content = ? WHERE id = ?', ['<p>' . implode('</p><p>', array_map(static fn (int $n): string => str_repeat('外圈带齿的回转支承第' . $n . '段说明。', 25), range(1, 4))) . '</p>', $long]);
        $id = $svc->create(1, [$long], ['en'], 'zh-CN', $now)['batch_id'];
        $svc->step($id, 30, static fn (): int => $now + 3);
        $paused = $row($id);
        $out['long_paused'] = ['status' => $paused['status'], 'note' => $paused['note'], 'requests' => $paused['requests'], 'mock' => count($mockRequests()), 'saved_replies' => $partial($id)];
        for ($round = 0; $round < 5 && $row($id)['status'] !== 'done'; $round++) {
            $svc->resume($id, $now + 4 + $round);
            $svc->step($id, 30, static fn (): int => $now + 10 + $round);
        }
        $bodies = array_map(static fn (array $r): string => json_encode($r['body'] ?? null), $mockRequests());
        $out['long_done'] = ['batch' => $row($id), 'mock' => count($bodies), 'distinct' => count(array_unique($bodies)), 'item' => $items($id)[0]['status']];

        // 3) 不支持 JSON 输出的服务商：每次被拒的请求和去掉 JSON 后的重发都算
        file_put_contents($log, '');
        $GLOBALS['probe_config']['translate_batch_max_calls'] = 1;
        $strict = new TranslationBatches(db(), $realGenerator('/strict/translate/v1'));
        $id = $strict->create(1, [$product('齿轮泵')], ['en'], 'zh-CN', $now)['batch_id'];
        $strict->step($id, 30, static fn (): int => $now + 20);
        $out['strict_paused'] = ['batch' => $row($id), 'mock' => count($mockRequests())];
        $strict->resume($id, $now + 21);
        $strict->step($id, 30, static fn (): int => $now + 22);
        $out['strict_done'] = ['batch' => $row($id), 'mock' => count($mockRequests()), 'item' => $items($id)[0]['status']];
        break;

    case 'request_failures':
        // 2.3.1：服务商出错时的计数与重用。真实 AiService + 模拟服务商，按服务商日志核对
        require_once ROOT_PATH . '/includes/TranslationState.php';
        unset($GLOBALS['probe_config']['translate_batch_max_calls']);   // 默认额度：每项 REQUESTS_PER_ITEM 次
        $svc = new TranslationBatches(db(), $realGenerator('/translate/v1'));
        $longProduct = static function (string $title) use ($product): int {
            $id = $product($title);
            db()->execute('UPDATE yikai_products SET content = ? WHERE id = ?', ['<p>' . implode('</p><p>', array_map(static fn (int $n): string => str_repeat($title . '第' . $n . '段说明。', 25), range(1, 4))) . '</p>', $id]);
            return $id;
        };
        // 服务商日志里每个请求体（不含模型名以外的随机内容）；同一请求体出现几次 = 这个请求发了几次
        $bodies = static fn (): array => array_map(static fn (array $r): string => (string) json_encode($r['body'] ?? null), $mockRequests());
        $item = static fn (int $batch): array => db()->fetchOne('SELECT status, attempts, error FROM yikai_translation_batch_items WHERE batch_id = ?', [$batch]);
        $runFault = static function (string $title, ?array $faults, int $t) use ($svc, $longProduct, $control, $log, $bodies, $row, $item, $partial, $now): array {
            file_put_contents($log, '');
            $control($faults);
            $id = $svc->create(1, [$longProduct($title)], ['en'], 'zh-CN', $now)['batch_id'];
            $svc->step($id, 30, static fn (): int => $t);
            $first = ['mock' => count($bodies()), 'batch' => $row($id), 'item' => $item($id), 'saved_replies' => $partial($id)];
            $control(null);
            $svc->step($id, 30, static fn (): int => $t + 3600);   // 过了退避时间再领
            $all = $bodies();
            $times = array_count_values($all);
            return ['first' => $first, 'final' => ['mock' => count($all), 'distinct' => count($times), 'batch' => $row($id), 'item' => $item($id)],
                // 第 1、第 2 个请求各发了几次
                'sent' => [$times[$all[0]] ?? 0, $times[$all[1]] ?? 0]];
        };
        // 0) 不出错时这一条要发几次请求（基准）
        file_put_contents($log, '');
        $id = $svc->create(1, [$longProduct('转盘轴承')], ['en'], 'zh-CN', $now)['batch_id'];
        $svc->step($id, 30, static fn (): int => $now);
        $out['baseline'] = ['mock' => count($bodies()), 'batch' => $row($id), 'item' => $item($id)['status']];
        // 1) 第 2 次请求服务商回 500：算一次请求、一次尝试；第 1 次的回复留着，重试时不再发
        $out['http_500'] = $runFault('转盘轴承', ['2' => '500'], $now + 100);
        // 2) 第 2 次请求回复发到一半断开（超时、断网同理：请求已发出、结果未知）：照样算一次已发出的请求
        $out['cut'] = $runFault('交叉滚子轴承', ['2' => 'cut'], $now + 200);
        // 3) 模型回复不合格（原样交回中文）：存下的回复作废，重试重新问
        $out['bad_reply'] = $runFault('齿圈', ['*' => 'same'], $now + 300);

        // 4) 已发布的译文在「更新」批次暂停期间不受影响；继续后已发布、审过的译文也只存为待审，不覆盖
        file_put_contents($log, '');
        $a = $product('液压泵');
        db()->execute('UPDATE yikai_products SET translation_group_id = id WHERE id = ?', [$a]);
        $t = $product('Published hydraulic pump', 'en', $a);
        db()->execute('UPDATE yikai_products SET status = 1 WHERE id = ?', [$t]);
        (new TranslationState(db()))->markReviewed('products', $t, $a, 'en', TranslationState::rowFingerprint(productModel()->find($a), 'title', 'summary'),
            TranslationState::rowFingerprint(productModel()->find($t), 'title', 'summary'), 7, $now);
        db()->execute("UPDATE yikai_products SET title = '液压泵 V2' WHERE id = ?", [$a]);   // 原文改过：译文过期
        $published = static fn (): array => db()->fetchOne('SELECT title, summary, content, status, updated_at FROM yikai_products WHERE id = ?', [$t]);
        $before = $published();
        $GLOBALS['probe_config']['translate_batch_max_calls'] = 1;
        $created = $svc->create(1, [$a], ['en'], 'zh-CN', $now, 'update');
        $id = $created['batch_id'];
        $svc->step($id, 30, static fn (): int => $now + 400);
        $out['published_paused'] = ['created_total' => $created['total'] ?? null, 'mock' => $requestPrompts($mockRequests()), 'batch' => $row($id),
            'item' => $item($id), 'unchanged' => $published() === $before];
        $svc->resume($id, $now + 401);
        $svc->step($id, 30, static fn (): int => $now + 402);
        $out['published_done'] = ['mock' => $requestPrompts($mockRequests()), 'batch' => $row($id), 'item' => $item($id), 'unchanged' => $published() === $before,
            'proposal' => (int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_translation_links WHERE target_id = ? AND proposal_json IS NOT NULL AND proposal_json <> ''", [$t])];
        break;

    case 'checkpoint':
        // 每收到一个成功回复就存进这一项：执行器在下一个请求途中被杀，接手的执行器也拿得到
        $seen = [];
        $svc = new TranslationBatches(db(), static function (array $source, string $lang, AiRequestContext $context) use (&$seen): array {
            foreach (['a', 'b'] as $n) {
                $context->beforeRequest();
                $context->remember(str_repeat($n, 64), 'reply ' . $n);
                $seen[] = count(AiRequestContext::decodeReplies((string) db()->fetchColumn('SELECT partial_json FROM yikai_translation_batch_items')));
            }
            return ['title' => 'Pump', 'summary' => 'Summary', 'content' => '<p>Body</p>'];
        });
        $id = $svc->create(1, [$product('液压泵')], ['en'], 'zh-CN', $now)['batch_id'];
        $svc->step($id, 30, static fn (): int => $now);
        $out['seen'] = $seen;
        $out['after'] = db()->fetchColumn('SELECT partial_json FROM yikai_translation_batch_items');
        break;

    case 'concurrent':
        // 两个执行器争最后 1 个额度：一条 UPDATE 判断加一，只有一个拿得到
        $GLOBALS['probe_config']['translate_batch_max_calls'] = 1;
        $a = new TranslationBatches(db(), $generator());
        $b = new TranslationBatches(db(), $generator());
        $id = $a->create(1, [$product('液压泵')], ['en'], 'zh-CN', $now)['batch_id'];
        $reserve = static fn (TranslationBatches $svc): bool => (new ReflectionMethod($svc, 'reserveRequest'))->invoke($svc, $id);
        $out['reserved'] = [$reserve($a), $reserve($b), $reserve($a)];
        $out['requests'] = (int) db()->fetchColumn('SELECT requests FROM yikai_translation_batches WHERE id = ?', [$id]);
        break;

    case 'migration':
        // 升级前已有的批次：请求数记为未知；重复执行迁移不影响之后新建的批次
        require_once ROOT_PATH . '/includes/Migrator.php';
        foreach (['translation_batches' => ['requests', 'requests_known'], 'translation_batch_items' => ['partial_json']] as $table => $columns) {
            foreach ($columns as $column) db()->execute('ALTER TABLE yikai_' . $table . ' DROP COLUMN ' . $column);
        }
        db()->execute("INSERT INTO yikai_translation_batches (admin_id, content_type, langs, status, total, created_at, updated_at, calls, max_calls) VALUES (1, 'product', '[\"en\"]', 'paused', 2, 1, 1, 4, 6)");
        $migration = require ROOT_PATH . '/migrations/20261126_translation_batch_requests.php';
        $out['check_before'] = ($migration['check'])();
        $out['first_run'] = ($migration['php'])();
        db()->execute("INSERT INTO yikai_translation_batches (admin_id, content_type, langs, status, total, created_at, updated_at) VALUES (1, 'product', '[\"en\"]', 'running', 1, 2, 2)");
        $out['second_run'] = ($migration['php'])();
        $out['check_after'] = ($migration['check'])();
        $out['rows'] = array_map(static fn (array $r): array => array_map('intval', $r), db()->fetchAll('SELECT calls, requests, requests_known FROM yikai_translation_batches ORDER BY id'));
        break;

    case 'partial-decode':
        $good = ['k' => str_repeat('a', 64)];
        $out['decoded'] = [
            count(AiRequestContext::decodeReplies(json_encode([$good['k'] => ['success' => true, 'content' => '{}']]))),
            count(AiRequestContext::decodeReplies(json_encode([$good['k'] => ['success' => false, 'content' => '{}']]))),
            count(AiRequestContext::decodeReplies(json_encode(['not-a-hash' => ['success' => true, 'content' => '{}']]))),
            count(AiRequestContext::decodeReplies('{broken')),
            count(AiRequestContext::decodeReplies(null)),
        ];
        break;

    default:
        fwrite(STDERR, "unknown scenario\n");
        exit(2);
}
$out['calls'] ??= $calls;
$out['in_transaction'] = db()->getPdo()->inTransaction();
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
