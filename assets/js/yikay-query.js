/**
 * 查询循环前台交互（2.0.3）：AJAX 分页、加载更多、无限滚动、前台筛选与结果摘要。
 *
 * 取数通道：请求同一页面 URL 并附 _ykq=<循环宿主节点 id>，服务端只返回该循环的片段
 * JSON（见 BloxQueryFragment）。无脚本时所有控件都退化为普通链接 / GET 表单，结果一致。
 *
 * 标记约定（BlockRenderer::renderLoopChildren 输出）：
 *   宿主根标签   data-yk-query="<id>" data-yk-query-param="<分页参数>" data-yk-query-total="<总数>"
 *   循环项根标签 data-yk-loop-item="<id>"
 *   分页包裹     data-yk-query-pagination（其内 data-yk-query-nav / data-yk-query-more / data-yk-query-infinite）
 *   空态         data-yk-query-empty
 * 筛选表单：form[data-yk-filter][data-yk-filter-target="<id>"][data-yk-filter-prefix="yfxxxxxx"]
 * 结果摘要：[data-yk-query-summary="<id>"][data-template][data-template-empty]
 */
(function () {
    "use strict";

    var FRAGMENT = "_ykq";

    /** 片段请求地址：同一页面 URL + _ykq=<宿主 id>。 */
    function fragmentUrl(url, id, base) {
        var target = new URL(url, base);
        target.searchParams.set(FRAGMENT, id);
        return target.toString();
    }

    /** 写进地址栏/整页跳转的地址：去掉片段参数，只留路径 + 查询 + 锚点。 */
    function cleanUrl(url, base) {
        var target = new URL(url, base);
        target.searchParams.delete(FRAGMENT);
        return target.pathname + (target.search ? target.search : "") + target.hash;
    }

    /**
     * 筛选后的地址：清掉该循环命名空间下的旧参数与分页参数，写入新值
     * （同名多值合并为逗号分隔，空值不写），其余参数原样保留。
     *
     * @param {string} href 当前地址 @param {string} prefix yfxxxxxx
     * @param {string} pageParam 目标循环的分页参数 @param {Array<[string,string]>} pairs 表单字段
     */
    function buildFilterUrl(href, prefix, pageParam, pairs) {
        var target = new URL(href);
        var remove = [];
        target.searchParams.forEach(function (_value, key) {
            if (key.indexOf(prefix + "_") === 0) remove.push(key);
        });
        remove.forEach(function (key) { target.searchParams.delete(key); });
        if (pageParam) target.searchParams.delete(pageParam);
        target.searchParams.delete(FRAGMENT);
        var grouped = {};
        var order = [];
        pairs.forEach(function (pair) {
            var key = String(pair[0]).replace(/\[\]$/, "");
            var value = String(pair[1]).trim();
            if (key.indexOf(prefix + "_") !== 0 || value === "") return;
            if (!grouped[key]) { grouped[key] = []; order.push(key); }
            if (grouped[key].indexOf(value) === -1) grouped[key].push(value);
        });
        order.forEach(function (key) { target.searchParams.set(key, grouped[key].join(",")); });
        return target.toString();
    }

    var pure = { fragmentUrl: fragmentUrl, cleanUrl: cleanUrl, buildFilterUrl: buildFilterUrl };
    if (typeof module === "object" && module.exports) module.exports = pure;
    if (typeof window === "undefined" || typeof document === "undefined" || window.BloxQuery) return;

    var busy = new WeakMap();
    var observers = new WeakMap();

    function css(value) {
        return window.CSS && window.CSS.escape ? window.CSS.escape(value) : String(value).replace(/["\\]/g, "\\$&");
    }

    function hostById(id) {
        return document.querySelector('[data-yk-query="' + css(id) + '"]');
    }

    /** 宿主自己的标记（排除嵌套循环里的同名标记）。 */
    function own(host, selector) {
        return Array.prototype.filter.call(host.querySelectorAll(selector), function (node) {
            var owner = node.parentElement && node.parentElement.closest("[data-yk-query]");
            return owner === host;
        });
    }

    function parts(host) {
        var id = host.getAttribute("data-yk-query");
        var items = Array.prototype.slice.call(host.querySelectorAll('[data-yk-loop-item="' + css(id) + '"]'));
        return {
            items: items,
            pagination: own(host, "[data-yk-query-pagination]"),
            empty: own(host, "[data-yk-query-empty]"),
        };
    }

    function loadAssets(assets) {
        if (!assets) return;
        (assets.styles || []).forEach(function (href) {
            if (document.querySelector('link[rel="stylesheet"][href*="' + css(href.split("?")[0]) + '"]')) return;
            var link = document.createElement("link");
            link.rel = "stylesheet";
            link.href = href;
            document.head.appendChild(link);
        });
        (assets.scripts || []).forEach(function (src) {
            if (document.querySelector('script[src*="' + css(src.split("?")[0]) + '"]')) return;
            var script = document.createElement("script");
            script.src = src;
            script.defer = true;
            document.body.appendChild(script);
        });
    }

    function toNodes(html) {
        var template = document.createElement("template");
        template.innerHTML = html;
        return Array.prototype.slice.call(template.content.childNodes);
    }

    function announce(host, payload) {
        var id = host.getAttribute("data-yk-query");
        host.setAttribute("data-yk-query-total", String(payload.total));
        document.querySelectorAll('[data-yk-query-summary="' + css(id) + '"]').forEach(function (node) {
            renderSummary(node, payload.total);
        });
        var live = host.querySelector(":scope > .yk-query-live") || null;
        if (!live) {
            live = document.createElement("span");
            live.className = "yk-query-live sr-only";
            live.setAttribute("aria-live", "polite");
            host.appendChild(live);
        }
        var summary = document.querySelector('[data-yk-query-summary="' + css(id) + '"]');
        live.textContent = summary ? summary.textContent : String(payload.total);
    }

    function renderSummary(node, total) {
        var template = total > 0 ? node.getAttribute("data-template") : node.getAttribute("data-template-empty");
        node.textContent = String(template || ":count").replace(/:count/g, String(total));
        node.hidden = false;
    }

    function contentUpdated(root) {
        try {
            document.dispatchEvent(new CustomEvent("blox:content-updated", { detail: { root: root } }));
        } catch (e) { /* 旧浏览器：动画不重放，不影响内容 */ }
        if (window.BloxCollapse && typeof window.BloxCollapse.update === "function") window.BloxCollapse.update();
    }

    /**
     * 取片段并更新宿主。mode: replace（翻页/筛选）| append（加载更多/无限滚动）。
     * 失败或拿到的不是片段 JSON（缓存页、节点已不存在）时整页跳转到目标地址，结果仍然正确。
     */
    function load(host, url, mode, options) {
        options = options || {};
        if (busy.get(host)) return Promise.resolve();
        busy.set(host, true);
        var id = host.getAttribute("data-yk-query");
        host.setAttribute("aria-busy", "true");
        host.classList.add("yk-query-loading");
        return window.fetch(fragmentUrl(url, id, window.location.href), {
            credentials: "same-origin",
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        }).then(function (response) {
            var type = response.headers.get("Content-Type") || "";
            if (!response.ok || type.indexOf("application/json") === -1) throw new Error("not a fragment");
            return response.json();
        }).then(function (payload) {
            if (!payload || typeof payload.html !== "string") throw new Error("bad fragment");
            loadAssets(payload.assets);
            apply(host, payload.html, mode);
            announce(host, payload);
            if (options.history) {
                var clean = cleanUrl(url, window.location.href);
                try { window.history.pushState({ ykq: id }, "", clean); } catch (e) { /* file:// 等环境 */ }
            }
            if (mode === "replace" && options.scroll !== false) {
                var top = host.getBoundingClientRect().top;
                if (top < 0 || top > window.innerHeight * 0.6) host.scrollIntoView({ behavior: reduceMotion() ? "auto" : "smooth", block: "start" });
            }
            watchInfinite(host);
        }).catch(function () {
            window.location.href = cleanUrl(url, window.location.href);
        }).finally(function () {
            busy.delete(host);
            host.removeAttribute("aria-busy");
            host.classList.remove("yk-query-loading");
        });
    }

    function reduceMotion() {
        return !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
    }

    function apply(host, html, mode) {
        var current = parts(host);
        var nodes = toNodes(html);
        var removing = mode === "append" ? current.pagination.concat(current.empty) : current.items.concat(current.pagination, current.empty);
        var anchor = mode === "append"
            ? (current.pagination[0] || null)
            : (current.items[0] || current.empty[0] || current.pagination[0] || null);
        var parent = anchor ? anchor.parentNode : (host.querySelector(".yk-collapse-body, .blox-content") || host);
        var firstNew = null;
        try {
            document.dispatchEvent(new CustomEvent("blox:content-removing", { detail: { root: host } }));
        } catch (e) { /* 同上 */ }
        nodes.forEach(function (node) {
            parent.insertBefore(node, anchor);
            if (!firstNew && node.nodeType === 1 && node.hasAttribute("data-yk-loop-item")) firstNew = node;
        });
        removing.forEach(function (node) { if (node.parentNode) node.parentNode.removeChild(node); });
        contentUpdated(host);
        // 加载更多：按钮消失后把焦点交给第一条新内容，键盘用户不会被甩回页首
        if (mode === "append" && firstNew && document.activeElement === document.body) {
            var focusable = firstNew.matches("a,button,[tabindex]") ? firstNew : firstNew.querySelector("a,button,[tabindex]");
            if (focusable) focusable.focus({ preventScroll: true });
        }
    }

    function watchInfinite(host) {
        if (!("IntersectionObserver" in window)) return;
        var previous = observers.get(host);
        if (previous) previous.disconnect();
        var sentinel = own(host, "[data-yk-query-infinite]")[0];
        if (!sentinel) return;
        var link = sentinel.querySelector("[data-yk-query-next]");
        if (!link) return;
        var observer = new IntersectionObserver(function (entries) {
            if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
            observer.disconnect();
            load(host, link.href, "append");
        }, { rootMargin: "400px 0px" });
        observer.observe(sentinel);
        observers.set(host, observer);
    }

    // ── 筛选 ───────────────────────────────────────────────────────

    function filterUrl(form) {
        var host = hostById(form.getAttribute("data-yk-filter-target") || "");
        var prefix = form.getAttribute("data-yk-filter-prefix") || "";
        // 同一目标的所有筛选表单合并（页面上可以有多个筛选元素）；条件变了就回到第 1 页
        var pairs = [];
        document.querySelectorAll('form[data-yk-filter][data-yk-filter-prefix="' + css(prefix) + '"]').forEach(function (each) {
            new FormData(each).forEach(function (value, key) { pairs.push([key, value]); });
        });
        return buildFilterUrl(window.location.href, prefix, host ? host.getAttribute("data-yk-query-param") : "", pairs);
    }

    function applyFilter(form) {
        var host = hostById(form.getAttribute("data-yk-filter-target") || "");
        var url = filterUrl(form);
        if (!host) {
            window.location.href = cleanUrl(url, window.location.href);
            return;
        }
        syncResetState(form.getAttribute("data-yk-filter-prefix") || "");
        load(host, url, "replace", { history: true, scroll: false });
    }

    function syncResetState(prefix) {
        var active = false;
        new URL(window.location.href).searchParams.forEach(function (_value, key) {
            if (key.indexOf(prefix + "_") === 0) active = true;
        });
        document.querySelectorAll('form[data-yk-filter][data-yk-filter-prefix="' + css(prefix) + '"]').forEach(function (form) {
            new FormData(form).forEach(function (value, key) {
                if (key.indexOf(prefix + "_") === 0 && String(value).trim() !== "") active = true;
            });
        });
        document.querySelectorAll('[data-yk-filter-reset="' + css(prefix) + '"]').forEach(function (button) {
            button.hidden = !active;
        });
    }

    function resetFilters(prefix) {
        document.querySelectorAll('form[data-yk-filter][data-yk-filter-prefix="' + css(prefix) + '"]').forEach(function (form) {
            Array.prototype.forEach.call(form.elements, function (field) {
                if (!field.name || field.name.indexOf(prefix + "_") !== 0) return;
                if (field.type === "checkbox" || field.type === "radio") field.checked = field.value === "";
                else if (field.tagName === "SELECT") field.selectedIndex = 0;
                else field.value = "";
            });
        });
        var form = document.querySelector('form[data-yk-filter][data-yk-filter-prefix="' + css(prefix) + '"]');
        if (form) applyFilter(form);
    }

    var searchTimers = new WeakMap();

    document.addEventListener("click", function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (!target || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        var navLink = target.closest("[data-yk-query-nav] a[href]");
        // 无限滚动的回退链接（观察器不可用或用户手动点）同样原地追加
        var more = target.closest("[data-yk-query-more], [data-yk-query-infinite] [data-yk-query-next]");
        var reset = target.closest("[data-yk-filter-reset]");
        if (reset) {
            event.preventDefault();
            resetFilters(reset.getAttribute("data-yk-filter-reset") || "");
            return;
        }
        var trigger = navLink || more;
        if (!trigger) return;
        var host = trigger.closest("[data-yk-query]");
        if (!host || !window.fetch) return;
        event.preventDefault();
        if (navLink) load(host, navLink.href, "replace", { history: true });
        else load(host, more.href, "append");
    });

    document.addEventListener("change", function (event) {
        var field = event.target;
        var form = field && field.form;
        if (!form || !form.hasAttribute("data-yk-filter") || !window.fetch) return;
        if (field.type === "search" || field.type === "text") return; // 搜索框走输入防抖/回车
        // 单选型「按钮组」：点已选中的项等于取消
        applyFilter(form);
    });

    document.addEventListener("input", function (event) {
        var field = event.target;
        var form = field && field.form;
        if (!form || !form.hasAttribute("data-yk-filter") || (field.type !== "search" && field.type !== "text")) return;
        if (field.type === "text" && !field.hasAttribute("data-yk-filter-search")) return;
        window.clearTimeout(searchTimers.get(form));
        searchTimers.set(form, window.setTimeout(function () { applyFilter(form); }, 450));
    });

    document.addEventListener("submit", function (event) {
        var form = event.target;
        if (!form || !form.hasAttribute || !form.hasAttribute("data-yk-filter") || !window.fetch) return;
        event.preventDefault();
        window.clearTimeout(searchTimers.get(form));
        applyFilter(form);
    });

    window.addEventListener("popstate", function (event) {
        var state = event.state;
        if (state && state.ykq) {
            var host = hostById(state.ykq);
            if (host) {
                load(host, window.location.href, "replace", { scroll: false });
                return;
            }
        }
        if (document.querySelector("[data-yk-query]")) window.location.reload();
    });

    function init(root) {
        (root || document).querySelectorAll("[data-yk-query]").forEach(function (host) {
            watchInfinite(host);
            var total = host.getAttribute("data-yk-query-total");
            if (total !== null) {
                document.querySelectorAll('[data-yk-query-summary="' + css(host.getAttribute("data-yk-query")) + '"]').forEach(function (node) {
                    renderSummary(node, parseInt(total, 10) || 0);
                });
            }
        });
        document.querySelectorAll("form[data-yk-filter]").forEach(function (form) {
            syncResetState(form.getAttribute("data-yk-filter-prefix") || "");
        });
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", function () { init(document); });
    else init(document);

    window.BloxQuery = {
        load: load,
        refresh: function (id, url) {
            var host = hostById(id);
            return host ? load(host, url || window.location.href, "replace", { scroll: false }) : Promise.resolve();
        },
        filterUrl: filterUrl,
    };
})();
