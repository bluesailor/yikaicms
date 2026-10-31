<?php
/**
 * Yikai CMS - AI 草稿提案审核
 *
 * 外部 AI 客户端（Claude Code、Codex 等）用访问令牌提交的文章草稿提案在这里审核。
 * 只显示本人的提案；应用后写成草稿（不发布），可撤销；拒绝后提案项终止。
 * 应用/拒绝/撤销都由 AiProposalService 在单一事务里完成，本页只传项目标识，不回传任何内容。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/ai-access/AiProposalService.php';
require_once ROOT_PATH . '/includes/ai-access/AiProposalReview.php';

checkLogin();
requirePermission('ai_access');

$currentMenu = 'ai_proposals';
$pageTitle = __('aipr_title');
$adminId = getAdminId();
$tablesReady = db()->tableExists('ai_proposals') && db()->tableExists('ai_proposal_items') && _aiprChangeLogReady();

/** 迁移前 ai_change_log 可能是助手按需建的旧结构，缺提案列时不能应用。 */
function _aiprChangeLogReady(): bool
{
    if (!db()->tableExists('ai_change_log')) return false;
    require_once ROOT_PATH . '/includes/Migrator.php';
    return _columnExists('ai_change_log', 'after_revision');
}

$errorText = static fn(string $code): string => in_array($code, ['ai_proposal_not_found', 'ai_proposal_not_pending', 'ai_proposal_busy', 'ai_proposal_undo_conflict', 'ai_proposal_actor_denied', 'ai_proposal_undo_unsupported'], true)
    ? __('aipr_err_' . substr($code, strlen('ai_proposal_')))
    : __('aipr_err_unavailable');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = static function (int $code, string $msg, array $data = []): never {
        echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!$tablesReady) $reply(1, __('aipr_need_upgrade'));
    $service = new AiProposalService(db());
    $action = (string) ($_POST['action'] ?? '');
    $ids = array_values(array_filter(array_map('strval', (array) ($_POST['item_ids'] ?? [])), static fn(string $id): bool => preg_match('/^[0-9a-f]{32}$/D', $id) === 1));

    if ($action === 'apply' || $action === 'reject') {
        if ($ids === [] || count($ids) > 50) $reply(1, __('aipr_err_select'));
        $results = $action === 'apply' ? $service->applyMany($adminId, $ids, time()) : $service->rejectMany($adminId, $ids, time());
        $done = 0;
        $messages = [];
        foreach ($results as $result) {
            if (($result['status'] ?? '') === 'error') {
                $messages[] = $errorText((string) $result['error']);
                continue;
            }
            $target = $action === 'apply' ? 'applied' : 'rejected';
            if ($result['status'] === $target) {
                $done++;
                if ($action === 'apply' && empty($result['replayed'])) {
                    adminLog('ai_proposal', 'apply', 'AI proposal applied as draft: ' . ((int) $result['content_id'] > 0 ? 'content #' . (int) $result['content_id'] : 'page draft') . ', log #' . (int) $result['log_id']);
                }
            } else {
                $messages[] = __('aipr_status_' . $result['status']) . ($result['reason'] !== '' ? '：' . __('aipr_reason_' . $result['reason']) : '');
            }
        }
        if ($action === 'reject' && $done > 0) adminLog('ai_proposal', 'reject', 'AI proposal items rejected: ' . $done);
        $summary = __($action === 'apply' ? 'aipr_applied_n' : 'aipr_rejected_n', ['n' => $done]);
        $reply($done === count($ids) ? 0 : 1, trim($summary . ' ' . implode('；', array_unique($messages))), ['results' => $results]);
    }

    if ($action === 'undo') {
        $logId = (int) ($_POST['log_id'] ?? 0);
        try {
            $result = $service->undo($adminId, $logId, time());
        } catch (RuntimeException $e) {
            $reply(1, $errorText($e->getMessage()));
        }
        if (empty($result['replayed'])) adminLog('ai_proposal', 'undo', 'AI proposal change undone: log #' . $logId);
        $reply(0, __('aipr_undone_msg'));
    }

    $reply(1, 'unknown action');
}

// 单页提案的私有预览：服务端算出“当前草稿 + 提案改动”，交给需登录与页面编辑权限的 Blox 预览端点渲染。
// 不保存、不生成公开链接；页面已变时不预览（应用也必然冲突）。
$previewItem = (string) ($_GET['preview'] ?? '');
if ($previewItem !== '') {
    requirePermission('blox_edit');
    requirePermission('edit_page');
    $preview = $tablesReady && preg_match('/^[0-9a-f]{32}$/D', $previewItem) === 1 ? (new AiProposalReview(db()))->previewDocument($adminId, $previewItem) : null;
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex');
    if ($preview === null) {
        http_response_code(409);
        echo '<!doctype html><meta charset="utf-8"><title>' . e(__('aipr_title')) . '</title><p style="font:14px sans-serif;padding:2rem">' . e(__('aipr_preview_unavailable')) . '</p>';
        exit;
    }
    $target = '/admin/blox_preview.php?id=' . $preview['page_id'] . '&_lang=' . rawurlencode($preview['lang']);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . e(__('aipr_preview')) . '</title></head><body>'
        . '<form id="aipr-preview" method="post" action="' . e($target) . '">' . csrfField()
        . '<input type="hidden" name="action" value="preview"><input type="hidden" name="blocks_data" value="' . e($preview['json']) . '">'
        . '<noscript><button type="submit">' . e(__('aipr_preview')) . '</button></noscript></form>'
        . '<script>document.getElementById("aipr-preview").submit();</script></body></html>';
    exit;
}

$tab = ($_GET['tab'] ?? '') === 'done' ? 'done' : 'pending';
$review = $tablesReady ? new AiProposalReview(db()) : null;
$sets = $review?->sets($adminId, $tab === 'pending') ?? [];
$pendingCount = $review?->pendingCount($adminId) ?? 0;
$statusClass = static fn(string $status): string => match ($status) {
    'pending' => 'bg-amber-100 text-amber-800',
    'applied' => 'bg-green-100 text-green-800',
    'conflicted', 'cancelled' => 'bg-red-100 text-red-700',
    default => 'bg-gray-100 text-gray-600',
};
$reasonText = static fn(string $reason): string => $reason === '' ? '' : __('aipr_reason_' . $reason);
$source = static fn(array $set): string => $set['source_ns'] === 'internal'
    ? __('aipr_source_internal')
    : __('aipr_source_token', ['name' => (string) ($set['token_name'] ?? ('#' . $set['token_id']))]);

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6 max-w-6xl">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><?php echo e(__('aipr_title')); ?></h1>
        <p class="text-sm text-gray-500 mt-1 max-w-3xl"><?php echo e(__('aipr_intro')); ?></p>
    </div>

    <?php if (!$tablesReady): ?>
    <div class="bg-amber-50 border-l-4 border-amber-400 text-amber-800 text-sm px-4 py-3 mb-6" role="alert"><?php echo e(__('aipr_need_upgrade')); ?></div>
    <?php else: ?>

    <nav class="flex gap-1 border-b mb-6" aria-label="<?php echo e(__('aipr_title')); ?>">
        <?php foreach (['pending' => __('aipr_tab_pending', ['n' => $pendingCount]), 'done' => __('aipr_tab_done')] as $key => $label): ?>
        <a href="?tab=<?php echo $key; ?>" class="px-4 py-2 -mb-px border-b-2 text-sm <?php echo $tab === $key ? 'border-primary text-gray-900 font-medium' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($sets === []): ?>
    <p class="text-sm text-gray-400" data-testid="aipr-empty"><?php echo e(__($tab === 'pending' ? 'aipr_none_pending' : 'aipr_none_done')); ?></p>
    <?php endif; ?>

    <?php foreach ($sets as $set): $hasPending = in_array('pending', array_map(static fn(array $v): string => (string) $v['item']['status'], $set['items']), true); ?>
    <section class="bg-white rounded-lg shadow mb-6" data-testid="aipr-set" data-proposal="<?php echo e((string) $set['public_id']); ?>">
        <header class="flex flex-wrap items-center justify-between gap-2 px-6 py-3 border-b">
            <div class="text-sm text-gray-600">
                <span class="font-medium text-gray-800"><?php echo e($source($set)); ?></span>
                <span class="mx-1 text-gray-300">·</span><?php echo e(date('Y-m-d H:i', (int) $set['created_at'])); ?>
                <?php if ($hasPending): ?><span class="mx-1 text-gray-300">·</span><?php echo e(__('aipr_expires', ['time' => date('Y-m-d H:i', (int) $set['expires_at'])])); ?><?php endif; ?>
            </div>
            <?php if ($hasPending): ?>
            <div class="flex gap-2">
                <button type="button" class="bg-primary hover:opacity-90 text-white px-4 py-1.5 rounded text-sm" onclick="aiprSelected(this, 'apply')"><?php echo e(__('aipr_apply_selected')); ?></button>
                <button type="button" class="border border-gray-300 hover:bg-gray-50 px-4 py-1.5 rounded text-sm text-red-600" data-confirm="<?php echo e(__('aipr_reject_confirm')); ?>" onclick="aiprSelected(this, 'reject')"><?php echo e(__('aipr_reject_selected')); ?></button>
            </div>
            <?php endif; ?>
        </header>

        <?php foreach ($set['items'] as $view): $item = $view['item']; $status = (string) $item['status']; $isPage = $item['operation'] === 'page_edit'; $hasCurrent = $isPage || $item['operation'] === 'update'; ?>
        <article class="px-6 py-4 border-b last:border-0" data-testid="aipr-item" data-item="<?php echo e((string) $item['public_id']); ?>" data-status="<?php echo e($status); ?>">
            <div class="flex flex-wrap items-start gap-3">
                <?php if ($status === 'pending'): ?>
                <input type="checkbox" class="aipr-pick mt-1 rounded border-gray-300 text-primary focus:ring-primary" value="<?php echo e((string) $item['public_id']); ?>" checked aria-label="<?php echo e(__('aipr_pick', ['title' => $view['title']])); ?>">
                <?php endif; ?>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-700"><?php echo e(__($isPage ? 'aipr_op_page' : ($item['operation'] === 'create' ? 'aipr_op_create' : 'aipr_op_update'))); ?></span>
                        <span class="font-medium text-gray-800 break-words"><?php echo e($view['title']); ?></span>
                        <span class="text-xs px-2 py-0.5 rounded <?php echo $statusClass($status); ?>"><?php echo e(__('aipr_status_' . $status)); ?></span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">
                        <?php echo e((string) $item['lang']); ?>
                        <?php if ($view['channel'] !== null): ?> · <?php echo e((string) $view['channel']['name']); ?><?php endif; ?>
                        <?php if ($item['operation'] === 'update'): ?> · <?php echo e(__('aipr_target', ['id' => (int) $item['target_id']])); ?><?php endif; ?>
                        <?php if ($isPage): ?> · <?php echo e(__('aipr_target_page', ['id' => (int) $item['target_id']])); ?><?php endif; ?>
                        <?php if ((string) $item['status_reason'] !== '' && $status !== 'reverted'): ?> · <?php echo e($reasonText((string) $item['status_reason'])); ?><?php endif; ?>
                    </div>
                    <?php if ($view['stale']): ?>
                    <p class="text-xs text-amber-700 mt-1"><?php echo e(__('aipr_stale')); ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex flex-wrap gap-3 text-sm w-full sm:w-auto">
                    <?php if ($status === 'pending' && $isPage && !$view['stale']): ?>
                    <a class="text-primary hover:underline" href="?preview=<?php echo e((string) $item['public_id']); ?>" target="_blank" rel="noopener"><?php echo e(__('aipr_preview')); ?></a>
                    <?php endif; ?>
                    <?php if ($status === 'pending'): ?>
                    <button type="button" class="text-primary hover:underline" onclick="aiprOne(this, 'apply')"><?php echo e(__('aipr_apply')); ?></button>
                    <button type="button" class="text-red-600 hover:underline" data-confirm="<?php echo e(__('aipr_reject_confirm')); ?>" onclick="aiprOne(this, 'reject')"><?php echo e(__('aipr_reject')); ?></button>
                    <?php endif; ?>
                    <?php if (in_array($status, ['applied', 'reverted'], true) && (int) $item['result_content_id'] > 0): ?>
                    <a class="text-primary hover:underline" href="article_edit.php?id=<?php echo (int) $item['result_content_id']; ?>"><?php echo e(__('aipr_open_draft')); ?></a>
                    <?php endif; ?>
                    <?php if ($isPage && $status === 'applied'): ?>
                    <a class="text-primary hover:underline" href="blox_editor.php?id=<?php echo (int) $item['target_id']; ?>"><?php echo e(__('aipr_open_page_draft')); ?></a>
                    <?php endif; ?>
                    <?php if ($status === 'applied'): ?>
                    <button type="button" class="text-red-600 hover:underline" data-log="<?php echo (int) $item['change_log_id']; ?>" data-confirm="<?php echo e(__('aipr_undo_confirm')); ?>" onclick="aiprUndo(this)"><?php echo e(__('aipr_undo')); ?></button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($view['fields'] !== [] || $view['content'] !== null): ?>
            <details class="mt-3"<?php echo $status === 'pending' ? ' open' : ''; ?>>
                <summary class="text-sm text-gray-600 cursor-pointer select-none"><?php echo e(__('aipr_changes')); ?></summary>
                <div class="mt-2 overflow-x-auto">
                    <?php // 表头放在 tbody 里：screen-options.js 会接管第一个 thead 有 3 列的表格，对照表不是列表。 ?>
                    <table class="w-full text-sm">
                        <tbody>
                        <tr class="text-left text-gray-400 border-b">
                            <th scope="col" class="py-1.5 pr-4 w-28 font-medium"><?php echo e(__('aipr_col_field')); ?></th>
                            <?php if ($hasCurrent): ?><th scope="col" class="py-1.5 pr-4 font-medium"><?php echo e(__('aipr_col_current')); ?></th><?php endif; ?>
                            <th scope="col" class="py-1.5 font-medium"><?php echo e(__('aipr_col_proposed')); ?></th>
                        </tr>
                        <?php foreach ($view['fields'] as $field): ?>
                            <tr class="border-b last:border-0 align-top<?php echo $field['changed'] ? '' : ' text-gray-400'; ?>">
                                <td class="py-1.5 pr-4 text-gray-500 break-words"><?php echo e($field['label'] ?? __('aipr_field_' . $field['field'])); ?></td>
                                <?php if ($hasCurrent): ?><td class="py-1.5 pr-4 break-words whitespace-pre-line"><?php echo e($field['before']); ?></td><?php endif; ?>
                                <td class="py-1.5 break-words whitespace-pre-line<?php echo $field['changed'] ? ' text-gray-900' : ''; ?>"><?php echo e($field['after']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($view['content'] !== null): ?>
                    <div class="mt-3">
                        <div class="text-xs text-gray-500 mb-1"><?php echo e(__($view['content']['changed'] ? 'aipr_content_changed' : 'aipr_content_same')); ?></div>
                        <div class="border rounded max-h-96 overflow-y-auto text-sm leading-6" data-testid="aipr-diff">
                            <?php foreach ($view['content']['diff'] as $line): ?>
                            <p class="px-3 py-0.5 break-words <?php echo match ($line['op']) { 'add' => 'bg-green-50 text-green-900', 'del' => 'bg-red-50 text-red-800 line-through', default => 'text-gray-600' }; ?>"><span class="sr-only"><?php echo e(__('aipr_diff_' . $line['op'])); ?></span><?php echo e($line['text']); ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
async function aiprSend(fd) {
    const r = await fetch(location.pathname, { method: 'POST', body: fd });
    return r.json();
}
// 不可轻易收回的操作（拒绝、撤销）用两步按钮：第一次点击只把文字换成确认语，不弹原生对话框。
function aiprArmed(button) {
    if (!button.dataset.confirm || button.dataset.armed === '1') return true;
    button.dataset.armed = '1';
    button.dataset.label = button.textContent;
    button.textContent = button.dataset.confirm;
    setTimeout(() => { button.dataset.armed = ''; button.textContent = button.dataset.label; }, 5000);
    return false;
}
async function aiprPost(button, action, ids) {
    if (!aiprArmed(button)) return;
    if (ids.length === 0) { showMessage(<?php echo json_encode(__('aipr_err_select'), JSON_UNESCAPED_UNICODE); ?>, 'error'); return; }
    button.disabled = true;
    const fd = new FormData(); fd.append('action', action);
    ids.forEach(id => fd.append('item_ids[]', id));
    const d = await aiprSend(fd);
    showMessage(d.msg || 'OK', d.code === 0 ? 'success' : 'error');
    setTimeout(() => location.reload(), d.code === 0 ? 600 : 1800);
}
function aiprOne(button, action) {
    aiprPost(button, action, [button.closest('[data-item]').dataset.item]);
}
function aiprSelected(button, action) {
    const set = button.closest('[data-proposal]');
    aiprPost(button, action, Array.from(set.querySelectorAll('.aipr-pick:checked')).map(el => el.value));
}
async function aiprUndo(button) {
    if (!aiprArmed(button)) return;
    button.disabled = true;
    const fd = new FormData(); fd.append('action', 'undo'); fd.append('log_id', button.dataset.log);
    const d = await aiprSend(fd);
    showMessage(d.msg || 'OK', d.code === 0 ? 'success' : 'error');
    setTimeout(() => location.reload(), d.code === 0 ? 600 : 1800);
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
