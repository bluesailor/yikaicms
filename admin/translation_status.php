<?php
/**
 * Yikai CMS - 翻译工作中心（评审 T04 的译文状态页；2.3.0 CORE-02 扩到文章、单页、产品分类）
 *
 * 默认语言的内容 × 已启用的其它语言：每格是该语言译文的状态——缺失、最新、原文已改（过期）、
 * 人改过、已审、待审、未追踪；是否已发布单独标出（已审不等于已发布）；并给出下一步：补译 / 审阅 / 更新 / 发布 / 核对。
 * 产品可勾选后建「翻译缺失项」或「更新过期译文」批次；其它种类的下一步进各自的编辑页处理。
 * 状态来自 translation_links 与当前内容的指纹比对（includes/TranslationCoverage.php，与交付清单同一份判断），本页只读。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/TranslationCoverage.php';
require_once ROOT_PATH . '/includes/TranslationPublish.php';

checkLogin();
if (isEnterpriseContentAdmin()) permissionDenied();
/**
 * 各种类：要哪项权限、原文与译文各进哪个编辑页。产品分类没有单条编辑页，进该语言的分类列表。
 * @var array<string,array{permission:string,source:Closure(int,string):string,target:Closure(int,string):string}> $kinds
 */
$byId = static fn (string $path): Closure => static fn (int $id, string $_lang): string => $path . '?id=' . $id;
$categoryList = static fn (int $_id, string $lang): string => '/admin/product_category.php?lang=' . rawurlencode($lang);
$kinds = [
    'products' => ['permission' => 'edit_product', 'source' => $byId('/admin/product_edit.php'), 'target' => $byId('/admin/product_edit.php')],
    'contents' => ['permission' => 'edit_article', 'source' => $byId('/admin/article_edit.php'), 'target' => $byId('/admin/article_edit.php')],
    'pages' => ['permission' => 'edit_page', 'source' => $byId('/admin/page_edit.php'), 'target' => $byId('/admin/page_edit.php')],
    'categories' => ['permission' => 'edit_product', 'source' => $categoryList, 'target' => $categoryList],
];
// 有产品、文章、单页任一编辑权限即可进入（所有者 2026-11-20 定）；只显示自己能编辑的种类，
// 批量翻译任务的入口只给 edit_product。一种都不能编辑时按产品权限的提示拒绝。
$kinds = array_filter($kinds, static fn (array $spec): bool => hasPermission($spec['permission']));
if ($kinds === []) requirePermission('edit_product');
$canBatch = hasPermission('edit_product');
$kind = isset($kinds[$_GET['kind'] ?? '']) ? (string) $_GET['kind'] : (string) array_key_first($kinds);

$currentMenu = 'translation_status';
$pageTitle = __('tss_title');
$tablesReady = db()->tableExists('translation_links') && db()->tableExists('translation_batches');
$defaultLang = (string) config('site_lang', 'zh-CN');
$langs = array_diff_key(enabledLanguages(), [$defaultLang => true]);
$filters = ['all', 'missing', 'draft', 'ready', 'outdated', 'proposal', 'untracked'];
$filter = in_array($_GET['filter'] ?? '', $filters, true) ? (string) $_GET['filter'] : 'all';
$keyword = trim((string) ($_GET['q'] ?? ''));

// 批量发布已审的草稿译文：种类按当前账号能编辑的来（$kinds 已按权限过滤），每一条在服务端按此刻的内容重新判断
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'publish') {
        $postKind = (string) ($_POST['kind'] ?? '');
        if (!isset($kinds[$postKind])) permissionDenied();
        if (!$tablesReady) error(__('tbt_need_upgrade'));
        $result = (new TranslationPublish(db()))->publishReviewed($postKind, array_map('intval', (array) ($_POST['ids'] ?? [])),
            $defaultLang, array_map('strval', array_keys($langs)), time());
        if ($result['published'] === [] && $result['skipped'] === []) error(__('tss_publish_none'));
        if ($result['published'] !== []) adminLog('translate', 'publish_reviewed', "批量发布已审译文（{$postKind}）：" . implode(',', $result['published']));
        $message = __('tss_publish_done', ['n' => (string) count($result['published'])]);
        if ($result['skipped'] !== []) $message .= ' ' . __('tss_publish_skipped', ['n' => (string) count($result['skipped'])]);
        success(['published' => $result['published'], 'skipped' => $result['skipped']], $message);
    }
    error(__('operation_failed'));
}
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

/**
 * 逐块算状态（includes/TranslationCoverage.php，与交付检查清单同一份判断），只留下当前页要显示的行；顶部计数覆盖全部产品。
 * 一格：['id' => 译文 id（0=缺失）, 'status' => TranslationState::status() 或 null, 'draft' => bool]
 */
$rows = [];
$totals = array_fill_keys($filters, 0);
$matched = 0;
if ($tablesReady && $langs !== []) {
    ['totals' => $totals, 'matched' => $matched, 'rows' => $rows] = (new TranslationCoverage(db()))
        ->page($defaultLang, array_map('strval', array_keys($langs)), $kind, $filter, $keyword, $page, $perPage);
}
$pages = max(1, (int) ceil($matched / $perPage));
$url = static fn (array $changes): string => BasePath::url('/admin/translation_status.php?' . http_build_query(array_filter(
    array_merge(['kind' => $kind, 'filter' => $filter, 'q' => $keyword, 'page' => $page], $changes),
    static fn (mixed $value): bool => $value !== '' && $value !== 'all' && $value !== 1 && $value !== 'products')));
$sourceLink = $kinds[$kind]['source'];
$targetLink = $kinds[$kind]['target'];
$batchable = $kind === 'products' && $canBatch;   // 批量翻译任务目前只收产品
$badge = static function (array $cell): array {
    $status = $cell['status'];
    if ($status === null) return ['tss_missing', 'bg-gray-50 text-gray-400 border border-dashed'];
    if ($status['proposal']) return ['lse_state_proposal', 'bg-blue-100 text-blue-700'];
    if ($status['outdated']) return ['lse_state_outdated', 'bg-amber-100 text-amber-700'];
    if ($status['state'] === 'untracked') return ['lse_state_untracked', 'bg-gray-100 text-gray-600'];
    if ($status['reviewed']) return ['lse_state_reviewed', 'bg-green-100 text-green-700'];
    if ($status['edited']) return ['lse_state_edited', 'bg-green-100 text-green-700'];
    return ['lse_state_current', 'bg-green-100 text-green-700'];
};

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6 max-w-7xl">
    <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-bold text-gray-800"><?php echo e(__('tss_title')); ?></h1>
            <p class="text-sm text-gray-500 mt-1 max-w-3xl"><?php echo e(__('tss_center_intro')); ?></p>
            <?php if ($batchable): ?><p class="text-sm text-gray-500 mt-1 max-w-3xl"><?php echo e(__('tss_intro')); ?></p><?php endif; ?>
        </div>
        <?php if ($canBatch): ?><a href="<?php echo e(BasePath::url('/admin/translation_tasks.php')); ?>" data-testid="tss-batch-tasks" class="text-sm text-primary hover:underline"><?php echo e(__('tbt_title')); ?></a><?php endif; ?>
    </div>

    <?php if (!$tablesReady): ?>
    <div class="bg-amber-50 border-l-4 border-amber-400 text-amber-800 text-sm px-4 py-3" role="alert"><?php echo e(__('tbt_need_upgrade')); ?></div>
    <?php elseif ($langs === []): ?>
    <p class="bg-white rounded-lg shadow px-6 py-8 text-sm text-gray-500"><?php echo e(__('tss_no_langs')); ?></p>
    <?php else: ?>
    <nav class="flex flex-wrap gap-1 mb-4 border-b" aria-label="<?php echo e(__('tss_kinds')); ?>">
        <?php foreach (array_keys($kinds) as $name): ?>
        <a href="<?php echo e($url(['kind' => $name, 'filter' => 'all', 'q' => '', 'page' => 1])); ?>" data-kind="<?php echo e($name); ?>"
           class="px-4 py-2 text-sm -mb-px border-b-2 <?php echo $name === $kind ? 'border-primary text-primary font-medium' : 'border-transparent text-gray-600 hover:text-gray-800'; ?>"
           <?php echo $name === $kind ? 'aria-current="page"' : ''; ?>><?php echo e(__('tss_kind_' . $name)); ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="flex flex-wrap items-center gap-2 mb-4" role="group" aria-label="<?php echo e(__('tss_filter')); ?>">
        <?php foreach ($filters as $name): ?>
        <a href="<?php echo e($url(['filter' => $name, 'page' => 1])); ?>" data-filter="<?php echo e($name); ?>"
           class="px-3 py-1 rounded-full text-sm <?php echo $name === $filter ? 'bg-primary text-white' : 'bg-white border text-gray-600 hover:bg-gray-50'; ?>"
           <?php echo $name === $filter ? 'aria-current="page"' : ''; ?>><?php echo e(__('tss_filter_' . $name)); ?> <?php echo (int) $totals[$name]; ?></a>
        <?php endforeach; ?>
        <form method="get" class="ml-auto flex gap-2">
            <?php if ($kind !== 'products'): ?><input type="hidden" name="kind" value="<?php echo e($kind); ?>"><?php endif; ?>
            <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?php echo e($filter); ?>"><?php endif; ?>
            <input type="search" name="q" value="<?php echo e($keyword); ?>" placeholder="<?php echo e(__('tss_search_any')); ?>" aria-label="<?php echo e(__('tss_search_any')); ?>" class="border rounded px-3 py-1 text-sm">
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b text-sm">
            <span class="text-gray-500"><?php echo e(__('tss_selected_any')); ?></span>
            <?php foreach ($langs as $code => $name): ?>
            <label class="flex items-center gap-1"><input type="checkbox" name="tss_langs[]" value="<?php echo e((string) $code); ?>" checked> <?php echo e((string) $name); ?></label>
            <?php endforeach; ?>
            <?php if ($batchable): ?>
            <button type="button" id="tssMissing" onclick="tssCreate('missing', this)" class="border px-3 py-1 rounded"><?php echo e(__('tss_translate_missing')); ?></button>
            <button type="button" id="tssUpdate" onclick="tssCreate('update', this)" class="border px-3 py-1 rounded" title="<?php echo e(__('tss_update_hint')); ?>"><?php echo e(__('tss_update_outdated')); ?></button>
            <?php endif; ?>
            <button type="button" id="tssPublish" data-confirm="<?php echo e(__('tbt_confirm_again')); ?>" onclick="tssPublish(this)" class="border border-green-300 text-green-800 px-3 py-1 rounded" title="<?php echo e(__('tss_publish_hint')); ?>"><?php echo e(__('tss_publish_reviewed')); ?></button>
        </div>
        <?php if ($rows === []): ?>
        <p class="px-6 py-8 text-sm text-gray-500"><?php echo e(__('tss_empty_any')); ?></p>
        <?php else: ?>
        <table class="w-full text-sm" id="tssTable">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr>
                    <th class="px-4 py-2 w-8"><input type="checkbox" aria-label="<?php echo e(__('tss_select_all')); ?>" onchange="document.querySelectorAll('#tssTable tbody input[type=checkbox]').forEach((box) => { box.checked = this.checked; })"></th>
                    <th class="px-4 py-2"><?php echo e(__('tss_kind_' . $kind)); ?></th>
                    <?php foreach ($langs as $name): ?><th class="px-4 py-2 whitespace-nowrap"><?php echo e((string) $name); ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr class="border-t align-top" data-source="<?php echo $row['id']; ?>" data-source-live="<?php echo $row['live'] ? '1' : '0'; ?>">
                    <td class="px-4 py-2"><input type="checkbox" value="<?php echo $row['id']; ?>" aria-label="<?php echo e($row['title']); ?>"></td>
                    <td class="px-4 py-2"><a class="text-primary hover:underline" href="<?php echo e(BasePath::url($sourceLink($row['id'], $defaultLang))); ?>"><?php echo e($row['title'] !== '' ? $row['title'] : '#' . $row['id']); ?></a>
                        <?php if (!$row['live']): ?><span class="text-xs text-gray-400 ml-1"><?php echo e(__('tss_draft')); ?></span><?php endif; ?></td>
                    <?php foreach ($row['cells'] as $lang => $cell):
                        [$key, $class] = $badge($cell);
                        $next = TranslationCoverage::nextAction($cell);
                        // 补译在原文的编辑页里做（语言版本条）；其余在译文自己的编辑页
                        $nextUrl = $next === 'translate' ? $sourceLink($row['id'], $defaultLang) : $targetLink($cell['id'], (string) $lang);
                    ?>
                    <td class="px-4 py-2 whitespace-nowrap" data-lang="<?php echo e($lang); ?>" data-target="<?php echo (int) $cell['id']; ?>"
                        data-live="<?php echo $cell['id'] > 0 && !$cell['draft'] ? '1' : '0'; ?>" data-next="<?php echo e($next); ?>"
                        data-ready="<?php echo $row['live'] && TranslationCoverage::ready($cell) ? '1' : '0'; ?>">
                        <?php if ($cell['id'] > 0): ?>
                        <a href="<?php echo e(BasePath::url($targetLink($cell['id'], (string) $lang))); ?>" class="px-2 py-0.5 rounded text-xs <?php echo $class; ?>" title="<?php echo e(__($key . '_hint')); ?>"><?php echo e(__($key)); ?></a>
                        <span class="text-xs ml-1 <?php echo $cell['draft'] ? 'text-gray-400' : 'text-green-700'; ?>"><?php echo e(__($cell['draft'] ? 'tss_draft' : 'tss_published')); ?></span>
                        <?php else: ?>
                        <span class="px-2 py-0.5 rounded text-xs <?php echo $class; ?>"><?php echo e(__($key)); ?></span>
                        <?php endif; ?>
                        <?php if ($next !== ''): ?>
                        <div class="mt-1"><a class="text-xs text-primary hover:underline" href="<?php echo e(BasePath::url($nextUrl)); ?>"><?php echo e(__('tss_next_' . $next)); ?> →</a></div>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1): ?>
    <nav class="flex gap-3 justify-end mt-4 text-sm" aria-label="<?php echo e(__('tss_pages')); ?>">
        <?php if ($page > 1): ?><a class="text-primary hover:underline" href="<?php echo e($url(['page' => $page - 1])); ?>">← <?php echo $page - 1; ?></a><?php endif; ?>
        <span class="text-gray-500"><?php echo $page; ?> / <?php echo $pages; ?></span>
        <?php if ($page < $pages): ?><a class="text-primary hover:underline" href="<?php echo e($url(['page' => $page + 1])); ?>"><?php echo $page + 1; ?> →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
    <script>
    // 勾选的产品 × 勾选的语言 → 建批次，跳到任务页去跑
    // 批量发布：勾选的行 × 勾选的语言里「已审、可直接发布」的草稿译文。两步按钮，第二次点击才提交；服务端逐条重新判断
    async function tssPublish(button) {
        const langs = Array.from(document.querySelectorAll('input[name="tss_langs[]"]:checked')).map((box) => box.value);
        const ids = Array.from(document.querySelectorAll('#tssTable tbody input[type=checkbox]:checked')).flatMap((box) =>
            Array.from(box.closest('tr').querySelectorAll('td[data-ready="1"]')).filter((cell) => langs.includes(cell.dataset.lang)).map((cell) => cell.dataset.target));
        if (ids.length === 0) { showMessage(<?php echo json_encode(__('tss_publish_none'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>, 'error'); return; }
        if (button.dataset.armed !== '1') {
            button.dataset.armed = '1';
            button.dataset.label = button.textContent;
            button.textContent = button.dataset.confirm + ' (' + ids.length + ')';
            setTimeout(() => { button.dataset.armed = ''; button.textContent = button.dataset.label; }, 5000);
            return;
        }
        button.disabled = true;
        const fd = new FormData();
        fd.append('action', 'publish');
        fd.append('kind', <?php echo json_encode($kind); ?>);
        ids.forEach((id) => fd.append('ids[]', id));
        try {
            const data = await safeJson(await fetch(location.pathname, { method: 'POST', body: fd }));
            if (data.code !== 0) throw new Error(data.msg || 'error');
            showMessage(data.msg);
            setTimeout(() => location.reload(), 900);
        } catch (e) {
            button.disabled = false;
            showMessage(e.message, 'error');
        }
    }
    async function tssCreate(mode, button) {
        const ids = Array.from(document.querySelectorAll('#tssTable tbody input[type=checkbox]:checked')).map((box) => box.value);
        const langs = Array.from(document.querySelectorAll('input[name="tss_langs[]"]:checked')).map((box) => box.value);
        if (ids.length === 0 || langs.length === 0) { showMessage(<?php echo json_encode(__('tss_pick_first'), JSON_UNESCAPED_UNICODE); ?>, 'error'); return; }
        button.disabled = true;
        const fd = new FormData();
        fd.append('action', 'create');
        fd.append('mode', mode);
        ids.forEach((id) => fd.append('ids[]', id));
        langs.forEach((lang) => fd.append('langs[]', lang));
        try {
            const data = await safeJson(await fetch(<?php echo json_encode(BasePath::url('/admin/translation_tasks.php'), JSON_UNESCAPED_SLASHES); ?>, { method: 'POST', body: fd }));
            if (data.code !== 0) throw new Error(data.msg || 'error');
            showMessage(data.msg);
            location.href = <?php echo json_encode(BasePath::url('/admin/translation_tasks.php?id='), JSON_UNESCAPED_SLASHES); ?> + data.data.id;
        } catch (e) {
            button.disabled = false;
            showMessage(e.message, 'error');
        }
    }
    </script>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
