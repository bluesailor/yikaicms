<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable smoke site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/ScheduledPublish.php';
require ROOT_PATH . '/includes/ProductEditDraft.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}
$action = $argv[1] ?? 'seed';
$id = (int) ($argv[2] ?? 0);
if ($action === 'read') {
    echo json_encode([
        'product' => productModel()->find($id),
        'draft' => contentRevisionModel()->productEditDraft($id),
        'history' => contentRevisionModel()->listFor('product_edit', $id),
        'route' => productRouteModel()->pathFor('product', $id),
    ], JSON_THROW_ON_ERROR);
} elseif ($action === 'change') {
    productModel()->updateById($id, ['content' => '<p>Another editor changed this product.</p>']);
} elseif ($action === 'fail_history') {
    db()->execute("CREATE TRIGGER reject_product_history BEFORE INSERT ON " . DB_PREFIX . "content_revisions BEGIN SELECT RAISE(ABORT, 'History unavailable'); END");
} elseif ($action === 'allow_history') {
    db()->execute('DROP TRIGGER reject_product_history');
} elseif ($action === 'probe_failure') {
    $record = contentRevisionModel()->productEditDraft($id);
    $draft = ProductEditDraft::snapshot($record);
    $_POST = [
        'product_live_version' => ProductEditDraft::fingerprint(ProductEditDraft::live(productModel()->find($id))),
        'product_draft_version' => $record['id'], 'product_editing_draft' => 1,
        'custom_url' => $draft['route'], 'publish_time' => $draft['time'],
    ];
    try {
        ProductEditDraft::save($id, $draft['fields'], $draft['ext'], 'apply', 0);
        throw new RuntimeException('Expected rollback');
    } catch (PDOException $exception) {
        if (!str_contains($exception->getMessage(), 'History unavailable')) throw $exception;
        echo 'rolled back';
    }
} elseif ($action === 'seed') {
    $id = (int) productModel()->create([
        'title' => 'Draft verification product', 'slug' => 'draft-verification-' . uniqid(),
        'content' => '<p>Original public description.</p>', 'summary' => 'Original summary',
        'cover' => '/assets/images/blox-templates/section-feature-grid.png',
        'images' => '["/assets/images/blox-templates/section-feature-grid.png"]',
        'specs' => '{"Power":"100 W"}', 'lang' => siteLang(), 'category_id' => 1, 'status' => 1,
    ]);
    echo $id;
} else {
    throw new InvalidArgumentException('Unknown fixture action');
}
