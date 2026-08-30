(function (global) {
    "use strict";

    function supports(node) {
        return !!(node && (node.type === "home-banner-item" || (node.type === "home-block" && (node.data || {}).block_type === "banner")));
    }

    function groupFor(key, node) {
        if (node && node.type === "home-banner-item") {
            if (key === "image_mobile") return "mobile";
            if (["content_motion", "background_motion"].includes(key)) return "motion";
            if (["btn1_text", "btn1_url", "btn2_text", "btn2_url", "link_url", "link_target"].includes(key)) return "playback";
            return "common";
        }
        if (["banner_mobile_mode", "banner_height_mobile"].includes(key)) return "mobile";
        if (["banner_effect", "banner_content_motion", "banner_background_motion", "banner_speed", "banner_stagger"].includes(key)) return "motion";
        if (["banner_autoplay", "banner_navigation", "banner_pagination", "banner_pause_hover", "limit"].includes(key)) return "playback";
        return "common";
    }

    function controls(node, list, group, showAll) {
        if (!supports(node) || showAll) return list;
        var visible = list.filter(function (control) { return groupFor(control.key, node) === group; });
        if (node.type === "home-banner-item" && group === "common") {
            var order = ["image", "title", "subtitle"];
            visible.sort(function (a, b) { return order.indexOf(a.key) - order.indexOf(b.key); });
        }
        return visible;
    }

    var methods = {
        inheritedBannerRuntime() {
            return this.isHomeBannerHost(this.selEl) && (this.selEl.data.banner_height_mode || "inherit") === "inherit"
                ? this.homeBannerRuntime : null;
        },

        bannerControlValue(key, fallback) {
            var runtime = this.inheritedBannerRuntime();
            if (!runtime || !Object.prototype.hasOwnProperty.call(runtime, key) || key === "banner_height_mode" || key === "banner_mobile_mode") return fallback;
            if (key === "banner_height_mobile" && ["fixed", "hidden"].includes(this.selEl.data.banner_mobile_mode)) return fallback;
            return runtime[key];
        },

        prepareBannerControlEdit(key, value) {
            var runtime = this.inheritedBannerRuntime();
            if (!runtime || !Object.prototype.hasOwnProperty.call(runtime, key)
                || ["banner_mobile_mode", "banner_height_mobile"].includes(key)
                || (key === "banner_height_mode" && value === "inherit")) return;
            // Snapshot the currently rendered group settings before a local edit.
            // Existing documents remain inherited until the user changes a setting.
            var mobile = this.selEl.data.banner_mobile_mode;
            var mobileHeight = this.selEl.data.banner_height_mobile;
            Object.assign(this.selEl.data, runtime);
            if (["fixed", "hidden"].includes(mobile)) {
                this.selEl.data.banner_mobile_mode = mobile;
                this.selEl.data.banner_height_mobile = mobileHeight;
            }
        },

        bannerImageUrl(key) {
            var data = (this.selEl && this.selEl.data) || {};
            return key === "image_mobile" ? (data.image_mobile || data.image || "") : (data.image || "");
        },

        replaceBannerControlImage(key) {
            var node = this.selEl;
            if (!node || node.type !== "home-banner-item" || !["image", "image_mobile"].includes(key)) return;
            var self = this;
            this.openMedia(function (url) {
                if (self.selEl !== node) return;
                self.runCommand("replace-banner-control-image", function () { node.data[key] = url; });
            }, key === "image_mobile" ? {} : { usage: "hero-bg" });
        },

        resetBannerMobileImage() {
            if (!this.selEl || this.selEl.type !== "home-banner-item" || !this.selEl.data.image_mobile) return;
            this.runCommand("reset-banner-mobile-image", function () { this.selEl.data.image_mobile = ""; });
        },

        previewBannerDevice(device) {
            if (!["desktop", "mobile"].includes(device) || !this.bannerHost()) return;
            this.previewDevice = device;
            this.mobilePanel = "";
            this.$nextTick(() => this.highlightCanvasSelection(true));
        },

        hasCustomBannerItems() {
            var host = this.isHomeBlockHost(this.selTopEl) ? this.selTopEl : this.selEl;
            return !!(this.isHomeBannerHost(host) && (host.data || {}).items_mode === "custom");
        },

        bannerHost() {
            if (this.isHomeBannerHost(this.selTopEl)) return this.selTopEl;
            return this.isHomeBannerHost(this.selEl) ? this.selEl : null;
        },

        bannerItems() {
            var host = this.bannerHost();
            return host && host.data && Array.isArray(host.data.children) ? host.data.children : [];
        },

        bannerPreviewItems() {
            if (this.hasCustomBannerItems()) return this.bannerItems();
            return this.homeBannerSeeds.map(function (item, index) {
                return { id: "seed-banner-" + index, type: "home-banner-item", data: item };
            });
        },

        homeBannerItemCount() {
            return this.bannerPreviewItems().length;
        },

        adoptBannerData(host) {
            if (!this.isHomeBannerHost(host)) return [];
            var self = this;
            host.data.items_mode = "custom";
            host.data.children = this.homeBannerSeeds.map(function (item) {
                return { id: self.uid("e"), type: "home-banner-item", data: JSON.parse(JSON.stringify(item)) };
            });
            return host.data.children;
        },

        selectBannerItem(index) {
            var host = this.bannerHost();
            if (!host || !this.bannerPreviewItems()[index]) return;
            this.runCommand("edit-banner-slide", function () {
                if (!this.hasCustomBannerItems()) this.adoptBannerData(host);
                this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, index, false);
            });
        },

        replaceBannerImage(index) {
            var host = this.bannerHost();
            if (!host || !this.bannerPreviewItems()[index]) return;
            var self = this;
            this.openMedia(function (url) {
                if (self.bannerHost() !== host) return;
                self.runCommand("replace-banner-image", function () {
                    if (!self.hasCustomBannerItems()) self.adoptBannerData(host);
                    var item = self.bannerItems()[index];
                    if (!item) return;
                    item.data = item.data || {};
                    item.data.image = url;
                    self.selectChild(self.selectedSi, self.selectedCi, self.selectedEi, index, false);
                });
            }, { usage: "hero-bg" });
        },

        addBannerItem() {
            var host = this.isHomeBlockHost(this.selTopEl) ? this.selTopEl : this.selEl;
            if (!this.isHomeBannerHost(host)) return;
            this.runCommand("add-banner-slide", function () {
                if (!this.hasCustomBannerItems()) this.adoptBannerData(host);
                var defaults = JSON.parse(JSON.stringify((this.elSchema("home-banner-item").defaults || {})));
                defaults.title = defaults.title || this.homeDynamicText.newItemTitle;
                host.data.children.push({ id: this.uid("e"), type: "home-banner-item", data: defaults });
                this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, host.data.children.length - 1, false);
            });
        },

        restoreBannerSource() {
            var host = this.isHomeBlockHost(this.selTopEl) ? this.selTopEl : this.selEl;
            if (!this.isHomeBannerHost(host)) return;
            if ((host.data.children || []).length && !confirm(this.homeDynamicText.restoreConfirm)) return;
            this.runCommand("restore-banner-source", function () {
                host.data.items_mode = "inherit";
                host.data.children = [];
                this.selectElement(this.selectedSi, this.selectedCi, this.selectedEi, false);
            });
        },

        moveBannerItem(index, direction) {
            var items = this.bannerItems();
            if (!this.hasCustomBannerItems() || !items[index] || ![-1, 1].includes(direction)
                || index + direction < 0 || index + direction >= items.length) return;
            this.runCommand("move-banner-slide", function () {
                this.moveChild(this.selectedSi, this.selectedCi, this.selectedEi, index, direction);
                this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, index + direction, false);
            });
        },

        duplicateBannerItem(index) {
            var items = this.bannerItems();
            if (!this.hasCustomBannerItems() || !items[index]) return;
            this.runCommand("duplicate-banner-slide", function () {
                items.splice(index + 1, 0, this.deepCloneNode(items[index], "e"));
                this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, index + 1, false);
            });
        },

        deleteBannerItem(index) {
            if (!this.hasCustomBannerItems() || !this.bannerItems()[index]) return;
            this.runCommand("delete-banner-slide", function () {
                this.deleteChild(this.selectedSi, this.selectedCi, this.selectedEi, index);
                var next = Math.min(index, this.bannerItems().length - 1);
                if (next >= 0) this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, next, false);
                else this.selectElement(this.selectedSi, this.selectedCi, this.selectedEi, false);
            });
        },
    };

    var api = { supports: supports, controls: controls, groupFor: groupFor, methods: methods };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxBannerPanel = api;
})(typeof window !== "undefined" ? window : globalThis);
