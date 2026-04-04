<?php
/**
 * AI 助手 - 浮动面板（通过钩子注入到后台编辑页面）
 */
if (!defined('ROOT_PATH')) exit;
?>

<!-- AI 助手浮动面板 -->
<div class="fixed bottom-4 right-4 z-[100] w-[580px] max-w-[calc(100vw-2rem)] rounded-xl overflow-hidden bg-white shadow-2xl ring-1 ring-blue-300/50" id="aiBox">
    <style>
    @keyframes ai-pulse { 0%,100% { opacity: 1; filter: drop-shadow(0 0 2px rgba(59,130,246,0.5)); } 50% { opacity: 0.6; filter: drop-shadow(0 0 6px rgba(59,130,246,0.8)); } }
    @keyframes ai-spark { 0% { transform: scale(0) rotate(0deg); opacity: 1; } 100% { transform: scale(1.2) rotate(180deg); opacity: 0; } }
    #aiIcon { animation: ai-pulse 2s ease-in-out infinite; }
    #aiIcon .ai-spark { position: absolute; width: 4px; height: 4px; background: #3b82f6; border-radius: 50%; animation: ai-spark 1.5s ease-out infinite; }
    #aiIcon .ai-spark:nth-child(2) { top: -2px; right: 0; animation-delay: 0.3s; }
    #aiIcon .ai-spark:nth-child(3) { bottom: 0; left: -2px; animation-delay: 0.7s; }
    #aiIcon .ai-spark:nth-child(4) { top: 2px; right: -3px; animation-delay: 1.1s; }
    </style>
    <!-- 标题栏（可拖动） -->
    <div id="aiBoxHeader" class="px-4 py-3 bg-white border-b border-gray-100 flex items-center justify-between select-none" style="cursor: move;">
        <div class="flex items-center gap-2.5" onclick="toggleAiBox(event)">
            <div id="aiIcon" class="relative w-7 h-7 flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>
                <span class="ai-spark"></span>
                <span class="ai-spark"></span>
                <span class="ai-spark"></span>
            </div>
            <span class="text-sm font-bold text-gray-800 cursor-pointer">AI 助手</span>
            <span id="aiStatus" class="text-xs text-gray-400 ml-1"></span>
        </div>
        <div class="flex items-center gap-2">
            <svg id="aiBoxArrow" class="w-4 h-4 text-gray-400 transition-transform cursor-pointer hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" onclick="toggleAiBox(event)"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </div>
    </div>

    <!-- 展开内容 -->
    <div id="aiBoxBody" class="hidden">
        <div class="p-4 space-y-3 border-t max-h-[70vh] overflow-y-auto">
            <input type="hidden" id="aiPanelAction" value="generate_all">

            <!-- 提示词 -->
            <div>
                <label class="block text-xs text-gray-500 mb-1">提示词 / 写作需求</label>
                <textarea id="aiPrompt" rows="3" class="w-full border rounded px-3 py-2 text-sm" placeholder="描述你想生成的内容，如：&#10;写一篇关于工业自动化PLC控制器的产品介绍，突出节能优势&#10;&#10;留空则使用文章标题"></textarea>
            </div>

            <!-- 模式 -->
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs text-gray-400">模式：</span>
                <button type="button" data-action="generate_all" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition font-medium">一键生成</button>
                <button type="button" data-action="generate_article" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition">仅内容</button>
                <button type="button" data-action="polish" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition">改写润色</button>
                <button type="button" data-action="continue" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition">续写</button>
                <span class="text-gray-200 mx-0.5">|</span>
                <button type="button" onclick="aiQuick('generate_summary')" class="px-3 py-1 text-xs rounded-full border border-gray-200 text-gray-500 hover:border-blue-300 hover:text-blue-500 cursor-pointer transition">摘要</button>
                <button type="button" onclick="aiQuick('generate_seo')" class="px-3 py-1 text-xs rounded-full border border-gray-200 text-gray-500 hover:border-purple-300 hover:text-purple-500 cursor-pointer transition">SEO</button>
            </div>

            <!-- 一键生成选项 -->
            <div id="aiAllOptions" class="flex items-center gap-4 text-xs text-gray-500">
                <span class="text-gray-400">生成项：</span>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenTitle" checked class="w-3.5 h-3.5 rounded"> 标题</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenSummary" checked class="w-3.5 h-3.5 rounded"> 摘要</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenTags" checked class="w-3.5 h-3.5 rounded"> 标签</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenSlug" checked class="w-3.5 h-3.5 rounded"> 别名</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenContent" checked class="w-3.5 h-3.5 rounded"> 内容</label>
            </div>

            <!-- 高级选项 -->
            <details class="group">
                <summary class="text-xs text-gray-400 cursor-pointer hover:text-blue-500 select-none">高级选项 ▾</summary>
                <div class="mt-3 space-y-3 pt-3 border-t border-dashed">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">行业/领域</label>
                            <input type="text" id="aiIndustry" class="w-full border rounded px-3 py-1.5 text-sm" placeholder="如：工业自动化">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">目标受众</label>
                            <input type="text" id="aiAudience" class="w-full border rounded px-3 py-1.5 text-sm" placeholder="如：企业采购">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">核心关键词</label>
                            <input type="text" id="aiKeywords" class="w-full border rounded px-3 py-1.5 text-sm" placeholder="逗号分隔">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">写作风格</label>
                            <div class="flex gap-1 flex-wrap">
                                <button type="button" data-val="professional" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">专业</button>
                                <button type="button" data-val="friendly" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">通俗</button>
                                <button type="button" data-val="marketing" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">营销</button>
                                <button type="button" data-val="news" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">资讯</button>
                                <button type="button" data-val="tutorial" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">教程</button>
                            </div>
                            <input type="hidden" id="aiStyle" value="professional">
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">字数</label>
                            <div class="flex gap-1">
                                <button type="button" data-val="300" class="ai-len-btn px-2.5 py-0.5 text-xs rounded-full border cursor-pointer transition">~300</button>
                                <button type="button" data-val="800" class="ai-len-btn px-2.5 py-0.5 text-xs rounded-full border cursor-pointer transition">~800</button>
                                <button type="button" data-val="1500" class="ai-len-btn px-2.5 py-0.5 text-xs rounded-full border cursor-pointer transition">~1500</button>
                            </div>
                            <input type="hidden" id="aiLength" value="800">
                        </div>
                        <div class="flex-1">
                            <label class="block text-xs text-gray-500 mb-1">补充要求</label>
                            <input type="text" id="aiExtra" class="w-full border rounded px-3 py-1.5 text-sm" placeholder="如：末尾加行动号召">
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <select id="aiPreset" class="border rounded px-2 py-1 text-xs text-gray-500" onchange="loadAiPreset()">
                            <option value="">-- 快捷预设 --</option>
                        </select>
                        <button type="button" onclick="saveAiPreset()" class="text-xs text-gray-400 hover:text-blue-500 cursor-pointer">保存预设</button>
                    </div>
                </div>
            </details>

            <!-- 生成按钮 -->
            <div class="flex justify-end pt-2 border-t">
                <button type="button" id="aiPanelSubmit" onclick="submitAiPanel()" class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-1.5 rounded text-sm cursor-pointer inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    开始生成
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ========== 拖动 ==========
(function(){
    var box = document.getElementById('aiBox');
    var header = document.getElementById('aiBoxHeader');
    var isDragging = false, startX, startY, origX, origY;

    header.addEventListener('mousedown', function(e) {
        if (e.target.closest('svg') || e.target.closest('button')) return;
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;
        var rect = box.getBoundingClientRect();
        origX = rect.left;
        origY = rect.top;
        box.style.transition = 'none';
        document.body.style.userSelect = 'none';
    });

    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        var dx = e.clientX - startX;
        var dy = e.clientY - startY;
        var newX = Math.max(0, Math.min(window.innerWidth - box.offsetWidth, origX + dx));
        var newY = Math.max(0, Math.min(window.innerHeight - 40, origY + dy));
        box.style.left = newX + 'px';
        box.style.top = newY + 'px';
        box.style.right = 'auto';
        box.style.bottom = 'auto';
    });

    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            document.body.style.userSelect = '';
        }
    });
})();

// ========== 展开/收起 ==========
function toggleAiBox(e) {
    if (e) e.stopPropagation();
    var body = document.getElementById('aiBoxBody');
    var arrow = document.getElementById('aiBoxArrow');
    body.classList.toggle('hidden');
    arrow.style.transform = body.classList.contains('hidden') ? '' : 'rotate(180deg)';
}

// ========== 模式按钮 ==========
document.querySelectorAll('.ai-mode-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        document.querySelectorAll('.ai-mode-btn').forEach(function(b){ b.className = 'ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition text-gray-600 border-gray-200'; });
        this.className = 'ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition text-blue-600 border-blue-400 bg-blue-50 font-medium';
        document.getElementById('aiPanelAction').value = this.dataset.action;
        var allOpts = document.getElementById('aiAllOptions');
        if (allOpts) allOpts.style.display = this.dataset.action === 'generate_all' ? 'flex' : 'none';
    });
});
document.querySelector('.ai-mode-btn[data-action="generate_all"]').click();

// ========== 风格/字数按钮 ==========
function initBtnGroup(cls, hiddenId) {
    document.querySelectorAll('.' + cls).forEach(function(btn){
        btn.addEventListener('click', function(){
            document.querySelectorAll('.' + cls).forEach(function(b){ b.className = cls + ' px-2 py-0.5 text-xs rounded-full border cursor-pointer transition text-gray-600 border-gray-200'; });
            this.className = cls + ' px-2 py-0.5 text-xs rounded-full border cursor-pointer transition text-blue-600 border-blue-400 bg-blue-50';
            document.getElementById(hiddenId).value = this.dataset.val;
        });
    });
}
initBtnGroup('ai-style-btn', 'aiStyle');
initBtnGroup('ai-len-btn', 'aiLength');
var ds = document.querySelector('.ai-style-btn[data-val="professional"]'); if (ds) ds.click();
var dl = document.querySelector('.ai-len-btn[data-val="800"]'); if (dl) dl.click();

// ========== 预设 ==========
var AI_PRESET_KEY = 'ik_ai_presets';
function getAiPresets() { try { return JSON.parse(localStorage.getItem(AI_PRESET_KEY) || '[]'); } catch(e) { return []; } }
function renderAiPresets() {
    var sel = document.getElementById('aiPreset'); if (!sel) return;
    sel.innerHTML = '<option value="">-- 快捷预设 --</option>';
    getAiPresets().forEach(function(p, i) { var o = document.createElement('option'); o.value = i; o.textContent = p.name; sel.appendChild(o); });
}
function loadAiPreset() {
    var idx = document.getElementById('aiPreset').value; if (idx === '') return;
    var p = getAiPresets()[idx]; if (!p) return;
    if (p.prompt) document.getElementById('aiPrompt').value = p.prompt;
    if (p.industry) document.getElementById('aiIndustry').value = p.industry;
    if (p.audience) document.getElementById('aiAudience').value = p.audience;
    if (p.keywords) document.getElementById('aiKeywords').value = p.keywords;
    if (p.extra) document.getElementById('aiExtra').value = p.extra;
    if (p.style) { var b = document.querySelector('.ai-style-btn[data-val="'+p.style+'"]'); if(b) b.click(); }
    if (p.length) { var b = document.querySelector('.ai-len-btn[data-val="'+p.length+'"]'); if(b) b.click(); }
}
function saveAiPreset() {
    var name = prompt('预设名称：'); if (!name) return;
    var presets = getAiPresets();
    presets.push({ name: name, prompt: document.getElementById('aiPrompt').value,
        industry: document.getElementById('aiIndustry').value, audience: document.getElementById('aiAudience').value,
        keywords: document.getElementById('aiKeywords').value, style: document.getElementById('aiStyle').value,
        length: document.getElementById('aiLength').value, extra: document.getElementById('aiExtra').value });
    localStorage.setItem(AI_PRESET_KEY, JSON.stringify(presets));
    renderAiPresets(); showMessage('预设已保存');
}
renderAiPresets();

// ========== 获取编辑器内容 ==========
function getEditorContent() {
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        tinymce.triggerSave();
        return tinymce.activeEditor.getContent();
    }
    var ta = document.getElementById('contentEditor');
    return ta ? ta.value : '';
}
function setEditorContent(html) {
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        tinymce.activeEditor.setContent(html);
    }
}

// ========== 提交生成 ==========
function submitAiPanel() {
    var action = document.getElementById('aiPanelAction').value;
    var title = (document.querySelector('input[name=title]') || {}).value || '';
    var prompt = document.getElementById('aiPrompt').value.trim();
    var content = getEditorContent();

    if (!prompt && !title) { showMessage('请填写提示词或文章标题', 'error'); return; }
    if ((action === 'polish' || action === 'continue') && !content) { showMessage('请先编写内容', 'error'); return; }

    var btn = document.getElementById('aiPanelSubmit');
    var status = document.getElementById('aiStatus');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> 生成中...';
    if (status) status.textContent = 'AI 生成中...';

    var fd = new FormData();
    fd.append('action', action);
    fd.append('title', title);
    fd.append('prompt', prompt);
    fd.append('content', content);
    fd.append('industry', document.getElementById('aiIndustry').value);
    fd.append('audience', document.getElementById('aiAudience').value);
    fd.append('keywords', document.getElementById('aiKeywords').value);
    fd.append('style', document.getElementById('aiStyle').value);
    fd.append('length', document.getElementById('aiLength').value);
    fd.append('extra', document.getElementById('aiExtra').value);

    fetch('/admin/api_ai.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (!data.success) { showMessage('AI 错误: ' + data.error, 'error'); return; }

        if (action === 'generate_all' && data.fields) {
            var f = data.fields;
            if (document.getElementById('aiGenTitle').checked && f.title) {
                var el = document.querySelector('input[name=title]'); if(el) el.value = f.title;
            }
            if (document.getElementById('aiGenSummary').checked && f.summary) {
                var el = document.querySelector('textarea[name=summary]'); if(el) el.value = f.summary;
            }
            if (document.getElementById('aiGenTags').checked && f.tags) {
                var el = document.querySelector('input[name=tags]'); if(el) el.value = f.tags;
            }
            if (document.getElementById('aiGenSlug').checked && f.slug) {
                var el = document.querySelector('input[name=slug]'); if(el) el.value = f.slug;
            }
            if (document.getElementById('aiGenContent').checked && f.content) {
                setEditorContent(f.content);
            }
            showMessage('一键生成完成');
        } else {
            setEditorContent(data.content);
            showMessage('内容已生成');
        }
    })
    .catch(function(e){ showMessage('请求失败: ' + e.message, 'error'); })
    .finally(function(){
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg> 开始生成';
        if (status) status.textContent = '';
    });
}

// ========== 摘要/SEO 快捷 ==========
function aiQuick(action) {
    var status = document.getElementById('aiStatus');
    var title = (document.querySelector('input[name=title]') || {}).value || '';
    var content = getEditorContent();
    var summary = document.querySelector('textarea[name=summary]');

    if (action === 'generate_seo' && !title) { showMessage('请先填写标题', 'error'); return; }
    if (action === 'generate_summary' && !title && !content) { showMessage('请先填写标题或内容', 'error'); return; }
    if (!confirm('AI ' + (action === 'generate_summary' ? '生成摘要' : '生成 SEO 信息') + '？')) return;
    if (status) status.textContent = 'AI 生成中...';

    var fd = new FormData();
    fd.append('action', action); fd.append('title', title); fd.append('content', content);

    fetch('/admin/api_ai.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (!data.success) { showMessage('AI 错误: ' + data.error, 'error'); return; }
        if (action === 'generate_summary' && summary) { summary.value = data.content; showMessage('摘要已生成'); }
        else if (action === 'generate_seo' && data.seo) {
            ['seo_title','seo_keywords','seo_description'].forEach(function(k){
                var el = document.querySelector('[name='+k+']'); if (el && data.seo[k]) el.value = data.seo[k];
            });
            showMessage('SEO 信息已生成');
        }
    })
    .catch(function(e){ showMessage('请求失败', 'error'); })
    .finally(function(){ if (status) status.textContent = ''; });
}
</script>
