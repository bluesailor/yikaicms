<?php
/**
 * S1-03 原型的合成测试库：只用于独立、可清理的测试库，绝不指向产品主库或客户库。
 *
 * 核心表（users/roles/settings/channels/contents）直接取自 install/sql 的建表语句；
 * 提案/令牌/日志表取自正式迁移（S3 起），与安装包同源。
 *
 * 连接参数来自环境变量：
 *   YK_S103_DRIVER=sqlite|mysql
 *   YK_S103_SQLITE_PATH=<临时文件>
 *   YK_S103_MYSQL_HOST / _PORT / _NAME / _USER / _PASSWORD（库名必须以 s103_ 开头）
 */

declare(strict_types=1);

const S103_PREFIX = 'yk_s103_';
const S103_CORE_TABLES = ['users', 'roles', 'settings', 'channels', 'contents', 'content_revisions'];
const S103_PROTOTYPE_TABLES = ['api_tokens', 'api_access_log', 'api_rate_limits', 'ai_proposals', 'ai_proposal_items', 'ai_change_log'];

function s103Boot(): Database
{
    if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 2));
    if (!defined('DEBUG')) define('DEBUG', false);
    $driver = (string) getenv('YK_S103_DRIVER');
    if ($driver === 'sqlite') {
        $path = (string) getenv('YK_S103_SQLITE_PATH');
        if ($path === '' || !str_starts_with(basename($path), 's103-')) throw new RuntimeException('s103 sqlite path must be a disposable s103-* file');
        foreach (['DB_DRIVER' => 'sqlite', 'DB_PATH' => $path, 'DB_HOST' => '', 'DB_PORT' => '0', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASS' => '', 'DB_CHARSET' => 'utf8mb4'] as $k => $v) define($k, $v);
    } elseif ($driver === 'mysql') {
        $name = (string) getenv('YK_S103_MYSQL_NAME');
        if (!str_starts_with($name, 's103_')) throw new RuntimeException('s103 MySQL database name must start with s103_');
        define('DB_DRIVER', 'mysql');
        define('DB_HOST', (string) (getenv('YK_S103_MYSQL_HOST') ?: '127.0.0.1'));
        define('DB_PORT', (string) (getenv('YK_S103_MYSQL_PORT') ?: '3306'));
        define('DB_NAME', $name);
        define('DB_USER', (string) getenv('YK_S103_MYSQL_USER'));
        define('DB_PASS', (string) getenv('YK_S103_MYSQL_PASSWORD'));
        define('DB_CHARSET', 'utf8mb4');
        define('DB_PATH', '');
    } else {
        throw new RuntimeException('YK_S103_DRIVER must be sqlite or mysql');
    }
    define('DB_PREFIX', S103_PREFIX);
    require_once ROOT_PATH . '/config/database.php';
    require_once ROOT_PATH . '/includes/ai-access/AiProposalService.php';
    if (db()->isMysql()) {
        // 与生产一致使用 InnoDB 默认隔离级别；锁等待尽量短，让异常路径在测试里可观测。
        db()->execute('SET SESSION innodb_lock_wait_timeout = 8');
    }
    return db();
}

/** 删除并重建全部测试表（事务外执行 DDL）。 */
function s103ResetSchema(Database $db): void
{
    foreach (array_merge(S103_PROTOTYPE_TABLES, S103_CORE_TABLES) as $table) {
        $db->execute('DROP TABLE IF EXISTS ' . S103_PREFIX . $table);
    }
    foreach (S103_CORE_TABLES as $table) {
        foreach (s103CoreTableSql($db->isSqlite(), $table) as $sql) $db->execute($sql);
    }
    s103ProductSchema($db);
}

/** @return list<string> */
function s103CoreTableSql(bool $sqlite, string $table): array
{
    $file = ROOT_PATH . '/install/sql/' . ($sqlite ? 'sqlite.sql' : 'mysql.sql');
    $sql = (string) file_get_contents($file);
    $quote = $sqlite ? '"' : '`';
    $name = $quote . 'yikai_' . $table . $quote;
    $start = strpos($sql, 'CREATE TABLE ' . $name . ' (');
    if ($start === false) throw new RuntimeException('missing install table ' . $table);
    $end = strpos($sql, ";\n", $start);
    $out = [substr($sql, $start, $end - $start)];
    if ($sqlite) {
        preg_match_all('/^CREATE (?:UNIQUE )?INDEX [^\n]* ON "yikai_' . preg_quote($table, '/') . '" [^\n]*;$/m', $sql, $m);
        foreach ($m[0] as $index) $out[] = rtrim($index, ';');
    }
    return array_map(static fn(string $s): string => str_replace('yikai_', S103_PREFIX, $s), $out);
}

/**
 * 提案相关表直接取自正式迁移（20261031_ai_access_tokens、20261031_ai_proposals），
 * 与产品 schema 同源：测试验证的是会随安装包发布的表结构，而不是另写的一份原型。
 */
function s103ProductSchema(Database $db): void
{
    require_once ROOT_PATH . '/includes/Migrator.php';
    foreach (['20261031_ai_access_tokens', '20261031_ai_proposals'] as $id) {
        $migration = require ROOT_PATH . '/migrations/' . $id . '.php';
        foreach ($migration['sqls'] as $sql) {
            $sql = $db->isSqlite() ? _sqlToSqlite($sql) : $sql;
            if ($sql !== null && $sql !== '') $db->execute($sql);
        }
        if (isset($migration['php'])) ($migration['php'])();
    }
}

/**
 * 合成数据：7=编辑（ai_access+edit_article），8=另一位编辑，1=超管；栏目 10=中文文章栏目，11=英文，12=单页，13=停用；
 * 内容 100=中文草稿，101=已发布文章，102=产品草稿，103=英文草稿。令牌 9 属于 7，令牌 19 属于 8。
 */
function s103Seed(Database $db, int $now): void
{
    $p = S103_PREFIX;
    $db->execute("INSERT INTO {$p}roles (id, name, permissions, status, created_at) VALUES (1, 'Super', '[\"*\"]', 1, 0), (2, 'Editor', '[\"ai_access\",\"edit_article\"]', 1, 0), (3, 'Viewer', '[\"ai_access\"]', 1, 0)");
    $hash = password_hash('synthetic-not-a-login', PASSWORD_DEFAULT);
    foreach ([[1, 's103-admin', 1], [7, 's103-editor', 2], [8, 's103-editor-b', 2]] as [$id, $name, $role]) {
        $db->execute("INSERT INTO {$p}users (id, username, password, role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 0, 0)", [$id, $name, $hash, $role]);
    }
    foreach ([['ai_access_enabled', '1'], ['demo_mode', '0'], ['site_lang', 'zh-CN'], ['enabled_languages', '["zh-CN","en"]'], ['support_access_user_id', '0']] as [$k, $v]) {
        $db->execute("INSERT INTO {$p}settings (`group`, `key`, `value`) VALUES ('s103', ?, ?)", [$k, $v]);
    }
    foreach ([[10, 'zh-CN', 'news-s103', 'list', 1], [11, 'en', 'news-s103-en', 'list', 1], [12, 'zh-CN', 'about-s103', 'page', 1], [13, 'zh-CN', 'old-s103', 'list', 0]] as [$id, $lang, $slug, $type, $status]) {
        $db->execute("INSERT INTO {$p}channels (id, lang, name, slug, type, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, 0)", [$id, $lang, 'S103 ' . $slug, $slug, $type, $status]);
    }
    $rows = [
        [100, 'zh-CN', 10, 'article', 'Draft A', '<p>draft body</p>', 0, 0],
        [101, 'zh-CN', 10, 'article', 'Published A', '<p>public body</p>', 1, $now - 3600],
        [102, 'zh-CN', 10, 'product', 'Product draft', '<p>product</p>', 0, 0],
        [103, 'en', 11, 'article', 'English draft', '<p>english</p>', 0, 0],
    ];
    foreach ($rows as [$id, $lang, $channel, $type, $title, $content, $status, $publish]) {
        $db->execute("INSERT INTO {$p}contents (id, lang, channel_id, type, title, summary, content, tags, status, publish_time, created_at, updated_at, admin_id)"
            . " VALUES (?, ?, ?, ?, ?, '', ?, '', ?, ?, ?, ?, 1)", [$id, $lang, $channel, $type, $title, $content, $status, $publish, $now - 7200, $now - 7200]);
    }
    $scopes = json_encode(['article_draft_propose', 'proposal_status']);
    $db->execute("INSERT INTO {$p}api_tokens (id, admin_id, token_hash, scopes, expires_at, revoked_at) VALUES (9, 7, ?, ?, ?, 0), (19, 8, ?, ?, ?, 0)",
        [hash('sha256', 's103-token-nine'), $scopes, $now + 86400, hash('sha256', 's103-token-nineteen'), $scopes, $now + 86400]);
}

/** 测试身份：等价于 S1-02 解析成功后的 AuthContext（令牌 9 属于用户 7）。 */
function s103TokenContext(int $owner = 7, int $token = 9): AiAccessAuthContext
{
    return new AiAccessAuthContext($owner, $token, ['ai_access', 'edit_article'], ['article_draft_propose', 'proposal_status']);
}
