/* 易开网页构建器：长内容折叠（容器 / Div，2.0.3）。
 * 服务端已输出折叠态（不跑脚本的访客由 <noscript> 样式完整显示）；本脚本负责：
 *   - 按当前设备的折叠高度判断内容是否真的超出：没超出就不显示渐隐和按钮；
 *   - 展开 / 收起（高度动画，访客偏好减少动效时直接切换），更新 aria-expanded 与按钮文字；
 *   - 折叠时把被遮住部分里的链接、按钮移出 Tab 顺序，展开后恢复；
 *   - 窗口尺寸或内容高度变化（图片加载等）时重新测量。 */
(function () {
    'use strict';
    var FOCUSABLE = 'a[href], button, input, select, textarea, iframe, [tabindex]';

    function parts(root) {
        var body = null, toggle = null;
        for (var i = 0; i < root.children.length; i++) {
            var child = root.children[i];
            if (child.classList.contains('yk-collapse-body')) body = child;
            else if (child.hasAttribute('data-yk-collapse-toggle')) toggle = child;
        }
        return { body: body, toggle: toggle, button: toggle ? toggle.querySelector('button') : null };
    }

    /** 当前设备的折叠高度（px）；0 = 该设备不折叠。断点与样式表一致。 */
    function limitPx(root) {
        var width = window.innerWidth;
        var style = getComputedStyle(root);
        var name = width >= 1440 ? '--ykc-h-w' : width >= 1024 ? '--ykc-h-d' : width >= 768 ? '--ykc-h-t' : '--ykc-h-m';
        var value = style.getPropertyValue(name).trim();
        if (!value && name === '--ykc-h-w') value = style.getPropertyValue('--ykc-h-d').trim();
        var px = parseFloat(value);
        return isFinite(px) && px > 0 ? px : 0;
    }

    function restoreFocus(body) {
        var hidden = body.querySelectorAll('[data-yk-collapse-tabindex]');
        for (var i = 0; i < hidden.length; i++) {
            var original = hidden[i].getAttribute('data-yk-collapse-tabindex');
            if (original === '') hidden[i].removeAttribute('tabindex');
            else hidden[i].setAttribute('tabindex', original);
            hidden[i].removeAttribute('data-yk-collapse-tabindex');
        }
    }

    function hideClippedFocus(body, limit) {
        restoreFocus(body);
        var bottom = body.getBoundingClientRect().top + limit;
        var items = body.querySelectorAll(FOCUSABLE);
        for (var i = 0; i < items.length; i++) {
            if (items[i].getBoundingClientRect().top >= bottom - 2) {
                items[i].setAttribute('data-yk-collapse-tabindex', items[i].getAttribute('tabindex') || '');
                items[i].setAttribute('tabindex', '-1');
            }
        }
    }

    function update(root) {
        var p = parts(root);
        if (!p.body || !p.toggle) return;
        if (root.hasAttribute('data-yk-collapse-busy')) return;
        var limit = limitPx(root);
        var needed = limit > 0 && p.body.scrollHeight > limit + 4;
        if (!needed) {
            root.removeAttribute('data-yk-collapse-clipped');
            p.toggle.hidden = true;
            restoreFocus(p.body);
            return;
        }
        p.toggle.hidden = false;
        if (root.getAttribute('data-yk-collapse-state') === 'collapsed') {
            root.setAttribute('data-yk-collapse-clipped', '');
            hideClippedFocus(p.body, limit);
        }
    }

    function afterTransition(body, done) {
        var finished = false;
        var finish = function () {
            if (finished) return;
            finished = true;
            body.removeEventListener('transitionend', finish);
            done();
        };
        body.addEventListener('transitionend', finish);
        setTimeout(finish, 700);
    }

    function toggle(root) {
        var p = parts(root);
        if (!p.body || !p.button) return;
        var expand = root.getAttribute('data-yk-collapse-state') !== 'expanded';
        var animate = root.hasAttribute('data-yk-collapse-animate')
            && !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
        var label = p.button.querySelector('[data-yk-collapse-label]');
        if (expand) {
            var from = p.body.clientHeight;
            root.setAttribute('data-yk-collapse-state', 'expanded');
            root.removeAttribute('data-yk-collapse-clipped');
            restoreFocus(p.body);
            if (animate) {
                root.setAttribute('data-yk-collapse-busy', '');
                p.body.style.maxHeight = from + 'px';
                void p.body.offsetHeight;
                p.body.style.maxHeight = p.body.scrollHeight + 'px';
                afterTransition(p.body, function () { p.body.style.maxHeight = ''; root.removeAttribute('data-yk-collapse-busy'); });
            }
        } else {
            var limit = limitPx(root);
            if (animate) {
                root.setAttribute('data-yk-collapse-busy', '');
                p.body.style.maxHeight = p.body.scrollHeight + 'px';
                void p.body.offsetHeight;
                root.setAttribute('data-yk-collapse-state', 'collapsed');
                root.setAttribute('data-yk-collapse-clipped', '');
                p.body.style.maxHeight = limit + 'px';
                afterTransition(p.body, function () { p.body.style.maxHeight = ''; root.removeAttribute('data-yk-collapse-busy'); update(root); });
            } else {
                root.setAttribute('data-yk-collapse-state', 'collapsed');
                root.setAttribute('data-yk-collapse-clipped', '');
            }
            hideClippedFocus(p.body, limit);
            // 收起后若折叠区已滚出视口顶部，回到它的开头，免得读者迷路
            if (root.getBoundingClientRect().top < 0) {
                root.scrollIntoView({ block: 'start', behavior: animate ? 'smooth' : 'auto' });
            }
        }
        p.button.setAttribute('aria-expanded', expand ? 'true' : 'false');
        if (label) label.textContent = p.button.getAttribute(expand ? 'data-yk-collapse-less' : 'data-yk-collapse-more') || label.textContent;
    }

    function roots() {
        return document.querySelectorAll('[data-yk-collapse]');
    }

    function init() {
        var list = roots();
        for (var i = 0; i < list.length; i++) update(list[i]);
        if (window.ResizeObserver) {
            var observer = new ResizeObserver(function (entries) {
                for (var j = 0; j < entries.length; j++) {
                    var root = entries[j].target.closest('[data-yk-collapse]');
                    if (root) update(root);
                }
            });
            for (var k = 0; k < list.length; k++) {
                var body = parts(list[k]).body;
                if (body) {
                    // 观察内层的子项：内容高度变化（图片加载、字体替换）时重新测量
                    for (var c = 0; c < body.children.length; c++) observer.observe(body.children[c]);
                }
            }
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('[data-yk-collapse-toggle] button') : null;
        if (!button) return;
        var root = button.closest('[data-yk-collapse]');
        if (root) toggle(root);
    });

    var resizeTimer = 0;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            var list = roots();
            for (var i = 0; i < list.length; i++) update(list[i]);
        }, 150);
    });
    window.addEventListener('load', function () {
        var list = roots();
        for (var i = 0; i < list.length; i++) update(list[i]);
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    window.BloxCollapse = { update: update, toggle: toggle, limitPx: limitPx };
})();
