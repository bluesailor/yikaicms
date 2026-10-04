/**
 * 后台扩展字段（2.0.4 高级字段）：媒体库选图、文件上传、颜色、重复器行增删排序、关联搜索、
 * 条件逻辑与挂载位置的即时显隐。服务端渲染见 admin/includes/extfield_render.php。
 * 被隐藏字段里的 required 暂存到 data-ef-req，免得浏览器拦下看不见的必填项。
 */
(function (window, document) {
    'use strict';
    if (window.YkExtFields) { window.YkExtFields.init(document); return; }
    var i18n = window.YK_EF_I18N || {};
    var base = window.YK_BASE || '';

    function closest(el, selector) { return el && el.closest ? el.closest(selector) : null; }
    function fmt(text, value) { return String(text || '').replace('%d', value); }
    function cssUrl(url) { return "url('" + String(url).replace(/'/g, '%27') + "')"; }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }

    // ── 图片 / 多图 / 文件 / 颜色 ──────────────────────────────
    function pickImage(callback) {
        if (typeof window.openMediaPicker === 'function') window.openMediaPicker(callback, { type: 'image' });
    }

    function thumb(url) {
        var div = document.createElement('div');
        div.className = 'relative h-16 w-16 rounded border bg-gray-50 bg-cover bg-center';
        div.setAttribute('data-ef-thumb', url);
        div.style.backgroundImage = cssUrl(url);
        div.innerHTML = '<button type="button" class="absolute -end-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-red-500 text-xs text-white" data-ef-remove-image>&times;</button>';
        return div;
    }

    function syncImages(box) {
        var urls = [];
        box.querySelectorAll('[data-ef-thumb]').forEach(function (t) { urls.push(t.getAttribute('data-ef-thumb')); });
        box.querySelector('[data-ef-value]').value = urls.join(',');
        changed(box);
    }

    function uploadFile(box, file) {
        var input = box.querySelector('[data-ef-value]');
        var button = box.querySelector('[data-ef-upload]');
        var fd = new FormData();
        fd.append('file', file);
        fd.append('type', 'files');
        var label = button.innerHTML;
        button.disabled = true;
        button.textContent = i18n.uploading || '…';
        fetch(base + '/admin/upload.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.code === 0) { input.value = d.data.url; changed(box); }
                else if (window.showMessage) window.showMessage(d.msg || i18n.upload_failed, 'error');
            })
            .catch(function () { if (window.showMessage) window.showMessage(i18n.upload_failed, 'error'); })
            .then(function () { button.disabled = false; button.innerHTML = label; });
    }

    // ── 重复器 ────────────────────────────────────────────────
    function reindex(repeater) {
        var name = repeater.getAttribute('data-name');
        var rows = repeater.querySelectorAll(':scope > [data-ef-rows] > [data-ef-row]');
        rows.forEach(function (row, i) {
            var no = row.querySelector('[data-ef-row-no]');
            if (no) no.textContent = fmt(i18n.row || '#%d', i + 1);
            row.querySelectorAll('[name]').forEach(function (el) {
                var n = el.getAttribute('name');
                if (n.indexOf(name + '[') === 0) {
                    el.setAttribute('name', n.replace(/^([^\[]+\[[^\]]+\])\[[^\]]*\]/, '$1[' + i + ']'));
                }
            });
        });
        var max = parseInt(repeater.getAttribute('data-max') || '0', 10);
        var add = repeater.querySelector(':scope > [data-ef-row-add]');
        if (add) add.disabled = max > 0 && rows.length >= max;
    }

    function addRow(repeater) {
        var max = parseInt(repeater.getAttribute('data-max') || '0', 10);
        var list = repeater.querySelector(':scope > [data-ef-rows]');
        if (max > 0 && list.children.length >= max) {
            if (window.showMessage) window.showMessage(fmt(i18n.max_rows, max), 'error');
            return;
        }
        var tpl = repeater.querySelector(':scope > template[data-ef-row-template]');
        var html = tpl.innerHTML.replace(/__INDEX__/g, String(list.children.length));
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        var row = wrap.firstElementChild;
        list.appendChild(row);
        reindex(repeater);
        var first = row.querySelector('input:not([type=hidden]),textarea,select');
        if (first) first.focus();
    }

    // ── 关联 ──────────────────────────────────────────────────
    var searchTimer = null;
    function relIds(box) {
        var ids = [];
        box.querySelectorAll('[data-ef-rel-item]').forEach(function (c) { ids.push(c.getAttribute('data-ef-rel-item')); });
        return ids;
    }
    function relSync(box) {
        box.querySelector('[data-ef-value]').value = relIds(box).join(',');
        changed(box);
    }
    function relAdd(box, id, title) {
        var max = parseInt(box.getAttribute('data-max') || '0', 10);
        var ids = relIds(box);
        if (ids.indexOf(String(id)) !== -1) return;
        if (max > 0 && ids.length >= max) {
            if (window.showMessage) window.showMessage(fmt(i18n.max_related, max), 'error');
            return;
        }
        var chip = document.createElement('span');
        chip.className = 'inline-flex items-center gap-1 rounded bg-primary/10 px-2 py-1 text-sm text-primary';
        chip.setAttribute('data-ef-rel-item', String(id));
        chip.innerHTML = escapeHtml(title) + '<button type="button" class="text-gray-500 hover:text-red-600" data-ef-rel-remove>&times;</button>';
        box.querySelector('[data-ef-rel-chips]').appendChild(chip);
        relSync(box);
    }
    function relSearch(box, q) {
        var results = box.querySelector('[data-ef-rel-results]');
        var url = base + '/admin/extfield_api.php?action=search&target=' + encodeURIComponent(box.getAttribute('data-target')) + '&q=' + encodeURIComponent(q);
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var items = d && d.code === 0 && d.data ? d.data.items || [] : [];
                var taken = relIds(box);
                results.innerHTML = items.length ? '' : '<div class="px-3 py-2 text-sm text-gray-400">' + escapeHtml(i18n.no_results || '') + '</div>';
                items.forEach(function (item) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'block w-full px-3 py-2 text-start text-sm hover:bg-gray-50' + (taken.indexOf(String(item.id)) !== -1 ? ' text-gray-400' : '');
                    b.setAttribute('data-ef-rel-pick', item.id);
                    b.setAttribute('data-title', item.title);
                    b.innerHTML = escapeHtml(item.title) + ' <span class="text-xs text-gray-400">#' + item.id + '</span>';
                    results.appendChild(b);
                });
                results.classList.remove('hidden');
                // 靠近窗口底部（编辑页底部有悬浮保存栏）时改为向上展开
                results.style.bottom = '';
                results.style.marginBottom = '';
                var rect = results.getBoundingClientRect();
                if (rect.bottom > window.innerHeight - 90) { results.style.bottom = '100%'; results.style.marginBottom = '4px'; }
            })
            .catch(function () { results.classList.add('hidden'); });
    }

    // ── 条件逻辑 / 挂载位置 ─────────────────────────────────────
    function fieldValue(form, key) {
        var box = form.querySelector('[data-ef-field="' + key + '"]');
        if (!box) return '';
        var picked = [];
        box.querySelectorAll('[name]').forEach(function (el) {
            var n = el.getAttribute('name');
            if (n.indexOf('__present_') !== -1) return;
            if (el.type === 'checkbox') { if (el.checked) picked.push(el.value); return; }
            if (el.type === 'hidden' && box.querySelector('input[type=checkbox][name="' + n + '"]')) return; // 开关的 0 占位
            if (el.type === 'radio') { if (el.checked) picked.push(el.value); return; }
            if (el.value !== '') picked.push(el.value);
        });
        return picked.join(',');
    }

    function conditionsMatch(form, rules) {
        for (var i = 0; i < rules.length; i++) {
            var r = rules[i];
            var v = fieldValue(form, r.field);
            var list = v === '' ? [] : v.split(',');
            var ok = r.op === 'empty' ? v.trim() === ''
                : r.op === 'not_empty' ? v.trim() !== ''
                : r.op === '!=' ? list.indexOf(String(r.value)) === -1
                : list.indexOf(String(r.value)) !== -1;
            if (!ok) return false;
        }
        return true;
    }

    function termId(form) {
        var selector = form.getAttribute('data-ef-term-input');
        if (!selector) return null;
        var el = document.querySelector(selector);
        return el ? parseInt(el.value || '0', 10) : null;
    }

    function setVisible(box, visible) {
        box.classList.toggle('hidden', !visible);
        box.querySelectorAll(visible ? '[data-ef-req]' : '[required]').forEach(function (el) {
            if (visible) { el.setAttribute('required', ''); el.removeAttribute('data-ef-req'); }
            else { el.removeAttribute('required'); el.setAttribute('data-ef-req', '1'); }
        });
    }

    function refresh(form) {
        var term = termId(form);
        form.querySelectorAll('[data-ef-field]').forEach(function (box) {
            var visible = true;
            var location = box.getAttribute('data-ef-location');
            if (location && term !== null) visible = location.split(',').indexOf(String(term)) !== -1;
            else if (location && term === null) visible = !box.classList.contains('hidden');
            var rules = box.getAttribute('data-ef-conditions');
            if (visible && rules) {
                try { visible = conditionsMatch(form, JSON.parse(rules)); } catch (e) { /* 坏配置：照常显示 */ }
            }
            setVisible(box, visible);
        });
    }

    function changed(el) {
        var form = closest(el, '[data-ef-form]');
        if (form) refresh(form);
    }

    // ── 绑定 ──────────────────────────────────────────────────
    function initForm(form) {
        if (form.__efBound) return;
        form.__efBound = true;
        form.querySelectorAll('[data-ef-repeater]').forEach(reindex);
        form.querySelectorAll('textarea[data-ef-richtext]').forEach(function (ta) {
            if (typeof window.initTinyEditor === 'function' && ta.id) window.initTinyEditor('#' + ta.id, { height: 320 });
        });
        var selector = form.getAttribute('data-ef-term-input');
        if (selector) {
            var input = document.querySelector(selector);
            if (input) {
                input.addEventListener('change', function () { refresh(form); });
                // 分类选择器多是自定义组件，只改隐藏域的值不触发 change：轮询兜底
                var last = input.value;
                window.setInterval(function () { if (input.value !== last) { last = input.value; refresh(form); } }, 500);
            }
        }
        form.addEventListener('change', function () { refresh(form); });
        form.addEventListener('input', function (e) {
            var color = closest(e.target, '[data-ef-color]');
            if (color) {
                if (e.target.matches('[data-ef-swatch]')) color.querySelector('[data-ef-value]').value = e.target.value;
                else if (/^#[0-9a-f]{6}$/i.test(e.target.value)) color.querySelector('[data-ef-swatch]').value = e.target.value;
            }
            var search = closest(e.target, '[data-ef-rel-search]');
            if (search) {
                var box = closest(search, '[data-ef-relationship]');
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () { relSearch(box, search.value.trim()); }, 250);
            }
        });
        form.addEventListener('focusin', function (e) {
            if (e.target.matches && e.target.matches('[data-ef-rel-search]')) relSearch(closest(e.target, '[data-ef-relationship]'), e.target.value.trim());
        });
        form.addEventListener('click', function (e) {
            var t = e.target;
            var btn;
            if ((btn = closest(t, '[data-ef-pick]'))) {
                var image = closest(btn, '[data-ef-image]');
                pickImage(function (url) {
                    if (!url) return;
                    image.querySelector('[data-ef-value]').value = url;
                    image.querySelector('[data-ef-preview]').style.backgroundImage = cssUrl(url);
                    changed(image);
                });
            } else if ((btn = closest(t, '[data-ef-clear]'))) {
                var img = closest(btn, '[data-ef-image]');
                img.querySelector('[data-ef-value]').value = '';
                img.querySelector('[data-ef-preview]').style.backgroundImage = '';
                changed(img);
            } else if ((btn = closest(t, '[data-ef-add-image]'))) {
                var images = closest(btn, '[data-ef-images]');
                pickImage(function (url) {
                    if (!url) return;
                    images.querySelector('[data-ef-thumbs]').appendChild(thumb(url));
                    syncImages(images);
                });
            } else if ((btn = closest(t, '[data-ef-remove-image]'))) {
                var box = closest(btn, '[data-ef-images]');
                closest(btn, '[data-ef-thumb]').remove();
                syncImages(box);
            } else if ((btn = closest(t, '[data-ef-upload]'))) {
                closest(btn, '[data-ef-file]').querySelector('[data-ef-file-input]').click();
            } else if ((btn = closest(t, '[data-ef-row-add]'))) {
                addRow(closest(btn, '[data-ef-repeater]'));
            } else if ((btn = closest(t, '[data-ef-row-remove]'))) {
                var rep = closest(btn, '[data-ef-repeater]');
                closest(btn, '[data-ef-row]').remove();
                reindex(rep);
                changed(rep);
            } else if ((btn = closest(t, '[data-ef-row-up]')) || (btn = closest(t, '[data-ef-row-down]'))) {
                var row = closest(btn, '[data-ef-row]');
                var up = btn.hasAttribute('data-ef-row-up');
                var sibling = up ? row.previousElementSibling : row.nextElementSibling;
                if (sibling) row.parentNode.insertBefore(up ? row : sibling, up ? sibling : row);
                reindex(closest(row, '[data-ef-repeater]'));
            } else if ((btn = closest(t, '[data-ef-rel-pick]'))) {
                var rel = closest(btn, '[data-ef-relationship]');
                relAdd(rel, btn.getAttribute('data-ef-rel-pick'), btn.getAttribute('data-title'));
                rel.querySelector('[data-ef-rel-results]').classList.add('hidden');
                rel.querySelector('[data-ef-rel-search]').value = '';
            } else if ((btn = closest(t, '[data-ef-rel-remove]'))) {
                var relBox = closest(btn, '[data-ef-relationship]');
                closest(btn, '[data-ef-rel-item]').remove();
                relSync(relBox);
            }
        });
        form.addEventListener('change', function (e) {
            if (e.target.matches && e.target.matches('[data-ef-file-input]') && e.target.files[0]) {
                uploadFile(closest(e.target, '[data-ef-file]'), e.target.files[0]);
                e.target.value = '';
            }
        });
        document.addEventListener('click', function (e) {
            if (!closest(e.target, '[data-ef-relationship]')) {
                form.querySelectorAll('[data-ef-rel-results]').forEach(function (r) { r.classList.add('hidden'); });
            }
        });
        refresh(form);
    }

    function init(scope) {
        (scope || document).querySelectorAll('[data-ef-form]').forEach(initForm);
    }

    // 富文本字段：提交前把编辑器内容写回 textarea（捕获阶段，先于各页面自己的 submit 处理）
    document.addEventListener('submit', function (e) {
        if (e.target && e.target.querySelector && e.target.querySelector('textarea[data-ef-richtext]') && window.hugerte) window.hugerte.triggerSave();
    }, true);

    /**
     * 弹窗表单（栏目 / 产品分类）：向接口取该条目的字段区片段挂进容器；没有字段时容器隐藏。
     * 先卸掉容器里旧的富文本编辑器实例，免得反复开关弹窗时实例越积越多。
     */
    function mount(container, owner, id) {
        if (!container) return Promise.resolve();
        if (window.hugerte) {
            container.querySelectorAll('textarea[data-ef-richtext]').forEach(function (ta) {
                var ed = ta.id ? window.hugerte.get(ta.id) : null;
                if (ed) ed.remove();
            });
        }
        container.innerHTML = '';
        container.classList.add('hidden');
        var url = base + '/admin/extfield_api.php?action=render&owner=' + encodeURIComponent(owner) + '&id=' + encodeURIComponent(id || 0);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var html = d && d.code === 0 && d.data ? String(d.data.html || '').trim() : '';
                if (html === '') return;
                container.innerHTML = html;
                container.classList.remove('hidden');
                init(container);
            })
            .catch(function () { /* 字段区取不到不影响弹窗其它部分 */ });
    }

    window.YkExtFields = { init: init, refresh: refresh, mount: mount };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); });
    else init(document);
})(window, document);
