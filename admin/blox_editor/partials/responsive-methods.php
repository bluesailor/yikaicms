            responsiveDeviceKey() {
                return window.BloxResponsive
                    ? window.BloxResponsive.deviceKey(this.previewDevice)
                    : ({ desktop: "d", tablet: "t", mobile: "m" }[this.previewDevice] || "d");
            },

            responsiveState(value, options, fallback, device) {
                if (!window.BloxResponsive) {
                    return { device: "d", value: fallback, source: "d", overridden: false, inherited: false };
                }
                return window.BloxResponsive.stateFor(
                    value,
                    device || this.previewDevice,
                    options,
                    fallback
                );
            },

            responsiveStatusText(state) {
                if (!state || state.device === "d") return "";
                if (state.overridden) return this.responsiveText.override;
                return state.source === "t"
                    ? this.responsiveText.inheritsTablet
                    : this.responsiveText.inheritsDesktop;
            },

            selectedResponsiveOverrideCount(device) {
                if (device === "desktop") return 0;
                if (this.selEl) {
                    var self = this;
                    return (this.elSchema(this.selEl.type).controls || []).filter(function (control) {
                        return control.responsive && self.controlResponsiveState(control, device).overridden;
                    }).length;
                }
                if (this.sel && this.selectedEi < 0 && this.selLayer === "sec") {
                    return [
                        this.sectionResponsiveState("padding", "md", device),
                        this.sectionResponsiveState("gap", "lg", device),
                    ].filter(function (state) { return state.overridden; }).length;
                }
                return 0;
            },

            /** 档位名 + 像素范围，如「平板 768–1023px」 */
            responsiveDeviceRangeLabel(device) {
                var item = this.devices.find(function (candidate) { return candidate.key === device; });
                var label = item ? item.label : device;
                return window.BloxResponsive && window.BloxResponsive.rangeLabel
                    ? label + " " + window.BloxResponsive.rangeLabel(device)
                    : label;
            },

            /** 当前预览宽度落在哪一档（与编辑档位可能不同） */
            previewWidthDevice() {
                return window.BloxResponsive && window.BloxResponsive.deviceForWidth
                    ? window.BloxResponsive.deviceForWidth(this.previewEffectiveWidth())
                    : this.previewDevice;
            },

            responsiveDeviceTitle(device) {
                var label = this.responsiveDeviceRangeLabel(device);
                if (device === "desktop" || (!this.selEl && !(this.sel && this.selectedEi < 0 && this.selLayer === "sec"))) {
                    return label;
                }
                var count = this.selectedResponsiveOverrideCount(device);
                var status = count > 0
                    ? this.responsiveText.summaryOverrides.replace(":count", count)
                    : this.responsiveText.summaryInherit;
                return label + " · " + status;
            },

            controlResponsiveState(ctrl, device) {
                var value = this.selEl && this.selEl.data
                    ? this.selEl.data[ctrl.key]
                    : (ctrl.default ?? "");
                value = value === undefined || value === null || value === "" ? (ctrl.default ?? "") : value;
                return this.responsiveState(
                    value,
                    this.controlOptions(ctrl),
                    ctrl.default ?? "",
                    device
                );
            },

