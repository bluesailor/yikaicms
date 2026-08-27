(function (global) {
    "use strict";

    function responseData(response, fallbackMessage) {
        return response.text().then(function (body) {
            var result;
            try {
                result = JSON.parse(body);
            } catch (error) {
                var suffix = response && response.status ? " (HTTP " + response.status + ")" : "";
                throw new Error(fallbackMessage + suffix);
            }
            if (response && response.ok === false) {
                throw new Error((result && result.msg) || fallbackMessage + " (HTTP " + response.status + ")");
            }
            if (!result || Number(result.code) !== 0) {
                throw new Error((result && result.msg) || fallbackMessage);
            }
            return result.data || {};
        });
    }

    function request(endpoint, action, context, key, fallbackMessage, refresh) {
        var url = endpoint + "?action=" + encodeURIComponent(action)
            + "&context=" + encodeURIComponent(context);
        if (key) url += "&key=" + encodeURIComponent(key);
        if (refresh) url += "&refresh=1";
        return fetch(url, { cache: "no-store" }).then(function (response) {
            return responseData(response, fallbackMessage);
        });
    }

    function list(endpoint, context, fallbackMessage, refresh) {
        return request(endpoint, "list", context, "", fallbackMessage, refresh).then(function (data) {
            var items = (Array.isArray(data.items) ? data.items : []).map(normalizeItem);
            items.remoteError = String(data.remote_error || "");
            return items;
        });
    }

    function normalizeItem(item) {
        var normalized = Object.assign({}, item || {});
        normalized.locked = normalized.locked === true
            || normalized.locked === 1
            || normalized.locked === "1";
        normalized.locked_reason = String(normalized.locked_reason || "");
        return normalized;
    }

    function resolve(endpoint, context, key, fallbackMessage, csrf) {
        var body = new URLSearchParams();
        body.set("action", "get");
        body.set("context", context);
        body.set("key", key);
        body.set("_token", csrf || "");
        return fetch(endpoint, { method: "POST", body: body, cache: "no-store" })
            .then(function (response) { return responseData(response, fallbackMessage); })
            .then(function (data) {
                if (!data.template || !Array.isArray(data.template.sections)) {
                    throw new Error(fallbackMessage);
                }
                return data.template;
            });
    }
    function categoryValue(item) {
        var value = String(item && item.category || "").trim().toLowerCase();
        return value || String(item && item.type || "").trim().toLowerCase();
    }

    function filter(items, query, type, source, category) {
        var q = String(query || "").trim().toLowerCase();
        var wantedCategory = String(category || "all").trim().toLowerCase();
        return (Array.isArray(items) ? items : []).filter(function (item) {
            if (type !== "all" && item.type !== type) return false;
            if (source && source !== "all" && item.source !== source) return false;
            if (wantedCategory !== "all" && categoryValue(item) !== wantedCategory) return false;
            if (!q) return true;
            return String(item.name || "").toLowerCase().indexOf(q) !== -1
                || String(item.provider || "").toLowerCase().indexOf(q) !== -1
                || String(item.description || "").toLowerCase().indexOf(q) !== -1
                || (Array.isArray(item.keywords) ? item.keywords.join(" ") : String(item.keywords || ""))
                    .toLowerCase().indexOf(q) !== -1
                || String(item.category || "").toLowerCase().indexOf(q) !== -1;
        });
    }

    function categories(items) {
        var seen = {};
        (Array.isArray(items) ? items : []).forEach(function (item) {
            var value = categoryValue(item);
            if (value) seen[value] = true;
        });
        return Object.keys(seen).sort();
    }

    function categoryLabel(category, text) {
        var value = String(category || "").trim().toLowerCase();
        var key = "category" + value.charAt(0).toUpperCase() + value.slice(1);
        return text && text[key] ? text[key] : value;
    }

    function scope(items, value) {
        var remote = value === "remote";
        return (Array.isArray(items) ? items : []).filter(function (item) {
            return remote ? item.source === "remote" : item.source !== "remote";
        });
    }

    function scopeCount(items, value) {
        return scope(items, value).length;
    }

    function upsertLocal(items, item) {
        var current = Array.isArray(items) ? items : [];
        if (!item || item.source !== "local" || !/^local:\d+$/.test(String(item.key || ""))
            || (item.type !== "section" && item.type !== "page")) {
            var unchanged = current.slice();
            unchanged.remoteError = String(current.remoteError || "");
            return unchanged;
        }
        var localItem = normalizeItem(item);
        localItem.locked = false;
        localItem.locked_reason = "";
        var merged = [localItem].concat(current.filter(function (entry) {
            return String(entry && entry.key || "") !== String(item.key);
        }));
        merged.remoteError = String(current.remoteError || "");
        return merged;
    }

    function providerLabel(item, text) {
        if (item && item.source === "plugin") return text.plugin + " / " + String(item.provider || "");
        if (item && item.source === "remote") return text.remote;
        return text.local;
    }

    function canEditLocal(item) {
        return !!item && item.source === "local" && /^local:\d+$/.test(String(item.key || ""));
    }

    function localEditUrl(item) {
        return canEditLocal(item) ? "/admin/blox_editor.php?template=" + String(item.key).slice(6) : "";
    }

    function lockLabel(item, text) {
        if (!item || !item.locked) return "";
        if (item.locked_reason === "license_expired") return text.lockedExpired;
        if (item.locked_reason === "module_missing") return text.lockedModule;
        return text.lockedLicense;
    }

    function hasLockedRemote(items) {
        return (Array.isArray(items) ? items : []).some(function (item) {
            return item.source === "remote" && !!item.locked;
        });
    }

    function freshSections(sections, uid) {
        return sections.map(function (section) {
            var copy = JSON.parse(JSON.stringify(section || {}));
            copy.id = uid("s");
            delete copy.library_id;
            delete copy.library_name;
            copy.type = copy.type || "section";
            copy.settings = copy.settings && typeof copy.settings === "object" ? copy.settings : {};
            copy.columns = Array.isArray(copy.columns) ? copy.columns : [];
            copy.columns.forEach(function (column) {
                column.id = uid("c");
                column.elements = Array.isArray(column.elements) ? column.elements : [];
                var freshElement = function (element) {
                    element.id = uid("e");
                    element.data = element.data && typeof element.data === "object" ? element.data : {};
                    if (Array.isArray(element.data.children)) element.data.children.forEach(freshElement);
                };
                column.elements.forEach(freshElement);
            });
            return copy;
        });
    }

    global.BloxTemplateLibrary = {
        list: list,
        resolve: resolve,
        filter: filter,
        categories: categories,
        categoryLabel: categoryLabel,
        scope: scope,
        scopeCount: scopeCount,
        upsertLocal: upsertLocal,
        providerLabel: providerLabel,
        canEditLocal: canEditLocal,
        localEditUrl: localEditUrl,
        lockLabel: lockLabel,
        hasLockedRemote: hasLockedRemote,
        freshSections: freshSections,
    };
})(window);
