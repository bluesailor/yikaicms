(function (global) {
    "use strict";

    // Legacy homepage icons used Heroicons-style names. Keep stored values stable
    // and translate only at the Tabler font rendering boundary.
    var TABLER_ALIASES = {
        "academic-cap": "school",
        "check-circle": "circle-check",
        "cog": "settings",
        "life-buoy": "lifebuoy",
        "mic": "microphone",
        "monitor": "device-desktop",
        "pen-tool": "pencil",
        "smile": "mood-smile",
        "thumbs-up": "thumb-up",
        "tv": "device-tv",
        "zap": "bolt",
    };

    function className(value) {
        var normalized = String(value || "star").trim().toLowerCase();
        if (normalized === "none") return "ti ti-ban";
        if (normalized.indexOf("bi:") === 0) {
            var bootstrapName = normalized.slice(3);
            return /^[a-z0-9][a-z0-9-]{0,79}$/.test(bootstrapName)
                ? "bi bi-" + bootstrapName : "ti ti-star";
        }
        if (normalized.indexOf("tabler:") === 0) normalized = normalized.slice(7);
        else if (normalized.indexOf("ti:") === 0) normalized = normalized.slice(3);
        else if (normalized.indexOf(":") !== -1) return "ti ti-star";
        normalized = normalized.replace(/[^a-z0-9-]/g, "");
        normalized = TABLER_ALIASES[normalized] || normalized;
        return /^[a-z0-9][a-z0-9-]{0,79}$/.test(normalized)
            ? "ti ti-" + normalized : "ti ti-star";
    }

    var api = { className: className, tablerAliases: TABLER_ALIASES };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxIconUtils = api;
})(typeof window !== "undefined" ? window : globalThis);
