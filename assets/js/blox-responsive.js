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
        normalize: normalize,
        stored: stored,
        deviceKey: deviceKey,
        valueFor: valueFor,
        setFor: setFor,
        stateFor: stateFor,
        inheritFor: inheritFor,
    };
})(typeof window !== "undefined" ? window : globalThis);
