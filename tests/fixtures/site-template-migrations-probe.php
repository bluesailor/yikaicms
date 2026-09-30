<?php
declare(strict_types=1);
/**
 * 整站模板导入不能让已应用的迁移翻回「待执行」（2.0.x 英文 Flow 模板实测：
 * 全新安装零待执行，导入后后台提示「数据库有 6 项升级待执行」）。
 *
 * 用真实 install SQL 建全新站，按英文模板包的样子造包（默认语言 en、包内带 _en 后缀行、
 * 只有 contact 表单、无下载分类、法务页正文为空的 Blox 页、页脚全换成模板自己的、
 * 不带 site_logo_max_height），走完整导入与撤销导入。
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
$databasePath = sys_get_temp_dir() . '/yk-sitemig-' . bin2hex(random_bytes(6)) . '.sqlite';
define('DB_PATH', $databasePath);
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);
function config(string $key, mixed $default = ''): mixed { return settingModel()->get($key, $default); }
function __(string $key, array $params = []): string { return $key; }
function siteLang(): string { return (string) config('site_lang', 'zh-CN'); }
function getDefaults(string $group = ''): array
{
    static $all = null;
    $all ??= require ROOT_PATH . '/config/defaults.php';
    return $group === '' ? $all : ($all[$group] ?? []);
}
require ROOT_PATH . '/config/version.php';
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/SiteTemplateService.php';
require_once ROOT_PATH . '/includes/Migrator.php';

$root = sys_get_temp_dir() . '/yikai-sitemig-' . bin2hex(random_bytes(8));
mkdir($root . '/uploads', 0700, true);
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
/** @return list<string> */
function pending(): array
{
    $ids = [];
    foreach (Migrator::loadAll() as $migration) if (!Migrator::isApplied($migration)) $ids[] = (string) $migration['id'];
    sort($ids);
    return $ids;
}
function setting(string $key): ?string
{
    $row = db()->fetchOne('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]);
    return $row === null ? null : (string) $row['value'];
}
try {
    db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
    settingModel()->clearCache();
    // 全新安装（不导入模板）本身就是零待执行——安装器 finalizeFreshInstallMigrations 也这么把关
    check(pending() === [], 'Fresh install already has pending migrations: ' . implode(', ', pending()));

    // 导入前就待执行的迁移必须照常提示，不能被导入「顺手」登记掉
    db()->delete('settings', '`key` = ?', ['admin_logo_max_height']);
    $pendingBefore = pending();
    check($pendingBefore === ['20260819_admin_logo_max_height'], 'Unexpected pre-import state: ' . implode(', ', $pendingBefore));
    $mailZh = setting('mail_tpl_register_subject');
    $mailEn = setting('mail_tpl_register_subject_en');
    check($mailZh !== null && preg_match('/\p{Han}/u', $mailZh) === 1 && $mailEn !== null && $mailEn !== $mailZh, 'Install seeds Chinese mail templates with English translations');

    // 英文模板包：以本站导出快照为底，改成 Flow 包的形状
    $data = SiteTemplateData::snapshot(true);
    $data['settings']['site_lang'] = 'en';
    $data['settings']['enabled_languages'] = '["en"]';
    $data['settings']['current_theme'] = 'flow';
    $data['settings']['site_name'] = 'Flow';
    $data['settings']['site_name_en'] = 'Flow';
    $data['settings']['site_description'] = '中文种子描述';
    $data['settings']['site_description_en'] = 'Clearer handoffs for busy teams.';
    unset($data['settings']['site_logo_max_height']);
    $data['tables']['form_templates'] = array_values(array_filter($data['tables']['form_templates'], static fn(array $row): bool => $row['slug'] === 'contact'));
    $data['tables']['downloads'] = [];
    $data['tables']['download_categories'] = [];
    foreach ($data['tables']['channels'] as &$channel) {
        if ($channel['slug'] === 'privacy') $channel['content'] = '';
    }
    unset($channel);
    check(count($data['tables']['form_templates']) === 1, 'Fixture keeps only the contact form');
    check($data['tables']['blox_templates'] !== [] && array_unique(array_column($data['tables']['blox_templates'], 'source')) === ['user'],
        'Exported Blox templates arrive as the template\'s own (source=user)');
    $manifest = ['format' => 'yikaicms-site-template', 'version' => 1, 'cms' => CMS_VERSION, 'plugins' => [], 'plugin_data' => [],
        'schema' => SiteTemplateData::schema(), 'theme' => 'flow', 'created_at' => gmdate('c'), 'data' => $data];
    $zip = $root . '/flow.zip';
    SiteTemplateArchive::write($zip, $manifest, [
        'theme/theme.json' => '{"name":"Flow","version":"1.0.0"}',
        'theme/layouts/header.php' => '<header>Flow</header>',
        'theme/layouts/footer.php' => '<footer>Flow</footer>',
    ]);

    $service = new SiteTemplateService($root);
    $service->markFreshInstall();
    $before = SiteTemplateData::fingerprint();
    $preview = $service->prepare($zip, 1);
    do { $staged = $service->stage($preview['token'], 1); } while (!$staged['complete']);
    $service->apply($preview['token'], 1, ['site_name' => 'Flow'], true);
    settingModel()->clearCache();
    Migrator::forgetSettled();

    check(pending() === $pendingBefore, 'Import must not leave migrations pending: ' . implode(', ', array_diff(pending(), $pendingBefore)));
    // 20260818：英文站的 contact_form_desc 正是那句英文种子，真去「修复」会把它改成中文
    check(Migrator::settledIds() === ['20260331_inquiry_form_template', '20260721_download_categories_seed', '20260810_legal_pages_seed',
        '20260818_repair_zh_contact_form_description', '20260819_site_logo_max_height', '20260904_bundled_theme_footer_templates'],
        'Exactly the seed migrations whose content the template replaced are settled: ' . implode(', ', Migrator::settledIds()));

    // 默认语言后缀行真的归位了（不是靠登记遮掉）：该迁移自己的 check() 通过
    foreach (Migrator::loadAll() as $migration) {
        if ($migration['id'] === '20260810_normalize_default_lang_shadow') check((bool) $migration['check'](), 'Default-language shadow rows are folded by the import');
    }
    check(db()->fetchAll('SELECT `key` FROM ' . DB_PREFIX . "settings WHERE `key` LIKE '%!_en' ESCAPE '!'") === [], 'No <key>_en rows left on an English-default site');
    check(setting('site_name') === 'Flow', 'Brand name lands in the base row');
    check(setting('site_description') === 'Clearer handoffs for busy teams.', 'A Chinese seed base is replaced by the package\'s English value');
    check(setting('mail_tpl_register_subject') === $mailEn && setting('mail_tpl_register_subject_zh-CN') === $mailZh,
        'Site mail templates follow the language switch like the admin setting does');

    // 模板合法替换掉的内容保持模板的样子：没有被补回中文种子
    check(array_column(db()->fetchAll('SELECT slug FROM ' . DB_PREFIX . 'form_templates'), 'slug') === ['contact'], 'Forms stay as the template shipped them');
    check((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'download_categories') === 0, 'No Chinese download categories are injected');
    check(trim((string) db()->fetchColumn('SELECT content FROM ' . DB_PREFIX . "channels WHERE slug = 'privacy'")) === '', 'Legal page body stays as shipped');
    check((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . "blox_templates WHERE source = 'builtin'") === 0, 'Bundled footers are not re-added');

    // 撤销导入：内容逐字节还原，站点邮件模板回到中文默认的行角色
    $service->restore();
    settingModel()->clearCache();
    check(hash_equals($before, SiteTemplateData::fingerprint()), 'Undo restores the pre-import content exactly');
    check(setting('mail_tpl_register_subject') === $mailZh && setting('mail_tpl_register_subject_en') === $mailEn
        && setting('mail_tpl_register_subject_zh-CN') === null, 'Undo puts the mail template rows back in their Chinese-default roles');
    check(pending() === $pendingBefore, 'Undo leaves no new pending migrations');
    echo "Site template import keeps migrations settled\n";
} finally {
    // Only this process-owned, random temporary fixture directory is removed.
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
    $instance = new ReflectionProperty(Database::class, 'instance');
    $instance->setAccessible(true);
    $instance->setValue(null, null);
    gc_collect_cycles();
    @unlink($databasePath);
}
