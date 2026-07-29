/**
 * Yikai CMS - 代码块「复制」按钮 + 语言标签
 *
 * 自动扫描正文里的 <pre><code>（编辑器 codesample 产出
 * <pre class="language-*"><code>），为每个代码块加：
 *   - 右上角复制按钮：点击复制纯文本，1.5 秒内反馈「已复制」
 *   - 左上角语言标签：从 language-* 类名取，无则不显示
 *
 * 存量文章无需改动即可生效；脚本失效时代码块照常显示。
 * 自包含、无依赖，约 2KB。
 */
(function () {
    'use strict';

    var blocks = document.querySelectorAll('.prose pre > code');
    if (!blocks.length) return;

    // 文案：优先取页面注入的 window.__ykCodeCopyI18n（多语言站），否则用中性默认
    var T = window.__ykCodeCopyI18n || {};
    var TXT_COPY = T.copy || 'Copy';
    var TXT_DONE = T.copied || 'Copied';

    function labelOf(codeEl, preEl) {
        var cls = (codeEl.className || '') + ' ' + (preEl.className || '');
        var m = cls.match(/language-([\w+#-]+)/i);
        if (!m) return '';
        var raw = m[1].toLowerCase();
        var map = {
            js: 'JavaScript', javascript: 'JavaScript', ts: 'TypeScript',
            php: 'PHP', py: 'Python', python: 'Python', rb: 'Ruby',
            sh: 'Shell', bash: 'Shell', shell: 'Shell', sql: 'SQL',
            html: 'HTML', xml: 'XML', css: 'CSS', scss: 'SCSS',
            json: 'JSON', yaml: 'YAML', yml: 'YAML', md: 'Markdown',
            markup: 'HTML', c: 'C', cpp: 'C++', csharp: 'C#', java: 'Java', go: 'Go'
        };
        return map[raw] || raw.toUpperCase();
    }

    function copyText(text, done) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
            return;
        }
        fallback(text, done);
    }

    // 非 HTTPS / 老浏览器回退
    function fallback(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:absolute;left:-9999px;top:0;';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* 忽略：失败时用户仍可手动选中 */ }
        document.body.removeChild(ta);
        done();
    }

    for (var i = 0; i < blocks.length; i++) {
        (function (codeEl) {
            var pre = codeEl.parentNode;
            if (!pre || pre.getAttribute('data-yk-code') === '1') return;
            pre.setAttribute('data-yk-code', '1');
            pre.classList.add('yk-code');

            var lang = labelOf(codeEl, pre);
            if (lang) {
                var tag = document.createElement('span');
                tag.className = 'yk-code-lang';
                tag.textContent = lang;
                pre.appendChild(tag);
            }

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'yk-code-copy';
            btn.textContent = TXT_COPY;
            btn.setAttribute('aria-label', TXT_COPY);
            btn.addEventListener('click', function () {
                copyText(codeEl.innerText, function () {
                    btn.textContent = TXT_DONE;
                    btn.classList.add('is-done');
                    setTimeout(function () {
                        btn.textContent = TXT_COPY;
                        btn.classList.remove('is-done');
                    }, 1500);
                });
            });
            pre.appendChild(btn);
        })(blocks[i]);
    }
})();
