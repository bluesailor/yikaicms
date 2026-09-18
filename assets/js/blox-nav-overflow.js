/**
 * 横向导航「更多 ▾」溢出收纳（priority+ 模式，渐进增强）。
 *
 * 由来：菜单项多 + 开启菜单图标后，主题页头的一级菜单（flex-wrap）折成两行。
 * 压缩间距治标，服务端不知道视口宽度——只有浏览器里量得准，故纯客户端处理：
 * 折行时从最后一个一级项开始收进「更多」下拉，窗口变宽再放回去。
 *
 * 挂载点：<ul data-yk-nav-overflow="更多"> —— 属性值即本地化的「更多」文案，
 * 由 NavElement 渲染（仅横向菜单输出；flex-col 页脚菜单不挂）。
 * 收纳形态：一级项复用原 <a>（保留图标），其下拉子项缩进平铺；CTA
 * （data-yk-nav-cta）与「更多」自身永不收纳。全部类名复用既有下拉的
 * 已编译 Tailwind 类，无新增样式依赖；对无 JS 环境零影响（原样 flex-wrap）。
 */
(function () {
    'use strict';

    var panelSequence = 0;

    function initNav(ul) {
        if (ul.dataset.ykNavOverflowReady) return;
        ul.dataset.ykNavOverflowReady = '1';

        var label = ul.getAttribute('data-yk-nav-overflow') || 'More';
        var isMega = !!ul.closest('.yk-mega');

        var more = document.createElement('li');
        more.className = 'relative group/nav hidden';
        more.setAttribute('data-yk-nav-more', '');
        var link = document.createElement('a');
        link.href = '#';
        link.setAttribute('aria-haspopup', 'true');
        link.setAttribute('aria-expanded', 'false');
        link.className = 'inline-flex items-center gap-1 hover:text-primary';
        if (isMega) link.className += ' px-3 py-2 font-medium';
        link.appendChild(document.createTextNode(label));
        link.insertAdjacentHTML('beforeend',
            '<svg class="h-3 w-3 shrink-0 opacity-60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>');
        var panel = document.createElement('ul');
        panel.id = 'yk-nav-overflow-' + (++panelSequence);
        link.setAttribute('aria-controls', panel.id);
        // 右对齐弹出：「更多」贴菜单尾部，面板左伸避免出屏
        panel.className = 'yk-nav-panel absolute right-0 top-full z-30 hidden w-max min-w-[10rem] rounded-xl border border-gray-100 bg-white py-2 shadow-lg group-hover/nav:block group-focus-within/nav:block';
        // 显式 display 状态覆盖 hover/focus 工具类，保证 Escape 后焦点回到触发器也不会立刻重开。
        panel.style.display = 'none';
        more.appendChild(link);
        more.appendChild(panel);

        var expanded = false;
        var pinned = false;

        function setExpanded(next, focusTrigger) {
            expanded = !!next && panel.children.length > 0 && !more.classList.contains('hidden');
            link.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            panel.style.display = expanded ? 'block' : 'none';
            if (focusTrigger) link.focus();
        }

        function focusPanelEdge(last) {
            var links = panel.querySelectorAll('a[href]');
            if (links.length === 0) return;
            links[last ? links.length - 1 : 0].focus();
        }

        function togglePinned() {
            pinned = !pinned;
            setExpanded(pinned, false);
        }

        link.addEventListener('click', function (e) {
            e.preventDefault();
            togglePinned();
        });
        link.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                pinned = true;
                setExpanded(true, false);
                focusPanelEdge(e.key === 'ArrowUp');
            } else if (e.key === ' ') {
                e.preventDefault();
                togglePinned();
            }
        });
        more.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !expanded) return;
            e.preventDefault();
            e.stopPropagation();
            pinned = false;
            setExpanded(false, true);
        });
        more.addEventListener('mouseenter', function () { setExpanded(true, false); });
        more.addEventListener('mouseleave', function () {
            if (!pinned && !more.contains(document.activeElement)) setExpanded(false, false);
        });
        more.addEventListener('focusout', function () {
            window.setTimeout(function () {
                if (!more.contains(document.activeElement)) {
                    pinned = false;
                    setExpanded(false, false);
                }
            }, 0);
        });
        document.addEventListener('click', function (e) {
            if (!more.contains(e.target)) {
                pinned = false;
                setExpanded(false, false);
            }
        });

        var cta = ul.querySelector(':scope > li[data-yk-nav-cta]');
        ul.insertBefore(more, cta);

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

        // 折行判定：任一项（含「更多」与 CTA）的 offsetTop 低于首项即已换行
        function isWrapped() {
            var items = movableItems();
            if (items.length === 0) return false;
            var top = items[0].offsetTop;
            // 图标、CTA 的行内盒可能产生 1-2px 正常垂直偏移；真正折行会接近整行高度。
            var lineTolerance = Math.max(4, Math.min(12, Math.round(items[0].offsetHeight / 4)));
            var bounds = ul.getBoundingClientRect();
            var visibleItems = items.concat(more.classList.contains('hidden') ? [] : [more], cta ? [cta] : []);
            for (var j = 0; j < visibleItems.length; j++) {
                var rect = visibleItems[j].getBoundingClientRect();
                if (rect.left < bounds.left - 1 || rect.right > bounds.right + 1) return true;
            }
            for (var i = 1; i < items.length; i++) {
                if (items[i].offsetTop > top + lineTolerance) return true;
            }
            if (!more.classList.contains('hidden') && more.offsetTop > top + lineTolerance) return true;
            if (cta && cta.offsetTop > top + lineTolerance) return true;
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
                var caret = a.querySelector('svg');
                if (caret) caret.remove();
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
            pinned = false;
            setExpanded(false, false);
            for (var i = 0; i < moved.length; i++) ul.insertBefore(moved[i], more);
            moved = [];
            panel.textContent = '';
            more.classList.add('hidden');

            if (ul.offsetParent === null) return;   // display:none（窄屏走抽屉），不量

            var guard = 0;
            while (isWrapped() && guard++ < 60) {
                var items = movableItems();
                if (items.length <= 2) break;       // 至少留两项在栏上，避免主导航只剩孤立入口
                var last = items[items.length - 1];
                more.classList.remove('hidden');
                panel.insertBefore(entryFor(last), panel.firstChild);
                moved.unshift(last);
                last.remove();
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
    document.addEventListener('blox:content-updated', boot);
})();
