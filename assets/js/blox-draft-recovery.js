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
        // 可观察状态：idle | saved | unavailable | quota | toolarge。
        // 只在状态变化时回调一次，避免每次输入重复提示。
        this.state = "idle";
        this.lastSavedAt = 0;
        this.onStateChange = typeof options.onStateChange === "function" ? options.onStateChange : null;
    }

    DraftRecovery.prototype.getState = function () {
        return this.state;
    };

    DraftRecovery.prototype._setState = function (state) {
        if (state === this.state) return;
        this.state = state;
        if (this.onStateChange) {
            try { this.onStateChange(state, this); } catch (_) {}
        }
    };

    function isQuotaError(error) {
        if (!error) return false;
        return error.name === "QuotaExceededError" || error.name === "NS_ERROR_DOM_QUOTA_REACHED"
            || error.code === 22 || error.code === 1014;
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
        if (typeof data !== "string") return;
        if (!this.available()) {
            this._setState("unavailable");
            return;
        }
        if (data.length > this.maxBytes) {
            // 超限：不更新恢复稿，但保留已有的有效副本；状态可观察。
            clearTimeout(this.timer);
            this.timer = null;
            this.pending = null;
            this._setState("toolarge");
            return;
        }
        this.pending = { data: data, baseRevision: String(baseRevision || "") };
        clearTimeout(this.timer);
        var self = this;
        this.timer = setTimeout(function () { self.flush(); }, this.delay);
    };

    DraftRecovery.prototype.flush = function () {
        clearTimeout(this.timer);
        this.timer = null;
        if (!this.pending) return false;
        if (!this.available()) {
            this._setState("unavailable");
            return false;
        }
        var snapshot = {
            version: VERSION,
            savedAt: this.now(),
            baseRevision: this.pending.baseRevision,
            data: this.pending.data,
        };
        var pending = this.pending;
        this.pending = null;
        try {
            // setItem 失败会原子地保留旧值：绝不先 removeItem 再写。
            this.storage.setItem(this.key, JSON.stringify(snapshot));
            this.lastSavedAt = snapshot.savedAt;
            this._setState("saved");
            return true;
        } catch (error) {
            // 写失败保留待写内容，下次 flush 可重试；旧的有效副本仍在。
            this.pending = pending;
            this._setState(isQuotaError(error) ? "quota" : "unavailable");
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
