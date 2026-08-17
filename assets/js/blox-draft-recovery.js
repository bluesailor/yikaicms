(function (root, factory) {
    "use strict";
    var api = factory();
    if (typeof module === "object" && module.exports) module.exports = api;
    else root.BloxDraftRecovery = api;
})(typeof globalThis !== "undefined" ? globalThis : this, function () {
    "use strict";

    var VERSION = 1;
    var DEFAULT_MAX_BYTES = 2_000_000;
    var DEFAULT_MAX_AGE = 7 * 24 * 60 * 60 * 1000;

    function DraftRecovery(options) {
        options = options || {};
        this.storage = options.storage;
        this.key = String(options.key || "");
        this.delay = Math.max(100, Number(options.delay) || 1200);
        this.maxBytes = Math.max(1024, Number(options.maxBytes) || DEFAULT_MAX_BYTES);
        this.maxAge = Math.max(60000, Number(options.maxAge) || DEFAULT_MAX_AGE);
        this.now = typeof options.now === "function" ? options.now : Date.now;
        this.timer = null;
        this.pending = null;
    }

    DraftRecovery.prototype.available = function () {
        return !!(this.storage && this.key);
    };

    DraftRecovery.prototype.read = function (currentData) {
        if (!this.available()) return null;
        var raw;
        try { raw = this.storage.getItem(this.key); } catch (_) { return null; }
        if (!raw) return null;
        try {
            var snapshot = JSON.parse(raw);
            if (!snapshot || snapshot.version !== VERSION || typeof snapshot.data !== "string"
                || snapshot.data.length > this.maxBytes || !Number.isFinite(snapshot.savedAt)) {
                this.clear();
                return null;
            }
            // 客户端时钟不能和服务器时间直接比较；只按同一客户端的保留期清理。
            if (snapshot.data === currentData || snapshot.savedAt < this.now() - this.maxAge) {
                this.clear();
                return null;
            }
            return snapshot;
        } catch (_) {
            this.clear();
            return null;
        }
    };

    DraftRecovery.prototype.queue = function (data, baseRevision) {
        if (!this.available() || typeof data !== "string" || data.length > this.maxBytes) return;
        this.pending = { data: data, baseRevision: String(baseRevision || "") };
        clearTimeout(this.timer);
        var self = this;
        this.timer = setTimeout(function () { self.flush(); }, this.delay);
    };

    DraftRecovery.prototype.flush = function () {
        clearTimeout(this.timer);
        this.timer = null;
        if (!this.available() || !this.pending) return false;
        var snapshot = {
            version: VERSION,
            savedAt: this.now(),
            baseRevision: this.pending.baseRevision,
            data: this.pending.data,
        };
        this.pending = null;
        try {
            this.storage.setItem(this.key, JSON.stringify(snapshot));
            return true;
        } catch (_) {
            return false;
        }
    };

    DraftRecovery.prototype.clear = function () {
        clearTimeout(this.timer);
        this.timer = null;
        this.pending = null;
        if (!this.available()) return;
        try { this.storage.removeItem(this.key); } catch (_) {}
    };

    DraftRecovery.prototype.dispose = function (flushPending) {
        if (flushPending === true) this.flush();
        else {
            clearTimeout(this.timer);
            this.timer = null;
            this.pending = null;
        }
    };

    return { DraftRecovery: DraftRecovery, VERSION: VERSION };
});
