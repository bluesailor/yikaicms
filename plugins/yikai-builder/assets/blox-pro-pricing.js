/* Yikai Builder Pro 作者端模块：价格方案元素的套餐编辑方法，以 `...window.BloxPricingControl.methods` 混入编辑器。
 * 价格方案的渲染、按月/按年切换脚本与保存校验留在核心；未加载本模块时编辑器不提供价格方案编辑。 */
(function (global) {
    'use strict';
    var methods = {
        /** 当前价格方案元素的套餐列表（缺省用元素 schema 的种子套餐） */
        pricingPlans(el) {
            var node = el || this.selEl;
            if (!node || node.type !== "pricing-table") return [];
            var control = (this.elSchema("pricing-table").controls || []).find(function (item) { return item.key === "plans"; }) || {};
            var source = Array.isArray((node.data || {}).plans) ? node.data.plans : (control.default || []);
            var fields = ["name", "badge", "price", "price_yearly", "period", "period_yearly", "description", "features", "button_text", "button_url"];
            return source.slice(0, Math.max(1, Number(control.max) || 6)).filter(function (item) {
                return item && typeof item === "object";
            }).map(function (item) {
                var plan = { featured: !!item.featured && item.featured !== "0" };
                fields.forEach(function (key) { plan[key] = item[key] == null ? "" : String(item[key]); });
                return plan;
            });
        },

        pricingPlanMax() {
            var control = (this.elSchema("pricing-table").controls || []).find(function (item) { return item.key === "plans"; }) || {};
            return Math.max(1, Math.min(6, Number(control.max) || 6));
        },

        storePricingPlans(plans) {
            if (!this.selEl || this.selEl.type !== "pricing-table") return;
            this.selEl.data = this.selEl.data && typeof this.selEl.data === "object" ? this.selEl.data : {};
            this.selEl.data.plans = plans;
        },

        setPricingPlan(index, field, value) {
            var plans = this.pricingPlans(), position = Number(index);
            if (!plans[position]) return;
            if (field === "featured") {
                plans[position].featured = !!value;
            } else if (Object.prototype.hasOwnProperty.call(plans[position], field)) {
                plans[position][field] = String(value == null ? "" : value);
            } else {
                return;
            }
            this.storePricingPlans(plans);
        },

        addPricingPlan(text) {
            var plans = this.pricingPlans();
            if (plans.length >= this.pricingPlanMax()) return;
            var last = plans[plans.length - 1] || {};
            plans.push({
                name: text, badge: "", price: "", price_yearly: "", period: last.period || "", period_yearly: last.period_yearly || "",
                description: "", features: "", button_text: last.button_text || "", button_url: last.button_url || "", featured: false,
            });
            this.storePricingPlans(plans);
        },

        deletePricingPlan(index) {
            var plans = this.pricingPlans(), position = Number(index);
            if (plans.length <= 1 || !plans[position]) return;
            plans.splice(position, 1);
            this.storePricingPlans(plans);
        },

        movePricingPlan(index, delta) {
            var plans = this.pricingPlans(), from = Number(index), to = from + Number(delta);
            if (!plans[from] || to < 0 || to >= plans.length) return;
            plans.splice(to, 0, plans.splice(from, 1)[0]);
            this.storePricingPlans(plans);
        },
    };
    global.BloxPricingControl = { methods: methods };
    if (typeof module !== 'undefined' && module.exports) module.exports = global.BloxPricingControl;
})(typeof window === 'undefined' ? globalThis : window);
