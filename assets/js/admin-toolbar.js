/**
 * 后台顶栏设置（2.3.1）：账号菜单 →「顶栏设置」里勾选哪些快捷入口固定在顶栏，没勾的收进「更多」。
 *
 * 位置只由各入口的 data-pinned 和顶栏根节点的 data-yk-tb-hidden（空格分隔的项名）决定，显示 / 收起全交给 admin.css；
 * 复杂入口（查找页面、提醒、AI 助手）收起时组件仍挂在页面上、只是移出顶栏的排版，
 * 所以 Ctrl/⌘+K、提醒计数这些照常工作，「更多」里点它们只是发 yk-toolbar-open 事件让组件自己打开。
 * 改动立即生效，并逐项 POST 到 /admin/toolbar_prefs_api.php 存到当前账号；保存失败就把界面改回去。
 */
(function (root) {
    'use strict';

    function normalize(list, order) {
        var wanted = {};
        (Array.isArray(list) ? list : []).forEach(function (key) {
            if (typeof key === 'string') wanted[key] = true;
        });
        return order.filter(function (key) { return wanted[key] === true; });
    }

    function withItem(hidden, item, pinned, order) {
        var next = hidden.filter(function (key) { return key !== item; });
        if (!pinned) next.push(item);
        return normalize(next, order);
    }

    /** 根节点属性只写当前页面真有的入口：不可用的项不能让「更多」按钮出现却点开是空的。 */
    function attributeValue(hidden, rendered) {
        return hidden.filter(function (key) { return rendered.indexOf(key) !== -1; }).join(' ');
    }

    function create(config, deps) {
        var order = config.items.slice();
        var rendered = config.rendered.slice();
        var state = { hidden: normalize(config.hidden, order) };
        var el = deps.root;
        var send = deps.send;
        var notify = deps.notify || function () {};
        var listeners = [];
        var seq = 0;

        function paint() {
            el.setAttribute('data-yk-tb-hidden', attributeValue(state.hidden, rendered));
            Array.prototype.forEach.call(el.querySelectorAll('[data-yk-tb-item], [data-yk-tb-more]'), function (node) {
                var key = node.getAttribute('data-yk-tb-item') || node.getAttribute('data-yk-tb-more');
                node.setAttribute('data-pinned', state.hidden.indexOf(key) === -1 ? '1' : '0');
            });
            listeners.forEach(function (fn) { fn(state.hidden.slice()); });
        }

        function commit(fields, previous, okText) {
            var mine = ++seq;
            return Promise.resolve()
                .then(function () { return send(fields); })
                .then(function (result) {
                    if (!result || Number(result.code) !== 0) throw new Error((result && result.msg) || '');
                    // 连点时只认最后一次请求的结果，免得旧响应把界面改回去
                    if (mine === seq && result.data && Array.isArray(result.data.hidden)) {
                        state.hidden = normalize(result.data.hidden, order);
                        paint();
                    }
                    notify(okText, 'success');
                    return true;
                })
                .catch(function () {
                    if (mine === seq) {
                        state.hidden = previous;
                        paint();
                    }
                    notify(config.text.failed, 'error');
                    return false;
                });
        }

        paint();
        return {
            hidden: function () { return state.hidden.slice(); },
            isPinned: function (item) { return state.hidden.indexOf(item) === -1; },
            onChange: function (fn) { listeners.push(fn); fn(state.hidden.slice()); },
            setPinned: function (item, pinned) {
                if (order.indexOf(item) === -1) return Promise.resolve(false);
                var previous = state.hidden.slice();
                state.hidden = withItem(previous, item, pinned, order);
                paint();
                return commit({ action: 'set_item', item: item, pinned: pinned ? '1' : '0' }, previous, config.text.saved);
            },
            reset: function () {
                var previous = state.hidden.slice();
                state.hidden = [];
                paint();
                return commit({ action: 'reset' }, previous, config.text.restored);
            }
        };
    }

    /** 顶栏放不下时依次收进「更多」的项（查找页面、提醒常驻）。 */
    var OVERFLOW_ORDER = ['cache', 'help', 'visit', 'language', 'ai'];

    /**
     * 顶栏放不下（中等宽度 + 展开的侧栏、长语言名等）就按 OVERFLOW_ORDER 逐项挪进「更多」，直到放得下，
     * 账号菜单永远不会被挤出屏幕。只临时标记 data-overflow，不改保存的设置；窄屏（< 640px）由 CSS 直接收起。
     * overflows() 由调用方给（浏览器里量 header），便于测试。
     */
    function fit(el, overflows, isVisible) {
        Array.prototype.forEach.call(el.querySelectorAll('[data-overflow]'), function (node) { node.removeAttribute('data-overflow'); });
        el.removeAttribute('data-yk-tb-overflowing');
        if (!overflows()) return [];
        el.setAttribute('data-yk-tb-overflowing', '');
        var moved = [];
        for (var i = 0; i < OVERFLOW_ORDER.length && overflows(); i++) {
            var key = OVERFLOW_ORDER[i];
            var item = el.querySelector('[data-yk-tb-item="' + key + '"]');
            if (!item || !isVisible(item)) continue;
            item.setAttribute('data-overflow', '');
            var entry = el.querySelector('[data-yk-tb-more="' + key + '"]');
            if (entry) entry.setAttribute('data-overflow', '');
            moved.push(key);
        }
        return moved;
    }

    function postForm(endpoint, token) {
        return function (fields) {
            var body = new FormData();
            body.set('_token', token);
            Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
            return fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { return response.json(); });
        };
    }

    /** 顶栏根节点上的 JSON 配置 → 控制器；设置对话框的复选框和状态行在这里接上。 */
    function boot(doc) {
        var el = doc.querySelector('[data-yk-toolbar]');
        if (!el || root.ykToolbar) return root.ykToolbar || null;
        var config = JSON.parse(el.getAttribute('data-yk-toolbar') || '{}');
        var status = doc.querySelector('[data-yk-tb-status]');
        var timer = null;
        var controller = create(config, {
            root: el,
            send: postForm((root.YK_BASE || '') + '/admin/toolbar_prefs_api.php', config.token),
            notify: function (text) {
                if (!status) return;
                status.textContent = text;
                clearTimeout(timer);
                timer = setTimeout(function () { status.textContent = ''; }, 2500);
            }
        });
        var boxes = Array.prototype.slice.call(doc.querySelectorAll('[data-yk-tb-check]'));
        boxes.forEach(function (box) {
            box.addEventListener('change', function () { controller.setPinned(box.value, box.checked); });
        });
        controller.onChange(function (hidden) {
            boxes.forEach(function (box) { box.checked = hidden.indexOf(box.value) === -1; });
        });
        var resetButton = doc.querySelector('[data-yk-tb-reset]');
        if (resetButton) resetButton.addEventListener('click', function () { controller.reset(); });
        // 对话框关闭时浏览器把焦点还给打开它的菜单项，可那个菜单已经收起：改放到账号菜单按钮上
        var dialog = doc.getElementById('ykToolbarSettings');
        if (dialog) dialog.addEventListener('close', function () {
            var active = doc.activeElement;
            var account = doc.querySelector('[data-testid="admin-account-menu"] button');
            if (account && (!active || active === doc.body || active.offsetParent === null)) account.focus();
        });
        var header = el.closest('header');
        if (header) {
            var refit = function () {
                fit(el, function () { return header.scrollWidth > header.clientWidth + 1; }, function (node) {
                    return root.getComputedStyle(node).display !== 'none' && root.getComputedStyle(node).position !== 'absolute';
                });
            };
            controller.onChange(refit);
            if (root.ResizeObserver) new root.ResizeObserver(refit).observe(header);
            root.addEventListener('resize', refit);
            if (doc.fonts && doc.fonts.ready) doc.fonts.ready.then(refit);
        }
        root.ykToolbar = controller;
        return controller;
    }

    /**
     * 「更多」里点收起的复杂入口：让对应组件自己打开（查找页面展开输入框、提醒和 AI 打开面板）。
     * 等这次点击冒泡完再发：查找框的 @click.away 挂在整个组件上，同一次点击会把刚打开的浮层又关掉。
     */
    function open(item) {
        setTimeout(function () { root.dispatchEvent(new CustomEvent('yk-toolbar-open', { detail: item })); }, 0);
    }

    /** 收进「更多」的复杂入口（CSS 把它移出排版：position absolute）。 */
    function collapsed(el) {
        return !!el && el.hasAttribute('data-yk-tb-detach') && root.getComputedStyle(el).position === 'absolute';
    }

    /**
     * 键盘关掉面板（Esc）后把焦点放回原处：入口收在「更多」里就回「更多」按钮，否则回它自己看得见的按钮；
     * 都没有（桌面端常驻的查找输入框）就不动。焦点不能留在刚隐藏的元素上，否则 Tab 从页首重来。
     */
    function returnFocus(el) {
        var doc = root.document;
        var target = collapsed(el) ? doc.querySelector('[data-testid="admin-toolbar-more-button"]') : el && el.querySelector('[data-yk-tb-trigger]');
        if (target && target.offsetParent !== null) target.focus();
    }

    /** 从「更多」打开的面板：焦点移到面板里第一个可操作的元素，键盘用户不用再从页首 Tab 过去。 */
    function focusFirst(panel, tries) {
        if (!panel) return;
        // x-show 不一定在 $nextTick 时已经显示出来（没有过渡的面板晚一拍），没显示就稍等再试。
        // 用 getClientRects 判断：手机上提醒面板是 position:fixed，它的 offsetParent 永远是 null
        if (!panel.getClientRects().length) {
            if ((tries || 0) < 20) setTimeout(function () { focusFirst(panel, (tries || 0) + 1); }, 25);
            return;
        }
        var items = panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select, textarea');
        for (var i = 0; i < items.length; i++) {
            if (items[i].offsetParent !== null) { items[i].focus(); return; }
        }
        panel.setAttribute('tabindex', '-1');
        panel.focus();
    }

    root.YikaiAdminToolbar = { normalize: normalize, withItem: withItem, attributeValue: attributeValue, create: create, fit: fit, boot: boot, open: open,
        returnFocus: returnFocus, focusFirst: focusFirst };
    if (root.document && root.document.querySelector) {
        if (root.document.readyState === 'loading') {
            root.document.addEventListener('DOMContentLoaded', function () { boot(root.document); });
        } else {
            boot(root.document);
        }
    }
})(typeof window !== 'undefined' ? window : globalThis);
