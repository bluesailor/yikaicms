<?php
/**
 * Yikai CMS - 翻译状态（评审 T04，第一条切片：产品）
 *
 * 默认语言的产品 × 已启用的其它语言：每格是该语言译文的状态——缺失、最新、原文已改（过期）、
 * 人改过、已审、待审、未追踪。可筛出有问题的行，勾选后建「翻译缺失项」或「更新过期译文」批次。
 * 状态来自 translation_links 与当前内容的指纹比对（includes/TranslationState.php），本页只读。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/TranslationState.php';

checkLogin();
requirePermission('edit_product');

$currentMenu = 'translation_status';
$pageTitle = __('tss_title');
$tablesReady = db()->tableExists('translation_links') && db()->tableExists('translation_batches');
$defaultLang = (string) config('site_lang', 'zh-CN');
$langs = array_diff_key(enabledLanguages(), [$defaultLang => true]);
$filters = ['all', 'missing', 'outdated', 'proposal', 'untracked'];
$filter = in_array($_GET['filter'] ?? '', $filters, true) ? (string) $_GET['filter'] : 'all';
$keyword = trim((string) ($_GET['q'] ?? ''));
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

/**
 * 逐块（100 个产品）算状态，只留下当前页要显示的行；顶部计数覆盖全部产品。
 * 一格：['id' => 译文 id（0=缺失）, 'status' => TranslationState::status() 或 null, 'draft' => bool]
 */
$rows = [];
$totals = array_fill_keys($filters, 0);
$matched = 0;
if ($tablesReady && $langs !== []) {
    $p = DB_PREFIX;
    $where = 'lang = ? AND deleted_at IS NULL';
    $params = [$defaultLang];
    if ($keyword !== '') {
        $where .= ' AND title LIKE ?';
        $params[] = '%' . $keyword . '%';
    }
    $states = new TranslationState(db());
    $langCodes = array_keys($langs);
    for ($offset = 0; ; $offset += 100) {
        $sources = db()->fetchAll("SELECT * FROM {$p}products WHERE {$where} ORDER BY id DESC LIMIT 100 OFFSET {$offset}", $params);
        if ($sources === []) break;
        $groups = [];
        foreach ($sources as $source) $groups[(int) ($source['translation_group_id'] ?: $source['id'])] = (int) $source['id'];
        $targets = db()->fetchAll("SELECT * FROM {$p}products WHERE deleted_at IS NULL AND translation_group_id IN (" . implode(',', array_fill(0, count($groups), '?')) . ')'
            . ' AND lang IN (' . implode(',', array_fill(0, count($langCodes), '?')) . ')', array_merge(array_keys($groups), $langCodes));
        $links = $states->links('products', array_map(static fn (array $row): int => (int) $row['id'], $targets));
        $byGroup = [];
        foreach ($targets as $target) {
            $groupId = (int) $target['translation_group_id'];
            if (($groups[$groupId] ?? 0) !== (int) $target['id']) $byGroup[$groupId][(string) $target['lang']] ??= $target;
        }
        foreach ($sources as $source) {
            $groupId = (int) ($source['translation_group_id'] ?: $source['id']);
            $sourceFp = TranslationState::rowFingerprint($source, 'title', 'summary');
            $cells = [];
            $flags = array_fill_keys($filters, false);
            $flags['all'] = true;
            foreach ($langCodes as $lang) {
                $target = $byGroup[$groupId][$lang] ?? null;
                if ($target === null) {
                    $cells[$lang] = ['id' => 0, 'status' => null, 'draft' => false];
                    $flags['missing'] = true;
                    continue;
                }
                $status = TranslationState::status($links[(int) $target['id']] ?? null, $sourceFp, TranslationState::rowFingerprint($target, 'title', 'summary'));
                $cells[$lang] = ['id' => (int) $target['id'], 'status' => $status, 'draft' => (int) ($target['status'] ?? 0) === 0];
                $flags['outdated'] = $flags['outdated'] || $status['outdated'];
                $flags['proposal'] = $flags['proposal'] || $status['proposal'];
                $flags['untracked'] = $flags['untracked'] || $status['state'] === 'untracked';
            }
            foreach ($flags as $name => $on) if ($on) $totals[$name]++;
            if (!$flags[$filter]) continue;
            $matched++;
            if ($matched > ($page - 1) * $perPage && $matched <= $page * $perPage) {
                $rows[] = ['id' => (int) $source['id'], 'title' => (string) $source['title'], 'cells' => $cells];
            }
        }
    }
}
$pages = max(1, (int) ceil($matched / $perPage));
$url = static fn (array $changes): string => BasePath::url('/admin/translation_status.php?' . http_build_query(array_filter(
    array_merge(['filter' => $filter, 'q' => $keyword, 'page' => $page], $changes), static fn (mixed $value): bool => $value !== '' && $value !== 'all' && $value !== 1)));
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
            <p class="text-sm text-gray-500 mt-1 max-w-3xl"><?php echo e(__('tss_intro')); ?></p>
        </div>
        <a href="<?php echo e(BasePath::url('/admin/translation_tasks.php')); ?>" class="text-sm text-primary hover:underline"><?php echo e(__('tbt_title')); ?></a>
    </div>

    <?php if (!$tablesReady): ?>
    <div class="bg-amber-50 border-l-4 border-amber-400 text-amber-800 text-sm px-4 py-3" role="alert"><?php echo e(__('tbt_need_upgrade')); ?></div>
    <?php elseif ($langs === []): ?>
    <p class="bg-white rounded-lg shadow px-6 py-8 text-sm text-gray-500"><?php echo e(__('tss_no_langs')); ?></p>
    <?php else: ?>
    <div class="flex flex-wrap items-center gap-2 mb-4" role="group" aria-label="<?php echo e(__('tss_filter')); ?>">
        <?php foreach ($filters as $name): ?>
        <a href="<?php echo e($url(['filter' => $name, 'page' => 1])); ?>" data-filter="<?php echo e($name); ?>"
           class="px-3 py-1 rounded-full text-sm <?php echo $name === $filter ? 'bg-primary text-white' : 'bg-white border text-gray-600 hover:bg-gray-50'; ?>"
           <?php echo $name === $filter ? 'aria-current="page"' : ''; ?>><?php echo e(__('tss_filter_' . $name)); ?> <?php echo (int) $totals[$name]; ?></a>
        <?php endforeach; ?>
        <form method="get" class="ml-auto flex gap-2">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?php echo e($filter); ?>"><?php endif; ?>
            <input type="search" name="q" value="<?php echo e($keyword); ?>" placeholder="<?php echo e(__('tss_search')); ?>" aria-label="<?php echo e(__('tss_search')); ?>" class="border rounded px-3 py-1 text-sm">
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b text-sm">
            <span class="text-gray-500"><?php echo e(__('tss_selected_action')); ?></span>
            <?php foreach ($langs as $code => $name): ?>
            <label class="flex items-center gap-1"><input type="checkbox" name="tss_langs[]" value="<?php echo e((string) $code); ?>" checked> <?php echo e((string) $name); ?></label>
            <?php endforeach; ?>
            <button type="button" id="tssMissing" onclick="tssCreate('missing', this)" class="border px-3 py-1 rounded"><?php echo e(__('tss_translate_missing')); ?></button>
            <button type="button" id="tssUpdate" onclick="tssCreate('update', this)" class="border px-3 py-1 rounded" title="<?php echo e(__('tss_update_hint')); ?>"><?php echo e(__('tss_update_outdated')); ?></button>
        </div>
        <?php if ($rows === []): ?>
        <p class="px-6 py-8 text-sm text-gray-500"><?php echo e(__('tss_empty')); ?></p>
        <?php else: ?>
        <table class="w-full text-sm" id="tssTable">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr>
                    <th class="px-4 py-2 w-8"><input type="checkbox" aria-label="<?php echo e(__('tss_select_all')); ?>" onchange="document.querySelectorAll('#tssTable tbody input[type=checkbox]').forEach((box) => { box.checked = this.checked; })"></th>
                    <th class="px-4 py-2"><?php echo e(__('tbt_col_product')); ?></th>
                    <?php foreach ($langs as $name): ?><th class="px-4 py-2 whitespace-nowrap"><?php echo e((string) $name); ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr class="border-t" data-source="<?php echo $row['id']; ?>">
                    <td class="px-4 py-2"><input type="checkbox" value="<?php echo $row['id']; ?>" aria-label="<?php echo e($row['title']); ?>"></td>
                    <td class="px-4 py-2"><a class="text-primary hover:underline" href="<?php echo e(BasePath::url('/admin/product_edit.php?id=' . $row['id'])); ?>"><?php echo e($row['title'] !== '' ? $row['title'] : '#' . $row['id']); ?></a></td>
                    <?php foreach ($row['cells'] as $lang => $cell): [$key, $class] = $badge($cell); ?>
                    <td class="px-4 py-2 whitespace-nowrap" data-lang="<?php echo e($lang); ?>">
                        <?php if ($cell['id'] > 0): ?>
                        <a href="<?php echo e(BasePath::url('/admin/product_edit.php?id=' . $cell['id'])); ?>" class="px-2 py-0.5 rounded text-xs <?php echo $class; ?>" title="<?php echo e(__($key . '_hint')); ?>"><?php echo e(__($key)); ?></a>
                        <?php if ($cell['draft']): ?><span class="text-xs text-gray-400 ml-1"><?php echo e(__('tss_draft')); ?></span><?php endif; ?>
                        <?php else: ?>
                        <span class="px-2 py-0.5 rounded text-xs <?php echo $class; ?>"><?php echo e(__($key)); ?></span>
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
