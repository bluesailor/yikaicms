(function (global) {
    "use strict";

    function settingsFor(section) {
        return section && section.settings && typeof section.settings === "object"
            ? section.settings
            : {};
    }

    function layerState(section, layer) {
        var settings = settingsFor(section);
        var imageKey = layer === "container" ? "container_bg_image" : "bg_image";
        var colorKey = layer === "container" ? "container_bg" : "bg_color";
        var image = String(settings[imageKey] || "").trim();
        if (image) return { kind: "image", value: image };

        if (layer !== "container") {
            var gradient = String(settings.bg_gradient || "").trim();
            if (gradient) return { kind: "gradient", value: gradient };
        }

        var color = String(settings[colorKey] || "").trim();
        return color ? { kind: "color", value: color } : { kind: "none", value: "" };
    }

    function preferredLayer(section) {
        var sectionState = layerState(section, "section");
        var containerState = layerState(section, "container");
        if (sectionState.kind === "image") return "section";
        if (containerState.kind === "image") return "container";
        if (sectionState.kind !== "none") return "section";
        return containerState.kind !== "none" ? "container" : "section";
    }

    var methods = {
        backgroundLayerState(layer) {
            return layerState(this.sel, layer);
        },
        selectBackgroundLayer(layer) {
            if (!this.sel || this.selectedSi < 0 || !["section", "container"].includes(layer)) return;
            var sectionIndex = this.selectedSi;
            if (layer === "container") {
                this.selectContainer(sectionIndex, false);
            } else {
                this.selectSection(sectionIndex, false);
                this.panelTab = "style";
            }
            this.highlightCanvasSelection(false);
        },
        openPreferredBackgroundLayer() {
            this.selectBackgroundLayer(preferredLayer(this.sel));
        },
    };

    var api = { layerState: layerState, preferredLayer: preferredLayer, methods: methods };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxBackgroundPanel = api;
})(typeof window !== "undefined" ? window : globalThis);
