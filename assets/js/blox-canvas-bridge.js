(function (global) {
    "use strict";

    function noop() {}

    function isObject(value) {
        return value !== null && typeof value === "object" && !Array.isArray(value);
    }

    function isIndex(value) {
        return Number.isInteger(value) && value >= 0 && value <= 10000;
    }

    function isElementPath(value) {
        return typeof value === "string" && /^\d+\.\d+\.\d+(?:\.\d+)?$/.test(value);
    }

    function isFieldName(value) {
        return typeof value === "string" && /^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}$/.test(value);
    }

    function coordinate(value) {
        return typeof value === "number" && Number.isFinite(value) && Math.abs(value) <= 100000 ? value : null;
    }

    function columnRatioPayload(value) {
        if (!isObject(value)) return null;
        if (value.kind === "home" && isElementPath(value.path)
            && Number.isInteger(value.index) && value.index >= 0 && value.index <= 4) {
            return { kind: "home", path: value.path, index: value.index };
        }
        if (value.kind !== "section" || !isIndex(value.si) || !Array.isArray(value.spans)
            || value.spans.length < 2 || value.spans.length > 12) return null;
        var spans = value.spans.slice();
        if (spans.some(function (span) { return !Number.isInteger(span) || span < 1 || span > 12; })) return null;
        if (spans.reduce(function (sum, span) { return sum + span; }, 0) > 12) return null;
        return { kind: "section", si: value.si, spans: spans };
    }

    function contextPayload(value) {
        if (!isObject(value) || typeof value.kind !== "string") return null;
        var target = isObject(value.target) ? value.target : {};
        var cleanTarget;
        if (value.kind === "canvas") {
            cleanTarget = {};
        } else if (value.kind === "sectionField") {
            cleanTarget = sectionFieldPayload(target);
            if (!cleanTarget) return null;
        } else if (value.kind === "section" || value.kind === "container") {
            if (!isIndex(target.si)) return null;
            cleanTarget = { si: target.si };
        } else if (value.kind === "column") {
            if (!isIndex(target.si) || !isIndex(target.ci)) return null;
            cleanTarget = { si: target.si, ci: target.ci };
        } else if (value.kind === "element") {
            if (!isIndex(target.si) || !isIndex(target.ci) || !isIndex(target.ei)) return null;
            cleanTarget = { si: target.si, ci: target.ci, ei: target.ei };
        } else if (value.kind === "child") {
            if (!isIndex(target.si) || !isIndex(target.ci) || !isIndex(target.ei) || !isIndex(target.cei)) return null;
            cleanTarget = { si: target.si, ci: target.ci, ei: target.ei, cei: target.cei };
        } else {
            return null;
        }
        var x = coordinate(value.x);
        var y = coordinate(value.y);
        if (x === null || y === null) return null;
        return { kind: value.kind, target: cleanTarget, x: x, y: y };
    }

    function sectionFieldPayload(value) {
        if (!isObject(value) || !isIndex(value.si)) return null;
        if (value.field !== "title" && value.field !== "subtitle") return null;
        return { si: value.si, field: value.field };
    }

    function homeTargetPayload(value) {
        if (!isObject(value) || !isElementPath(value.path) || !isFieldName(value.field)) return null;
        return { path: value.path, field: value.field };
    }

    function homeColumnPayload(value) {
        if (!isObject(value) || !isElementPath(value.path) || !isFieldName(value.column)) return null;
        return { path: value.path, column: value.column };
    }

    function inlineEditPayload(value) {
        if (!isObject(value) || typeof value.value !== "string" || value.value.length > 2097152) return null;
        if (value.kind === "sectionField") {
            var sectionField = sectionFieldPayload(value);
            return sectionField ? { kind: "sectionField", si: sectionField.si, field: sectionField.field, format: "text", value: value.value } : null;
        }
        if (value.kind === "homeField" && isElementPath(value.path) && isFieldName(value.field)
            && (value.format === "text" || value.format === "plain")) {
            return { kind: "homeField", path: value.path, field: value.field, format: value.format, value: value.value };
        }
        if (value.kind === "element" && isElementPath(value.path)
            && (value.field === "text" || value.field === "html")
            && (value.format === "text" || value.format === "plain")) {
            return { kind: "element", path: value.path, field: value.field, format: value.format, value: value.value };
        }
        return null;
    }

    function dropPayload(value) {
        if (!isObject(value) || value.version !== 1) return null;
        if (typeof value.type !== "string" || !/^[a-zA-Z0-9_][a-zA-Z0-9_\/-]{0,99}$/.test(value.type)) return null;
        if (!isIndex(value.sec) || !isIndex(value.col)) return null;
        if (typeof value.dropId !== "string" || value.dropId.length < 1 || value.dropId.length > 128) return null;
        if (!isObject(value.target)) return null;
        var target;
        if (value.target.kind === "column" && isIndex(value.target.sec) && isIndex(value.target.col)
            && value.target.position === "end") {
            target = { kind: "column", sec: value.target.sec, col: value.target.col, position: "end" };
        } else if (value.target.kind === "element" && isElementPath(value.target.path)
            && (value.target.position === "before" || value.target.position === "after")) {
            target = { kind: "element", path: value.target.path, position: value.target.position };
        } else {
            return null;
        }
        return { version: 1, type: value.type, sec: value.sec, col: value.col, dropId: value.dropId, target: target };
    }

    function BloxCanvasBridge(options) {
        options = options || {};
        this.getFrame = options.getFrame;
        this.onColumnRatio = options.onColumnRatio || noop;
        this.onContext = options.onContext || noop;
        this.onDrop = options.onDrop || noop;
        this.onInlineEdit = options.onInlineEdit || noop;
        this.onEditSectionField = options.onEditSectionField || noop;
        this.onPickSectionField = options.onPickSectionField || noop;
        this.onPickHomeColumn = options.onPickHomeColumn || noop;
        this.onPickHomeField = options.onPickHomeField || noop;
        this.onPickElement = options.onPickElement || noop;
        this.onEditElement = options.onEditElement || noop;
        this.onPickColumn = options.onPickColumn || noop;
        this.onPickContainer = options.onPickContainer || noop;
        this.onPickSection = options.onPickSection || noop;
        this.onClear = options.onClear || noop;
        this.lastDropId = "";
        this.started = false;
        this.boundMessage = this.handleMessage.bind(this);
    }

    BloxCanvasBridge.prototype.frameWindow = function () {
        var frame = typeof this.getFrame === "function" ? this.getFrame() : null;
        return frame && frame.contentWindow ? frame.contentWindow : null;
    };

    BloxCanvasBridge.prototype.start = function () {
        if (this.started) return this;
        global.addEventListener("message", this.boundMessage);
        this.started = true;
        return this;
    };

    BloxCanvasBridge.prototype.dispose = function () {
        if (!this.started) return;
        global.removeEventListener("message", this.boundMessage);
        this.started = false;
        this.lastDropId = "";
    };

    BloxCanvasBridge.prototype.post = function (message) {
        var target = this.frameWindow();
        if (!target || !isObject(message)) return false;
        target.postMessage(message, "*");
        return true;
    };

    BloxCanvasBridge.prototype.handleMessage = function (event) {
        var source = this.frameWindow();
        if (!source || !event || event.source !== source || !isObject(event.data)) return false;
        var data = event.data;
        var payload;

        if (data.ykColumnRatio !== undefined) {
            payload = columnRatioPayload(data.ykColumnRatio);
            if (!payload) return false;
            this.onColumnRatio(payload);
            return true;
        }
        if (data.ykContext !== undefined) {
            payload = contextPayload(data.ykContext);
            if (!payload) return false;
            this.onContext(payload);
            return true;
        }
        if (data.ykDrop !== undefined) {
            payload = dropPayload(data.ykDrop);
            if (!payload) return false;
            if (this.lastDropId === payload.dropId) return true;
            this.lastDropId = payload.dropId;
            this.onDrop(payload);
            return true;
        }
        if (data.ykInlineEdit !== undefined) {
            payload = inlineEditPayload(data.ykInlineEdit);
            if (!payload) return false;
            this.onInlineEdit(payload);
            return true;
        }
        payload = sectionFieldPayload(data.ykEditSectionField);
        if (payload) {
            this.onEditSectionField(payload);
            return true;
        }
        payload = sectionFieldPayload(data.ykPickSectionField);
        if (payload) {
            this.onPickSectionField(payload);
            return true;
        }
        payload = homeColumnPayload(data.ykPickHomeColumn);
        if (payload) {
            this.onPickHomeColumn(payload);
            return true;
        }
        payload = homeTargetPayload(data.ykPickHomeField);
        if (payload) {
            this.onPickHomeField(payload);
            return true;
        }
        if (isElementPath(data.ykPickEl)) {
            this.onPickElement(data.ykPickEl);
            return true;
        }
        if (isElementPath(data.ykEditEl)) {
            this.onEditElement(data.ykEditEl);
            return true;
        }
        if (typeof data.ykPickCol === "string" && /^\d+\.\d+$/.test(data.ykPickCol)) {
            payload = data.ykPickCol.split(".").map(function (value) { return parseInt(value, 10); });
            this.onPickColumn(payload[0], payload[1]);
            return true;
        }
        if (isIndex(data.ykPickCon)) {
            this.onPickContainer(data.ykPickCon);
            return true;
        }
        if (isIndex(data.ykPick)) {
            this.onPickSection(data.ykPick);
            return true;
        }
        if (data.ykClear === true) {
            this.onClear();
            return true;
        }
        return false;
    };

    global.BloxCanvasBridge = BloxCanvasBridge;
})(window);