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
// 按钮与卡片上的语言标记统一按 中 / 日 / 英 排列（与演示站目录页一致）
$languageOrder = ['zh-CN', 'ja', 'en'];
$catalogLanguages = array_values(array_unique(array_merge([], ...array_map(static fn(array $item): array => $item['languages'] ?? [], $items))));
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
        <?php if ($allCount > 0): ?>
        <?php // 概况：套数 / 语言数 / 行业数，左侧彩色竖线区分（与演示站目录页同一版式） ?>
        <div class="mt-5 flex flex-wrap gap-x-7 gap-y-3 text-xs text-gray-500" data-testid="st-market-stats">
            <span class="inline-flex items-baseline gap-1.5 border-l-2 border-blue-600 pl-3"><strong class="text-xl leading-none text-gray-900"><?= $allCount ?></strong><?= e(__('st_market_stat_templates')) ?></span>
            <?php if ($catalogLanguages !== []): ?><span class="inline-flex items-baseline gap-1.5 border-l-2 border-emerald-500 pl-3"><strong class="text-xl leading-none text-gray-900"><?= count($catalogLanguages) ?></strong><?= e(__('st_market_stat_languages')) ?></span><?php endif; ?>
            <span class="inline-flex items-baseline gap-1.5 border-l-2 border-orange-400 pl-3"><strong class="text-xl leading-none text-gray-900"><?= count($categories) ?></strong><?= e(__('st_market_stat_categories')) ?></span>
        </div>
        <?php endif; ?>
    </header>
    <?php if ($errorMessage !== ''): ?><p role="alert" class="bg-red-50 text-red-700 p-4 rounded"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if ($catalog === null): ?>
    <p role="status" class="bg-amber-50 text-amber-900 p-4 rounded"><?= e(__('st_market_unavailable')) ?> <a href="/admin/site_template_market.php?refresh=1" class="underline"><?= e(__('st_market_retry')) ?></a> · <a href="/admin/site_templates.php" class="underline"><?= e(__('st_market_local')) ?></a></p>
    <?php else: ?>
    <?php // 左栏一张卡片：搜索 → 语言 → 行业 → 数量小结（桌面端吸顶；窄屏排在卡片上方，行业变成可横向滑动的一行）；右：模板卡片 ?>
    <div class="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:items-start lg:gap-8">
    <aside class="mb-6 lg:mb-0 lg:sticky lg:top-24 lg:max-h-[calc(100vh-7rem)] lg:overflow-y-auto rounded-xl border border-gray-200 bg-white p-5 shadow-sm space-y-5" aria-label="<?= e(__('st_market_filters')) ?>">
    <form method="get" role="search">
        <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
        <?php if ($contentLanguage !== ''): ?><input type="hidden" name="lang" value="<?= e($contentLanguage) ?>"><?php endif; ?>
        <label for="st-market-q" class="block mb-2 text-xs font-semibold text-gray-500"><?= e(__('st_market_search_label')) ?></label>
        <div class="relative">
            <input id="st-market-q" type="search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="<?= e(__('st_market_search_placeholder')) ?>"
                   class="w-full rounded-lg border border-gray-300 py-2 pl-3 pr-9 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-blue-100">
            <button type="submit" class="absolute inset-y-0 right-0 grid w-9 place-items-center text-gray-400 hover:text-primary" aria-label="<?= e(__('st_market_search')) ?>"><i class="ti ti-search" aria-hidden="true"></i></button>
        </div>
    </form>
    <?php if ($hasLanguages): ?>
    <nav aria-labelledby="st-market-lang-title" data-testid="st-market-languages">
        <h2 id="st-market-lang-title" class="mb-2 text-xs font-semibold text-gray-500"><?= e(__('st_market_language')) ?></h2>
        <?php // 一行四个：全部【中】【日】【英】；完整语言名和数量放在提示里 ?>
        <ul class="grid grid-cols-4 gap-1.5">
            <?php foreach (array_merge([''], $languageOrder) as $code): $active = $contentLanguage === $code; $label = $code === '' ? __('admin_all') : __('st_market_lang_' . substr($code, 0, 2)); ?>
            <li><a href="<?= e($marketUrl(['lang' => $code])) ?>"<?= $active ? ' aria-current="true"' : '' ?><?= $code !== '' ? ' lang="' . e($code) . '"' : '' ?>
                title="<?= e(($code === '' ? __('admin_all') : (string) ($languageNames[$code] ?? $code)) . ' · ' . (int) ($languageCounts[$code] ?? 0)) ?>"
                class="flex min-h-8 items-center justify-center rounded-md border px-1 text-xs font-semibold whitespace-nowrap <?= $active ? 'border-primary bg-primary text-white' : 'border-gray-200 text-gray-600 hover:border-blue-300 hover:text-primary' ?>"><?= e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <?php endif; ?>
    <nav aria-labelledby="st-market-categories-title" data-testid="st-market-categories">
        <h2 id="st-market-categories-title" class="mb-2 text-xs font-semibold text-gray-500"><?= e(__('st_market_category')) ?></h2>
        <ul class="flex lg:flex-col gap-1 overflow-x-auto pb-2 lg:pb-0 lg:overflow-visible">
            <?php // 有搜索或语言条件时，数量为 0 的行业不列出（当前选中的除外），免得一长串 0 ?>
            <?php foreach (['' => __('st_market_all')] + $categories as $key => $label): $key = (string) $key; $active = $category === $key; if ($key !== '' && !$active && ($search !== '' || $contentLanguage !== '') && (int) ($categoryCounts[$key] ?? 0) === 0) continue; ?>
            <li class="shrink-0"><a href="<?= e($marketUrl(['category' => $key])) ?>"<?= $active ? ' aria-current="page"' : '' ?>
                class="flex items-center justify-between gap-3 rounded-md px-2.5 py-2 text-sm whitespace-nowrap <?= $active ? 'bg-blue-50 text-primary font-semibold' : 'text-gray-600 hover:bg-gray-50 hover:text-primary' ?>">
                <span><?= e((string) $label) ?></span><span class="text-xs <?= $active ? 'text-primary' : 'text-gray-400' ?>"><?= $key === '' ? count($pool) : (int) ($categoryCounts[$key] ?? 0) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>
    <div class="border-t border-gray-100 pt-4 text-xs text-gray-500 space-y-1" data-testid="st-market-summary">
        <p role="status"><strong class="text-primary"><?= count($items) ?></strong> / <?= $allCount ?> <?= e(__('st_market_stat_templates')) ?> · <?= e(__('st_market_importable', ['count' => (string) $importableCount])) ?></p>
        <?php if ($search !== '' || $category !== '' || $contentLanguage !== ''): ?><a href="/admin/site_template_market.php" class="text-primary hover:underline"><?= e(__('st_market_clear_filter')) ?></a><?php endif; ?>
    </div>
    </aside>
    <div class="min-w-0">
    <?php if ($items === []): ?><p class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-gray-500"><?= e(__('st_market_empty')) ?></p><?php endif; ?>
    <div class="grid grid-cols-1 min-[640px]:grid-cols-2 min-[1280px]:grid-cols-3 min-[1680px]:grid-cols-4 gap-6">
        <?php foreach ($items as $item): $name = (string) ($item['name' . $suffix] ?: $item['name']); $description = (string) ($item['description' . $suffix] ?: $item['description']); $categoryLabel = (string) ($categories[$item['category']] ?? ''); $hasDemo = $item['demo_url'] !== ''; ?>
        <article class="group flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white transition duration-200 hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-lg motion-reduce:transition-none motion-reduce:hover:translate-y-0<?= $item['blocked_reason'] !== '' ? ' opacity-75' : '' ?>" data-testid="st-market-card" data-available="<?= $item['blocked_reason'] === '' ? '1' : '0' ?>">
            <?php // 封面加载失败（404、被拦）时换成占位，而不是留一块破图 ?>
            <?php // 4:3 比 16:9 高，能多看到首屏以下的版面；悬停时压暗 + 正中「查看演示 →」+ 底部渐变里的简介与行业 ?>
            <div class="relative aspect-[4/3] overflow-hidden bg-slate-100">
                <?php if ($item['screenshot'] !== ''): ?><img src="<?= e($item['screenshot']) ?>" alt="<?= e($name) ?>" loading="lazy" referrerpolicy="no-referrer" class="h-full w-full object-cover object-left-top" data-market-cover><?php endif; ?>
                <div class="absolute inset-0 items-center justify-center flex-col gap-2 text-gray-500 <?= $item['screenshot'] !== '' ? 'hidden' : 'flex' ?>" data-market-cover-fallback><i class="ti ti-photo text-2xl" aria-hidden="true"></i><span class="text-sm"><?= e(__('st_market_no_cover')) ?></span></div>
                <?php if ($hasDemo): ?>
                <?php // 鼠标专用的大热区：键盘和触屏用户走卡片下方的「查看演示」按钮，所以这里不进 Tab 顺序、不重复朗读 ?>
                <a href="<?= e($item['demo_url']) ?>" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true" data-testid="st-market-cover-demo"
                   class="absolute inset-0 bg-slate-900/30 opacity-0 transition-opacity duration-200 group-hover:opacity-100 motion-reduce:transition-none">
                    <span class="absolute left-1/2 top-1/2 inline-flex -translate-x-1/2 -translate-y-1/2 items-center gap-3 whitespace-nowrap rounded-full bg-white/95 py-2 pl-5 pr-2 text-sm font-bold text-slate-800 shadow-xl"><?= e(__('st_market_demo')) ?><span class="grid size-8 place-items-center rounded-full bg-blue-50 text-primary"><i class="ti ti-arrow-right" aria-hidden="true"></i></span></span>
                    <span class="absolute inset-x-0 bottom-0 bg-linear-to-t from-slate-900/90 via-slate-900/70 to-transparent px-4 pb-3 pt-8 text-left text-white">
                        <?php if ($description !== ''): ?><span class="mb-2 line-clamp-2 text-xs leading-relaxed"><?= e($description) ?></span><?php endif; ?>
                        <?php if ($categoryLabel !== ''): ?><span class="inline-block rounded border border-white/35 bg-white/10 px-2 py-1 text-[11px] font-semibold leading-none"><?= e($categoryLabel) ?></span><?php endif; ?>
                    </span>
                </a>
                <?php endif; ?>
            </div>
            <div class="flex flex-1 flex-col gap-2.5 px-4 py-3.5">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="min-w-0 line-clamp-2 text-base font-bold text-gray-900"><?= e($name) ?></h2>
                    <?php if (($item['languages'] ?? []) !== []): ?><span class="flex shrink-0 gap-1 pt-0.5 text-xs font-semibold text-gray-500" data-testid="st-market-card-languages" title="<?= e(implode(' · ', array_map(static fn(string $code): string => (string) ($languageNames[$code] ?? $code), $item['languages']))) ?>"><?php foreach (array_values(array_intersect($languageOrder, $item['languages'])) as $code): ?><span><?= e(__('st_market_lang_' . substr((string) $code, 0, 2))) ?></span><?php endforeach; ?></span><?php endif; ?>
                </div>
                <?php // 有演示时简介和行业在封面悬停层里；触屏与窄屏看不到悬停，照常显示 ?>
                <?php if ($description !== ''): ?><p class="line-clamp-2 text-sm text-gray-600<?= $hasDemo ? ' lg:hidden' : '' ?>"><?= e($description) ?></p><?php endif; ?>
                <p class="text-xs text-gray-400"><?php if ($categoryLabel !== ''): ?><?= e($categoryLabel) ?> · <?php endif; ?><?= e(__('st_market_version', ['version' => $item['version'], 'cms' => $item['cms'], 'series' => SiteTemplateArchive::cmsSeries($item['cms'])])) ?></p>
                <?php if ($item['format_version'] > 1): ?><p class="text-sm text-gray-600"><?= e(__('st_market_format_hint', ['format' => (string) $item['format_version']])) ?></p><?php endif; ?>
                <?php if ($item['blocked_reason'] !== ''): ?><p class="mt-auto text-sm text-amber-800"><?= e(__($item['blocked_reason'])) ?>
                    <?php if ($hasDemo): ?><a href="<?= e($item['demo_url']) ?>" target="_blank" rel="noopener" class="ml-2 text-primary underline"><?= e(__('st_market_demo')) ?></a><?php endif; ?></p>
                <?php else: ?>
                <form method="post" class="mt-auto pt-1" data-st-market-prepare>
                    <?= csrfField() ?><input type="hidden" name="action" value="prepare_market"><input type="hidden" name="slug" value="<?= e($item['slug']) ?>"><input type="hidden" name="version" value="<?= e($item['version']) ?>">
                    <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-60"><?= e(__('st_market_prepare')) ?></button>
                    <?php if ($hasDemo): ?><a href="<?= e($item['demo_url']) ?>" target="_blank" rel="noopener" data-testid="st-market-demo"
                       class="inline-flex items-center gap-1 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:border-blue-300 hover:text-primary"><i class="ti ti-external-link" aria-hidden="true"></i><?= e(__('st_market_demo')) ?></a><?php endif; ?>
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
