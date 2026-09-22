(function (global) {
    "use strict";

    function buildFormUrl(action, entries) {
        var url = new URL(action || global.location.href, global.location.href);
        var params = new URLSearchParams();
        Array.from(entries).forEach(function (entry) {
            if (entry[1] !== "") params.append(String(entry[0]), String(entry[1]));
        });
        params.delete("page");
        url.search = params.toString();
        return url.toString();
    }

    function createLoader(fetcher) {
        var controller = null;
        var sequence = 0;
        return {
            abort: function () { if (controller) controller.abort(); },
            load: async function (url) {
                if (controller) controller.abort();
                controller = typeof AbortController !== "undefined" ? new AbortController() : null;
                var current = ++sequence;
                var response = await fetcher(url, {
                    method: "GET", credentials: "same-origin",
                    headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "text/html" },
                    signal: controller ? controller.signal : undefined,
                });
                if (!response.ok) throw new Error("catalog-http");
                var html = await response.text();
                return current === sequence ? { html: html, url: response.url || url } : null;
            },
        };
    }

    function ensureStatus(root) {
        var doc = root.ownerDocument || document;
        var host = root.previousElementSibling;
        if (!host || !host.hasAttribute("data-catalog-status")) {
            host = doc.createElement("div");
            host.setAttribute("data-catalog-status", "");
            root.before(host);
        }
        var live = host.querySelector("[data-catalog-live]");
        if (!live) {
            live = doc.createElement("p");
            live.className = "sr-only";
            live.setAttribute("data-catalog-live", "");
            live.setAttribute("aria-live", "polite");
            live.setAttribute("aria-atomic", "true");
            host.append(live);
        }
        var error = host.querySelector("[data-catalog-error-box]");
        if (!error) {
            error = doc.createElement("div");
            error.hidden = true;
            error.setAttribute("data-catalog-error-box", "");
            error.setAttribute("role", "alert");
            error.className = "mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700";
            var message = doc.createElement("span");
            message.setAttribute("data-catalog-error-message", "");
            message.textContent = root.dataset.catalogError || "";
            var retry = doc.createElement("button");
            retry.type = "button";
            retry.setAttribute("data-catalog-retry-button", "");
            retry.className = "ml-3 font-semibold underline";
            retry.textContent = root.dataset.catalogRetry || "";
            error.append(message, retry);
            host.append(error);
        } else {
            var existingMessage = error.querySelector("[data-catalog-error-message]");
            var existingRetry = error.querySelector("[data-catalog-retry-button]");
            if (existingMessage) existingMessage.textContent = root.dataset.catalogError || "";
            if (existingRetry) existingRetry.textContent = root.dataset.catalogRetry || "";
        }
        return { live: live, error: error };
    }

    function controlledLink(link, root) {
        if (!link || !root.contains(link) || link.target || link.hasAttribute("download")) return false;
        return !!link.closest("[data-catalog-facets],[data-catalog-sort],[data-catalog-pagination]");
    }

    function boot(doc) {
        if (!doc || !doc.querySelector("[data-product-catalog][data-catalog-ajax]")) return null;
        if (typeof global.fetch !== "function") return null;
        var loader = createLoader(global.fetch.bind(global));
        var debounce = 0;
        var announceTimer = 0;
        var lastUrl = global.location.href;
        var navigation = 0;

        function currentRoot() {
            return doc.querySelector("[data-product-catalog][data-catalog-ajax]");
        }

        function announce(live, message) {
            global.clearTimeout(announceTimer);
            live.textContent = "";
            announceTimer = global.setTimeout(function () { live.textContent = message; }, 0);
        }

        function setLoading(root, loading) {
            root.setAttribute("aria-busy", loading ? "true" : "false");
            root.style.opacity = loading ? "0.55" : "";
            var status = ensureStatus(root);
            if (loading) {
                status.error.hidden = true;
                announce(status.live, root.dataset.catalogLoading || "");
            }
        }

        async function navigate(url, options) {
            options = options || {};
            global.clearTimeout(debounce);
            debounce = 0;
            var root = currentRoot();
            if (!root) return false;
            var request = ++navigation;
            var active = doc.activeElement;
            var focusName = active && root.contains(active) && active.name ? active.name : "";
            var selection = focusName && typeof active.selectionStart === "number" ? active.selectionStart : null;
            setLoading(root, true);
            lastUrl = url;
            try {
                var loaded = await loader.load(url);
                if (!loaded || request !== navigation) return false;
                var finalUrl = new URL(loaded.url, global.location.href);
                if (finalUrl.origin !== global.location.origin) throw new Error("catalog-origin");
                var parsed = new DOMParser().parseFromString(loaded.html, "text/html");
                var next = parsed.querySelector("[data-product-catalog][data-catalog-ajax]");
                if (!next) throw new Error("catalog-fragment");
                var imported = doc.importNode ? doc.importNode(next, true) : next.cloneNode(true);
                root.replaceWith(imported);
                var status = ensureStatus(imported);
                announce(status.live, imported.dataset.catalogUpdated || "");
                if (options.history === "replace") global.history.replaceState({ yikaiCatalog: true }, "", finalUrl.href);
                else if (options.history !== false) global.history.pushState({ yikaiCatalog: true }, "", finalUrl.href);
                if (focusName) {
                    var escapedName = global.CSS && typeof global.CSS.escape === "function"
                        ? global.CSS.escape(focusName) : focusName.replace(/[^a-zA-Z0-9_-]/g, "");
                    var input = imported.querySelector('[name="' + escapedName + '"]');
                    if (input) {
                        input.focus({ preventScroll: true });
                        if (selection !== null && typeof input.setSelectionRange === "function") input.setSelectionRange(selection, selection);
                    }
                } else if (options.focus !== false) {
                    imported.focus({ preventScroll: true });
                }
                return true;
            } catch (error) {
                if (request !== navigation || (error && error.name === "AbortError")) return false;
                var latest = currentRoot();
                if (latest) {
                    setLoading(latest, false);
                    var status = ensureStatus(latest);
                    status.error.hidden = false;
                    announce(status.live, latest.dataset.catalogError || "");
                }
                return false;
            } finally {
                if (request === navigation) {
                    var latest = currentRoot();
                    if (latest) setLoading(latest, false);
                }
            }
        }

        doc.addEventListener("click", function (event) {
            var root = currentRoot();
            if (!root) return;
            var retry = event.target.closest("[data-catalog-retry-button]");
            var statusHost = root.previousElementSibling;
            if (retry && statusHost && statusHost.hasAttribute("data-catalog-status") && statusHost.contains(retry)) {
                event.preventDefault(); navigate(lastUrl); return;
            }
            var toggle = event.target.closest(".category-toggle");
            if (event.yikaiCatalogToggleHandled) return;
            if (toggle && root.contains(toggle)) {
                event.preventDefault();
                var item = toggle.closest(".category-item");
                var children = item ? item.querySelector(".category-children") : null;
                if (children) {
                    var expanded = toggle.dataset.expanded === "true";
                    children.classList.toggle("hidden", expanded);
                    toggle.dataset.expanded = expanded ? "false" : "true";
                    toggle.setAttribute("aria-expanded", expanded ? "false" : "true");
                }
                return;
            }
            var link = event.target.closest("a[href]");
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
                || !controlledLink(link, root)) return;
            var url = new URL(link.href, global.location.href);
            if (url.origin !== global.location.origin) return;
            event.preventDefault();
            navigate(url.href);
        });

        doc.addEventListener("submit", function (event) {
            var root = currentRoot();
            var form = event.target;
            if (!root || !root.contains(form) || form.method.toLowerCase() !== "get") return;
            event.preventDefault();
            navigate(buildFormUrl(form.action, new FormData(form).entries()));
        });

        doc.addEventListener("input", function (event) {
            var root = currentRoot();
            var input = event.target;
            if (!root || !root.contains(input) || input.name !== "keyword" || input.form === null || event.isComposing) return;
            global.clearTimeout(debounce);
            loader.abort();
            navigation += 1;
            setLoading(root, false);
            debounce = global.setTimeout(function () {
                navigate(buildFormUrl(input.form.action, new FormData(input.form).entries()), { focus: false, history: "replace" });
            }, 350);
        });

        global.addEventListener("popstate", function () { navigate(global.location.href, { history: false }); });
        ensureStatus(currentRoot());
        return { navigate: navigate, loader: loader };
    }

    var api = { buildFormUrl: buildFormUrl, createLoader: createLoader, controlledLink: controlledLink, boot: boot };
    global.YikaiProductCatalog = api;
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    if (global.document) {
        if (global.document.readyState === "loading") global.document.addEventListener("DOMContentLoaded", function () { boot(global.document); }, { once: true });
        else boot(global.document);
    }
})(typeof window !== "undefined" ? window : globalThis);
