/**
 * Blox 产品相册灯箱绑定。
 *
 * 为什么这不是"第二套引擎"：缩放、滑动、键盘浏览、关闭动画全部由站点既有的
 * PhotoSwipe Lightbox 提供——与原生 product.php 用同一个库、同一批
 * assets/photoswipe 资源、同一个 loadAndOpen 调用序列。本文件只做"容器锚点 →
 * dataSource"的搬运与尺寸探测，不实现任何手势或动画。
 *
 * 降级：PhotoSwipe 未加载（脚本没到/被拦）时不拦截点击，锚点保持自身 href 的
 * 普通链接行为，图片依然可看——这是有意的无脚本降级。
 */
(function (window, document) {
    "use strict";

    // PhotoSwipe 需要真实宽高做缩放动画；探测未完成时先用保守占位，加载后会自然纠正。
    var DIM_FALLBACK = { w: 1600, h: 1600 };
    var dims = Object.create(null);

    function srcOf(link) {
        return link.getAttribute("data-yk-gallery-full") || link.getAttribute("href") || "";
    }

    function probe(src) {
        if (src === "" || dims[src]) return;
        dims[src] = DIM_FALLBACK;   // 先占位：同一张图不重复探测
        var image = new window.Image();
        image.onload = function () {
            if (image.naturalWidth > 0 && image.naturalHeight > 0) {
                dims[src] = { w: image.naturalWidth, h: image.naturalHeight };
            }
        };
        image.src = src;
    }

    function itemsOf(container) {
        return Array.prototype.slice.call(container.querySelectorAll("[data-yk-gallery-item]"));
    }

    /**
     * 有效条目：没有 src 的锚点不进灯箱。
     *
     * 索引必须按这份过滤后的列表算——若按 DOM 顺序算，缺 src 的图片会让
     * loadAndOpen 越界，点第 3 张打开的是第 2 张。
     */
    function entriesOf(container) {
        return itemsOf(container).map(function (link) {
            var src = srcOf(link);
            var size = dims[src] || DIM_FALLBACK;
            return {
                link: link,
                item: {
                    src: src,
                    width: size.w,
                    height: size.h,
                    alt: link.getAttribute("data-yk-gallery-alt") || ""
                }
            };
        }).filter(function (entry) { return entry.item.src !== ""; });
    }

    function open(container, link) {
        if (!window.PhotoSwipeLightbox || !window.PhotoSwipe) return false;
        var entries = entriesOf(container);
        if (!entries.length) return false;

        var index = 0;
        entries.forEach(function (entry, i) { if (entry.link === link) index = i; });

        var lightbox = new window.PhotoSwipeLightbox({
            dataSource: entries.map(function (entry) { return entry.item; }),
            pswpModule: window.PhotoSwipe,
            showHideAnimationType: "zoom",
            bgOpacity: 0.92
        });
        lightbox.init();
        lightbox.loadAndOpen(index);
        lightbox.on("destroy", function () { lightbox = null; });
        return true;
    }

    function bind(container) {
        if (container.dataset.ykGalleryBound === "1") return;
        container.dataset.ykGalleryBound = "1";
        entriesOf(container).forEach(function (entry) { probe(entry.item.src); });

        container.addEventListener("click", function (event) {
            var target = event.target;
            var link = target && target.closest ? target.closest("[data-yk-gallery-item]") : null;
            if (!link || !container.contains(link)) return;
            // 打不开就放行默认行为（锚点 href），不吞掉用户点击
            if (open(container, link)) event.preventDefault();
        });
    }

    /** 幂等：画布重渲染/重复插入时对同一容器只绑定一次。 */
    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        Array.prototype.slice.call(scope.querySelectorAll("[data-yk-gallery]")).forEach(bind);
    }

    window.YikaiBloxProductGallery = Object.freeze({ init: init });

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () { init(document); });
    } else {
        init(document);
    }
})(window, document);
