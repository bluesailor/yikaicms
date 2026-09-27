(function (window, document) {
    'use strict';
    var states = new WeakMap();
    function editorHtml(value) {
        // 旧纯文本按原有换行显示；未编辑时仍保存原文，不自动转成 HTML。
        if (/<\/?[a-z][^>]*>/i.test(value)) return value;
        return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\r?\n/g, '<br>');
    }
    function read(input) {
        var state = states.get(input);
        if (state && state.editor && !state.source && state.editor.isDirty()) {
            input.value = state.editor.getContent();
        }
        return input.value;
    }
    function clear(input) {
        input.value = '';
        var state = states.get(input);
        if (state && state.editor) {
            state.editor.setContent('');
            state.editor.setDirty(false);
        }
    }
    function menu(input, selected) {
        var state = states.get(input);
        input.readOnly = selected;
        var note = input.closest('.fcol-row').querySelector('.fcol-menu-notice');
        if (note) note.hidden = !selected;
        if (state && state.editor) state.editor.mode.set(selected ? 'readonly' : 'design');
    }
    window.YikaiFooterEditor = { read: read, clear: clear, menu: menu, editorHtml: editorHtml };
    document.querySelectorAll('.fcol-content').forEach(function (input) {
        var row = input.closest('.fcol-row');
        var selected = function () { return Number(row.querySelector('.fcol-menu').value) > 0; };
        if (!window.hugerte) return;
        // 单独编辑副本，防止第三方 triggerSave() 把未编辑的旧 HTML 重排后写回。
        var draft = document.createElement('textarea');
        draft.id = input.id + '-rich';
        draft.hidden = true;
        input.insertAdjacentElement('afterend', draft);
        var state = { editor: null, source: false };
        states.set(input, state);
        window.hugerte.init({
            target: draft,
            language: window.editorLanguage(document.documentElement.lang) || undefined,
            height: 220, min_height: 180,
            menubar: false, statusbar: false, branding: false, promotion: false,
            plugins: 'lists link image',
            toolbar: 'undo redo | bold italic | bullist numlist | link unlink | footermedia | removeformat | footersource',
            toolbar_mode: 'sliding',
            convert_urls: false, paste_as_text: true,
            content_style: 'body{font-family:system-ui,sans-serif;font-size:14px;line-height:1.6;margin:12px}p{margin:0 0 .5em}a{color:#2563eb}img{max-width:100%;height:auto}',
            setup: function (editor) {
                editor.ui.registry.addButton('footersource', {
                    icon: 'sourcecode',
                    tooltip: input.dataset.sourceLabel,
                    onAction: function () {
                        if (selected() || state.source) return;
                        var original = read(input);
                        state.source = true;
                        editor.windowManager.open({
                            title: input.dataset.sourceLabel,
                            size: 'large',
                            body: { type: 'panel', items: [{ type: 'textarea', name: 'code' }] },
                            buttons: [
                                { type: 'cancel', name: 'cancel', text: 'Cancel' },
                                { type: 'submit', name: 'save', text: 'Ok', primary: true }
                            ],
                            initialData: { code: original },
                            onSubmit: function (dialog) {
                                var code = dialog.getData().code;
                                // 未改源码时不经编辑库重排，保留旧 HTML 和占位符。
                                if (!selected() && code !== original) {
                                    editor.focus();
                                    editor.undoManager.transact(function () { editor.setContent(editorHtml(code)); });
                                    input.value = code;
                                    editor.setDirty(false);
                                    editor.selection.setCursorLocation();
                                    editor.nodeChanged();
                                }
                                dialog.close();
                            },
                            onClose: function () { state.source = false; }
                        });
                    }
                });
                editor.ui.registry.addButton('footermedia', {
                    icon: 'image',
                    tooltip: input.dataset.mediaLabel,
                    onAction: function () {
                        if (state.source || selected() || typeof window.openMediaPicker !== 'function') return;
                        var bookmark = editor.selection.getBookmark(2, true);
                        window.openMediaPicker(function (url) {
                            if (state.source || selected() || typeof url !== 'string'
                                || /[\s\\\x00-\x1f]/.test(url) || !/^(\/[^/]|https?:\/\/)/i.test(url)) return;
                            editor.focus();
                            editor.selection.moveToBookmark(bookmark);
                            editor.undoManager.transact(function () {
                                editor.insertContent(editor.dom.createHTML('img', { src: url, alt: '', width: '160' }));
                            });
                            editor.setDirty(true);
                        }, { type: 'image', source: 'local' });
                    }
                });
                editor.on('init', function () {
                    state.editor = editor;
                    editor.setContent(editorHtml(input.value));
                    editor.setDirty(false);
                    input.hidden = true;
                    menu(input, selected());
                });
            }
        }).catch(function () {
            // 编辑库加载失败时保留可用的原文字段，不丢内容、不阻止保存。
            if (state.editor) state.editor.remove();
            draft.remove();
            states.delete(input);
            input.hidden = false;
        });
    });
})(window, document);
