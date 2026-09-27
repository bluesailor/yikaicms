<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/SiteSetup.php';
checkLogin();
requirePermission('*');

// 顶部推荐模板的数据接口（页面异步取）：目录要走网络、可能要几秒，绝不能挡住页面渲染。
// 取目录前先释放会话锁，否则这几秒里同一管理员打开的其它后台页都会排队等它。
if (($_GET['catalog'] ?? '') === '1') {
    require_once ROOT_PATH . '/includes/SiteTemplateService.php';
    require_once ROOT_PATH . '/includes/SiteTemplateMarket.php';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $cached = $_SESSION['site_template_market_catalog'] ?? null;
    $fresh = is_array($cached) && ($cached['expires'] ?? 0) > time();
    $catalog = $fresh ? ($cached['data'] ?? null) : null;
    if (!$fresh) {
        session_write_close();
        $catalog = SiteTemplateMarket::request();
        // 与模板市场页共用同一份缓存（失败也缓存 60 秒，避免目录不可达时每次打开向导都等超时）
        session_start();
        $_SESSION['site_template_market_catalog'] = ['expires' => time() + SiteTemplateMarket::CACHE_SECONDS, 'data' => $catalog];
        session_write_close();
    }
    if (!is_array($catalog)) {
        http_response_code(503);
        echo json_encode(['code' => 503]);
        exit;
    }
    $preview = SiteSetup::marketPreview($catalog, (string) config('admin_lang', getLang()), (string) config('site_lang', 'zh-CN'));
    echo json_encode(['code' => 0, 'data' => $preview], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        if (post('action') === 'theme_home' && post('confirm') === '1') {
            $_SESSION['setup_home_undo'] = SiteSetup::changeHomeFlags(
                ['home_layout_active' => '0', 'home_blox_active' => '0'], post('fingerprint')
            );
        } elseif (post('action') === 'undo_home' && isset($_SESSION['setup_home_undo'])) {
            $undo = $_SESSION['setup_home_undo'];
            SiteSetup::changeHomeFlags($undo['flags'], $undo['fingerprint']);
            unset($_SESSION['setup_home_undo']);
        } else {
            throw new RuntimeException(__('setup_plan_stale'));
        }
        adminLog('home', 'source', 'Homepage source changed from site setup');
        $_SESSION['setup_home_notice'] = true;
        redirect('/admin/site_setup.php');
    } catch (Throwable $e) {
        // 与同批其它页面对齐：只回显已登记的错误码，PDOException 等一律归为通用失败，
        // 避免把 SQL 语句/表名/连接信息渲染给管理员
        $code = $e->getMessage();
        $errorMessage = __(preg_match('/^(?:setup|st)_[a-z_]+$/D', $code) ? $code : 'setup_plan_missing');
    }
}
$savedNotice = !empty($_SESSION['setup_home_notice']);
unset($_SESSION['setup_home_notice']);

$pageTitle = __('setup_title');
$currentMenu = 'site_setup';
$homeMode = SiteSetup::currentHomeMode();
$homeEditUrl = SiteSetup::homeEditUrl();
$legacyHome = $homeEditUrl !== '/admin/setting_home.php';
$checks = SiteSetup::checklist();
$doneChecks = count(array_filter($checks, static fn(array $check): bool => $check['done']));
$freshSite = false;
try {
    require_once ROOT_PATH . '/includes/SiteTemplateService.php';
    $freshSite = (new SiteTemplateService(ROOT_PATH))->canApply();
} catch (Throwable $error) {
    $freshSite = false;
}
// 模板市场刚看过时会话里有目录缓存：直接带进页面，省一次请求；没有就交给页面异步取（见上方接口）
$cachedCatalog = $_SESSION['site_template_market_catalog'] ?? null;
$marketPreview = is_array($cachedCatalog) && ($cachedCatalog['expires'] ?? 0) > time() && is_array($cachedCatalog['data'] ?? null)
    ? SiteSetup::marketPreview($cachedCatalog['data'], (string) config('admin_lang', getLang()), (string) config('site_lang', 'zh-CN'))
    : null;
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="space-y-6">
    <?php if ($errorMessage !== ''): ?><p role="alert" class="bg-red-50 text-red-700 p-4 rounded"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if ($savedNotice): ?><p role="status" class="bg-green-50 text-green-700 p-4 rounded"><?= e(__('setup_home_saved')) ?></p><?php endif; ?>
    <?php // 「改用主题首页」：经典首页在用时放在首页卡里；可视化首页在用时收进页底的旧版首页选项
    $renderHomeSwitch = static function (string $spacing): void { ?>
        <details class="<?= $spacing ?>border rounded p-4">
            <summary class="cursor-pointer py-2 font-medium"><?= e(__('setup_home_switch')) ?></summary>
            <form method="post" class="space-y-3 mt-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="theme_home">
                <input type="hidden" name="fingerprint" value="<?= e(SiteSetup::homeFingerprint()) ?>">
                <p class="text-gray-700"><?= e(__('setup_home_switch_hint')) ?></p>
                <label class="flex items-start gap-2 py-2"><input type="checkbox" name="confirm" value="1" required>
                    <span><?= e(__('setup_home_confirm')) ?></span></label>
                <button class="border rounded px-4 py-3" type="submit"><?= e(__('setup_home_switch')) ?></button>
            </form>
        </details>
    <?php }; ?>
    <header>
        <h1 class="text-2xl font-bold text-gray-800"><?= e($pageTitle) ?></h1>
        <p class="text-gray-600 mt-1"><?= e(__('setup_intro')) ?></p>
    </header>

    <?php // 这几件事没有先后依赖，所以不编号。模板市场是最重要的入口：新站、已有内容的站都放在最上面、最醒目 ?>
    <section class="rounded-xl border border-blue-100 bg-gradient-to-br from-blue-50 via-white to-white p-6 sm:p-8 shadow-sm" aria-labelledby="setup-start" data-testid="setup-template-card">
        <div class="grid gap-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:items-center">
            <div>
                <span class="inline-flex items-center gap-1 rounded-full bg-primary px-3 py-1 text-xs font-medium text-white"><i class="ti ti-sparkles" aria-hidden="true"></i><?= e(__('setup_recommended')) ?></span>
                <h2 id="setup-start" class="mt-3 text-2xl font-bold text-gray-900"><?= e(__('setup_start')) ?></h2>
                <p class="mt-2 text-gray-600"><?= e(__('setup_template_hint')) ?></p>
                <?php if (!$freshSite): ?>
                <p class="mt-3 flex items-start gap-2 text-sm text-gray-600" data-testid="setup-template-existing"><i class="ti ti-info-circle mt-0.5 text-base text-primary" aria-hidden="true"></i><span><?= e(__('setup_template_existing_note')) ?></span></p>
                <?php endif; ?>
                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <a class="inline-flex items-center gap-2 rounded-lg bg-primary px-5 py-3 font-medium text-white shadow-sm hover:bg-secondary" href="/admin/site_template_market.php" data-testid="setup-template-market"><i class="ti ti-layout-grid" aria-hidden="true"></i><?= e(__('st_market_cta_button')) ?></a>
                    <a class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-5 py-3 text-gray-700 hover:bg-gray-50" href="/admin/site_templates.php"><?= e(__('setup_template_upload')) ?></a>
                </div>
                <p class="mt-5 text-sm text-gray-500"><?= e(__('setup_recipe_hint')) ?> <a class="text-primary hover:underline" href="/admin/recipe.php"><?= e(__('admin_recipe')) ?></a></p>
            </div>
            <div data-testid="setup-template-picks" data-browse-count="<?= e(__('setup_template_browse_all')) ?>">
                <ul class="grid grid-cols-2 gap-3" data-picks-list aria-busy="true">
                    <?php for ($i = 0; $i < 4; $i++): ?><li class="aspect-[16/10] animate-pulse rounded-lg bg-gray-100"></li><?php endfor; ?>
                </ul>
                <a class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline" href="/admin/site_template_market.php" data-picks-all><?= e(__('setup_template_browse')) ?></a>
            </div>
        </div>
    </section>
    <?php if ($marketPreview !== null): ?><script type="application/json" id="setup-market-data"><?= json_encode($marketPreview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script><?php endif; ?>

    <div class="grid gap-6 md:grid-cols-2 md:items-start">
    <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6" aria-labelledby="setup-home">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="setup-home" class="text-lg font-bold text-gray-800"><?= e(__('setup_home')) ?></h2>
            <span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600" data-testid="setup-home-mode"><?= e(__('setup_mode_' . $homeMode)) ?></span>
        </div>
        <div class="flex flex-wrap gap-3 mt-4">
            <a class="rounded-lg bg-primary px-4 py-2.5 text-white hover:bg-secondary" data-testid="setup-edit-home" href="<?= e($homeEditUrl) ?>"><?= e(__('setup_edit_home')) ?></a>
            <a class="rounded-lg border px-4 py-2.5 text-gray-700 hover:bg-gray-50" href="/" target="_blank" rel="noopener"><?= e(__('setup_preview')) ?></a>
            <a class="rounded-lg border px-4 py-2.5 text-gray-700 hover:bg-gray-50" href="/admin/theme.php"><?= e(__('setup_change_theme')) ?></a>
            <?php if ($homeMode === 'theme'): ?>
            <?php // 主题内容只在主题接管首页时才有意义 ?>
            <a class="rounded-lg border px-4 py-2.5 text-gray-700 hover:bg-gray-50" href="/admin/theme_content.php"><?= e(__('tc_title')) ?></a>
            <?php endif; ?>
        </div>
        <?php if (!$legacyHome && $homeMode !== 'theme') $renderHomeSwitch('mt-4 '); ?>
        <?php if (isset($_SESSION['setup_home_undo'])): ?>
        <form method="post" class="mt-4">
            <?= csrfField() ?><input type="hidden" name="action" value="undo_home">
            <p class="text-sm text-gray-600"><?= e(__('setup_home_undo_hint')) ?></p>
            <button type="submit" class="border rounded px-4 py-3 mt-2"><?= e(__('setup_home_undo')) ?></button>
        </form>
        <?php endif; ?>
    </section>
    <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6" aria-labelledby="setup-content">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="setup-content" class="flex items-center gap-2 text-lg font-bold text-gray-800"><?= e(__('setup_content')) ?>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium <?= $doneChecks === count($checks) ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' ?>"
                      data-testid="setup-content-progress" aria-label="<?= e(__('setup_content_progress', ['done' => (string) $doneChecks, 'total' => (string) count($checks)])) ?>"><?= $doneChecks ?>/<?= count($checks) ?></span></h2>
            <a class="text-sm text-primary hover:underline" href="/admin/site_content_check.php"><?= e(__('sc_title')) ?></a>
        </div>
        <p class="text-gray-600 mt-1 text-sm"><?= e(__('setup_check_hint')) ?></p>
        <ul class="divide-y mt-3">
            <?php foreach ($checks as $check): ?>
            <li class="flex items-center justify-between gap-3 py-3">
                <span class="flex items-center gap-2 text-gray-800">
                    <i class="ti <?= $check['done'] ? 'ti-circle-check text-green-600' : 'ti-circle-dashed text-amber-500' ?> text-lg" aria-hidden="true"></i>
                    <span class="font-medium"><?= e(__($check['label'])) ?></span>
                    <span class="sr-only"><?= e(__($check['done'] ? 'setup_present' : 'setup_missing')) ?></span>
                </span>
                <a class="shrink-0 text-sm text-primary hover:underline" href="<?= e($check['url']) ?>" aria-label="<?= e(__('setup_review') . ': ' . __($check['label'])) ?>"><?= e(__('setup_review')) ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    </div>

    <?php if ($legacyHome): ?>
    <?php // 经典首页设置与「改用主题首页」只留给从老站迁移的人：放在整页最底下、小号浅色，
          // 新手不会被它打断；需要的人展开后内容与原先一致 ?>
    <details class="px-1 text-xs text-gray-400" data-testid="setup-legacy-home">
        <summary class="cursor-pointer w-fit hover:text-gray-600"><?= e(__('setup_legacy_home')) ?></summary>
        <div class="mt-3 space-y-3 border-l-2 border-gray-200 pl-4 text-sm text-gray-500">
            <p><?= e(__('setup_legacy_home_hint')) ?></p>
            <p><a class="underline hover:text-gray-700" href="/admin/setting_home.php"><?= e(__('setup_classic_home')) ?></a></p>
            <?php if ($homeMode !== 'theme') $renderHomeSwitch(''); ?>
        </div>
    </details>
    <?php endif; ?>
</div>
<script>
// 推荐模板：有缓存就用页面里带的数据，否则异步取；取不到就只留「去模板市场挑选」，不报错也不占位
(function () {
    var box = document.querySelector('[data-testid="setup-template-picks"]');
    if (!box) return;
    var list = box.querySelector('[data-picks-list]');
    var all = box.querySelector('[data-picks-all]');
    var base = window.YK_BASE || '';
    function hide() { list.remove(); }
    function render(data) {
        var items = data && Array.isArray(data.templates) ? data.templates : [];
        if (items.length === 0) { hide(); return; }
        list.replaceChildren();
        items.forEach(function (item) {
            var li = document.createElement('li');
            var link = document.createElement('a');
            link.href = base + '/admin/site_template_market.php?q=' + encodeURIComponent(item.slug || '');
            link.className = 'group block overflow-hidden rounded-lg border border-gray-200 bg-white hover:border-primary hover:shadow-md';
            var frame = document.createElement('span');
            frame.className = 'block aspect-[16/10] overflow-hidden bg-gray-100';
            if (item.screenshot) {
                var img = document.createElement('img');
                img.src = item.screenshot;
                img.alt = '';
                img.loading = 'lazy';
                img.referrerPolicy = 'no-referrer';
                img.className = 'h-full w-full object-cover object-top transition-transform duration-200 group-hover:scale-105';
                img.addEventListener('error', function () { img.remove(); });
                frame.appendChild(img);
            }
            var name = document.createElement('span');
            name.className = 'block truncate px-3 py-2 text-sm font-medium text-gray-800';
            name.textContent = item.name || '';
            link.append(frame, name);
            li.appendChild(link);
            list.appendChild(li);
        });
        list.removeAttribute('aria-busy');
        if (data.total > 0) all.textContent = box.dataset.browseCount.replace(':count', String(data.total));
    }
    var embedded = document.getElementById('setup-market-data');
    if (embedded) {
        try { render(JSON.parse(embedded.textContent)); } catch (e) { hide(); }
        return;
    }
    fetch(base + '/admin/site_setup.php?catalog=1', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) { if (json && json.code === 0) render(json.data); else hide(); })
        .catch(hide);
})();
</script>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
