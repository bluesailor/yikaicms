(function (global) {
    "use strict";

    function responseData(response, fallbackMessage) {
        return response.json().then(function (result) {
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
            var items = Array.isArray(data.items) ? data.items : [];
            items.remoteError = String(data.remote_error || "");
            return items;
        });
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
    function filter(items, query, type) {
        var q = String(query || "").trim().toLowerCase();
        return (Array.isArray(items) ? items : []).filter(function (item) {
            if (type !== "all" && item.type !== type) return false;
            if (!q) return true;
            return String(item.name || "").toLowerCase().indexOf(q) !== -1
                || String(item.provider || "").toLowerCase().indexOf(q) !== -1
                || String(item.description || "").toLowerCase().indexOf(q) !== -1
                || String(item.category || "").toLowerCase().indexOf(q) !== -1;
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
        freshSections: freshSections,
    };
})(window);
