<?php
/** Destructive fixtures are admitted only in an owned, disposable MySQL site. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/ScheduledPublish.php';
require ROOT_PATH . '/includes/ProductEditDraft.php';
require ROOT_PATH . '/includes/Migrator.php';
if (DB_DRIVER !== 'mysql' || !str_starts_with(DB_NAME, 's103_product_') || DB_HOST !== '127.0.0.1' || (int) DB_PORT < 33080) {
    throw new RuntimeException('Disposable connection required');
}
$expected = (string) getenv('YK_PRODUCT_MYSQL_DATADIR');
$actual = (string) db()->fetchColumn('SELECT @@datadir');
$canonical = static fn(string $path): string => strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
if ($expected === '' || $canonical($actual) !== $canonical($expected)) throw new RuntimeException('Wrong MySQL datadir');
db()->execute('SET SESSION innodb_lock_wait_timeout = 12');
$_SESSION = ['admin_id' => 1, 'admin_username' => 'draft-test-admin'];
const DRAFT_PROBE_ID = 90001;
$action = $argv[1] ?? '';
$output = [];
$checks = [];
$check = static function(bool $ok, string $label) use (&$checks): void { $checks[] = ['ok' => $ok, 'label' => $label]; };
$stateFile = ROOT_PATH . '/storage/product-mysql-baseline.json';
$tables = ['translation_batches', 'translation_batch_items', 'translation_links'];
$hashes = static function(): array {
    $out = [];
    foreach (['products', 'contents', 'channels', 'users', 'roles', 'settings', 'metas', 'product_routes'] as $name) {
        $rows = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $name . ' ORDER BY id');
        $out[$name] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
    return $out;
};

if ($action === 'provision') {
    if (db()->fetchAll('SHOW TABLES') !== []) throw new RuntimeException('An empty database is required');
    $sql = file_get_contents(ROOT_PATH . '/storage/v220-mysql.sql');
    db()->getPdo()->exec($sql);
    db()->execute("INSERT INTO " . DB_PREFIX . "products (id, title, slug, content, summary, lang, status, specs, cover, images, created_at, updated_at)
        VALUES (?, 'Original published product', 'mysql-draft-probe', '<p>Original body</p>', 'Original summary', 'zh-CN', 1, ?, '/assets/images/blox-templates/section-feature-grid.png', ?, 1, 1)",
        [DRAFT_PROBE_ID, '{"Power":"100 W"}', '["/assets/images/blox-templates/section-feature-grid.png"]']);
    $check(!db()->tableExists('translation_batches') && !db()->tableExists('translation_links'), 'released v2.2.0 has neither new migration');
    file_put_contents($stateFile, json_encode($hashes(), JSON_THROW_ON_ERROR));
} elseif ($action === 'verify' || $action === 'verify-partial') {
    $check($hashes() === json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR), 'all pre-upgrade product/content/settings/account/route/meta rows preserved byte-for-byte');
    foreach ($tables as $name) {
        $check(db()->tableExists($name), $name . ' exists');
        $migrationId = $name === 'translation_links' ? '20261108_translation_links' : '20261107_translation_batches';
        $all = array_column(Migrator::loadAll(), null, 'id');
        $check(Migrator::isApplied($all[$migrationId]), $migrationId . ' recognized by the common migrator');
        $quote = '`yikai_' . $name . '`';
        $source = (string) file_get_contents(ROOT_PATH . '/install/sql/mysql.sql');
        $start = strpos($source, 'CREATE TABLE ' . $quote . ' (');
        $ddl = substr($source, $start, strpos($source, ";\n", $start) - $start);
        preg_match_all('/^\s+`([a-z_]+)`\s+/m', $ddl, $matches);
        $columns = array_column(db()->fetchAll('SHOW COLUMNS FROM ' . DB_PREFIX . $name), 'Field');
        $check($columns === $matches[1], $name . ' upgrade columns match fresh-install columns');
    }
    $indexes = db()->fetchAll('SHOW INDEX FROM ' . DB_PREFIX . 'translation_batch_items');
    $check(count(array_filter($indexes, static fn(array $row): bool => $row['Key_name'] === 'uk_translation_batch_items' && (int) $row['Non_unique'] === 0)) === 3, 'batch item uniqueness covers batch, source and language');
    $indexes = db()->fetchAll('SHOW INDEX FROM ' . DB_PREFIX . 'translation_links');
    $check(count(array_filter($indexes, static fn(array $row): bool => $row['Key_name'] === 'uk_translation_links_target' && (int) $row['Non_unique'] === 0)) === 2, 'translation target uniqueness retained');
    if ($action === 'verify-partial') {
        $check((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'translation_batches WHERE id = 70001') === 1, 'partial-migration retry preserves a batch row in the surviving table');
    }
} elseif ($action === 'partial') {
    db()->insert('translation_batches', ['id' => 70001, 'admin_id' => 1, 'content_type' => 'product', 'langs' => '["en"]', 'total' => 1, 'created_at' => 1, 'updated_at' => 1]);
    db()->execute('DROP TABLE ' . DB_PREFIX . 'translation_batch_items');
    $check(!Migrator::isApplied((require ROOT_PATH . '/migrations/20261107_translation_batches.php')), 'a partially created batch schema is detected as pending');
} elseif ($action === 'worker') {
    $job = json_decode((string) file_get_contents((string) $argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $started = microtime(true);
    file_put_contents($job['ready'], json_encode(['connection' => (int) db()->fetchColumn('SELECT CONNECTION_ID()')]));
    $_POST = $job['post'];
    try {
        ProductEditDraft::save(DRAFT_PROBE_ID, $job['fields'], $job['ext'], $job['mode'], $job['revision'] ?? 0);
        $output = ['ok' => true];
    } catch (Throwable $exception) {
        $output = ['ok' => false, 'error' => $exception->getMessage()];
    }
    $output['elapsed'] = microtime(true) - $started;
} elseif ($action === 'concurrency') {
    $db = db();
    $prefix = DB_PREFIX;
    db()->insert('extfields', ['owner_type' => 'product', 'field_key' => 'draft_note', 'field_name' => 'Draft note', 'field_type' => 'text']);
    metaModel()->set('product', DRAFT_PROBE_ID, 'draft_note', 'Original note');
    $original = productModel()->find(DRAFT_PROBE_ID);
    $reset = static function() use ($original): void {
        db()->delete('content_revisions', 'target_id = ? AND target_type IN (?, ?)', [DRAFT_PROBE_ID, 'product_edit', 'product_edit_draft']);
        productModel()->updateById(DRAFT_PROBE_ID, $original);
        productRouteModel()->remove('product', DRAFT_PROBE_ID);
        metaModel()->set('product', DRAFT_PROBE_ID, 'draft_note', 'Original note');
        ScheduledPublish::setProductTime(DRAFT_PROBE_ID, 0);
    };
    $job = static function(string $title, string $mode = 'draft'): array {
        $live = ProductEditDraft::live(productModel()->find(DRAFT_PROBE_ID));
        $record = contentRevisionModel()->productEditDraft(DRAFT_PROBE_ID);
        $draft = $record ? ProductEditDraft::snapshot($record) : $live;
        return ['mode' => $mode, 'fields' => array_replace($draft['fields'], [
            'title' => $title, 'content' => '<p>' . $title . '</p>', 'summary' => $title,
            'cover' => '/assets/images/blox-templates/section-image-text.jpg',
            'images' => '["/assets/images/blox-templates/section-image-text.jpg"]', 'specs' => '{"Power":"250 W"}', 'status' => 0,
        ]), 'ext' => ['draft_note' => $title], 'post' => [
            'product_live_version' => ProductEditDraft::fingerprint($live),
            'product_draft_version' => (int) ($record['id'] ?? 0), 'product_editing_draft' => $record ? 1 : 0,
            'custom_url' => '/draft-mysql/' . DRAFT_PROBE_ID . '/', 'publish_time' => '',
        ]];
    };
    $save = static function(array $item): void {
        $_POST = $item['post'];
        ProductEditDraft::save(DRAFT_PROBE_ID, $item['fields'], $item['ext'], $item['mode'], $item['revision'] ?? 0);
    };
    // Parent owns the row lock first. Observe actual InnoDB LOCK WAIT states before releasing it.
    $race = static function(array $jobs, ?Closure $change = null) use ($db, $check): array {
        $db->beginTransaction();
        $db->lockRow('products', DRAFT_PROBE_ID);
        if ($change) $change();
        $running = [];
        try {
            foreach ($jobs as $index => $item) {
                $stem = ROOT_PATH . '/storage/draft-worker-' . bin2hex(random_bytes(5));
                $item['ready'] = $stem . '.ready.json';
                file_put_contents($stem . '.job.json', json_encode($item, JSON_THROW_ON_ERROR));
                $pipes = [];
                $proc = proc_open([PHP_BINARY, __FILE__, 'worker', $stem . '.job.json'],
                    [0 => ['pipe', 'r'], 1 => ['file', $stem . '.out.json', 'w'], 2 => ['file', $stem . '.err', 'w']], $pipes);
                if (!is_resource($proc)) throw new RuntimeException('Worker startup failed');
                fclose($pipes[0]);
                $running[] = ['proc' => $proc, 'stem' => $stem, 'ready' => $item['ready']];
            }
            $waiting = false;
            $deadline = microtime(true) + 8;
            do {
                $connections = [];
                foreach ($running as $worker) {
                    if (is_file($worker['ready'])) {
                        $signal = json_decode((string) file_get_contents($worker['ready']), true);
                        if (isset($signal['connection'])) $connections[] = (int) $signal['connection'];
                    }
                }
                if (count($connections) === count($jobs)) {
                    $places = implode(',', array_fill(0, count($connections), '?'));
                    $count = (int) $db->fetchColumn("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT' AND trx_mysql_thread_id IN ({$places})", $connections);
                    $waiting = $count === count($jobs);
                }
                if (!$waiting) usleep(20000);
            } while (!$waiting && microtime(true) < $deadline);
            $check($waiting, count($jobs) . ' independent connections observed waiting for the held product row lock');
            $db->commit();
            $out = [];
            foreach ($running as $worker) {
                $deadline = microtime(true) + 15;
                while (proc_get_status($worker['proc'])['running'] && microtime(true) < $deadline) usleep(10000);
                if (proc_get_status($worker['proc'])['running']) {
                    proc_terminate($worker['proc']);
                    throw new RuntimeException('Worker deadline exceeded');
                }
                proc_close($worker['proc']);
                $out[] = json_decode((string) file_get_contents($worker['stem'] . '.out.json'), true, 512, JSON_THROW_ON_ERROR);
            }
            return $out;
        } finally {
            if ($db->getPdo()->inTransaction()) $db->rollback();
            foreach ($running as $worker) if (is_resource($worker['proc'])) { proc_terminate($worker['proc']); proc_close($worker['proc']); }
        }
    };
    $oneWinner = static function(array $results, string $label) use ($check): void {
        $check(count(array_filter($results, static fn(array $result): bool => $result['ok'])) === 1
            && count(array_filter($results, static fn(array $result): bool => ($result['error'] ?? '') === 'prod_edit_draft_conflict')) === 1, $label);
    };

    $reset();
    $results = $race([$job('Writer A'), $job('Writer B')]);
    $oneWinner($results, 'two concurrent initial drafts have exactly one winner and one conflict');
    $check((int) $db->fetchColumn("SELECT COUNT(*) FROM {$prefix}content_revisions WHERE target_type = 'product_edit_draft' AND target_id = ?", [DRAFT_PROBE_ID]) === 1, 'only one working draft row exists');
    $check(productModel()->find(DRAFT_PROBE_ID) === $original, 'initial draft race leaves the published row unchanged');
    $check(contentRevisionModel()->productEditDraft(DRAFT_PROBE_ID) !== null, 'winning draft is readable after commit');

    $reset();
    $stale = $job('Stale writer');
    $results = $race([$stale], static fn() => productModel()->updateById(DRAFT_PROBE_ID, ['content' => '<p>Human edit wins</p>']));
    $check(($results[0]['error'] ?? '') === 'prod_edit_draft_conflict', 'a human edit committed first rejects the blocked draft writer');
    $check(productModel()->find(DRAFT_PROBE_ID)['content'] === '<p>Human edit wins</p>' && contentRevisionModel()->productEditDraft(DRAFT_PROBE_ID) === null, 'human content preserved and no stale draft created');

    $reset();
    $results = $race([$job('Counter-safe draft')], static fn() => $db->execute("UPDATE {$prefix}products SET views = views + 1 WHERE id = ?", [DRAFT_PROBE_ID]));
    $check($results[0]['ok'] && (int) productModel()->find(DRAFT_PROBE_ID)['views'] === (int) $original['views'] + 1, 'visit counters do not cause false edit conflicts');

    $reset();
    $save($job('First saved draft'));
    $results = $race([$job('Apply contender', 'apply'), $job('Newer draft contender')]);
    $oneWinner($results, 'apply and a newer draft cannot both consume the same draft version');
    $row = productModel()->find(DRAFT_PROBE_ID);
    $check(($row['title'] === 'Apply contender' && (int) $row['status'] === 0 && contentRevisionModel()->productEditDraft(DRAFT_PROBE_ID) === null)
        || ($row['title'] === $original['title'] && (int) $row['status'] === 1 && ProductEditDraft::snapshot(contentRevisionModel()->productEditDraft(DRAFT_PROBE_ID))['fields']['title'] === 'Newer draft contender'), 'winning apply or save has consistent product and draft state');

    $reset();
    $save($job('History A'));
    $save($job('History B'));
    $history = contentRevisionModel()->listFor('product_edit', DRAFT_PROBE_ID);
    $restore = $job('Restore contender', 'restore');
    $restore['revision'] = (int) $history[0]['id'];
    $results = $race([$restore, $job('Save contender')]);
    $oneWinner($results, 'history restore and save have exactly one winner');
    $check(productModel()->find(DRAFT_PROBE_ID) === $original, 'history restore race preserves the published version');

    $reset();
    $save($job('Retained draft'));
    $results = $race([$job('Stale apply', 'apply')], static fn() => productModel()->updateById(DRAFT_PROBE_ID, ['summary' => 'New public summary']));
    $check(($results[0]['error'] ?? '') === 'prod_edit_draft_conflict'
        && ProductEditDraft::snapshot(contentRevisionModel()->productEditDraft(DRAFT_PROBE_ID))['fields']['title'] === 'Retained draft', 'changed published content blocks apply while preserving the draft');

    $reset();
    $save($job('Deletion-safe draft'));
    $results = $race([$job('Blocked deleted writer')], static fn() => productModel()->updateById(DRAFT_PROBE_ID, ['deleted_at' => time()]));
    $check(($results[0]['error'] ?? '') === 'product_url_missing'
        && contentRevisionModel()->productEditDraft(DRAFT_PROBE_ID) !== null, 'deletion during save does not resurrect the product or remove its draft');

    $reset();
    $save($job('Atomic apply'));
    $beforeHistory = contentRevisionModel()->listFor('product_edit', DRAFT_PROBE_ID);
    $beforeDraft = contentRevisionModel()->productEditDraft(DRAFT_PROBE_ID);
    $db->execute("CREATE TRIGGER pd_reject_meta BEFORE UPDATE ON {$prefix}metas FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'draft atomic failure'");
    try {
        $save($job('Atomic failure', 'apply'));
        $check(false, 'metadata failure must throw');
    } catch (PDOException $exception) {
        $check(str_contains($exception->getMessage(), 'draft atomic failure'), 'real database failure occurs after the product and route updates');
    } finally {
        $db->execute('DROP TRIGGER pd_reject_meta');
    }
    $check(productModel()->find(DRAFT_PROBE_ID) === $original && productRouteModel()->pathFor('product', DRAFT_PROBE_ID) === '', 'failed apply rolls back product and custom route');
    $check($beforeDraft === contentRevisionModel()->productEditDraft(DRAFT_PROBE_ID)
        && $beforeHistory === contentRevisionModel()->listFor('product_edit', DRAFT_PROBE_ID)
        && metaModel()->get('product', DRAFT_PROBE_ID, 'draft_note') === 'Original note', 'failed apply preserves metadata, the current draft and history');
}

if ($action !== 'worker') {
    $output = ['action' => $action, 'php' => PHP_VERSION, 'server' => db()->fetchColumn('SELECT VERSION()'), 'database' => DB_NAME,
        'assertions' => count($checks), 'failed' => count(array_filter($checks, static fn(array $row): bool => !$row['ok'])), 'checks' => $checks];
}
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
exit(($output['failed'] ?? 0) === 0 ? 0 : 1);
