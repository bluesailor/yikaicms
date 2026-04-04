<?php if (!defined('ROOT_PATH') || !config('ai_api_key')) return; ?>
<script>
// ========== AI 助手 ==========
function toggleAiBox() {
    var body = document.getElementById('aiBoxBody');
    var arrow = document.getElementById('aiBoxArrow');
    if (!body) return;
    body.classList.toggle('hidden');
    arrow.style.transform = body.classList.contains('hidden') ? '' : 'rotate(180deg)';
}

// 模式按钮
document.querySelectorAll('.ai-mode-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.ai-mode-btn').forEach(function(b) { b.className = 'ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition text-gray-600 border-gray-200'; });
        this.className = 'ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition text-blue-600 border-blue-400 bg-blue-50 font-medium';
        document.getElementById('aiPanelAction').value = this.dataset.action;
        var allOpts = document.getElementById('aiAllOptions');
        if (allOpts) allOpts.style.display = this.dataset.action === 'generate_all' ? 'flex' : 'none';
    });
});
var defMode = document.querySelector('.ai-mode-btn[data-action="generate_all"]');
if (defMode) defMode.click();

// 风格/字数
function initBtnGroup(cls, hiddenId) {
    document.querySelectorAll('.' + cls).forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.' + cls).forEach(function(b) { b.className = cls + ' px-2 py-0.5 text-xs rounded-full border cursor-pointer transition text-gray-600 border-gray-200'; });
            this.className = cls + ' px-2 py-0.5 text-xs rounded-full border cursor-pointer transition text-blue-600 border-blue-400 bg-blue-50';
            document.getElementById(hiddenId).value = this.dataset.val;
        });
    });
}
initBtnGroup('ai-style-btn', 'aiStyle');
initBtnGroup('ai-len-btn', 'aiLength');
var ds = document.querySelector('.ai-style-btn[data-val="professional"]'); if (ds) ds.click();
var dl = document.querySelector('.ai-len-btn[data-val="800"]'); if (dl) dl.click();

// 编辑器交互
function getEditorContent() {
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) { tinymce.triggerSave(); return tinymce.activeEditor.getContent(); }
    var ta = document.getElementById('contentEditor'); return ta ? ta.value : '';
}
function setEditorContent(html) {
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) { tinymce.activeEditor.setContent(html); }
}

// 提交生成
function submitAiPanel() {
    var action = document.getElementById('aiPanelAction').value;
    var title = (document.querySelector('input[name=title]') || {}).value || '';
    var prompt = (document.getElementById('aiPrompt') || {}).value || '';
    prompt = prompt.trim();
    var content = getEditorContent();

    if (!prompt && !title) { showMessage('请填写提示词或文章标题', 'error'); return; }
    if ((action === 'polish' || action === 'continue') && !content) { showMessage('请先编写内容', 'error'); return; }

    var btn = document.getElementById('aiPanelSubmit');
    var status = document.getElementById('aiStatus');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> 生成中...';
    if (status) status.textContent = 'AI 生成中...';

    var fd = new FormData();
    fd.append('action', action); fd.append('title', title); fd.append('prompt', prompt); fd.append('content', content);
    fd.append('industry', (document.getElementById('aiIndustry') || {}).value || '');
    fd.append('audience', (document.getElementById('aiAudience') || {}).value || '');
    fd.append('keywords', (document.getElementById('aiKeywords') || {}).value || '');
    fd.append('style', (document.getElementById('aiStyle') || {}).value || 'professional');
    fd.append('length', (document.getElementById('aiLength') || {}).value || '800');
    fd.append('extra', (document.getElementById('aiExtra') || {}).value || '');

    fetch('/admin/api_ai.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) { showMessage('AI 错误: ' + data.error, 'error'); return; }
        if (action === 'generate_all' && data.fields) {
            var f = data.fields;
            if (document.getElementById('aiGenTitle').checked && f.title) { var el = document.querySelector('input[name=title]'); if(el) el.value = f.title; }
            if (document.getElementById('aiGenSummary').checked && f.summary) { var el = document.querySelector('textarea[name=summary]'); if(el) el.value = f.summary; }
            if (document.getElementById('aiGenTags').checked && f.tags) { var el = document.querySelector('input[name=tags]'); if(el) el.value = f.tags; }
            if (document.getElementById('aiGenSlug').checked && f.slug) { var el = document.querySelector('input[name=slug]'); if(el) el.value = f.slug; }
            if (document.getElementById('aiGenContent').checked && f.content) { setEditorContent(f.content); }
            showMessage('一键生成完成');
        } else {
            setEditorContent(data.content); showMessage('内容已生成');
        }
    })
    .catch(function(e) { showMessage('请求失败: ' + e.message, 'error'); })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg> 开始生成';
        if (status) status.textContent = '';
    });
}

// 摘要/SEO 快捷
function aiQuick(action) {
    var status = document.getElementById('aiStatus');
    var title = (document.querySelector('input[name=title]') || {}).value || '';
    var content = getEditorContent();
    var summary = document.querySelector('textarea[name=summary]');

    if (action === 'generate_seo' && !title) { showMessage('请先填写标题', 'error'); return; }
    if (action === 'generate_summary' && !title && !content) { showMessage('请先填写标题或内容', 'error'); return; }
    if (!confirm('AI ' + (action === 'generate_summary' ? '生成摘要' : '生成 SEO') + '？')) return;
    if (status) status.textContent = 'AI 生成中...';

    var fd = new FormData();
    fd.append('action', action); fd.append('title', title); fd.append('content', content);

    fetch('/admin/api_ai.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) { showMessage('AI 错误: ' + data.error, 'error'); return; }
        if (action === 'generate_summary' && summary) { summary.value = data.content; showMessage('摘要已生成'); }
        else if (action === 'generate_seo' && data.seo) {
            ['seo_title','seo_keywords','seo_description'].forEach(function(k) {
                var el = document.querySelector('[name='+k+']'); if (el && data.seo[k]) el.value = data.seo[k];
            });
            showMessage('SEO 已生成');
        }
    })
    .catch(function() { showMessage('请求失败', 'error'); })
    .finally(function() { if (status) status.textContent = ''; });
}
</script>
