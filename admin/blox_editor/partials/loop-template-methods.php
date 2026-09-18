<?php

declare(strict_types=1);
// 循环模板宿主（动态列表 / 内容目录）的编辑方法：由 admin/blox_editor.php 的 Alpine 方法表 require。
?>
            /** 以子元素作为「每条数据的卡片模板」的宿主元素 */
            isLoopTemplateHost(el) {
                var node = el || this.selTopEl;
                return !!(node && ["list-dynamic", "content-catalog"].indexOf(node.type) !== -1);
            },

            isLoopTemplateChild() {
                return this.selectedSubEi >= 0 && this.isLoopTemplateHost(this.selTopEl);
            },

            hasLoopTemplate() {
                var host = this.isLoopTemplateHost(this.selTopEl) ? this.selTopEl : null;
                return !!(host && host.data && (host.data.children || []).length);
            },

            /** 已拆成卡片模板后，宿主上「内置卡片」的字段开关不再生效，面板里隐藏 */
            loopItemControlHidden(ctrl) {
                if (!this.selEl || this.selectedSubEi >= 0 || !this.hasLoopTemplate()) return false;
                var keys = this.selEl.type === "content-catalog"
                    ? ["show_cover", "show_summary", "show_channel", "show_author", "show_date", "show_views"]
                    : ["show_image", "image_field", "show_title", "title_field", "show_summary", "summary_field", "show_date", "date_field", "show_meta", "meta_field", "link_field", "summary_len", "item_preset", "image_ratio"];
                return keys.indexOf(ctrl.key) !== -1;
            },

            /**
             * 把内容目录的内置文章卡片拆成可单独设置样式的子元素：封面、标题、日期、摘要。
             * 沿用当前的显示开关决定拆出哪些部分；之后每个部分都是普通元素，可改样式、调顺序或删除。
             */
            splitContentCatalogItems() {
                var host = this.selEl;
                if (!host || host.type !== "content-catalog" || this.selectedSubEi >= 0 || this.hasLoopTemplate()) return;
                var data = host.data || {};
                var on = function (key, fallback) {
                    return Object.prototype.hasOwnProperty.call(data, key) ? !!data[key] && data[key] !== "0" : fallback;
                };
                var self = this;
                var make = function (type, values) {
                    var lib = self.elementLib.find(function (item) { return item.type === type; });
                    if (!lib) return null;
                    var node = self.newElementNode(lib);
                    Object.keys(values).forEach(function (key) { node.data[key] = values[key]; });
                    return node;
                };
                var parts = [];
                if (on("show_cover", true)) parts.push(make("image", { src: "", alt: "", loop_field: "cover", loop_alt_field: "title", loop_link_field: "url" }));
                parts.push(make("heading", { text: "", level: "h3", loop_field: "title", loop_url_field: "url" }));
                if (on("show_date", true)) parts.push(make("text", { html: "", loop_field: "date" }));
                if (on("show_summary", true)) parts.push(make("text", { html: "", loop_field: "summary", loop_length: 120 }));
                parts = parts.filter(Boolean);
                if (!parts.length) return;
                this.flushHistory(true);
                this.runCommand("split-content-catalog-items", function () {
                    host.data.children = parts;
                });
                this.flushHistory(true);
                this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, parts.length > 1 ? 1 : 0, false);
            },
