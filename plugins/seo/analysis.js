/**
 * SEO 工坊 - 实时内容分析面板（免费）
 *
 * 由 ik_admin_footer_scripts 注入到所有后台页；自门控：仅当检测到标准内容编辑器
 * （标题输入 + #contentInput + seo_description）时才激活并注入面板。
 *
 * 针对中文调整：用「字符数」代替英文「词数」，关键词密度按字符占比估算，
 * 不套用 Flesch 可读性（拉丁语系专用，中文不适用）。
 */
(function () {
    'use strict';

    var titleEl = document.querySelector('input[name="title"]');
    var contentInput = document.getElementById('contentInput');
    var seoDescEl = document.querySelector('textarea[name="seo_description"]');
    // 非标准内容编辑页 → 不激活
    if (!titleEl || !contentInput || !seoDescEl) return;

    var seoTitleEl = document.querySelector('input[name="seo_title"]');
    var seoKwEl = document.querySelector('input[name="seo_keywords"]');
    var slugEl = document.querySelector('input[name="slug"]');

    var KW_STORE = 'yk_seo_focus_kw:' + location.pathname + location.search;

    // ---------- 文本工具 ----------
    function getContentHtml() {
        if (window.editor && typeof window.editor.getHtml === 'function') {
            try { return window.editor.getHtml() || ''; } catch (e) {}
        }
        return contentInput.value || '';
    }
    function htmlToText(html) {
        var d = document.createElement('div');
        d.innerHTML = html || '';
        return (d.textContent || d.innerText || '').replace(/\s+/g, ' ').trim();
    }
    // 非空白字符数（中文按字计）
    function charCount(text) {
        return (text.replace(/\s/g, '')).length;
    }
    function countOccurrences(haystack, needle) {
        if (!needle) return 0;
        var h = haystack.toLowerCase(), n = needle.toLowerCase(), i = 0, c = 0;
        while ((i = h.indexOf(n, i)) !== -1) { c++; i += n.length; }
        return c;
    }
    function truncate(s, n) {
        s = (s || '').trim();
        return s.length > n ? s.slice(0, n - 1).trim() + '…' : s;
    }
    function esc(s) {
        return (s || '').replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    // ---------- 分析引擎 ----------
    // 返回 [{level:'good|ok|bad|na', text}]，level 决定分数与颜色
    function analyze() {
        var kw = (kwInput.value || '').trim();
        var title = (titleEl.value || '').trim();
        var seoTitle = (seoTitleEl && seoTitleEl.value.trim()) || title;
        var desc = (seoDescEl.value || '').trim();
        var slug = (slugEl && slugEl.value.trim()) || '';
        var kws = (seoKwEl && seoKwEl.value.trim()) || '';
        var html = getContentHtml();
        var text = htmlToText(html);
        var chars = charCount(text);

        var r = [];

        // —— 关键词相关 ——
        if (!kw) {
            r.push({ level: 'na', text: '设置焦点关键词后，可获得针对性建议' });
        } else {
            r.push(countOccurrences(seoTitle, kw) > 0
                ? { level: 'good', text: '焦点关键词出现在 SEO 标题中' }
                : { level: 'bad', text: 'SEO 标题未包含焦点关键词' });

            r.push(countOccurrences(desc, kw) > 0
                ? { level: 'good', text: '焦点关键词出现在 SEO 描述中' }
                : { level: 'bad', text: 'SEO 描述未包含焦点关键词' });

            var head = text.slice(0, 120);
            r.push(countOccurrences(head, kw) > 0
                ? { level: 'good', text: '焦点关键词出现在正文开头' }
                : { level: 'ok', text: '正文开头（前 120 字）未出现焦点关键词' });

            if (slug) {
                r.push(slug.toLowerCase().indexOf(kw.toLowerCase()) !== -1
                    ? { level: 'good', text: '焦点关键词出现在 URL 别名中' }
                    : { level: 'ok', text: 'URL 别名未包含焦点关键词（可用拼音/英文）' });
            }

            if (kws) {
                r.push(countOccurrences(kws, kw) > 0
                    ? { level: 'good', text: '关键词标签包含焦点关键词' }
                    : { level: 'ok', text: '关键词标签未包含焦点关键词' });
            }

            // 密度：出现次数×关键词长度 / 总字数
            if (chars > 0) {
                var density = (countOccurrences(text, kw) * kw.length) / chars * 100;
                var dtxt = '关键词密度 ' + density.toFixed(1) + '%';
                if (density === 0) r.push({ level: 'bad', text: '正文未出现焦点关键词' });
                else if (density < 0.5) r.push({ level: 'ok', text: dtxt + '（偏低，建议 0.5%–2.5%）' });
                else if (density <= 2.5) r.push({ level: 'good', text: dtxt + '（理想区间）' });
                else r.push({ level: 'ok', text: dtxt + '（偏高，注意别堆砌）' });
            }
        }

        // —— 长度/结构（与关键词无关）——
        var stLen = seoTitle.length;
        if (stLen === 0) r.push({ level: 'bad', text: '缺少标题' });
        else if (stLen < 10) r.push({ level: 'ok', text: 'SEO 标题偏短（' + stLen + ' 字，建议 10–60）' });
        else if (stLen <= 60) r.push({ level: 'good', text: 'SEO 标题长度合适（' + stLen + ' 字）' });
        else r.push({ level: 'ok', text: 'SEO 标题偏长（' + stLen + ' 字，搜索结果可能截断）' });

        var dLen = desc.length;
        if (dLen === 0) r.push({ level: 'bad', text: '缺少 SEO 描述（搜索摘要）' });
        else if (dLen < 60) r.push({ level: 'ok', text: 'SEO 描述偏短（' + dLen + ' 字，建议 60–160）' });
        else if (dLen <= 160) r.push({ level: 'good', text: 'SEO 描述长度合适（' + dLen + ' 字）' });
        else r.push({ level: 'ok', text: 'SEO 描述偏长（' + dLen + ' 字，可能被截断）' });

        if (chars < 100) r.push({ level: 'bad', text: '正文过短（' + chars + ' 字，建议 ≥ 300）' });
        else if (chars < 300) r.push({ level: 'ok', text: '正文偏短（' + chars + ' 字，建议 ≥ 300）' });
        else r.push({ level: 'good', text: '正文字数充足（' + chars + ' 字）' });

        var hCount = (html.match(/<h[2-4][\s>]/gi) || []).length;
        r.push(hCount > 0
            ? { level: 'good', text: '正文使用了 ' + hCount + ' 个小标题（H2–H4）' }
            : { level: 'ok', text: '正文没有小标题，长文建议加 H2/H3 分段' });

        var imgs = html.match(/<img[^>]*>/gi) || [];
        if (imgs.length === 0) {
            r.push({ level: 'ok', text: '正文没有配图，适当配图更利于阅读与收录' });
        } else {
            var noAlt = imgs.filter(function (t) { return !/\balt\s*=\s*["'][^"']+["']/i.test(t); }).length;
            r.push(noAlt === 0
                ? { level: 'good', text: '全部 ' + imgs.length + ' 张图片都有 alt 描述' }
                : { level: 'bad', text: imgs.length + ' 张图片中 ' + noAlt + ' 张缺少 alt 描述' });
        }

        return { items: r, chars: chars };
    }

    // ---------- 评分 ----------
    function score(items) {
        var w = { good: 2, ok: 1, bad: 0 }, got = 0, max = 0;
        items.forEach(function (it) {
            if (it.level === 'na') return;
            got += w[it.level]; max += 2;
        });
        var pct = max ? Math.round(got / max * 100) : 0;
        var band = pct >= 70 ? 'good' : (pct >= 40 ? 'ok' : 'bad');
        return { pct: pct, band: band };
    }

    // ---------- UI ----------
    var COLORS = {
        good: '#16a34a', ok: '#d97706', bad: '#dc2626', na: '#9ca3af'
    };
    var BAND_LABEL = { good: '良好', ok: '尚可', bad: '待改进' };

    var panel = document.createElement('div');
    panel.className = 'bg-white rounded-lg shadow p-6';
    panel.innerHTML =
        '<h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2">' +
            '<i class="ti ti-chart-arcs text-blue-500"></i> SEO 分析' +
            '<span class="text-[10px] font-medium bg-green-100 text-green-700 px-1.5 py-0.5 rounded">免费</span>' +
        '</h3>' +
        '<p class="text-xs text-gray-400 mb-3">边写边评分，绿=好 / 橙=可优化 / 红=需改进。</p>' +
        '<label class="block text-sm text-gray-600 mb-1">焦点关键词</label>' +
        '<input type="text" id="ykSeoKw" placeholder="这篇内容最想被搜到的词" ' +
            'class="w-full border rounded px-3 py-2 text-sm mb-3">' +
        '<div class="flex items-center gap-3 mb-3">' +
            '<div class="relative w-12 h-12 shrink-0">' +
                '<svg viewBox="0 0 36 36" class="w-12 h-12 -rotate-90">' +
                    '<circle cx="18" cy="18" r="15.9" fill="none" stroke="#e5e7eb" stroke-width="3"></circle>' +
                    '<circle id="ykSeoRing" cx="18" cy="18" r="15.9" fill="none" stroke="#9ca3af" stroke-width="3" ' +
                        'stroke-dasharray="0 100" stroke-linecap="round"></circle>' +
                '</svg>' +
                '<span id="ykSeoPct" class="absolute inset-0 flex items-center justify-center text-xs font-bold text-gray-700">0</span>' +
            '</div>' +
            '<div>' +
                '<div id="ykSeoBand" class="text-sm font-medium text-gray-500">—</div>' +
                '<div id="ykSeoRead" class="text-xs text-gray-400"></div>' +
            '</div>' +
        '</div>' +
        '<ul id="ykSeoList" class="space-y-1.5 text-xs"></ul>';

    // SERP 摘要预览卡（谷歌/百度风格，展示搜索结果观感）
    var snippet = document.createElement('div');
    snippet.className = 'bg-white rounded-lg shadow p-6';
    snippet.innerHTML =
        '<h3 class="font-bold text-gray-800 mb-1 flex items-center gap-2">' +
            '<i class="ti ti-search text-blue-500"></i> 搜索结果预览' +
            '<span class="text-[10px] font-medium bg-green-100 text-green-700 px-1.5 py-0.5 rounded">免费</span>' +
        '</h3>' +
        '<div class="flex items-center gap-1 mb-3 text-xs">' +
            '<button type="button" data-dev="desktop" class="ykSeoDev px-2 py-1 rounded border border-blue-400 bg-blue-50 text-blue-600 inline-flex items-center gap-1"><i class="ti ti-device-desktop"></i>电脑</button>' +
            '<button type="button" data-dev="mobile" class="ykSeoDev px-2 py-1 rounded border border-gray-200 text-gray-500 inline-flex items-center gap-1"><i class="ti ti-device-mobile"></i>手机</button>' +
        '</div>' +
        '<div id="ykSeoSerp" class="border border-gray-100 rounded-lg p-3 bg-white" style="max-width:600px">' +
            '<div id="ykSeoSerpUrl" class="text-xs text-gray-500 truncate"></div>' +
            '<div id="ykSeoSerpTitle" class="text-[#1a0dab] text-lg leading-snug truncate" style="font-family:arial,sans-serif"></div>' +
            '<div id="ykSeoSerpDesc" class="text-sm text-gray-600 leading-snug mt-0.5"></div>' +
        '</div>';

    // 插入到 SEO 设置卡之后（同左主栏）：SEO设置 → 搜索预览 → SEO分析。退化到 body 末尾
    var seoCard = seoDescEl.closest('.bg-white');
    if (seoCard && seoCard.parentNode) {
        seoCard.parentNode.insertBefore(snippet, seoCard.nextSibling);
        snippet.parentNode.insertBefore(panel, snippet.nextSibling);
    } else {
        document.body.appendChild(snippet);
        document.body.appendChild(panel);
    }

    // SERP 预览：设备切换 + 元素引用
    var serpDevice = 'desktop';
    var serpUrlEl = snippet.querySelector('#ykSeoSerpUrl');
    var serpTitleEl = snippet.querySelector('#ykSeoSerpTitle');
    var serpDescEl = snippet.querySelector('#ykSeoSerpDesc');
    snippet.querySelectorAll('.ykSeoDev').forEach(function (b) {
        b.addEventListener('click', function () {
            serpDevice = b.getAttribute('data-dev');
            snippet.querySelectorAll('.ykSeoDev').forEach(function (x) {
                var on = x === b;
                x.className = 'ykSeoDev px-2 py-1 rounded border inline-flex items-center gap-1 ' +
                    (on ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500');
            });
            updateSnippet();
        });
    });

    function updateSnippet() {
        var title = (titleEl.value || '').trim();
        var seoTitle = (seoTitleEl && seoTitleEl.value.trim()) || title || '页面标题';
        var desc = (seoDescEl.value || '').trim();
        if (!desc) desc = htmlToText(getContentHtml());   // 回退：正文摘要（与前台一致）
        var slug = (slugEl && slugEl.value.trim()) || '';
        var base = location.protocol + '//' + location.host;
        var url = base + (slug ? '/' + slug : '');
        // 电脑 vs 手机的截断宽度不同
        var tMax = serpDevice === 'mobile' ? 38 : 60;
        var dMax = serpDevice === 'mobile' ? 120 : 158;
        serpUrlEl.textContent = url;
        serpTitleEl.textContent = truncate(seoTitle, tMax);
        serpDescEl.textContent = desc ? truncate(desc, dMax) : '（暂无描述：填写 SEO 描述或正文，将在此显示搜索摘要）';
        serpDescEl.style.color = desc ? '' : '#9ca3af';
        serpTitleEl.style.maxWidth = serpDevice === 'mobile' ? '360px' : '';
    }

    var kwInput = panel.querySelector('#ykSeoKw');
    var ring = panel.querySelector('#ykSeoRing');
    var pctEl = panel.querySelector('#ykSeoPct');
    var bandEl = panel.querySelector('#ykSeoBand');
    var readEl = panel.querySelector('#ykSeoRead');
    var listEl = panel.querySelector('#ykSeoList');

    // 恢复上次的焦点关键词
    try { kwInput.value = localStorage.getItem(KW_STORE) || ''; } catch (e) {}

    function render() {
        updateSnippet();
        var res = analyze();
        var sc = score(res.items);
        ring.setAttribute('stroke-dasharray', sc.pct + ' 100');
        ring.setAttribute('stroke', COLORS[sc.band]);
        pctEl.textContent = sc.pct;
        bandEl.textContent = BAND_LABEL[sc.band];
        bandEl.style.color = COLORS[sc.band];
        var mins = Math.max(1, Math.round(res.chars / 400));
        readEl.textContent = res.chars + ' 字 · 约 ' + mins + ' 分钟阅读';
        listEl.innerHTML = res.items.map(function (it) {
            return '<li class="flex items-start gap-2">' +
                '<span style="color:' + COLORS[it.level] + '" class="mt-0.5 shrink-0">' +
                (it.level === 'good' ? '●' : it.level === 'bad' ? '●' : it.level === 'ok' ? '●' : '○') +
                '</span><span class="text-gray-600">' + it.text.replace(/</g, '&lt;') + '</span></li>';
        }).join('');
    }

    var timer = null;
    function schedule() { clearTimeout(timer); timer = setTimeout(render, 300); }

    kwInput.addEventListener('input', function () {
        try { localStorage.setItem(KW_STORE, kwInput.value); } catch (e) {}
        schedule();
    });
    [titleEl, seoTitleEl, seoDescEl, seoKwEl, slugEl].forEach(function (el) {
        if (el) el.addEventListener('input', schedule);
    });
    // 编辑器正文变化：contentInput 由 onChange 程序化更新，无 input 事件 → 轮询
    var lastHtml = '';
    setInterval(function () {
        var h = getContentHtml();
        if (h !== lastHtml) { lastHtml = h; schedule(); }
    }, 1000);

    // ---------- AI 一键优化（专业版）----------
    if (window.__ykSeoPro) {
        var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        var targets = { seo_title: seoTitleEl, seo_description: seoDescEl, seo_keywords: seoKwEl };
        var btnCls = 'ykSeoAi text-xs border border-amber-200 text-amber-700 bg-amber-50 hover:bg-amber-100 disabled:opacity-50 px-2 py-1 rounded inline-flex items-center gap-1';
        var aiRow = document.createElement('div');
        aiRow.className = 'mb-3';
        aiRow.innerHTML =
            '<div class="text-xs text-gray-500 mb-1 flex items-center gap-1">' +
                '<i class="ti ti-sparkles text-amber-500"></i>AI 一键优化' +
                '<span class="text-[10px] font-medium bg-amber-100 text-amber-700 px-1 rounded">Pro</span>' +
            '</div>' +
            '<div class="flex flex-wrap gap-1">' +
                '<button type="button" data-f="seo_title" class="' + btnCls + '"><i class="ti ti-wand"></i>标题</button>' +
                '<button type="button" data-f="seo_description" class="' + btnCls + '"><i class="ti ti-wand"></i>描述</button>' +
                '<button type="button" data-f="seo_keywords" class="' + btnCls + '"><i class="ti ti-wand"></i>关键词</button>' +
            '</div>' +
            '<div class="ykSeoAiMsg text-[11px] text-gray-400 mt-1"></div>';
        kwInput.parentNode.insertBefore(aiRow, kwInput.nextSibling);
        var aiMsg = aiRow.querySelector('.ykSeoAiMsg');
        aiRow.querySelectorAll('.ykSeoAi').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var f = btn.getAttribute('data-f');
                var target = targets[f];
                if (!target) { aiMsg.textContent = '当前编辑器没有该字段'; return; }
                var label = btn.textContent;
                btn.disabled = true; aiMsg.textContent = 'AI 生成中…';
                var body = new URLSearchParams();
                body.set('field', f);
                body.set('title', titleEl.value || '');
                body.set('content', htmlToText(getContentHtml()).slice(0, 3000));
                body.set('keyword', kwInput.value || '');
                body.set('_token', csrf);
                fetch('/plugins/seo/ai.php', { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.success) {
                            target.value = res.content;
                            target.dispatchEvent(new Event('input', { bubbles: true }));
                            aiMsg.textContent = '✓ 已填入「' + label + '」，可继续手改';
                            render();
                        } else {
                            aiMsg.textContent = (res && res.error) || '生成失败';
                        }
                    })
                    .catch(function () { aiMsg.textContent = '请求失败'; })
                    .finally(function () { btn.disabled = false; });
            });
        });
    }

    render();
})();
