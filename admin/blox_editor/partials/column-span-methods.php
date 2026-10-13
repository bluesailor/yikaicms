<?php

declare(strict_types=1);
?>
            // 响应式跨度 {d:桌面, t:平板, m:手机, w:宽屏}：存储标量优先——只有差异档才升级为对象，
            // 存量文档保持标量形态不被改写（黄金对拍同款纪律）。
            // m 缺省=整行堆叠（不是继承桌面）；w 缺省继承桌面，等值不落盘。
            rawSpanD(col) {
                if (!col || col.span == null) return 0;
                if (typeof col.span === "object") return parseInt(col.span.d || 0, 10) || 0;
                return parseInt(col.span || 0, 10) || 0;
            },

            rawSpanT(col) {
                if (!col || col.span == null || typeof col.span !== "object") return null;
                var t = parseInt(col.span.t || 0, 10);
                return t >= 1 && t <= 12 ? t : null;
            },

            rawSpanM(col) {
                if (!col || col.span == null || typeof col.span !== "object") return null;
                var m = parseInt(col.span.m || 0, 10);
                return m >= 1 && m <= 12 ? m : null;
            },

            rawSpanW(col) {
                if (!col || col.span == null || typeof col.span !== "object") return null;
                var w = parseInt(col.span.w || 0, 10);
                return w >= 1 && w <= 12 ? w : null;
            },

            // 第 4、5 参可省略（undefined = 保留列上已有的 m/w 档），传 null 表示清除该档。
            writeSpan(col, d, t, m, w) {
                d = Math.min(12, Math.max(1, parseInt(d, 10) || 12));
                t = t == null ? null : Math.min(12, Math.max(1, parseInt(t, 10) || d));
                if (m === undefined) m = this.rawSpanM(col);
                if (w === undefined) w = this.rawSpanW(col);
                m = m == null ? null : Math.min(12, Math.max(1, parseInt(m, 10) || 12));
                w = w == null ? null : Math.min(12, Math.max(1, parseInt(w, 10) || d));
                if (w === d) w = null;
                if ((t === null || t === d) && m === null && w === null) {
                    col.span = d;
                    return;
                }
                var span = { d: d };
                if (t !== null && t !== d) span.t = t;
                if (m !== null) span.m = m;
                if (w !== null) span.w = w;
                col.span = span;
            },

            columnSpan(col) {
                var span = this.rawSpanD(col);
                if (!span) {
                    var s = this.sel;
                    span = s && s.columns && s.columns.length ? Math.floor(12 / Math.max(1, s.columns.length)) : 12;
                }
                return Math.min(12, Math.max(1, span));
            },

            columnSpanT(col) {
                return this.rawSpanT(col);
            },

            columnSpanM(col) {
                return this.rawSpanM(col);
            },

            columnSpanW(col) {
                return this.rawSpanW(col);
            },

            setColumnSpan(span) {
                var col = this.selectedCol();
                if (!col) return;
                this.writeSpan(col, span, this.rawSpanT(col));
                if (this.rawSpanD(col) >= 12) delete col.position;
            },

            // 结构树逐区块标列宽：没存宽度时按这一区块自己的列数平分，余数给前几列（与画布 data-yk-col-span 一致）；
            // columnSpan() 的兜底只看选中的区块，放在树里会标错
            columnSpanIn(section, ci) {
                var columns = section && Array.isArray(section.columns) ? section.columns : [];
                var span = this.rawSpanD(columns[ci]);
                if (!span) {
                    var n = Math.max(1, columns.length);
                    span = Math.floor(12 / n) + (ci < 12 % n ? 1 : 0);
                }
                return Math.min(12, Math.max(1, span));
            },

            // 只有一列、宽度又不足整行（2.1.0）：渲染器按宽度占位，面板相应给出位置与各设备宽度
            singleColumnNarrow() {
                var s = this.sel;
                if (!s || !Array.isArray(s.columns) || s.columns.length !== 1) return false;
                var d = this.rawSpanD(s.columns[0]);
                return d >= 1 && d < 12;
            },

            columnSizingVisible() {
                var s = this.sel;
                return !!(s && Array.isArray(s.columns) && (s.columns.length > 1 || this.singleColumnNarrow()));
            },

            // 宽度按钮与下拉的文字：3 → "1/4 · 25%"，12 → 「满宽」
            spanFraction(n) {
                n = Math.min(12, Math.max(1, parseInt(n, 10) || 12));
                var a = n, b = 12;
                while (b) { var r = a % b; a = b; b = r; }
                return (n / a) + "/" + (12 / a);
            },

            spanOptionLabel(n) {
                n = Math.min(12, Math.max(1, parseInt(n, 10) || 12));
                if (n === 12) return <?= $jt('blox_col_full_width') ?>;
                return this.spanFraction(n) + " · " + Math.round(n / 12 * 100) + "%";
            },

            columnPosition() {
                var col = this.selectedCol();
                return col && (col.position === "center" || col.position === "right") ? col.position : "left";
            },

            setColumnPosition(position) {
                var col = this.selectedCol();
                if (!col) return;
                if (position === "center" || position === "right") col.position = position;
                else delete col.position;
            },

            setColumnSpanT(t) {
                var col = this.selectedCol();
                if (!col) return;
                this.writeSpan(col, this.columnSpan(col), t === "" ? null : t);
            },

            setColumnSpanM(m) {
                var col = this.selectedCol();
                if (!col) return;
                this.writeSpan(col, this.columnSpan(col), this.rawSpanT(col), m === "" ? null : m);
            },

            setColumnSpanW(w) {
                var col = this.selectedCol();
                if (!col) return;
                this.writeSpan(col, this.columnSpan(col), this.rawSpanT(col), undefined, w === "" ? null : w);
            },
