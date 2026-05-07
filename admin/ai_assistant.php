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
$pageTitle = 'AI 助手';
$abilities = Abilities::all();
$aiConfigured = aiService()->isConfigured();
$cfg = AiService::getProviders()[config('ai_provider', 'openai')] ?? null;
$supported = $cfg && $cfg['format'] === 'openai';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">AI 助手</h1>
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
                    <span class="inline-block mt-2 text-gray-500">"列出最近 5 篇草稿，挑标题最长的发布上线"</span><br>
                    <span class="inline-block mt-1 text-gray-500">"给文章 #12 生成 SEO 摘要并自动打标签"</span>
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
