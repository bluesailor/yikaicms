<?php
declare(strict_types=1);
/** @psalm-suppress UnusedVariable Shared header consumes pageTitle/currentMenu. */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/SiteTemplateService.php';
require_once ROOT_PATH . '/includes/SiteTemplateMarket.php';
require_once ROOT_PATH . '/includes/License.php';
checkLogin();
requirePermission('*');
$service = new SiteTemplateService(ROOT_PATH);
$errorMessage = '';
$fresh = $service->canApply();
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$language = (string) config('admin_lang', getLang());
$suffix = $language === 'en' ? '_en' : ($language === 'ja' ? '_ja' : '');
if ($isPost) verifyCsrf();
// Cache display only. Every download reloads the official catalog and verifies its signature.
$cached = $_SESSION['site_template_market_catalog'] ?? null;
$usingCache = is_array($cached) && ($cached['expires'] ?? 0) > time() && !$isPost;
$catalog = $usingCache ? ($cached['data'] ?? null) : SiteTemplateMarket::request();
if (!$usingCache) $_SESSION['site_template_market_catalog'] = ['expires' => time() + SiteTemplateMarket::CACHE_SECONDS, 'data' => $catalog];
if ($isPost) {
    $temporary = null;
    try {
        if (post('action') !== 'prepare_market') throw new RuntimeException('st_invalid');
        // 预览只存包和导入计划，不改网站；已有内容的站在导入那一步勾选「已备份、确认替换」，服务端凭它放行
        $replaceExisting = !$fresh;
        if (!is_array($catalog)) throw new RuntimeException('st_market_unavailable');
        $selected = null;
        foreach ($catalog['templates'] as $item) {
            if ($item['slug'] === post('slug') && $item['version'] === post('version')) { $selected = $item; break; }
        }
        if ($selected === null) throw new RuntimeException('st_market_unavailable');
        if ($selected['blocked_reason'] !== '') throw new RuntimeException((string) $selected['blocked_reason']);
        $temporary = tempnam(sys_get_temp_dir(), 'yk-site-market-');
        if ($temporary === false) throw new RuntimeException('st_storage');
        SiteTemplateMarket::download($selected, $temporary, license_pubkey());
        SiteTemplateMarket::verifyArchive($temporary, $selected);
        $marketName = (string) ($selected['name' . $suffix] ?: $selected['name']);
        $_SESSION['site_template_preview'] = $service->prepare($temporary, getAdminId(), $replaceExisting, [
            'official' => true, 'name' => $marketName, 'screenshot' => (string) $selected['screenshot'], 'version' => (string) $selected['version'],
        ]);
        adminLog('theme', 'market_prepare', 'Site template verified for preview: ' . $selected['slug'] . ' v' . $selected['version']);
        @unlink($temporary);
        redirect('/admin/site_templates.php');
    } catch (Throwable $error) {
        $code = $error->getMessage();
        $errorMessage = __(preg_match('/^st_[a-z_]+$/D', $code) ? $code : 'st_market_download');
        adminLog('theme', 'market_prepare_failed', 'Site template preparation failed: ' . (preg_match('/^st_[a-z_]+$/D', $code) ? $code : 'unknown'));
    } finally {
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
    }
}
$search = mb_substr(trim(get('q')), 0, 100);
$category = trim(get('category'));
$items = is_array($catalog) ? $catalog['templates'] : [];
$categories = [];
foreach ($items as $item) $categories[$item['category']] = (string) ($item['category_name' . $suffix] ?: ($item['category_name'] ?: $item['category']));
$allCount = count($items);
$importableCount = count(array_filter($items, static fn(array $item): bool => $item['blocked_reason'] === ''));
$items = array_values(array_filter($items, static function (array $item) use ($search, $category): bool {
    if ($category !== '' && $item['category'] !== $category) return false;
    return $search === '' || mb_stripos(implode(' ', array_map(static fn(string $key): string => (string) ($item[$key] ?? ''),
        ['slug', 'name', 'name_en', 'name_ja', 'description', 'description_en', 'description_ja', 'category_name'])), $search) !== false;
}));
// 能导入的排在前面（稳定排序，目录内原有顺序不变）；暂不可用的仍然显示并说明原因
usort($items, static fn(array $a, array $b): int => ($a['blocked_reason'] === '' ? 0 : 1) <=> ($b['blocked_reason'] === '' ? 0 : 1));
asort($categories);
$pageTitle = __('st_market_title');
$currentMenu = 'site_setup';
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="space-y-6">
    <header>
        <?php $breadcrumb = [[__('setup_title'), '/admin/site_setup.php'], [$pageTitle]]; require ROOT_PATH . '/admin/includes/breadcrumb.php'; ?>
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-2xl font-bold text-gray-800"><?= e($pageTitle) ?></h1>
            <a href="/admin/site_templates.php" class="inline-flex items-center gap-1 text-sm text-primary hover:underline" data-testid="st-market-local"><i class="ti ti-upload text-base" aria-hidden="true"></i><?= e(__('st_market_local')) ?></a>
        </div>
        <p class="text-gray-600 mt-2"><?= e(__('st_market_intro')) ?></p>
    </header>
    <?php if ($errorMessage !== ''): ?><p role="alert" class="bg-red-50 text-red-700 p-4 rounded"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if ($catalog === null): ?>
    <p role="status" class="bg-amber-50 text-amber-900 p-4 rounded"><?= e(__('st_market_unavailable')) ?> <a href="/admin/site_templates.php" class="underline"><?= e(__('st_market_local')) ?></a></p>
    <?php else: ?>
    <form method="get" class="flex flex-wrap gap-3 items-end">
        <div><label for="st-market-q" class="block text-sm mb-1"><?= e(__('st_market_search')) ?></label><input id="st-market-q" name="q" value="<?= e($search) ?>" maxlength="100" class="border rounded px-3 py-2"></div>
        <div><label for="st-market-category" class="block text-sm mb-1"><?= e(__('st_market_category')) ?></label><select id="st-market-category" name="category" @change="$el.form.requestSubmit()" class="border rounded px-3 py-2"><option value=""><?= e(__('st_market_all')) ?></option><?php foreach ($categories as $key => $label): ?><option value="<?= e((string) $key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?></select></div>
        <button class="bg-primary text-white rounded px-4 py-2" type="submit"><?= e(__('st_market_search')) ?></button>
        <?php if ($search !== '' || $category !== ''): ?><a href="/admin/site_template_market.php" class="px-2 py-2 text-sm text-gray-600 underline"><?= e(__('st_market_clear_filter')) ?></a><?php endif; ?>
        <p class="ml-auto text-sm text-gray-600" data-testid="st-market-summary"><?= e(__('st_market_summary', ['total' => (string) $allCount, 'available' => (string) $importableCount])) ?><?php if (count($items) !== $allCount): ?> · <?= e(__('st_market_filtered', ['count' => (string) count($items)])) ?><?php endif; ?></p>
    </form>
    <?php if ($items === []): ?><p class="text-gray-600"><?= e(__('st_market_empty')) ?></p><?php endif; ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
        <?php foreach ($items as $item): $name = (string) ($item['name' . $suffix] ?: $item['name']); $description = (string) ($item['description' . $suffix] ?: $item['description']); $categoryLabel = (string) ($categories[$item['category']] ?? ''); ?>
        <article class="bg-white border rounded-lg overflow-hidden flex flex-col<?= $item['blocked_reason'] !== '' ? ' opacity-75' : '' ?>" data-testid="st-market-card" data-available="<?= $item['blocked_reason'] === '' ? '1' : '0' ?>">
            <?php if ($item['screenshot'] !== ''): ?><img src="<?= e($item['screenshot']) ?>" alt="<?= e($name) ?>" loading="lazy" referrerpolicy="no-referrer" class="w-full aspect-video object-cover object-top bg-gray-100"><?php else: ?><div class="aspect-video bg-gray-100 flex items-center justify-center text-gray-500"><?= e(__('st_market_no_cover')) ?></div><?php endif; ?>
            <div class="p-5 flex flex-col flex-1 gap-3">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-lg font-bold"><?= e($name) ?></h2>
                    <?php if ($categoryLabel !== ''): ?><span class="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600"><?= e($categoryLabel) ?></span><?php endif; ?>
                </div>
                <p class="text-sm text-gray-600"><?= e($description) ?></p>
                <p class="text-xs text-gray-500"><?= e(__('st_market_version', ['version' => $item['version'], 'cms' => $item['cms'], 'series' => SiteTemplateArchive::cmsSeries($item['cms'])])) ?></p>
                <?php if ($item['format_version'] > 1): ?><p class="text-sm text-gray-600"><?= e(__('st_market_format_hint', ['format' => (string) $item['format_version']])) ?></p><?php endif; ?>
                <?php if ($item['blocked_reason'] !== ''): ?><p class="mt-auto text-sm text-amber-800"><?= e(__($item['blocked_reason'])) ?></p>
                <?php else: ?>
                <form method="post" class="mt-auto" data-st-market-prepare>
                    <?= csrfField() ?><input type="hidden" name="action" value="prepare_market"><input type="hidden" name="slug" value="<?= e($item['slug']) ?>"><input type="hidden" name="version" value="<?= e($item['version']) ?>">
                    <button type="submit" class="bg-primary text-white rounded px-4 py-3 disabled:opacity-60"><?= e(__('st_market_prepare')) ?></button>
                </form>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<script>
// 下载并校验整站包要几秒：按钮给出进度，也防止重复提交
document.querySelectorAll('form[data-st-market-prepare]').forEach(function (form) {
    form.addEventListener('submit', function () {
        var button = form.querySelector('button[type="submit"]');
        if (!button) return;
        setTimeout(function () {
            button.disabled = true;
            button.textContent = <?= json_encode(__('st_market_downloading'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        }, 0);
    });
});
</script>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
