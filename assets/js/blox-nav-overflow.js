/**
 * 横向导航「更多 ▾」溢出收纳（priority+ 模式，渐进增强）。
 *
 * 由来：菜单项多 + 开启菜单图标后，主题页头的一级菜单（flex-wrap）折成两行。
 * 压缩间距治标，服务端不知道视口宽度——只有浏览器里量得准，故纯客户端处理：
 * 折行时从最后一个一级项开始收进「更多」下拉，窗口变宽再放回去。
 *
 * 挂载点：<ul data-yk-nav-overflow="更多"> —— 属性值即本地化的「更多」文案，
 * 由 NavElement / NavMegaElement 渲染（仅横向菜单输出；flex-col 页脚菜单不挂）。
 * 收纳形态：一级项复用原 <a>（保留图标），其下拉子项缩进平铺；CTA
 * （data-yk-nav-cta）与「更多」自身永不收纳。全部类名复用既有下拉的
 * 已编译 Tailwind 类，无新增样式依赖；对无 JS 环境零影响（原样 flex-wrap）。
 */
(function () {
    'use strict';

    function initNav(ul) {
        if (ul.dataset.ykNavOverflowReady) return;
        ul.dataset.ykNavOverflowReady = '1';

        var label = ul.getAttribute('data-yk-nav-overflow') || 'More';

        var more = document.createElement('li');
        more.className = 'relative group/nav hidden';
        more.setAttribute('data-yk-nav-more', '');
        var link = document.createElement('button');
        link.type = 'button';
        link.setAttribute('aria-haspopup', 'true');
        link.setAttribute('aria-expanded', 'false');
        link.className = 'inline-flex items-center gap-1 hover:text-primary';
        link.appendChild(document.createTextNode(label));
        link.insertAdjacentHTML('beforeend',
            '<svg class="h-3 w-3 shrink-0 opacity-60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>');
        var panel = document.createElement('ul');
        // 右对齐弹出：「更多」贴菜单尾部，面板左伸避免出屏
        panel.className = 'absolute right-0 top-full z-30 hidden w-max min-w-[10rem] rounded-xl border border-gray-100 bg-white py-2 shadow-lg';
        panel.style.maxWidth = 'calc(100vw - 32px)';
        panel.style.maxHeight = '70vh';
        panel.style.overflowY = 'auto';
        more.appendChild(link);
        more.appendChild(panel);

        var cta = ul.querySelector(':scope > li[data-yk-nav-cta]');
        ul.insertBefore(more, cta);

        var clickedOpen = false;
        function setOpen(open) {
            panel.classList.toggle('hidden', !open);
            link.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (!open) clickedOpen = false;
        }
        link.addEventListener('click', function () {
            var open = !clickedOpen;
            setOpen(open);
            clickedOpen = open;
        });
        more.addEventListener('mouseenter', function () { setOpen(true); });
        more.addEventListener('mouseleave', function () {
            if (!more.contains(document.activeElement)) setOpen(false);
        });
        more.addEventListener('focusout', function (event) {
            if (!more.contains(event.relatedTarget)) setOpen(false);
        });
        document.addEventListener('pointerdown', function (event) {
            if (!more.contains(event.target)) setOpen(false);
        });
        more.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                setOpen(false);
                link.focus();
            } else if (event.target === link && event.key === 'ArrowDown') {
                event.preventDefault();
                setOpen(true);
                var first = panel.querySelector('a');
                if (first) first.focus();
            }
        });

        var moved = [];   // 原始 li 引用，按原顺序；还原=插回「更多」之前

        function movableItems() {
            var out = [];
            for (var i = 0; i < ul.children.length; i++) {
                var li = ul.children[i];
                if (li === more || li.hasAttribute('data-yk-nav-cta')) continue;
                out.push(li);
            }
            return out;
        }

        // Mega 单行导航可能向左挤占 Logo；普通导航可能折行，两种情况都要收纳。
        function isOverflowing() {
            var boundary = (ul.closest('.yk-mega') || ul).getBoundingClientRect();
            var firstRect = null;
            for (var i = 0; i < ul.children.length; i++) {
                var li = ul.children[i];
                if (!li.getClientRects().length) continue;
                var rect = li.getBoundingClientRect();
                if (rect.left < boundary.left - 1 || rect.right > boundary.right + 1) return true;
                if (firstRect === null) firstRect = rect;
                // Compare viewport coordinates: positioned li offsetTop values use different parents.
                // Different button heights on the same centered row are not a wrap.
                if (rect.top >= firstRect.bottom - 1 || rect.bottom <= firstRect.top + 1) return true;
            }
            return false;
        }

        // 一级项 → 面板条目：克隆顶层 <a>（图标随克隆保留、去掉下拉箭头），
        // 其下拉子项以缩进条目平铺（收进「更多」后子菜单仍可达）
        function entryFor(li) {
            var frag = document.createDocumentFragment();
            var top = li.querySelector(':scope > a');
            if (top) {
                var item = document.createElement('li');
                var a = top.cloneNode(true);
                var caret = a.querySelector('[data-yk-nav-caret], svg');
                if (caret) caret.remove();
                a.removeAttribute('aria-haspopup');
                a.removeAttribute('aria-expanded');
                a.className = 'block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 hover:text-primary';
                item.appendChild(a);
                frag.appendChild(item);
            }
            li.querySelectorAll(':scope > ul a, :scope > .yk-mega-panel a').forEach(function (sub) {
                var item = document.createElement('li');
                var a = sub.cloneNode(true);
                a.className = 'block pl-8 pr-4 py-1.5 text-sm text-gray-500 hover:bg-gray-50 hover:text-primary';
                item.appendChild(a);
                frag.appendChild(item);
            });
            return frag;
        }

        function reflow() {
            var active = document.activeElement;
            // Keep focus on the same destination if a resize moves it between the bar and dropdown.
            var focusedHref = ul.contains(active) && active.tagName === 'A' ? active.getAttribute('href') : null;
            var moreFocused = active === link;
            for (var i = 0; i < moved.length; i++) ul.insertBefore(moved[i], more);
            moved = [];
            panel.textContent = '';
            more.classList.add('hidden');
            setOpen(false);

            if (ul.offsetParent === null) return;   // display:none（窄屏走抽屉），不量

            var guard = 0;
            while (isOverflowing() && guard++ < 60) {
                var items = movableItems();
                if (items.length <= 1) break;       // 至少留一项在栏上
                var last = items[items.length - 1];
                more.classList.remove('hidden');
                panel.insertBefore(entryFor(last), panel.firstChild);
                moved.unshift(last);
                last.remove();
            }
            if (focusedHref !== null) {
                var destination = Array.from(ul.querySelectorAll('a')).find(function (a) {
                    return a.getAttribute('href') === focusedHref;
                });
                if (destination && panel.contains(destination)) setOpen(true);
                if (destination && document.activeElement !== destination) destination.focus({preventScroll: true});
            } else if (moreFocused && more.classList.contains('hidden')) {
                var firstLink = ul.querySelector('a');
                if (firstLink) firstLink.focus({preventScroll: true});
            }
        }

        var pending = 0;
        function schedule() {
            if (pending) return;
            pending = requestAnimationFrame(function () { pending = 0; reflow(); });
        }

        window.addEventListener('resize', schedule);
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(schedule).observe(ul.parentElement || ul);
        }
        reflow();
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(schedule);
    }

    function boot() {
        document.querySelectorAll('ul[data-yk-nav-overflow]').forEach(initNav);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
