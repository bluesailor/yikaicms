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
$aiConfigured = aiService()->isConfigured();
$cfg = AiService::getProviders()[config('ai_provider', 'openai')] ?? null;
$supported = $cfg && $cfg['format'] === 'openai';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800"><?php echo __('admin_ai_assistant'); ?></h1>
            <p class="text-sm text-gray-500 mt-1">用自然语言指令让 AI 调用 CMS 能力。基于 Abilities API + function calling。</p>
        </div>
        <a href="/admin/setting_ai.php" class="text-sm text-primary hover:underline">→ AI 设置</a>
    </div>

    <?php if (!$aiConfigured): ?>
    <div class="mb-6 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
        AI 尚未配置。请先到 <a href="/admin/setting_ai.php" class="underline">AI 设置</a> 填写 API Key。
    </div>
    <?php elseif (!$supported): ?>
    <div class="mb-6 px-4 py-3 rounded-lg bg-amber-50 border border-amber-200 text-sm text-amber-800">
        当前 AI 供应商（<?php echo e(config('ai_provider', '')); ?>）暂不支持 function-calling。请切换到 OpenAI / DeepSeek / Qwen / 智谱。
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- 聊天区 -->
        <div class="lg:col-span-2 bg-white rounded-lg shadow flex flex-col" style="min-height: 600px;">
            <div id="chatArea" class="flex-1 p-4 overflow-y-auto space-y-3" style="max-height: 540px;">
                <div class="text-center text-gray-400 text-sm py-12">
                    输入指令开始对话。例如：<br>
                    <span class="inline-block mt-2 text-gray-500">"把 ICP 备案号填上：京ICP备2024099999号"</span><br>
                    <span class="inline-block mt-1 text-gray-500">"列出最近 5 篇草稿，挑标题最长的发布上线"</span><br>
                    <span class="inline-block mt-1 text-gray-500">"给文章 #12 生成 SEO 摘要并自动打标签"</span><br>
                    <span class="inline-block mt-2 text-xs text-amber-600">修改类操作会先给出「待确认的改动」，点确认才生效，可一键撤销</span>
                </div>
            </div>

            <form id="chatForm" class="border-t p-3 flex gap-2">
                <input id="promptInput" type="text" placeholder="问点什么…（Enter 发送）"
                       class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                <button type="submit" id="sendBtn"
                        class="px-5 py-2 bg-primary text-white rounded-lg hover:opacity-90 transition disabled:opacity-50 cursor-pointer">
                    发送
                </button>
            </form>
        </div>

        <!-- 能力清单 -->
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="font-bold text-gray-800">可用能力（<?php echo count($abilities); ?>）</h2>
                <button id="toggleAll" type="button" class="text-xs text-gray-500 hover:text-primary cursor-pointer">展开全部</button>
            </div>
            <div class="space-y-1.5 max-h-[540px] overflow-y-auto pr-1">
                <?php foreach ($abilities as $name => $a): ?>
                <details class="group rounded border border-gray-100 hover:border-gray-200">
                    <summary class="cursor-pointer px-3 py-2 text-sm flex items-center justify-between">
                        <span class="font-mono text-xs text-primary"><?php echo e($name); ?></span>
                        <span class="text-xs text-gray-400 group-open:rotate-180 transition">▾</span>
                    </summary>
                    <div class="px-3 pb-3 text-xs text-gray-600 space-y-1">
                        <div><span class="font-semibold"><?php echo e($a['label']); ?></span></div>
                        <div class="text-gray-500"><?php echo e($a['description']); ?></div>
                        <pre class="bg-gray-50 rounded p-2 text-[11px] overflow-x-auto"><?php echo e(json_encode($a['input_schema'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
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
        const label = role === 'user' ? '你' : (role === 'error' ? '错误' : 'AI');
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
            `<div class="text-[11px] uppercase tracking-wide text-amber-700 mb-1">待确认的改动 (${proposals.length})</div>` +
            list +
            `<div class="mt-2 flex gap-2">` +
            `<button class="btn-apply px-3 py-1.5 bg-primary text-white text-sm rounded-lg cursor-pointer">确认应用</button>` +
            `<button class="btn-ignore px-3 py-1.5 border border-gray-300 text-gray-500 text-sm rounded-lg cursor-pointer">忽略</button>` +
            `</div>`;
        chatArea.appendChild(wrap);
        chatArea.scrollTop = chatArea.scrollHeight;

        wrap.querySelector('.btn-ignore').addEventListener('click', () => wrap.remove());
        const applyBtn = wrap.querySelector('.btn-apply');
        applyBtn.addEventListener('click', async () => {
            applyBtn.disabled = true; applyBtn.textContent = '应用中…';
            try {
                const fd = new FormData(); fd.append('set_id', setId);
                const res = await fetch('/admin/api_ai_apply.php', { method: 'POST', body: fd });
                const data = await res.json();
                if ((data.applied && data.applied.length) || data.success) {
                    renderApplied(wrap, data.applied || [], data.errors || []);
                } else {
                    applyBtn.disabled = false; applyBtn.textContent = '确认应用';
                    addMsg('error', data.error || (data.errors || []).join('；') || '应用失败');
                }
            } catch (e) {
                applyBtn.disabled = false; applyBtn.textContent = '确认应用';
                addMsg('error', '网络错误：' + e.message);
            }
        });
    }

    function renderApplied(wrap, applied, errors) {
        wrap.className = 'border border-green-200 bg-green-50 rounded-lg px-3 py-3';
        let html = `<div class="text-[11px] uppercase tracking-wide text-green-700 mb-1">已应用 ✓</div>`;
        html += applied.map(a =>
            `<div class="flex items-center justify-between gap-2 py-0.5">` +
            `<div class="text-sm text-gray-800">${escapeHtml(a.summary)}</div>` +
            `<button class="btn-undo text-xs text-gray-500 underline cursor-pointer" data-log="${a.log_id}">撤销</button></div>`).join('');
        if (errors && errors.length) {
            html += `<div class="text-xs text-red-600 mt-1">${errors.map(escapeHtml).join('<br>')}</div>`;
        }
        wrap.innerHTML = html;
        wrap.querySelectorAll('.btn-undo').forEach(b => b.addEventListener('click', async () => {
            b.disabled = true; b.textContent = '撤销中…';
            try {
                const fd = new FormData(); fd.append('id', b.dataset.log);
                const res = await fetch('/admin/api_ai_undo.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) { b.textContent = '已撤销'; b.classList.remove('text-gray-500'); b.classList.add('text-green-600'); }
                else { b.disabled = false; b.textContent = '撤销'; addMsg('error', data.error || '撤销失败'); }
            } catch (e) { b.disabled = false; b.textContent = '撤销'; addMsg('error', '网络错误：' + e.message); }
        }));
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const prompt = input.value.trim();
        if (!prompt) return;

        addMsg('user', prompt);
        input.value = '';
        sendBtn.disabled = true;
        sendBtn.textContent = '思考中…';
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
                addMsg('ai', data.content || '(无回复内容)');
            } else {
                addMsg('error', data.error || '未知错误');
            }

            // 待确认的写操作提案
            const stagedFallback = stagedProposalsFromToolCalls(data.tool_calls);
            const proposals = (data.proposals && data.proposals.length) ? data.proposals : (stagedFallback ? stagedFallback.proposals : []);
            const proposalSetId = data.proposal_set_id || (stagedFallback ? stagedFallback.setId : '');
            if (proposals.length) {
                if (proposalSetId) {
                    addProposals(proposals, proposalSetId);
                } else {
                    addMsg('error', 'AI 已生成待确认改动，但服务端没有返回提案集 ID，请重新发起本次操作。');
                }
            } else if (data.content && /暂存|待确认|确认/.test(data.content)) {
                addMsg('error', 'AI 回复提到了待确认改动，但本次没有生成可应用的服务端提案。请重新发送指令，或换一种更明确的说法。');
            }
        } catch (err) {
            placeholder.remove();
            addMsg('error', '网络错误：' + err.message);
        } finally {
            sendBtn.disabled = false;
            sendBtn.textContent = '发送';
            input.focus();
        }
    });

    document.getElementById('toggleAll').addEventListener('click', function () {
        const items = document.querySelectorAll('details.group');
        const allOpen = Array.from(items).every(d => d.open);
        items.forEach(d => d.open = !allOpen);
        this.textContent = allOpen ? '展开全部' : '折叠全部';
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
