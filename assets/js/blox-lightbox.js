/**
 * 图片灯箱（E05 切片 C）。
 *
 * 契约沿用既有的 a[data-lightbox]，所以相册等非 Blox 内容不改一行也能用上分组与键盘操作。
 * data-lightbox 的值即分组名：同名的归一组，可前后切换；值为 album 的交给相册页自己处理。
 *
 * 可访问性是这一版的重点——旧实现打开后焦点仍留在页面底层，键盘用户既翻不了页也回不去：
 *   · 打开即把焦点移进对话框，Tab 在对话框内循环（焦点陷阱）；
 *   · 关闭后焦点回到当初点击的那张图，而不是丢到 body；
 *   · Esc 关闭，← → 翻页；
 *   · 触屏左右滑动翻页，且不依赖 hover。
 *
 * 脚本失败不该让图片变得不可用：绑定发生在这里，绑定不上时 a[href] 仍是一个能打开原图的
 * 普通链接（渲染端保证 href 指向原图）。
 */
(function () {
    'use strict';

    var dialog = null;
    var image = null;
    var caption = null;
    var counter = null;
    var previousButton = null;
    var nextButton = null;
    var group = [];
    var index = 0;
    var opener = null;

    function build() {
        if (dialog) return;
        dialog = document.createElement('div');
        dialog.className = 'yk-lightbox';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.hidden = true;
        dialog.innerHTML =
            '<button type="button" class="yk-lightbox-close" data-close aria-label="Close">&times;</button>' +
            '<button type="button" class="yk-lightbox-prev" data-prev aria-label="Previous">&#8249;</button>' +
            '<figure class="yk-lightbox-figure">' +
            '<img class="yk-lightbox-img" alt="">' +
            '<figcaption class="yk-lightbox-caption"></figcaption>' +
            '</figure>' +
            '<button type="button" class="yk-lightbox-next" data-next aria-label="Next">&#8250;</button>' +
            '<p class="yk-lightbox-counter" aria-live="polite"></p>';
        document.body.appendChild(dialog);
        image = dialog.querySelector('.yk-lightbox-img');
        caption = dialog.querySelector('.yk-lightbox-caption');
        counter = dialog.querySelector('.yk-lightbox-counter');
        previousButton = dialog.querySelector('[data-prev]');
        nextButton = dialog.querySelector('[data-next]');

        dialog.addEventListener('click', function (event) {
            if (event.target === dialog || event.target.hasAttribute('data-close')) close();
            else if (event.target.hasAttribute('data-prev')) step(-1);
            else if (event.target.hasAttribute('data-next')) step(1);
        });
        bindSwipe();
    }

    function bindSwipe() {
        var startX = 0;
        var startY = 0;
        var tracking = false;
        dialog.addEventListener('touchstart', function (event) {
            if (event.touches.length !== 1) { tracking = false; return; }
            tracking = true;
            startX = event.touches[0].clientX;
            startY = event.touches[0].clientY;
        }, { passive: true });
        dialog.addEventListener('touchend', function (event) {
            if (!tracking) return;
            tracking = false;
            var touch = event.changedTouches[0];
            var dx = touch.clientX - startX;
            var dy = touch.clientY - startY;
            // 横向位移明显大于纵向才算翻页，避免把滚动误判成滑动
            if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) step(dx < 0 ? 1 : -1);
        }, { passive: true });
    }

    function groupName(link) {
        var value = link.getAttribute('data-lightbox');
        return value === null || value === '' ? '' : value;
    }

    function collect(link) {
        var name = groupName(link);
        if (name === '') return [link];
        var all = document.querySelectorAll('a[data-lightbox="' + name.replace(/"/g, '\\"') + '"]');
        return Array.prototype.slice.call(all);
    }

    function show(position) {
        var link = group[position];
        if (!link) return;
        index = position;
        image.src = link.href;
        var img = link.querySelector('img');
        var text = link.getAttribute('data-caption') || (img && img.getAttribute('alt')) || '';
        image.alt = text;
        caption.textContent = text;
        caption.hidden = text === '';
        var many = group.length > 1;
        previousButton.hidden = !many;
        nextButton.hidden = !many;
        counter.textContent = many ? (position + 1) + ' / ' + group.length : '';
    }

    function step(delta) {
        if (group.length < 2) return;
        show((index + delta + group.length) % group.length);
    }

    function open(link) {
        build();
        opener = link;
        group = collect(link);
        dialog.hidden = false;
        document.body.style.overflow = 'hidden';
        show(Math.max(0, group.indexOf(link)));
        // 焦点进对话框：键盘用户随即就能翻页与关闭
        (dialog.querySelector('[data-close]') || dialog).focus();
    }

    function close() {
        if (!dialog || dialog.hidden) return;
        dialog.hidden = true;
        image.src = '';
        document.body.style.overflow = '';
        // 焦点回到点开的那张图，而不是丢回 body
        if (opener && typeof opener.focus === 'function') opener.focus();
        opener = null;
    }

    function trapFocus(event) {
        var focusable = dialog.querySelectorAll('button:not([hidden])');
        if (!focusable.length) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a[data-lightbox]') : null;
        if (!link) return;
        // 相册页有自己的查看器；外链与新窗口意图交给浏览器
        if (link.getAttribute('data-lightbox') === 'album') return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
        event.preventDefault();
        open(link);
    });

    document.addEventListener('keydown', function (event) {
        if (!dialog || dialog.hidden) return;
        if (event.key === 'Escape') close();
        else if (event.key === 'ArrowLeft') step(-1);
        else if (event.key === 'ArrowRight') step(1);
        else if (event.key === 'Tab') trapFocus(event);
    });
})();
