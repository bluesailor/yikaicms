<?php
/**
 * Yikai CMS - AI 助手（Abilities API + function-calling）
 *
 * 通过 /admin/api_ai_agent.php 调用 chatWithTools，
 * 让 AI 自主调用已注册的 abilities 完成任务。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

if (!class_exists('AiService')) {
    require_once ROOT_PATH . '/includes/AiService.php';
}

checkLogin();
requirePermission('*');

$currentMenu = 'ai_assistant';
$pageTitle = __('admin_ai_assistant');
$abilities = Abilities::all();
$abilitySchemaForUi = static function (array $node) use (&$abilitySchemaForUi): array {
    foreach ($node as $key => $value) {
        if ($key === 'description') {
            unset($node[$key]);
        } elseif (is_array($value)) {
            $node[$key] = $abilitySchemaForUi($value);
        }
    }
    return $node;
};

$abilityUi = [
    'cms_search_content' => [__('ability_cms_search_content_label'), __('ability_cms_search_content_desc')],
    'cms_list_drafts' => [__('ability_cms_list_drafts_label'), __('ability_cms_list_drafts_desc')],
    'cms_get_content' => [__('ability_cms_get_content_label'), __('ability_cms_get_content_desc')],
    'cms_publish_content' => [__('ability_cms_publish_content_label'), __('ability_cms_publish_content_desc')],
    'cms_create_article_draft' => [__('ability_cms_create_article_draft_label'), __('ability_cms_create_article_draft_desc')],
    'cms_generate_seo_summary' => [__('ability_cms_generate_seo_summary_label'), __('ability_cms_generate_seo_summary_desc')],
    'cms_auto_tag_content' => [__('ability_cms_auto_tag_content_label'), __('ability_cms_auto_tag_content_desc')],
    'cms_translate_text' => [__('ability_cms_translate_text_label'), __('ability_cms_translate_text_desc')],
    'cms_navigate_admin' => [__('ability_cms_navigate_admin_label'), __('ability_cms_navigate_admin_desc')],
    'cms_list_common_settings' => [__('ability_cms_list_common_settings_label'), __('ability_cms_list_common_settings_desc')],
    'cms_get_setting' => [__('ability_cms_get_setting_label'), __('ability_cms_get_setting_desc')],
    'cms_update_setting' => [__('ability_cms_update_setting_label'), __('ability_cms_update_setting_desc')],
    'cms_list_channels' => [__('ability_cms_list_channels_label'), __('ability_cms_list_channels_desc')],
    'cms_set_content_flags' => [__('ability_cms_set_content_flags_label'), __('ability_cms_set_content_flags_desc')],
];
$aiConfigured = aiService()->isConfigured();
$cfg = AiService::getProviders()[config('ai_provider', 'openai')] ?? null;
$supported = $cfg && $cfg['format'] === 'openai';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800"><?php echo __('admin_ai_assistant'); ?></h1>
            <p class="text-sm text-gray-500 mt-1"><?php echo e(__('aia_intro')); ?></p>
        </div>
        <a href="/admin/setting_ai.php" class="text-sm text-primary hover:underline">→ <?php echo e(__('aia_settings')); ?></a>
    </div>

    <?php if (!$aiConfigured): ?>
    <div class="mb-6 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
        <?php echo str_replace(':link', '<a href="/admin/setting_ai.php" class="underline">' . e(__('aia_settings')) . '</a>', e(__('aia_not_configured'))); ?>
    </div>
    <?php elseif (!$supported): ?>
    <div class="mb-6 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
        <?php echo str_replace(':provider', e(config('ai_provider', '')), e(__('aia_no_function_calling'))); ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- 聊天区 -->
        <div class="lg:col-span-2 bg-white rounded-lg shadow flex flex-col" style="min-height: 600px;">
            <div id="chatArea" class="flex-1 p-4 overflow-y-auto space-y-3" style="max-height: 540px;">
                <div class="text-center text-gray-400 text-sm py-12">
                    <?php echo e(__('aia_start_hint')); ?><br>
                    <span class="inline-block mt-2 text-gray-500"><?php echo e(__('aia_example1')); ?></span><br>
                    <span class="inline-block mt-1 text-gray-500"><?php echo e(__('aia_example2')); ?></span><br>
                    <span class="inline-block mt-1 text-gray-500"><?php echo e(__('aia_example3')); ?></span><br>
                    <span class="inline-block mt-2 text-xs text-amber-600"><?php echo e(__('aia_confirm_note')); ?></span>
                </div>
            </div>

            <form id="chatForm" class="border-t p-3 flex gap-2">
                <input id="promptInput" type="text" placeholder="<?php echo e(__('aia_input_ph')); ?>"
                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                <button type="submit" id="sendBtn"
                        class="px-5 py-2 bg-primary text-white rounded-lg hover:opacity-90 transition disabled:opacity-50 cursor-pointer">
                    <?php echo e(__('aia_send')); ?>
                </button>
            </form>
        </div>

        <!-- 能力清单 -->
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-bold text-gray-800"><?php echo str_replace(':n', (string) count($abilities), e(__('aia_abilities'))); ?></h2>
                <button id="toggleAll" type="button" class="text-xs text-gray-500 hover:text-primary cursor-pointer"><?php echo e(__('nav_menu_collapse_all')); ?></button>
            </div>
            <div class="space-y-1.5 max-h-[540px] overflow-y-auto pr-1">
                <?php foreach ($abilities as $name => $a): ?>
                <details class="group rounded border border-gray-100 hover:border-gray-200">
                    <summary class="cursor-pointer px-3 py-2 text-sm flex items-center justify-between">
                        <span class="font-mono text-xs text-primary"><?php echo e($name); ?></span>
                        <span class="text-xs text-gray-400 group-open:rotate-180 transition">▾</span>
                    </summary>
                    <div class="px-3 pb-3 text-xs text-gray-600 space-y-1">
                        <?php $ui = $abilityUi[$name] ?? [(string) $a['label'], (string) $a['description']]; ?>
                        <div><span class="font-semibold"><?php echo e($ui[0]); ?></span></div>
                        <div class="text-gray-500"><?php echo e($ui[1]); ?></div>
                        <pre class="bg-gray-50 rounded p-2 text-[11px] overflow-x-auto"><?php echo e(json_encode($abilitySchemaForUi($a['input_schema']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .msg-user { background: #eff6ff; border-color: #bfdbfe; }
    .msg-ai { background: #f9fafb; border-color: #e5e7eb; }
    .msg-tool { background: #fef3c7; border-color: #fde68a; font-family: ui-monospace, monospace; font-size: 11px; }
    .msg-error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
    .typing::after { content: '▍'; animation: blink 1s steps(2) infinite; }
    @keyframes blink { 50% { opacity: 0; } }
</style>

<script>
(function () {
    const chatArea = document.getElementById('chatArea');
    const form = document.getElementById('chatForm');
    const input = document.getElementById('promptInput');
    const sendBtn = document.getElementById('sendBtn');

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function linkifyAdmin(s) {
        return escapeHtml(s).replace(/(\/admin\/[a-z_]+\.php(?:\?[^\s<]*)?)/gi,
            '<a href="$1" class="text-primary underline">$1</a>');
    }

    function addMsg(role, text) {
        // 第一条消息时清掉 placeholder
        if (chatArea.querySelector('.text-gray-400')) chatArea.innerHTML = '';

        const cls = role === 'user' ? 'msg-user' : (role === 'error' ? 'msg-error' : 'msg-ai');
        const label = role === 'user' ? <?php echo json_encode(__('aia_you'), JSON_UNESCAPED_UNICODE); ?> : (role === 'error' ? <?php echo json_encode(__('aia_error'), JSON_UNESCAPED_UNICODE); ?> : 'AI');
        const wrap = document.createElement('div');
        wrap.className = `border rounded-lg px-3 py-2 ${cls}`;
        const bodyHtml = role === 'ai' ? linkifyAdmin(text) : escapeHtml(text);
        wrap.innerHTML = `<div class="text-[11px] uppercase tracking-wide text-gray-500 mb-1">${label}</div><div class="whitespace-pre-wrap text-sm text-gray-800">${bodyHtml}</div>`;
        chatArea.appendChild(wrap);
        chatArea.scrollTop = chatArea.scrollHeight;
        return wrap;
    }

    function addToolCall(call) {
        const wrap = document.createElement('details');
        wrap.className = 'border rounded-lg px-3 py-2 msg-tool';
        const ok = call.result && call.result.success;
        const summary = `🔧 ${escapeHtml(call.name)} ${ok ? '✓' : '✗'}`;
        const argsJson = JSON.stringify(call.args, null, 2);
        const resJson = JSON.stringify(call.result, null, 2);
        wrap.innerHTML = `<summary class="cursor-pointer">${summary}</summary>
            <div class="mt-2 space-y-1">
                <div><span class="opacity-60">args:</span><pre class="mt-0.5 whitespace-pre-wrap">${escapeHtml(argsJson)}</pre></div>
                <div><span class="opacity-60">result:</span><pre class="mt-0.5 whitespace-pre-wrap">${escapeHtml(resJson)}</pre></div>
            </div>`;
        chatArea.appendChild(wrap);
        chatArea.scrollTop = chatArea.scrollHeight;
    }

    // 待确认的写操作提案卡片
    function stagedProposalsFromToolCalls(toolCalls) {
        const staged = (toolCalls || []).filter(call => call.result && call.result.staged && call.result.proposal_id);
        if (!staged.length) return null;
        const setId = staged[0].result.proposal_set_id || '';
        return {
            setId,
            proposals: staged.map(call => ({
                id: call.result.proposal_id,
                ability: call.name,
                label: call.name,
                summary: call.result.summary || call.name
            }))
        };
    }
    function addProposals(proposals, setId) {
        if (chatArea.querySelector('.text-gray-400')) chatArea.innerHTML = '';
        const wrap = document.createElement('div');
        wrap.className = 'border border-amber-200 bg-amber-50 rounded-lg px-3 py-3';
        const list = proposals.map(p =>
            `<div class="flex items-start gap-2 py-0.5"><span class="text-amber-600 mt-0.5">✎</span>` +
            `<div class="text-sm text-gray-800">${escapeHtml(p.summary || p.label)}</div></div>`).join('');
        wrap.innerHTML =
            `<div class="text-[11px] uppercase tracking-wide text-amber-700 mb-1">${<?php echo json_encode(__('ai_pending_changes'), JSON_UNESCAPED_UNICODE); ?>} (${proposals.length})</div>` +
            list +
            `<div class="mt-2 flex gap-2">` +
            `<button class="btn-apply px-3 py-1.5 bg-primary text-white text-sm rounded-lg cursor-pointer">${<?php echo json_encode(__('aia_confirm_apply'), JSON_UNESCAPED_UNICODE); ?>}</button>` +
            `<button class="btn-ignore px-3 py-1.5 border border-gray-300 text-gray-500 text-sm rounded-lg cursor-pointer">${<?php echo json_encode(__('ai_ignore'), JSON_UNESCAPED_UNICODE); ?>}</button>` +
            `</div>`;
        chatArea.appendChild(wrap);
        chatArea.scrollTop = chatArea.scrollHeight;

        wrap.querySelector('.btn-ignore').addEventListener('click', () => wrap.remove());
        const applyBtn = wrap.querySelector('.btn-apply');
        applyBtn.addEventListener('click', async () => {
            applyBtn.disabled = true; applyBtn.textContent = <?php echo json_encode(__('aia_applying'), JSON_UNESCAPED_UNICODE); ?>;
            try {
                const fd = new FormData(); fd.append('set_id', setId);
                const res = await fetch('/admin/api_ai_apply.php', { method: 'POST', body: fd });
                const data = await res.json();
                if ((data.applied && data.applied.length) || data.success) {
                    renderApplied(wrap, data.applied || [], data.errors || []);
                } else {
                    applyBtn.disabled = false; applyBtn.textContent = <?php echo json_encode(__('aia_confirm_apply'), JSON_UNESCAPED_UNICODE); ?>;
                    addMsg('error', data.error || (data.errors || []).join('；') || <?php echo json_encode(__('ai_apply_failed'), JSON_UNESCAPED_UNICODE); ?>);
                }
            } catch (e) {
                applyBtn.disabled = false; applyBtn.textContent = <?php echo json_encode(__('aia_confirm_apply'), JSON_UNESCAPED_UNICODE); ?>;
                addMsg('error', <?php echo json_encode(__('ai_network_error'), JSON_UNESCAPED_UNICODE); ?> + e.message);
            }
        });
    }

    function renderApplied(wrap, applied, errors) {
        wrap.className = 'border border-green-200 bg-green-50 rounded-lg px-3 py-3';
        let html = `<div class="text-[11px] uppercase tracking-wide text-green-700 mb-1">${<?php echo json_encode(__('ai_applied'), JSON_UNESCAPED_UNICODE); ?>} ✓</div>`;
        html += applied.map(a =>
            `<div class="flex items-center justify-between gap-2 py-0.5">` +
            `<div class="text-sm text-gray-800">${escapeHtml(a.summary)}</div>` +
            `<button class="btn-undo text-xs text-gray-500 underline cursor-pointer" data-log="${a.log_id}">${<?php echo json_encode(__('ai_undo'), JSON_UNESCAPED_UNICODE); ?>}</button></div>`).join('');
        if (errors && errors.length) {
            html += `<div class="text-xs text-red-600 mt-1">${errors.map(escapeHtml).join('<br>')}</div>`;
        }
        wrap.innerHTML = html;
        wrap.querySelectorAll('.btn-undo').forEach(b => b.addEventListener('click', async () => {
            b.disabled = true; b.textContent = <?php echo json_encode(__('aia_undoing'), JSON_UNESCAPED_UNICODE); ?>;
            try {
                const fd = new FormData(); fd.append('id', b.dataset.log);
                const res = await fetch('/admin/api_ai_undo.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { b.textContent = <?php echo json_encode(__('ai_undone'), JSON_UNESCAPED_UNICODE); ?>; b.classList.remove('text-gray-500'); b.classList.add('text-green-600'); }
                else { b.disabled = false; b.textContent = <?php echo json_encode(__('ai_undo'), JSON_UNESCAPED_UNICODE); ?>; addMsg('error', data.error || <?php echo json_encode(__('ai_undo_failed'), JSON_UNESCAPED_UNICODE); ?>); }
            } catch (e) { b.disabled = false; b.textContent = <?php echo json_encode(__('ai_undo'), JSON_UNESCAPED_UNICODE); ?>; addMsg('error', <?php echo json_encode(__('ai_network_error'), JSON_UNESCAPED_UNICODE); ?> + e.message); }
        }));
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const prompt = input.value.trim();
        if (!prompt) return;

        addMsg('user', prompt);
        input.value = '';
        sendBtn.disabled = true;
        sendBtn.textContent = <?php echo json_encode(__('ai_thinking'), JSON_UNESCAPED_UNICODE); ?>;
        const placeholder = addMsg('ai', '…');
        placeholder.querySelector('.text-sm').classList.add('typing');
        placeholder.querySelector('.text-sm').textContent = '';

        try {
            const fd = new FormData();
            fd.append('prompt', prompt);
            const res = await fetch('/admin/api_ai_agent.php', { method: 'POST', body: fd });
            const data = await res.json();

            placeholder.remove();

            if (data.tool_calls && data.tool_calls.length) {
                data.tool_calls.forEach(addToolCall);
            }

            if (data.success) {
                addMsg('ai', data.content || <?php echo json_encode(__('ai_no_reply'), JSON_UNESCAPED_UNICODE); ?>);
            } else {
                addMsg('error', data.error || <?php echo json_encode(__('ai_unknown_error'), JSON_UNESCAPED_UNICODE); ?>);
            }

            // 待确认的写操作提案
            const stagedFallback = stagedProposalsFromToolCalls(data.tool_calls);
            const proposals = (data.proposals && data.proposals.length) ? data.proposals : (stagedFallback ? stagedFallback.proposals : []);
            const proposalSetId = data.proposal_set_id || (stagedFallback ? stagedFallback.setId : '');
            if (proposals.length) {
                if (proposalSetId) {
                    addProposals(proposals, proposalSetId);
                } else {
                    addMsg('error', <?php echo json_encode(__('aia_no_proposal_id'), JSON_UNESCAPED_UNICODE); ?>);
                }
            } else if (data.content && /暂存|待确认|确认|pending|confirm/.test(data.content)) {
                addMsg('error', <?php echo json_encode(__('aia_no_applicable'), JSON_UNESCAPED_UNICODE); ?>);
            }
        } catch (err) {
            placeholder.remove();
            addMsg('error', <?php echo json_encode(__('ai_network_error'), JSON_UNESCAPED_UNICODE); ?> + err.message);
        } finally {
            sendBtn.disabled = false;
            sendBtn.textContent = <?php echo json_encode(__('aia_send'), JSON_UNESCAPED_UNICODE); ?>;
            input.focus();
        }
    });

    document.getElementById('toggleAll').addEventListener('click', function () {
        const items = document.querySelectorAll('details.group');
        const allOpen = Array.from(items).every(d => d.open);
        items.forEach(d => d.open = !allOpen);
        this.textContent = allOpen ? <?php echo json_encode(__('nav_menu_expand_all'), JSON_UNESCAPED_UNICODE); ?> : <?php echo json_encode(__('nav_menu_collapse_all'), JSON_UNESCAPED_UNICODE); ?>;
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
