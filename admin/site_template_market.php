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
$usingCache = is_array($cached) && ($cached['expires'] ?? 0) > time() && !$isPost && get('refresh') !== '1';
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
            'demo_url' => (string) $selected['demo_url'],
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
$contentLanguage = in_array(get('lang'), SiteTemplateMarket::LANGUAGES, true) ? get('lang') : '';
$items = is_array($catalog) ? $catalog['templates'] : [];
$categories = [];
foreach ($items as $item) $categories[$item['category']] = (string) ($item['category_name' . $suffix] ?: ($item['category_name'] ?: $item['category']));
$allCount = count($items);
$importableCount = count(array_filter($items, static fn(array $item): bool => $item['blocked_reason'] === ''));
// 语言筛选只在目录给出了模板语言时出现（老的目录没有这个字段，不显示一个筛不出东西的选项）
$hasLanguages = array_filter($items, static fn(array $item): bool => ($item['languages'] ?? []) !== []) !== [];
$languageNames = availableLanguages();
// 左栏两组筛选的数量互相跟随：行业数量按「搜索 + 语言」算，语言数量按「搜索 + 行业」算，
// 点哪一项都能先知道会看到几套
$searchPool = array_values(array_filter($items, static fn(array $item): bool => $search === '' || mb_stripos(implode(' ', array_map(
    static fn(string $key): string => (string) ($item[$key] ?? ''),
    ['slug', 'name', 'name_en', 'name_ja', 'description', 'description_en', 'description_ja', 'category_name'])), $search) !== false));
$inLanguage = static fn(array $item, string $code): bool => $code === '' || in_array($code, $item['languages'] ?? [], true);
$inCategory = static fn(array $item, string $key): bool => $key === '' || $item['category'] === $key;
$pool = array_values(array_filter($searchPool, static fn(array $item): bool => $inLanguage($item, $contentLanguage)));
$categoryCounts = array_count_values(array_column($pool, 'category'));
$languageCounts = [];
foreach (array_merge([''], SiteTemplateMarket::LANGUAGES) as $code) {
    $languageCounts[$code] = count(array_filter($searchPool, static fn(array $item): bool => $inCategory($item, $category) && $inLanguage($item, $code)));
}
$items = array_values(array_filter($pool, static fn(array $item): bool => $inCategory($item, $category)));
$marketUrl = static function (array $change) use ($search, $category, $contentLanguage): string {
    $query = array_filter(array_merge(['q' => $search, 'category' => $category, 'lang' => $contentLanguage], $change), static fn($v): bool => $v !== '');
    return '/admin/site_template_market.php' . ($query === [] ? '' : '?' . http_build_query($query));
};
// 能导入的排在前面（稳定排序，目录内原有顺序不变）；暂不可用的仍然显示并说明原因
usort($items, static fn(array $a, array $b): int => ($a['blocked_reason'] === '' ? 0 : 1) <=> ($b['blocked_reason'] === '' ? 0 : 1));
asort($categories);
$pageTitle = __('st_market_title');
$currentMenu = 'site_setup';
$sidebarCompact = true;  // 模板卡片需要宽度：进入本页先把后台侧栏收成图标栏
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
    <p role="status" class="bg-amber-50 text-amber-900 p-4 rounded"><?= e(__('st_market_unavailable')) ?> <a href="/admin/site_template_market.php?refresh=1" class="underline"><?= e(__('st_market_retry')) ?></a> · <a href="/admin/site_templates.php" class="underline"><?= e(__('st_market_local')) ?></a></p>
    <?php else: ?>
    <?php // 左：语言在上、行业在下（都带数量，桌面端吸顶；窄屏各变成可横向滑动的一行）；右：搜索与模板卡片 ?>
    <div class="lg:flex lg:items-start lg:gap-8">
    <div class="lg:w-56 shrink-0 lg:sticky lg:top-24 mb-6 lg:mb-0 space-y-5">
    <?php if ($hasLanguages): ?>
    <nav aria-labelledby="st-market-lang-title" data-testid="st-market-languages">
        <h2 id="st-market-lang-title" class="px-3 mb-2 text-xs font-semibold text-gray-500"><?= e(__('st_market_language')) ?></h2>
        <ul class="flex lg:flex-col gap-1 overflow-x-auto pb-2 lg:pb-0">
            <?php // 顺序固定为 中 / 英 / 日（LANGUAGES 的顺序），名称用各语言自己的写法 ?>
            <?php foreach (['' => __('admin_all')] + array_combine(SiteTemplateMarket::LANGUAGES, array_map(static fn(string $code): string => (string) ($languageNames[$code] ?? $code), SiteTemplateMarket::LANGUAGES)) as $code => $label): $code = (string) $code; $active = $contentLanguage === $code; ?>
            <li class="shrink-0"><a href="<?= e($marketUrl(['lang' => $code])) ?>"<?= $active ? ' aria-current="true"' : '' ?><?= $code !== '' ? ' lang="' . e($code) . '"' : '' ?>
                class="flex items-center justify-between gap-3 rounded px-3 py-2 text-sm whitespace-nowrap <?= $active ? 'bg-blue-50 text-primary font-medium' : 'text-gray-700 hover:bg-gray-100' ?>">
                <span><?= e((string) $label) ?></span><span class="text-xs <?= $active ? 'text-primary' : 'text-gray-400' ?>"><?= (int) ($languageCounts[$code] ?? 0) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <?php endif; ?>
    <nav aria-labelledby="st-market-categories-title" data-testid="st-market-categories">
        <h2 id="st-market-categories-title" class="px-3 mb-2 text-xs font-semibold text-gray-500"><?= e(__('st_market_category')) ?></h2>
        <ul class="flex lg:flex-col gap-1 overflow-x-auto pb-2 lg:pb-0 lg:overflow-x-visible lg:overflow-y-auto lg:max-h-[calc(100vh-18rem)]">
            <?php foreach (['' => __('st_market_all')] + $categories as $key => $label): $key = (string) $key; $active = $category === $key; ?>
            <li class="shrink-0"><a href="<?= e($marketUrl(['category' => $key])) ?>"<?= $active ? ' aria-current="page"' : '' ?>
                class="flex items-center justify-between gap-3 rounded px-3 py-2 text-sm whitespace-nowrap <?= $active ? 'bg-blue-50 text-primary font-medium' : 'text-gray-700 hover:bg-gray-100' ?>">
                <span><?= e((string) $label) ?></span><span class="text-xs <?= $active ? 'text-primary' : 'text-gray-400' ?>"><?= $key === '' ? count($pool) : (int) ($categoryCounts[$key] ?? 0) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
    </div>
    <div class="flex-1 min-w-0 space-y-5">
    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
        <form method="get" class="flex items-center gap-2" role="search">
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
            <?php if ($contentLanguage !== ''): ?><input type="hidden" name="lang" value="<?= e($contentLanguage) ?>"><?php endif; ?>
            <label for="st-market-q" class="sr-only"><?= e(__('st_market_search')) ?></label>
            <input id="st-market-q" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="<?= e(__('st_market_search')) ?>" class="border rounded px-3 py-2 w-56 max-w-full">
            <button class="bg-primary text-white rounded px-4 py-2" type="submit"><?= e(__('st_market_search')) ?></button>
        </form>
        <?php if ($search !== '' || $category !== '' || $contentLanguage !== ''): ?><a href="/admin/site_template_market.php" class="text-sm text-gray-600 underline"><?= e(__('st_market_clear_filter')) ?></a><?php endif; ?>
        <p class="ml-auto text-sm text-gray-600" data-testid="st-market-summary"><?= e(__('st_market_summary', ['total' => (string) $allCount, 'available' => (string) $importableCount])) ?><?php if (count($items) !== $allCount): ?> · <?= e(__('st_market_filtered', ['count' => (string) count($items)])) ?><?php endif; ?></p>
    </div>
    <?php if ($items === []): ?><p class="text-gray-600"><?= e(__('st_market_empty')) ?></p><?php endif; ?>
    <div class="grid grid-cols-1 min-[640px]:grid-cols-2 min-[1280px]:grid-cols-3 min-[1400px]:grid-cols-4 gap-6">
        <?php foreach ($items as $item): $name = (string) ($item['name' . $suffix] ?: $item['name']); $description = (string) ($item['description' . $suffix] ?: $item['description']); $categoryLabel = (string) ($categories[$item['category']] ?? ''); ?>
        <article class="bg-white border rounded-lg overflow-hidden flex flex-col<?= $item['blocked_reason'] !== '' ? ' opacity-75' : '' ?>" data-testid="st-market-card" data-available="<?= $item['blocked_reason'] === '' ? '1' : '0' ?>">
            <?php // 封面加载失败（404、被拦）时换成占位，而不是留一块破图 ?>
            <?php // 4:3 比 16:9 高，能多看到首屏以下的版面；悬停时遮罩 + 正中「查看演示」 ?>
            <div class="group relative aspect-[4/3] bg-gray-100 overflow-hidden">
                <?php if ($item['screenshot'] !== ''): ?><img src="<?= e($item['screenshot']) ?>" alt="<?= e($name) ?>" loading="lazy" referrerpolicy="no-referrer" class="w-full h-full object-cover object-top" data-market-cover><?php endif; ?>
                <div class="absolute inset-0 items-center justify-center flex-col gap-2 text-gray-500 <?= $item['screenshot'] !== '' ? 'hidden' : 'flex' ?>" data-market-cover-fallback><i class="ti ti-photo text-2xl" aria-hidden="true"></i><span class="text-sm"><?= e(__('st_market_no_cover')) ?></span></div>
                <?php if ($item['demo_url'] !== ''): ?>
                <?php // 鼠标专用的大热区：键盘和触屏用户走卡片下方的「查看演示」按钮，所以这里不进 Tab 顺序、不重复朗读 ?>
                <a href="<?= e($item['demo_url']) ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true" data-testid="st-market-cover-demo"
                   class="absolute inset-0 flex items-center justify-center bg-gray-900/55 opacity-0 transition-opacity duration-200 group-hover:opacity-100">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-5 py-2.5 text-sm font-medium text-gray-900 shadow-lg"><i class="ti ti-external-link" aria-hidden="true"></i><?= e(__('st_market_demo')) ?></span>
                </a>
                <?php endif; ?>
            </div>
            <div class="p-5 flex flex-col flex-1 gap-3">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-lg font-bold"><?= e($name) ?></h2>
                    <?php if ($categoryLabel !== ''): ?><span class="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600"><?= e($categoryLabel) ?></span><?php endif; ?>
                </div>
                <p class="text-sm text-gray-600"><?= e($description) ?></p>
                <?php if (($item['languages'] ?? []) !== []): ?><p class="text-xs text-gray-500" data-testid="st-market-card-languages"><i class="ti ti-world" aria-hidden="true"></i> <?= e(implode(' · ', array_map(static fn(string $code): string => (string) ($languageNames[$code] ?? $code), $item['languages']))) ?></p><?php endif; ?>
                <p class="text-xs text-gray-500"><?= e(__('st_market_version', ['version' => $item['version'], 'cms' => $item['cms'], 'series' => SiteTemplateArchive::cmsSeries($item['cms'])])) ?></p>
                <?php if ($item['format_version'] > 1): ?><p class="text-sm text-gray-600"><?= e(__('st_market_format_hint', ['format' => (string) $item['format_version']])) ?></p><?php endif; ?>
                <?php if ($item['blocked_reason'] !== ''): ?><p class="mt-auto text-sm text-amber-800"><?= e(__($item['blocked_reason'])) ?>
                    <?php if ($item['demo_url'] !== ''): ?><a href="<?= e($item['demo_url']) ?>" target="_blank" rel="noopener" class="ml-2 text-primary underline"><?= e(__('st_market_demo')) ?></a><?php endif; ?></p>
                <?php else: ?>
                <form method="post" class="mt-auto" data-st-market-prepare>
                    <?= csrfField() ?><input type="hidden" name="action" value="prepare_market"><input type="hidden" name="slug" value="<?= e($item['slug']) ?>"><input type="hidden" name="version" value="<?= e($item['version']) ?>">
                    <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="bg-primary text-white rounded px-4 py-3 disabled:opacity-60"><?= e(__('st_market_prepare')) ?></button>
                    <?php if ($item['demo_url'] !== ''): ?><a href="<?= e($item['demo_url']) ?>" target="_blank" rel="noopener" data-testid="st-market-demo"
                       class="inline-flex items-center gap-1 border rounded px-4 py-3 text-gray-700 hover:bg-gray-50"><i class="ti ti-external-link" aria-hidden="true"></i><?= e(__('st_market_demo')) ?></a><?php endif; ?>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    </div>
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
document.querySelectorAll('[data-market-cover]').forEach(function (image) {
    var fallback = image.parentElement.querySelector('[data-market-cover-fallback]');
    function showFallback() {
        image.classList.add('hidden');
        fallback.classList.remove('hidden');
        fallback.classList.add('flex');
    }
    image.addEventListener('error', showFallback);
    if (image.complete && image.naturalWidth === 0) showFallback();
});
</script>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
