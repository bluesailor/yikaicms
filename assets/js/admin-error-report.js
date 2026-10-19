/**
 * 后台前端错误上报（2.1.1）：脚本错误（含 Alpine 表达式错误）去重、限频后发给 /admin/client_error_api.php，
 * 写进后台「错误日志」，站点健康按近 7 天统计。不改页面行为：只监听，不吞错误。
 *
 * 用法：页面先给 window.YikaiErrorReportConfig = { endpoint, token, page }，再加载本文件。
 */
(function (root) {
    "use strict";

    var MAX_PER_PAGE = 10;

    /** 值得报的错误：同源脚本抛的，排除跨域的「Script error.」和浏览器自己的 ResizeObserver 提示。 */
    function shouldReport(message, source, origin) {
        message = String(message || "");
        if (message === "" || /^Script error\.?$/i.test(message) || /ResizeObserver loop/i.test(message)) return false;
        if (!source) return true;
        try {
            return new URL(String(source), origin).origin === origin;
        } catch (_) {
            return false;
        }
    }

    /** 同一条错误（消息 + 表达式 + 位置）一页只报一次，一页最多报 MAX_PER_PAGE 条。 */
    function createReporter(config, send, origin) {
        var seen = {};
        var count = 0;
        return function (message, source, line, column, error) {
            var err = error && typeof error === "object" ? error : null;
            message = String(message || (err && err.message) || "");
            if (!shouldReport(message, source, origin)) return false;
            var expression = err && typeof err.expression === "string" ? err.expression : "";
            var key = message + "|" + expression + "|" + (source || "") + ":" + (line || 0);
            if (seen[key] || count >= MAX_PER_PAGE) return false;
            seen[key] = true;
            count++;
            send({
                _token: String(config.token || ""),
                page: String(config.page || "").slice(0, 200),
                message: message.slice(0, 500),
                expression: expression.slice(0, 300),
                source: String(source || "").slice(0, 300),
                line: String(parseInt(line, 10) || 0),
                column: String(parseInt(column, 10) || 0),
                stack: String((err && err.stack) || "").split("\n").slice(0, 4).join("\n").slice(0, 800),
            });
            return true;
        };
    }

    function install(config) {
        if (!config || !config.endpoint || !root.addEventListener) return null;
        var endpoint = config.endpoint;
        var origin = root.location ? root.location.origin : "";
        // 记下出错的页面（如 blox-editor /admin/blox_editor.php?id=2），按页面定位问题
        var where = root.location ? root.location.pathname + root.location.search : "";
        config = { token: config.token, page: (config.page ? config.page + " " : "") + where };
        var send = function (fields) {
            var body = new URLSearchParams(fields);
            try {
                if (root.navigator && root.navigator.sendBeacon && root.navigator.sendBeacon(endpoint, body)) return;
            } catch (_) { /* 退回 fetch */ }
            try {
                root.fetch(endpoint, { method: "POST", body: body, credentials: "same-origin", keepalive: true });
            } catch (_) { /* 上报失败不影响页面 */ }
        };
        var report = createReporter(config, send, origin);
        root.addEventListener("error", function (event) {
            // 资源加载失败（img/script 404）不是脚本错误
            if (!event || event.message === undefined) return;
            report(event.message, event.filename, event.lineno, event.colno, event.error);
        });
        root.addEventListener("unhandledrejection", function (event) {
            var reason = event ? event.reason : null;
            report(reason && reason.message ? reason.message : String(reason || ""), "", 0, 0, reason);
        });
        return report;
    }

    root.YikaiErrorReport = { shouldReport: shouldReport, createReporter: createReporter, install: install, MAX_PER_PAGE: MAX_PER_PAGE };
    if (root.YikaiErrorReportConfig) install(root.YikaiErrorReportConfig);
})(typeof window !== "undefined" ? window : globalThis);
