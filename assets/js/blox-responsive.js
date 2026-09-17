(function (global) {
    "use strict";

    var DEVICE_KEYS = {
        d: "d", desktop: "d",
        t: "t", tablet: "t",
        m: "m", mobile: "m",
    };

    function has(options, value) {
        return Object.prototype.hasOwnProperty.call(options || {}, String(value));
    }

    function normalize(value, options, fallback) {
        var keys = Object.keys(options || {});
        var safeFallback = has(options, fallback) ? fallback : (keys[0] || "");
        if (!value || typeof value !== "object" || Array.isArray(value)) {
            var scalar = has(options, value) ? value : safeFallback;
            return { d: scalar, t: scalar, m: scalar };
        }

        var canonical = {};
        Object.keys(value).forEach(function (device) {
            var key = DEVICE_KEYS[String(device).toLowerCase()];
            if (key && has(options, value[device])) canonical[key] = value[device];
        });
        var desktop = Object.prototype.hasOwnProperty.call(canonical, "d") ? canonical.d : safeFallback;
        var tablet = Object.prototype.hasOwnProperty.call(canonical, "t") ? canonical.t : desktop;
        var mobile = Object.prototype.hasOwnProperty.call(canonical, "m") ? canonical.m : tablet;
        return { d: desktop, t: tablet, m: mobile };
    }

    function deviceKey(device) {
        return DEVICE_KEYS[String(device || "desktop").toLowerCase()] || "d";
    }

    /**
     * 断点定义（与 BloxCssCompiler / Tailwind md:、lg: 一致）：编辑器按钮提示与宽度档位标签的唯一来源。
     * min/max 为含端点的像素范围；null 表示不设限。
     */
    var BREAKPOINTS = [
        { device: "mobile", key: "m", min: null, max: 767 },
        { device: "tablet", key: "t", min: 768, max: 1023 },
        { device: "desktop", key: "d", min: 1024, max: null },
    ];

    function breakpointFor(device) {
        var key = deviceKey(device);
        return BREAKPOINTS.find(function (item) { return item.key === key; }) || BREAKPOINTS[BREAKPOINTS.length - 1];
    }

    /** 档位像素范围文案：<768px、768–1023px、≥1024px */
    function rangeLabel(device) {
        var item = breakpointFor(device);
        if (item.min === null) return "<" + (item.max + 1) + "px";
        if (item.max === null) return "\u2265" + item.min + "px";
        return item.min + "\u2013" + item.max + "px";
    }

    /** 某个视口宽度落在哪一档（返回 desktop/tablet/mobile） */
    function deviceForWidth(width) {
        var value = Number(width);
        if (!Number.isFinite(value)) return "desktop";
        var match = BREAKPOINTS.find(function (item) {
            return (item.min === null || value >= item.min) && (item.max === null || value <= item.max);
        });
        return match ? match.device : "desktop";
    }

    var PREVIEW_WIDTH_MIN = 320;
    var PREVIEW_WIDTH_MAX = 2560;

    /**
     * R2B：数值型预览宽度的钳制。0/空 = 自动（跟随档位默认宽度）；
     * 其余取整并钳到 320–2560px。预览宽度是工作区状态，不进入文档。
     */
    function clampPreviewWidth(raw) {
        var value = parseInt(raw, 10);
        if (!Number.isFinite(value) || value <= 0) return 0;
        return Math.min(PREVIEW_WIDTH_MAX, Math.max(PREVIEW_WIDTH_MIN, value));
    }

    function stored(value, options, fallback) {
        var keys = Object.keys(options || {});
        var safeFallback = has(options, fallback) ? fallback : (keys[0] || "");
        if (!value || typeof value !== "object" || Array.isArray(value)) {
            return has(options, value) ? value : safeFallback;
        }

        var result = {};
        Object.keys(value).forEach(function (device) {
            var key = DEVICE_KEYS[String(device).toLowerCase()];
            if (key && has(options, value[device])) result[key] = value[device];
        });
        if (Object.keys(result).length === 0) return safeFallback;
        if (Object.keys(result).length === 1 && Object.prototype.hasOwnProperty.call(result, "d")) {
            return result.d;
        }
        return result;
    }

    function valueFor(value, device, options, fallback) {
        return normalize(value, options, fallback)[deviceKey(device)];
    }

    function setFor(value, device, next, options, fallback) {
        var key = deviceKey(device);
        var safeNext = has(options, next) ? next : fallback;
        var current = stored(value, options, fallback);
        if (key === "d" && (typeof current !== "object" || Array.isArray(current))) return safeNext;

        var result = typeof current === "object" && !Array.isArray(current)
            ? Object.assign({}, current)
            : { d: current };
        if (!Object.prototype.hasOwnProperty.call(result, "d")) {
            result.d = normalize(value, options, fallback).d;
        }
        result[key] = safeNext;
        return result;
    }

    function stateFor(value, device, options, fallback) {
        var key = deviceKey(device);
        var current = stored(value, options, fallback);
        var explicit = typeof current === "object" && !Array.isArray(current)
            ? Object.prototype.hasOwnProperty.call(current, key)
            : key === "d";
        var source = key;
        if (!explicit && key === "t") source = "d";
        if (!explicit && key === "m") {
            source = typeof current === "object" && !Array.isArray(current)
                && Object.prototype.hasOwnProperty.call(current, "t") ? "t" : "d";
        }
        return {
            device: key,
            value: valueFor(value, key, options, fallback),
            source: source,
            overridden: key !== "d" && explicit,
            inherited: key !== "d" && !explicit,
        };
    }

    function inheritFor(value, device, options, fallback) {
        var key = deviceKey(device);
        var current = stored(value, options, fallback);
        if (key === "d" || typeof current !== "object" || Array.isArray(current)) return current;

        var result = Object.assign({}, current);
        delete result[key];
        if (Object.keys(result).length === 1 && Object.prototype.hasOwnProperty.call(result, "d")) {
            return result.d;
        }
        if (Object.keys(result).length === 0) return normalize(value, options, fallback).d;
        return result;
    }

    global.BloxResponsive = {
        BREAKPOINTS: BREAKPOINTS,
        rangeLabel: rangeLabel,
        deviceForWidth: deviceForWidth,
        clampPreviewWidth: clampPreviewWidth,
        PREVIEW_WIDTH_MIN: PREVIEW_WIDTH_MIN,
        PREVIEW_WIDTH_MAX: PREVIEW_WIDTH_MAX,
        normalize: normalize,
        stored: stored,
        deviceKey: deviceKey,
        valueFor: valueFor,
        setFor: setFor,
        stateFor: stateFor,
        inheritFor: inheritFor,
    };
})(typeof window !== "undefined" ? window : globalThis);
