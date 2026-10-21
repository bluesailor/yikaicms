<?php
declare(strict_types=1);
/**
 * 整站模板导入后，后台设置页不能丢字段（英文 Flow 包实测：导入后 Logo 最大高度、导航布局、
 * 页脚栏目、版权文字、二维码等约 70 个声明项从设置页消失——replace() 删了包里没带的行，
 * 而 setting.php / setting_contact.php 按库行渲染表单）。
 *
 * 用真实 install SQL 建全新站，造一个只带品牌与语言设置的英文包，走完整导入：包里没带的行
 * 照旧删除（导入语义不变），设置页按声明补合成行、中文出厂文案显示为空、原样保存不落库，
 * 撤销导入仍逐字节还原。
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
$databasePath = sys_get_temp_dir() . '/yk-sitedecl-' . bin2hex(random_bytes(6)) . '.sqlite';
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
require_once ROOT_PATH . '/includes/DeclaredSettingRows.php';

$root = sys_get_temp_dir() . '/yikai-sitedecl-' . bin2hex(random_bytes(8));
mkdir($root . '/uploads', 0700, true);
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function setting(string $key): ?string
{
    $row = db()->fetchOne('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]);
    return $row === null ? null : (string) $row['value'];
}
/** @return array<string,array<string,mixed>> key => 设置页拿到的行 */
function settingsPage(string $group): array
{
    settingModel()->clearCache();
    $rows = DeclaredSettingRows::complete(settingModel()->getByGroup($group), $group, getDefaults($group), settingModel()->getAll());
    return array_column($rows, null, 'key');
}
const PAGE_GROUPS = ['basic', 'url', 'header', 'footer', 'code', 'contact'];
try {
    db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
    settingModel()->clearCache();

    // 英文包：可移植的声明设置只带品牌与语言，其余（Logo 高度、导航布局、页脚、联系、首页……）一概不带
    $data = SiteTemplateData::snapshot(true);
    $keep = ['site_lang', 'enabled_languages', 'current_theme', 'site_name', 'site_description'];
    $dropped = [];
    foreach (getDefaults() as $definitions) foreach (array_keys($definitions) as $key) {
        if (SiteTemplateData::settingAllowed($key) && !in_array($key, $keep, true) && !str_starts_with($key, 'contact_')) $dropped[] = $key;
    }
    foreach ($dropped as $key) {
        unset($data['settings'][$key]);
        // 安装种子的 <key>_en 等语言行也不带，否则导入会按默认语言把它归位成 base 行
        foreach (LanguageRegistry::codes() as $language) unset($data['settings'][$key . '_' . $language]);
    }
    foreach (array_keys($data['settings']) as $key) {
        if (str_starts_with($key, 'contact_') && !in_array($key, ['contact_phone', 'contact_email', 'contact_address'], true)) {
            unset($data['settings'][$key]);
            $dropped[] = $key;
        }
    }
    $data['settings']['site_lang'] = 'en';
    $data['settings']['enabled_languages'] = '["en"]';
    $data['settings']['current_theme'] = 'flow';
    $data['settings']['site_name'] = 'Flow';
    $data['settings']['site_description'] = 'Clearer handoffs for busy teams.';
    check(in_array('site_logo_max_height', $dropped, true) && in_array('footer_copyright_text', $dropped, true)
        && in_array('contact_qrcode', $dropped, true), 'Fixture drops the fields the Flow package left out');
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
    $service->apply($preview['token'], 1, ['site_name' => 'Flow', 'contact_phone' => '', 'contact_email' => '', 'contact_address' => ''], true);
    settingModel()->clearCache();
    $after = SiteTemplateData::fingerprint();

    // 导入语义不变：包里没带的可移植行照删（中文出厂文案不留进模板站）
    foreach (['site_logo_max_height', 'header_nav_layout', 'footer_columns', 'footer_copyright_text', 'contact_qrcode', 'home_about_content'] as $key) {
        check(setting($key) === null, 'Import still drops the row the package left out: ' . $key);
    }

    // 设置页：每个声明项都在，缺行的是合成行
    foreach (PAGE_GROUPS as $group) {
        $page = settingsPage($group);
        foreach (getDefaults($group) as $key => $definition) {
            check(isset($page[$key]), "Settings page still shows {$group}.{$key}");
            $row = $page[$key];
            check($row['group'] === $group && (string) $row['name'] !== '' && $row['type'] === (string) ($definition['type'] ?? 'text'),
                "Synthetic {$key} carries its declared metadata");
            if (!empty($row['synthetic'])) {
                check(preg_match('/\p{Han}/u', (string) $row['value']) !== 1, "Missing {$key} does not show the Chinese factory copy");
            }
        }
        $orders = array_map(static fn(array $row): int => (int) $row['sort_order'], array_values($page));
        $sorted = $orders;
        sort($sorted);
        check($orders === $sorted, "{$group} fields keep their declared order");
    }
    $basic = settingsPage('basic');
    check($basic['site_logo_max_height']['value'] === '40' && $basic['site_logo_max_height']['synthetic'] === true, 'Logo height shows its default');
    check(empty($basic['site_name']['synthetic']) && $basic['site_name']['value'] === 'Flow', 'Stored rows keep their stored value');
    $header = settingsPage('header');
    check($header['header_nav_layout']['value'] === 'right' && $header['header_scroll_opacity']['value'] === '100', 'Header layout shows its defaults');
    $footer = settingsPage('footer');
    check($footer['footer_columns']['value'] === '[]' && $footer['footer_copyright_text']['value'] === '', 'Footer copy falls back to empty, like the frontend');
    check(settingsPage('contact')['contact_qrcode']['value'] === '', 'Contact QR code field is back');

    // 原样保存：合成值不落库，撤销导入仍逐字节还原
    $submitted = [];
    foreach (PAGE_GROUPS as $group) foreach (settingsPage($group) as $key => $row) $submitted[$key] = $row['value'];
    $written = DeclaredSettingRows::withoutUntouched($submitted, getDefaults(), settingModel()->getAll());
    check(array_intersect_key($written, array_flip($dropped)) === [], 'Untouched synthetic values are not written back');
    settingModel()->saveBatch($written);
    settingModel()->clearCache();
    check(hash_equals($after, SiteTemplateData::fingerprint()), 'Saving the settings page unchanged leaves the imported data untouched');
    $service->restore();
    settingModel()->clearCache();
    check(hash_equals($before, SiteTemplateData::fingerprint()), 'Undo restores the pre-import content exactly');
    check(setting('site_logo_max_height') !== null && setting('footer_copyright_text') !== null, 'Undo brings the dropped rows back');

    // 改过的值照常保存，缺行时按声明分组建行
    db()->delete('settings', '`key` = ?', ['header_nav_layout']);
    settingModel()->clearCache();
    settingModel()->saveBatch(DeclaredSettingRows::withoutUntouched(['header_nav_layout' => 'below'], getDefaults(), settingModel()->getAll()));
    $row = db()->fetchOne('SELECT value, `group` FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', ['header_nav_layout']);
    check($row !== null && $row['value'] === 'below' && $row['group'] === 'header', 'An edited synthetic field is saved into its declared group');
    echo "Settings page keeps declared fields after a site template import\n";
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
