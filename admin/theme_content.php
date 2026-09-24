<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
checkLogin();
requirePermission('*');
$theme = currentTheme();
$language = get('lang', (string) config('site_lang', 'zh-CN'));
if (!in_array($language, ['zh-CN', 'en', 'ja'], true)) $language = 'zh-CN';
$fields = [];
$values = [];
$errorMessage = '';
$notice = !empty($_SESSION['theme_content_saved']);
unset($_SESSION['theme_content_saved']);
try {
    $fields = ThemeContent::schema($theme);
    $values = ThemeContent::values($theme, $language, $fields);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $input = $_POST['fields'] ?? [];
        if (!is_array($input)) throw new RuntimeException('tc_value');
        ThemeContent::save(post('theme'), $language, $input, post('fingerprint'));
        $_SESSION['theme_content_saved'] = true;
        redirect('/admin/theme_content.php?lang=' . rawurlencode($language));
    }
} catch (Throwable $error) {
    $code = $error->getMessage();
    $errorMessage = __(preg_match('/^tc_[a-z_]+$/D', $code) ? $code : 'tc_schema');
    // Keep valid typed values on validation errors; never place array input in HTML attributes.
    foreach (array_keys($fields) as $key) if (isset($_POST['fields'][$key]) && is_string($_POST['fields'][$key])) $values[$key] = $_POST['fields'][$key];
}
$pageTitle = __('tc_title');
$currentMenu = 'site_setup';
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="max-w-3xl space-y-6">
    <header><a class="underline text-primary" href="/admin/site_setup.php"><?= e(__('setup_title')) ?></a>
        <h1 class="text-2xl font-bold mt-2"><?= e($pageTitle) ?></h1>
        <p class="text-gray-600 mt-2"><?= e(__('tc_intro')) ?></p></header>
    <?php if ($errorMessage !== ''): ?><p role="alert" class="bg-red-50 text-red-700 p-4 rounded"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if ($notice): ?><p role="status" class="bg-green-50 text-green-700 p-4 rounded"><?= e(__('tc_saved')) ?></p><?php endif; ?>
    <nav aria-label="<?= e(__('tc_language')) ?>" class="flex flex-wrap gap-3">
        <?php foreach (['zh-CN' => '简体中文', 'en' => 'English', 'ja' => '日本語'] as $code => $name): ?>
        <a class="border rounded px-4 py-3 <?= $code === $language ? 'bg-primary text-white' : '' ?>" href="?lang=<?= e($code) ?>" <?= $code === $language ? 'aria-current="page"' : '' ?>><?= e($name) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($fields === []): ?>
        <p class="bg-white rounded p-6"><?= e(__('tc_empty')) ?></p>
    <?php else: ?>
    <form method="post" class="bg-white rounded-lg shadow p-6 space-y-6">
        <?= csrfField() ?><input type="hidden" name="theme" value="<?= e($theme) ?>">
        <input type="hidden" name="fingerprint" value="<?= e(ThemeContent::fingerprint($theme, $fields)) ?>">
        <?php foreach ($fields as $key => $field): $value = $values[$key] ?? ''; ?>
        <div>
            <label class="block font-medium mb-2" for="tc-<?= e($key) ?>"><?= e(ThemeContent::localized($field['label'], $language)) ?></label>
            <?php if ($field['type'] === 'textarea'): ?>
                <textarea id="tc-<?= e($key) ?>" name="fields[<?= e($key) ?>]" rows="4" class="border rounded px-3 py-2 w-full"><?= e($value) ?></textarea>
            <?php elseif ($field['type'] === 'toggle'): ?>
                <input type="hidden" name="fields[<?= e($key) ?>]" value="0">
                <input id="tc-<?= e($key) ?>" type="checkbox" name="fields[<?= e($key) ?>]" value="1" <?= $value === '1' ? 'checked' : '' ?>>
            <?php else: ?>
                <input id="tc-<?= e($key) ?>" name="fields[<?= e($key) ?>]" type="text" value="<?= e($value) ?>" class="border rounded px-3 py-2 w-full">
                <?php if ($field['type'] === 'image'): ?>
                    <button type="button" data-content-image="tc-<?= e($key) ?>" class="border rounded px-4 py-3 mt-2"><?= e(__('mp_pick_title')) ?></button>
                    <?php if (UrlPolicy::image($value) !== ''): ?><img src="<?= e(UrlPolicy::image($value)) ?>" alt="<?= e(ThemeContent::localized($field['label'], $language)) ?>" class="max-h-40 max-w-full mt-2 rounded"><?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($field['hint'])): ?><p class="text-sm text-gray-600 mt-2"><?= e(ThemeContent::localized($field['hint'], $language)) ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <p class="text-sm text-gray-600"><?= e(__('tc_publish_hint')) ?></p>
        <button type="submit" class="bg-primary text-white rounded px-4 py-3"><?= e(__('tc_save')) ?></button>
    </form>
    <?php endif; ?>
    <?php require_once ROOT_PATH . '/includes/SiteSetup.php'; ?>
    <div class="flex flex-wrap gap-3"><a href="<?= e(SiteSetup::homeEditUrl()) ?>" class="border rounded px-4 py-3"><?= e(__('setup_edit_home')) ?></a>
        <a href="<?= e(langUrl('/', $language)) ?>" target="_blank" rel="noopener" class="border rounded px-4 py-3"><?= e(__('setup_preview')) ?></a></div>
</div>
<script>
document.querySelectorAll('[data-content-image]').forEach(function(button) {
    button.addEventListener('click', function() {
        openMediaPicker(function(url) { document.getElementById(button.dataset.contentImage).value = url; });
    });
});
</script>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
