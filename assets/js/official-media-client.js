(function (global) {
    "use strict";

    function payload(response) {
        return response.json().then(function (result) {
            return result && typeof result === "object" ? result : {};
        });
    }

    function publicItem(item) {
        var source = item && typeof item === "object" ? item : {};
        var allowed = [
            "id", "version", "name", "name_en", "name_ja", "description", "description_en", "description_ja",
            "purposes", "industries", "keywords", "width", "height", "aspect", "focal_point", "safe_area",
            "text_tone", "overlay", "preview_url", "preview_large_url", "license_code", "attribution", "updated_at",
        ];
        var result = {};
        allowed.forEach(function (field) {
            if (Object.prototype.hasOwnProperty.call(source, field)) result[field] = source[field];
        });
        return result;
    }

    function list(endpoint, page, keyword, options) {
        var config = options && typeof options === "object" ? options : {};
        var url = endpoint + "?action=remote_list&page=" + encodeURIComponent(page);
        var query = String(keyword || "").trim();
        var usage = String(config.usage || "").trim();
        if (query) url += "&keyword=" + encodeURIComponent(query);
        if (usage) url += "&usage=" + encodeURIComponent(usage);

        return fetch(url, { cache: "no-store" }).then(payload).then(function (result) {
            var data = result.data && typeof result.data === "object" ? result.data : {};
            var entitlement = data.entitlement && typeof data.entitlement === "object"
                ? data.entitlement
                : {};
            return {
                ok: Number(result.code) === 0,
                message: String(result.msg || ""),
                items: Array.isArray(data.items) ? data.items.map(publicItem) : [],
                page: Math.max(1, Number(data.page) || 1),
                pages: Math.max(0, Number(data.pages) || 0),
                total: Math.max(0, Number(data.total) || 0),
                entitlement: {
                    canImport: entitlement.can_import === true,
                    reason: String(entitlement.reason || ""),
                },
            };
        });
    }

    function importAsset(endpoint, assetId, options) {
        var config = options && typeof options === "object" ? options : {};
        var body = new FormData();
        body.append("asset_id", String(assetId || ""));
        if (config.csrf) body.append("_token", String(config.csrf));

        return fetch(endpoint + "?action=remote_import", { method: "POST", body: body })
            .then(payload)
            .then(function (result) {
                var data = result.data && typeof result.data === "object" ? result.data : {};
                var url = typeof data.url === "string" && /^\/uploads\/images\//.test(data.url) ? data.url : "";
                return {
                    ok: Number(result.code) === 0 && url !== "",
                    message: String(result.msg || ""),
                    data: data,
                    url: url,
                };
            });
    }

    global.OfficialMediaClient = {
        list: list,
        importAsset: importAsset,
    };
})(window);
