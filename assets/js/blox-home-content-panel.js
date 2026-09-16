(function (global) {
    "use strict";

    var contentKeys = ["override_title", "override_content", "override_description", "override_button_text", "override_button_url", "override_call_text", "override_call_phone"];
    var imageKeys = ["override_image", "override_tag_title", "override_tag_description"];
    var inheritedKeys = ["override_title", "override_content", "override_image", "override_tag_title", "override_tag_description", "override_button_text", "override_button_url"];

    function supports(node) {
        return !!(node && node.type === "home-block" && ["about", "cta"].includes((node.data || {}).block_type));
    }

    function groupFor(key) {
        if (contentKeys.includes(key)) return "content";
        if (imageKeys.includes(key)) return "media";
        if (["override_layout", "override_breakpoint", "override_ratio"].includes(key) || key.startsWith("title_decor_")) return "layout";
        return "more";
    }

    function tabFor(node, control) {
        return control.tab || (control.type === "color" ? "style" : "content");
    }

    function controls(node, list, group, showAll) {
        if (!supports(node) || showAll) return list;
        return list.filter(function (control) { return groupFor(control.key) === group; });
    }

    function isImage(node, key) {
        return supports(node) && key === (node.data.block_type === "cta" ? "bg_image" : "override_image");
    }

    function fieldState(node, key, seeds) {
        if (!supports(node) || node.data.block_type !== "about" || !inheritedKeys.includes(key)
            || !seeds || !Object.prototype.hasOwnProperty.call(seeds, key)) return null;
        // Match PHP trim used by runtimeConfigOverrides, including the string "0".
        var stored = String(node.data[key] ?? "");
        var inherited = stored.replace(/^[ \t\n\r\v\0]+|[ \t\n\r\v\0]+$/g, "") === "";
        return { inherited: inherited, value: inherited ? String((seeds || {})[key] ?? "") : stored };
    }

    var methods = {
        openContentParent(kind) {
            var node = this.selEl;
            if (!node) return;
            this.contentReturnTarget = {
                id: String(node.id || ""), sectionId: this.selectedSectionId(),
                parentId: kind === "banner" ? String((this.selTopEl || {}).id || "") : "",
                kind: kind, label: this.panelTitle(), group: this.homeContentGroup,
                field: this.selectedHomeField, column: this.selectedHomeColumn,
                bannerGroup: this.bannerPanelGroup,
            };
            if (kind === "banner") this.selectElement(this.selectedSi, this.selectedCi, this.selectedEi, false);
            else this.selectSection(this.selectedSi, false);
            this.panelTab = kind === "banner" ? "content" : "style";
            this.highlightCanvasSelection(false);
        },
        contentReturnAvailable() {
            var target = this.contentReturnTarget;
            if (!target || this.selectedSectionId() !== target.sectionId || !this.elementPathById(target.id)) return false;
            return target.kind === "banner"
                ? !!this.selEl && this.selEl.id === target.parentId
                : !this.selEl && this.selLayer === "sec";
        },
        returnToContent() {
            if (!this.contentReturnAvailable()) return;
            var target = this.contentReturnTarget;
            var path = this.elementPathById(target.id);
            this.selectPath(path, false);
            this.homeContentGroup = target.group;
            this.bannerPanelGroup = target.bannerGroup;
            this.selectedHomeField = target.field;
            this.selectedHomeColumn = target.column;
            this.panelTab = "content";
            this.contentReturnTarget = null;
            this.highlightCanvasSelection(false);
        },
        isPartnersBlock() {
            return !!(this.selEl && this.selEl.type === "home-block" && this.selEl.data.block_type === "partners");
        },
        partnerItems() {
            if (!this.isPartnersBlock()) return [];
            var data = this.selEl.data.partners_custom ? this.selEl.data : (this.homeFieldSeeds.partners || {});
            return Array.isArray(data.partner_items) ? data.partner_items : [];
        },
        setPartnersMode(custom) {
            if (!this.isPartnersBlock() || !!this.selEl.data.partners_custom === custom) return;
            var node = this.selEl;
            this.flushHistory(true);
            this.runCommand("partners-source", function () {
                if (custom && !Array.isArray(node.data.partner_items)) node.data.partner_items = [];
                node.data.partners_custom = custom;
            });
            this.selectedHomeField = "";
            this.selectedHomeColumn = "";
            this.flushHistory(true);
        },
        copySharedPartners() {
            if (!this.isPartnersBlock() || !this.selEl.data.partners_custom || this.partnerItems().length) return;
            var node = this.selEl, seeds = (this.homeFieldSeeds.partners || {}).partner_items || [];
            this.flushHistory(true);
            this.runCommand("partners-copy-shared", function () {
                node.data.partner_items = seeds.slice(0, 12).map(function (item) {
                    return { name: item.name || "", url: item.url || "", logo: item.logo || "" };
                });
            });
            this.flushHistory(true);
        },
        changePartner(action, index) {
            if (!this.isPartnersBlock() || !this.selEl.data.partners_custom) return;
            var node = this.selEl, items = this.partnerItems();
            if (action === "add" ? items.length >= 12 : !Number.isInteger(index) || !items[index]) return;
            if (action === "up" && index === 0) return;
            if (action === "down" && index === items.length - 1) return;
            if (!["add", "remove", "up", "down"].includes(action)) return;
            this.flushHistory(true);
            this.runCommand("partners-" + action, function () {
                var next = items.slice();
                if (action === "add") next.push({ name: "", url: "", logo: "" });
                else if (action === "remove") next.splice(index, 1);
                else { var target = index + (action === "up" ? -1 : 1); next.splice(target, 0, next.splice(index, 1)[0]); }
                node.data.partner_items = next;
            });
            this.selectedHomeField = "";
            this.selectedHomeColumn = "";
            this.flushHistory(true);
        },
        setPartnerField(index, key, value) {
            if (!this.isPartnersBlock() || !this.selEl.data.partners_custom || !Number.isInteger(index)
                || !this.partnerItems()[index] || !["name", "url", "logo"].includes(key)) return;
            var node = this.selEl;
            this.runCommand("partners-field", function () { node.data.partner_items[index][key] = value; });
        },
        replacePartnerLogo(index) {
            if (!this.isPartnersBlock() || !this.selEl.data.partners_custom) return;
            var node = this.selEl, item = this.partnerItems()[index], self = this;
            if (!item) return;
            this.openMedia(function (url) {
                if (self.selEl !== node || !node.data.partners_custom) return;
                var current = node.data.partner_items.indexOf(item);
                if (current >= 0) self.setPartnerField(current, "logo", url);
            });
        },
        homeContentSource() {
            var node = this.selEl;
            if (!node) return null;
            var key = node.type === 'home-block' ? (node.data || {}).block_type : node.type;
            return Object.prototype.hasOwnProperty.call(this.homeSourceLinks || {}, key) ? this.homeSourceLinks[key] : null;
        },
        homeContentField(key) {
            return fieldState(this.selEl, key, (this.homeFieldSeeds || {}).about);
        },
        homeContentPlaceholder(ctrl) {
            var state = this.homeContentField(ctrl.key);
            return state && state.inherited && state.value ? state.value : (ctrl.placeholder || "");
        },
        homeContentImageValue(key) {
            var state = this.homeContentField(key);
            return state ? state.value : String(((this.selEl || {}).data || {})[key] || "");
        },
        setHomeContentImage(key, url, discrete) {
            var node = this.selEl;
            if (!isImage(node, key) || typeof url !== "string") return;
            var previousUrl = String(node.data[key] || "");
            if (previousUrl === url) return;
            var self = this;
            if (discrete !== false && typeof this.flushHistory === "function") this.flushHistory(true);
            this.runCommand("set-home-content-image", function () {
                if (node.data.block_type === "cta" && key === "bg_image"
                    && typeof self.clearMatchingHomeBackgroundCopies === "function") {
                    self.clearMatchingHomeBackgroundCopies(previousUrl, node.data, key);
                    self.clearMatchingHomeBackgroundCopies(url, node.data, key);
                }
                node.data[key] = url;
            });
            if (discrete !== false && typeof this.flushHistory === "function") this.flushHistory(true);
        },
        inheritHomeContentField(key) {
            var state = this.homeContentField(key), node = this.selEl;
            if (!state || state.inherited) return;
            // A reset is a discrete action, not part of the preceding typing batch.
            this.flushHistory(true);
            this.runCommand("inherit-home-content-field", function () { node.data[key] = ""; });
            this.flushHistory(true);
        },
        setHomeContentGroup(group) {
            if (!["content", "media", "layout", "more"].includes(group)) return;
            this.homeContentGroup = group;
            this.selectedHomeField = "";
            this.selectedHomeColumn = "";
        },
        openHomeContentGroup(group) {
            if (group === "media" && supports(this.selEl) && this.selEl.data.block_type === "cta") {
                this.openContentParent("background");
                return;
            }
            this.setHomeContentGroup(group);
        },
        replaceHomeContentImage(key) {
            var node = this.selEl;
            if (!isImage(node, key)) return;
            var self = this;
            this.openMedia(function (url) {
                if (self.selEl !== node) return;
                methods.setHomeContentImage.call(self, key, url);
            }, node.data.block_type === "cta" ? { usage: "cta", source: "official" } : {});
        },
    };

    var api = { supports: supports, groupFor: groupFor, tabFor: tabFor, controls: controls, isImage: isImage, fieldState: fieldState, methods: methods };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxHomeContentPanel = api;
})(typeof window !== "undefined" ? window : globalThis);
