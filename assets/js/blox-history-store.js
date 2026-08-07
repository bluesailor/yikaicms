(function (global) {
    "use strict";

    function BloxHistoryStore(options) {
        options = options || {};
        this.limit = Math.max(2, parseInt(options.limit, 10) || 51);
        this.delay = Math.max(0, parseInt(options.delay, 10) || 700);
        this.getData = options.getData;
        this.getStructure = options.getStructure;
        this.getSelection = options.getSelection;
        this.isApplying = options.isApplying || function () { return false; };
        this.entries = [];
        this.index = -1;
        this.pending = null;
        this.timer = null;
    }

    BloxHistoryStore.prototype.snapshot = function (data) {
        return {
            data: data === undefined ? this.getData() : data,
            structure: this.getStructure(),
            selection: this.getSelection(),
        };
    };

    BloxHistoryStore.prototype.init = function () {
        clearTimeout(this.timer);
        var initial = this.snapshot();
        this.entries = [initial];
        this.index = 0;
        this.pending = null;
        return initial;
    };

    BloxHistoryStore.prototype.append = function (snapshot) {
        var current = this.entries[this.index] || null;
        if (!snapshot || (current && snapshot.data === current.data)) return false;
        if (this.index < this.entries.length - 1) {
            this.entries.splice(this.index + 1);
        }
        this.entries.push(snapshot);
        if (this.entries.length > this.limit) this.entries.shift();
        this.index = this.entries.length - 1;
        return true;
    };

    BloxHistoryStore.prototype.queue = function (data) {
        if (this.isApplying()) return;
        var snapshot = this.snapshot(data);
        var current = this.entries[this.index] || null;
        if (!current) {
            this.init();
            return;
        }
        if (snapshot.data === current.data) {
            clearTimeout(this.timer);
            this.pending = null;
            return;
        }
        if (snapshot.structure !== current.structure) {
            this.flush(false);
            this.append(snapshot);
            return;
        }
        this.pending = snapshot;
        clearTimeout(this.timer);
        var self = this;
        this.timer = setTimeout(function () { self.flush(false); }, this.delay);
    };

    BloxHistoryStore.prototype.flush = function (captureCurrent) {
        clearTimeout(this.timer);
        if (captureCurrent === true) {
            var current = this.entries[this.index] || null;
            var currentData = this.getData();
            if ((!this.pending || this.pending.data !== currentData)
                && (!current || current.data !== currentData)) {
                this.pending = this.snapshot(currentData);
            }
        }
        var pending = this.pending;
        this.pending = null;
        if (!pending) return false;
        if (pending.data === this.getData()) pending.selection = this.getSelection();
        return this.append(pending);
    };

    BloxHistoryStore.prototype.canUndo = function () {
        return this.index > 0 || !!this.pending;
    };

    BloxHistoryStore.prototype.canRedo = function () {
        return !this.pending && this.index >= 0 && this.index < this.entries.length - 1;
    };

    BloxHistoryStore.prototype.undo = function () {
        this.flush(true);
        if (this.index <= 0) return null;
        this.index--;
        return this.entries[this.index];
    };

    BloxHistoryStore.prototype.redo = function () {
        this.flush(false);
        if (this.index < 0 || this.index >= this.entries.length - 1) return null;
        this.index++;
        return this.entries[this.index];
    };

    BloxHistoryStore.prototype.dispose = function () {
        clearTimeout(this.timer);
        this.timer = null;
        this.pending = null;
    };

    global.BloxHistoryStore = BloxHistoryStore;
})(window);
