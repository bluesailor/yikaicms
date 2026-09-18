<?php
declare(strict_types=1);
?>
            setControlValue(ctrl, value) {
                if (!this.selEl) return;
                if (this.homeMode && this.selEl.type === "home-block" && ctrl.key === "block_type" && value === "about") {
                    this.convertHomeAbout(true);
                    return;
                }
                this.prepareBannerControlEdit(ctrl.key, value);
                var oldLabel = String((this.selEl.data || {}).label || "");
                this.selEl.data[ctrl.key] = ctrl.responsive && window.BloxResponsive
                    ? window.BloxResponsive.setFor(
                        this.selEl.data[ctrl.key],
                        this.previewDevice,
                        value,
                        this.controlOptions(ctrl),
                        ctrl.default ?? ""
                    )
                    : value;
                if (this.selEl.type === "list-dynamic" && ctrl.key === "query_source") {
                    this.normalizeSourceControls();
                }
                if (this.selEl.type === "home-block" && ctrl.key === "block_type") {
                    var defaultLabel = String(((this.elSchema("home-block").defaults || {}).label) || "");
                    if (!oldLabel || oldLabel === "首页区块" || oldLabel === defaultLabel) {
                        this.selEl.data.label = this.homeBlockSourceLabel();
                    }
                }
            },

            convertingHomeAbout: false,
            async convertHomeAbout(useSiteDefaults = false) {
                if (this.convertingHomeAbout || !this.selEl || this.selEl.type !== "home-block") return;
                var node = this.selEl;
                var si = this.selectedSi, ci = this.selectedCi, ei = this.selectedEi;
                var section = this.sections[si];
                // A nested reference cannot safely be promoted into a top-level section.
                if (!section || !section.columns[ci] || section.columns[ci].elements[ei] !== node) return;
                var before = this.historyData();
                var data = useSiteDefaults ? { block_type: "about", enabled: true } : JSON.parse(JSON.stringify(node.data || {}));
                if (data.block_type !== "about") return;
                this.convertingHomeAbout = true;
                try {
                    var body = new FormData();
                    body.append("action", "convert_about");
                    body.append("_token", this.csrf);
                    body.append("block_data", JSON.stringify(data));
                    var response = await fetch(this.endpoint, { method: "POST", body: body });
                    var result = await response.json();
                    if (!response.ok || !result || Number(result.code) !== 0 || !result.data || !result.data.section) {
                        throw new Error((result && result.msg) || this.homeText.actionFailed);
                    }
                    if (this.historyData() !== before) {
                        this.toast(<?= json_encode(__('blox_save_conflict'), JSON_UNESCAPED_UNICODE) ?>);
                        return;
                    }
                    this.runCommand("convert-home-about", function () {
                        var nativeSection = result.data.section;
                        var targetSi = si;
                        if (section.columns.length === 1 && section.columns[0].elements.length === 1) {
                            nativeSection.id = section.id;
                            nativeSection.name = section.name || nativeSection.name;
                            nativeSection.settings = Object.assign({}, section.settings || {}, nativeSection.settings);
                            this.sections.splice(si, 1, nativeSection);
                        } else {
                            section.columns[ci].elements.splice(ei, 1);
                            targetSi = si + 1;
                            this.sections.splice(targetSi, 0, nativeSection);
                        }
                        this.selectSection(targetSi);
                    });
                } catch (error) {
                    this.toast(error.message || this.homeText.actionFailed);
                } finally {
                    this.convertingHomeAbout = false;
                }
            },
