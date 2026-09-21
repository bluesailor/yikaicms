<?php
declare(strict_types=1);
// Run only against a dedicated local fixture freshly installed as "Template workflow test".
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IK_CLI', true);
require dirname(__DIR__, 2) . '/includes/init.php';
require ROOT_PATH . '/includes/SiteTemplateService.php';
require ROOT_PATH . '/includes/SiteContentChecks.php';
$base = 'http://127.0.0.1:8080';
$service = new SiteTemplateService(ROOT_PATH);
$resume = in_array('--resume', $argv, true);
if ($resume && config('site_url') === $base && ($service->recovery()['can_restore'] ?? false)) $service->restore();
if (config('site_name') !== 'Template workflow test' || !$service->canApply()) throw new RuntimeException('Dedicated fresh local fixture required');
$theme = ROOT_PATH . '/themes/studio-starter';
$assets = ROOT_PATH . '/uploads/site-template-workflow';
if (!$resume && (file_exists($theme) || file_exists($assets))) throw new RuntimeException('Fixture paths already exist');
if (!is_dir($theme)) mkdir($theme, 0700, true);
if (!is_dir($assets)) mkdir($assets, 0700, true);
$example = ROOT_PATH . '/deploy/examples/studio-starter';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($example, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $example)) + 1);
    $target = $theme . '/' . $relative;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
    if (is_file($target)) {
        if (hash_file('sha256', $target) !== hash_file('sha256', $file->getPathname())) throw new RuntimeException('Fixture theme modified');
    } else { copy($file->getPathname(), $target); }
}
if (is_file($assets . '/cover.svg') && hash_file('sha256', $assets . '/cover.svg') !== hash_file('sha256', $example . '/assets/cover.svg')) throw new RuntimeException('Fixture image modified');
if (!is_file($assets . '/cover.svg')) copy($example . '/assets/cover.svg', $assets . '/cover.svg');
if (!is_dir(ROOT_PATH . '/output')) mkdir(ROOT_PATH . '/output', 0700, true);
$zip = ROOT_PATH . '/output/site-template-studio.zip';
$fresh = SiteTemplateData::fingerprint();
db()->beginTransaction();
try {
    foreach (array_reverse(SiteTemplateData::TABLES) as $table) {
        if ($table !== 'form_templates') db()->execute('DELETE FROM ' . DB_PREFIX . $table);
    }
    foreach (array_keys(settingModel()->getAll()) as $key) if (SiteTemplateData::settingAllowed($key)) db()->delete('settings', '`key` = ?', [$key]);
    settingModel()->clearCache();
    foreach ([
        [1, '关于我们', 'about', 'page', '<h2>从理解业务开始</h2><p>我们是一支专注品牌表达与网站体验的设计团队，服务中小企业的数字化展示需求。</p><p>每个项目都从访谈、资料梳理开始，通过可编辑的网站交付，让团队可以自己持续更新内容。</p>'],
        [2, '服务介绍', 'services', 'page', '<h2>清晰的服务，完整的交付</h2><h3>品牌梳理</h3><p>整理定位、视觉风格与表达方式，形成统一的对外形象。</p><h3>网站设计</h3><p>从栏目规划、页面设计到响应式开发，兼顾展示与日常维护。</p><h3>持续支持</h3><p>提供使用培训、内容更新指引与后续技术支持。</p>'],
        [3, '工作室动态', 'news', 'list', ''],
        [4, '服务方案', 'product', 'product', ''],
        [5, '联系我们', 'contact', 'page', '<p>欢迎告诉我们项目目标和时间安排，我们会与您一起梳理适合的方案。</p>'],
    ] as [$id, $name, $slug, $type, $body]) db()->insert('channels', ['id' => $id, 'name' => $name, 'slug' => $slug, 'type' => $type,
        'content' => $body, 'redirect_type' => 'none', 'lang' => 'zh-CN', 'translation_group_id' => $id, 'is_home' => $id === 4 ? 1 : 0, 'sort_order' => $id]);
    db()->insert('contents', ['id' => 101, 'channel_id' => 3, 'type' => 'article', 'title' => '一个网站如何保持长期可用', 'slug' => 'maintainable-website',
        'content' => '<p>好的交付不仅是首页好看，也包括清晰的栏目、可靠的联系入口，以及团队能够维护的内容结构。</p>', 'cover' => '/uploads/site-template-workflow/cover.svg', 'status' => 1]);
    db()->insert('product_categories', ['id' => 1, 'name' => '企业服务', 'slug' => 'services']);
    foreach ([1 => '品牌形象设计', 2 => '企业网站建设', 3 => '内容维护支持'] as $id => $title) db()->insert('products', ['id' => $id, 'category_id' => 1,
        'title' => $title, 'slug' => 'service-' . $id, 'summary' => '根据业务阶段规划适合的服务内容。', 'content' => '<p>我们从实际需求出发，与团队一起确认目标、范围、交付节点和后续维护方式。</p>',
        'cover' => '/uploads/site-template-workflow/cover.svg', 'status' => 1, 'sort_order' => $id]);
    db()->insert('media', ['name' => 'Studio illustration', 'path' => $assets . '/cover.svg', 'url' => '/uploads/site-template-workflow/cover.svg', 'ext' => 'svg', 'mime' => 'image/svg+xml']);
    settingModel()->saveBatch(['current_theme' => 'studio-starter', 'site_name' => '青序设计工作室', 'site_lang' => 'zh-CN', 'enabled_languages' => '["zh-CN"]',
        'site_logo' => '', 'site_description' => '清晰、有温度的品牌与网站设计服务。', 'primary_color' => '#253e30', 'secondary_color' => '#55784d',
        'home_layout_active' => '0', 'home_blox_active' => '0', 'home_blocks_config' => '[{"type":"banner","enabled":true},{"type":"about","enabled":true},{"type":"channel:4","enabled":true},{"type":"cta","enabled":true}]',
        'home_about_title' => '把每一次沟通，变成有价值的表达。', 'home_about_content' => '从梳理业务到交付可维护的网站，我们关注的不只是上线那一天。',
        'home_about_image' => '/uploads/site-template-workflow/cover.svg', 'home_cta_title' => '一起讨论你的下一个项目', 'home_cta_desc' => '留下需求，我们将认真回复。',
        'home_cta_button' => '联系工作室', 'home_cta_link' => '/contact.html', 'contact_phone' => '', 'contact_email' => 'studio@example.test', 'contact_address' => '',
        'contact_form_enabled' => '1', 'contact_form_title' => '告诉我们你的想法', 'footer_copyright_text' => '© {year} {site_name}']);
    $summary = $service->export($zip);
    echo 'Exported sample: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";
} finally { db()->rollback(); settingModel()->clearCache(); }
if ($fresh !== SiteTemplateData::fingerprint()) throw new RuntimeException('Source fixture did not roll back');
$jar = tempnam(sys_get_temp_dir(), 'yk-site-http-');
if ($jar === false) throw new RuntimeException('Cannot create cookie jar');
$request = static function(string $path, ?array $data = null) use ($base, $jar): array {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || $status >= 500) throw new RuntimeException('HTTP failure: ' . $path);
    return [$body, $status];
};
$field = static function(string $body, string $name): string {
    if (!preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]+)"/', $body, $match)) throw new RuntimeException('Missing form field: ' . $name);
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
};
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    [$login] = $request('/admin/login.php');
    $request('/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $field($login, '_token')]);
    [$page] = $request('/admin/site_templates.php');
    $csrf = $field($page, '_token');
    [$preview] = $request('/admin/site_templates.php', ['action' => 'prepare', '_token' => $csrf, 'package' => new CURLFile($zip, 'application/zip', 'studio.zip')]);
    $token = $field($preview, 'token');
    [$applied] = $request('/admin/site_templates.php', ['action' => 'apply', '_token' => $csrf, 'token' => $token, 'trusted' => '1', 'confirm' => '1', 'site_name' => '青序设计工作室']);
    $assert(str_contains($applied, '模板已启用'), 'Import did not show success');
    settingModel()->clearCache();
    $assert($service->recovery()['can_restore'], 'HTTP import has no recovery');
    foreach (['/' => '让好想法', '/about.html' => '从理解业务开始', '/services.html' => '清晰的服务', '/product.html' => '品牌形象设计', '/news.html' => '长期可用', '/contact.html' => '告诉我们'] as $path => $needle) {
        [$body, $status] = $request($path);
        $assert($status === 200 && str_contains($body, $needle), 'Sample page failed: ' . $path);
    }
    [$protected, $status] = $request('/storage/site-templates/current.php');
    $assert($status === 404 && !str_contains($protected, 'snapshot'), 'Recovery file exposed');
    [$restored] = $request('/admin/site_templates.php', ['action' => 'restore', 'confirm' => '1', '_token' => $csrf]);
    $assert(str_contains($restored, '已恢复到导入前'), 'HTTP restore failed');
    settingModel()->clearCache();
    $assert($service->canApply(), 'Fresh baseline not restored');
    // Leave a second imported sample for browser editing and responsive verification.
    [$preview] = $request('/admin/site_templates.php', ['action' => 'prepare', '_token' => $csrf, 'package' => new CURLFile($zip, 'application/zip', 'studio.zip')]);
    $request('/admin/site_templates.php', ['action' => 'apply', '_token' => $csrf, 'token' => $field($preview, 'token'), 'trusted' => '1', 'confirm' => '1', 'site_name' => '青序设计工作室']);
    echo "PASS: real install baseline, ZIP upload, preview, apply, six public pages, protected recovery, HTTP restore\n";
} finally { unlink($jar); }
