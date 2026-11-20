<?php
/**
 * 翻译工作中心 e2e 数据（2.3.0 CORE-02）：每类内容各建两条中文原文，一条的英文版已发布、一条的英文版是草稿，
 * 草稿那条的译文记为「已审」——用来验「已审不等于已发布」和「页面说的发布状态 = 访客实际能不能打开」。
 * 输出 JSON：{kind: {live: {source, target}, draft: {source, target}}, listChannel: 英文产品栏目 id}
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json') || !str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')) {
    throw new RuntimeException('Disposable site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/TranslationState.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');

$now = time();

// pending：还没应用的迁移编号（新装站点不该把翻译相关的迁移算成待执行）
if (($argv[1] ?? '') === 'pending') {
    require ROOT_PATH . '/includes/Migrator.php';
    echo json_encode(array_values(array_map(static fn (array $m): string => (string) $m['id'], array_filter(Migrator::loadAll(), static fn (array $m): bool => !Migrator::isApplied($m)))));
    exit;
}
// editors：三个受限账号（只有文章 / 只有单页 / 只有产品权限），密码当场生成，只用于这个一次性本地站点
if (($argv[1] ?? '') === 'editors') {
    $password = 'Wc@' . bin2hex(random_bytes(8));
    $accounts = [];
    foreach (['article' => ['edit_article'], 'page' => ['edit_page'], 'product' => ['edit_product']] as $name => $permissions) {
        $roleId = (int) db()->insert('roles', ['name' => 'WC ' . $name . ' ' . bin2hex(random_bytes(2)), 'permissions' => json_encode($permissions), 'status' => 1, 'created_at' => $now]);
        $username = 'wc_' . $name . '_' . bin2hex(random_bytes(3));
        db()->insert('users', ['username' => $username, 'password' => password_hash($password, PASSWORD_DEFAULT), 'role_id' => $roleId, 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $accounts[$name] = ['username' => $username, 'password' => $password];
    }
    echo json_encode($accounts);
    exit;
}
$tag = 'WC' . bin2hex(random_bytes(3));
$states = new TranslationState(db());
$reviewed = static function (string $table, int $source, int $target, string $title, string $summary, ?Closure $body = null) use ($states, $now): void {
    $row = static fn (int $id): array => (array) db()->fetchOne('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE id = ?', [$id]);
    $states->markReviewed($table, $target, $source, 'en',
        TranslationState::rowFingerprint($row($source), $title, $summary, $body ? $body($source) : null),
        TranslationState::rowFingerprint($row($target), $title, $summary, $body ? $body($target) : null), 1, $now);
};
$channelOf = static fn (string $type, string $lang): int => (int) db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'channels WHERE type = ? AND lang = ? AND status = 1 ORDER BY id LIMIT 1', [$type, $lang]);
$out = [];

// 产品
foreach (['live' => 1, 'draft' => 0] as $name => $status) {
    $source = (int) productModel()->create(['title' => "{$tag} 产品 {$name}", 'slug' => strtolower($tag) . "-p-{$name}", 'summary' => '摘要', 'content' => '<p>正文</p>',
        'lang' => 'zh-CN', 'status' => 1, 'category_id' => 1, 'created_at' => $now, 'updated_at' => $now]);
    db()->execute('UPDATE ' . DB_PREFIX . 'products SET translation_group_id = id WHERE id = ?', [$source]);
    $target = (int) productModel()->create(['title' => "{$tag} product {$name}", 'slug' => strtolower($tag) . "-p-{$name}-en", 'summary' => 'Summary', 'content' => '<p>Body</p>',
        'lang' => 'en', 'translation_group_id' => $source, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
    $reviewed('products', $source, $target, 'title', 'summary');
    $out['products'][$name] = ['source' => $source, 'target' => $target];
}

// 文章
foreach (['live' => 1, 'draft' => 0] as $name => $status) {
    $source = (int) contentModel()->create(['title' => "{$tag} 文章 {$name}", 'slug' => strtolower($tag) . "-a-{$name}", 'summary' => '摘要', 'content' => '<p>正文</p>',
        'type' => 'article', 'channel_id' => $channelOf('list', 'zh-CN'), 'lang' => 'zh-CN', 'status' => 1, 'publish_time' => $now, 'created_at' => $now, 'updated_at' => $now]);
    db()->execute('UPDATE ' . DB_PREFIX . 'contents SET translation_group_id = id WHERE id = ?', [$source]);
    $target = (int) contentModel()->create(['title' => "{$tag} article {$name}", 'slug' => strtolower($tag) . "-a-{$name}-en", 'summary' => 'Summary', 'content' => '<p>Body</p>',
        'type' => 'article', 'channel_id' => $channelOf('list', 'en'), 'lang' => 'en', 'translation_group_id' => $source, 'status' => $status, 'publish_time' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $reviewed('contents', $source, $target, 'title', 'summary');
    $out['contents'][$name] = ['source' => $source, 'target' => $target];
}

// 单页：栏目行 + 栏目下的正文行
$pageBody = static fn (int $channelId): ?array => db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'contents WHERE channel_id = ? ORDER BY id DESC LIMIT 1', [$channelId]);
foreach (['live' => 1, 'draft' => 0] as $name => $status) {
    $ids = [];
    foreach (['zh-CN' => ["{$tag} 单页 {$name}", 1, ''], 'en' => ["{$tag} page {$name}", $status, '-en']] as $lang => [$title, $rowStatus, $suffix]) {
        $ids[$lang] = (int) db()->insert('channels', ['name' => $title, 'slug' => strtolower($tag) . "-pg-{$name}{$suffix}", 'type' => 'page', 'lang' => $lang,
            'translation_group_id' => $ids['zh-CN'] ?? 0, 'parent_id' => 0, 'status' => $rowStatus, 'is_nav' => 0, 'sort_order' => 99]);
        db()->insert('contents', ['title' => $title, 'content' => '<p>' . $title . '</p>', 'type' => 'page', 'channel_id' => $ids[$lang], 'lang' => $lang, 'status' => 1,
            'publish_time' => $now, 'created_at' => $now, 'updated_at' => $now]);
    }
    db()->execute('UPDATE ' . DB_PREFIX . 'channels SET translation_group_id = id WHERE id = ?', [$ids['zh-CN']]);
    $reviewed('channels', $ids['zh-CN'], $ids['en'], 'name', 'description', $pageBody);
    $out['pages'][$name] = ['source' => $ids['zh-CN'], 'target' => $ids['en']];
}

// 产品分类
foreach (['live' => 1, 'draft' => 0] as $name => $status) {
    $source = (int) db()->insert('product_categories', ['name' => "{$tag} 分类 {$name}", 'slug' => strtolower($tag) . "-c-{$name}", 'lang' => 'zh-CN', 'status' => 1, 'is_nav' => 1, 'sort_order' => 99, 'created_at' => $now]);
    db()->execute('UPDATE ' . DB_PREFIX . 'product_categories SET translation_group_id = id WHERE id = ?', [$source]);
    $target = (int) db()->insert('product_categories', ['name' => "{$tag} category {$name}", 'slug' => strtolower($tag) . "-c-{$name}-en", 'lang' => 'en',
        'translation_group_id' => $source, 'status' => $status, 'is_nav' => 1, 'sort_order' => 99, 'created_at' => $now]);
    $reviewed('product_categories', $source, $target, 'name', 'description');
    $out['categories'][$name] = ['source' => $source, 'target' => $target, 'name' => "{$tag} category {$name}", 'slug' => strtolower($tag) . "-c-{$name}-en"];
}
$out['listChannel'] = $channelOf('product', 'en');
// 一篇没有英文版的文章：下一步应是「去翻译」
$missing = (int) contentModel()->create(['title' => "{$tag} 文章 missing", 'slug' => strtolower($tag) . '-a-missing', 'summary' => '摘要', 'content' => '<p>正文</p>',
    'type' => 'article', 'channel_id' => $channelOf('list', 'zh-CN'), 'lang' => 'zh-CN', 'status' => 1, 'publish_time' => $now, 'created_at' => $now, 'updated_at' => $now]);
$out['contents']['missing'] = ['source' => $missing, 'target' => 0];
echo json_encode($out, JSON_UNESCAPED_UNICODE);
