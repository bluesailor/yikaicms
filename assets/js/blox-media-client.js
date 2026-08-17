(function (global) {
    "use strict";

    function payload(response) {
        return response.json().then(function (result) {
            return result && typeof result === "object" ? result : {};
        });
    }

    function list(endpoint, page, keyword) {
        var url = endpoint + "?action=list&type=image&page=" + encodeURIComponent(page);
        var query = String(keyword || "").trim();
        if (query) url += "&keyword=" + encodeURIComponent(query);

        return fetch(url, { cache: "no-store" }).then(payload).then(function (result) {
            var data = result.data && typeof result.data === "object" ? result.data : {};
            return {
                ok: Number(result.code) === 0,
                message: String(result.msg || ""),
                items: Array.isArray(data.items) ? data.items : [],
                pages: Math.max(1, Number(data.pages) || 1),
                total: Math.max(0, Number(data.total) || 0),
            };
        });
    }

    function upload(endpoint, file) {
        var body = new FormData();
        body.append("file", file);
        body.append("type", "images");

        return fetch(endpoint + "?action=upload", { method: "POST", body: body })
            .then(payload)
            .then(function (result) {
                var data = result.data && typeof result.data === "object" ? result.data : {};
                return {
                    ok: Number(result.code) === 0 && typeof data.url === "string" && data.url !== "",
                    message: String(result.msg || ""),
                    url: String(data.url || ""),
                };
            });
    }

    global.BloxMediaClient = { list: list, upload: upload };
})(window);
