/* Blox 通用条目编辑器（ctrl.type === 'items_repeater'）：客户评价轮播、合作伙伴 Logo 墙等元素共用，
 * 以 `...window.BloxItemsControl.methods` 混入编辑器。字段由控件 schema 的 fields 声明，只接受声明过的键。 */
(function (global) {
    'use strict';

    var BLOCKED_KEYS = ['__proto__', 'prototype', 'constructor'];

    function fieldKeys(ctrl) {
        return (ctrl && Array.isArray(ctrl.fields) ? ctrl.fields : []).map(function (field) {
            return field && typeof field.key === 'string' ? field.key : '';
        }).filter(function (key) {
            return key !== '' && BLOCKED_KEYS.indexOf(key) === -1;
        });
    }

    function maxItems(ctrl) {
        return Math.max(1, Math.min(50, Number(ctrl && ctrl.max) || 12));
    }

    var methods = {
        /** 当前元素的条目列表（未设置时用 schema 默认条目），只保留声明过的字段 */
        repeaterItems(ctrl, el) {
            var node = el || this.selEl;
            if (!node || !ctrl) return [];
            var data = node.data && typeof node.data === 'object' ? node.data : {};
            var source = Array.isArray(data[ctrl.key]) ? data[ctrl.key] : (Array.isArray(ctrl.default) ? ctrl.default : []);
            var keys = fieldKeys(ctrl);
            return source.slice(0, maxItems(ctrl)).filter(function (item) {
                return item && typeof item === 'object';
            }).map(function (item) {
                var copy = {};
                keys.forEach(function (key) { copy[key] = item[key] == null ? '' : String(item[key]); });
                return copy;
            });
        },

        storeRepeaterItems(ctrl, items) {
            if (!this.selEl || !ctrl || BLOCKED_KEYS.indexOf(ctrl.key) !== -1) return;
            this.selEl.data = this.selEl.data && typeof this.selEl.data === 'object' ? this.selEl.data : {};
            this.selEl.data[ctrl.key] = items;
        },

        setRepeaterItem(ctrl, index, key, value) {
            var items = this.repeaterItems(ctrl), position = Number(index);
            if (!items[position] || fieldKeys(ctrl).indexOf(key) === -1) return;
            items[position][key] = String(value == null ? '' : value);
            this.storeRepeaterItems(ctrl, items);
        },

        canAddRepeaterItem(ctrl) {
            return this.repeaterItems(ctrl).length < maxItems(ctrl);
        },

        addRepeaterItem(ctrl) {
            var items = this.repeaterItems(ctrl);
            if (items.length >= maxItems(ctrl)) return;
            var blank = {};
            fieldKeys(ctrl).forEach(function (key) { blank[key] = ''; });
            items.push(blank);
            this.storeRepeaterItems(ctrl, items);
        },

        deleteRepeaterItem(ctrl, index) {
            var items = this.repeaterItems(ctrl), position = Number(index);
            if (items.length <= 1 || !items[position]) return;
            items.splice(position, 1);
            this.storeRepeaterItems(ctrl, items);
        },

        moveRepeaterItem(ctrl, index, delta) {
            var items = this.repeaterItems(ctrl), from = Number(index), to = from + Number(delta);
            if (!items[from] || to < 0 || to >= items.length) return;
            items.splice(to, 0, items.splice(from, 1)[0]);
            this.storeRepeaterItems(ctrl, items);
        },

        pickRepeaterImage(ctrl, index, key) {
            var self = this, element = this.selEl;
            if (!element || typeof this.openMedia !== 'function') return;
            this.openMedia(function (url) {
                // 选择器是异步的：期间切换了元素就不写回
                if (self.selEl === element) self.setRepeaterItem(ctrl, index, key, url);
            });
        },
    };

    global.BloxItemsControl = { methods: methods, fieldKeys: fieldKeys };
    if (typeof module !== 'undefined' && module.exports) module.exports = global.BloxItemsControl;
})(typeof window === 'undefined' ? globalThis : window);
