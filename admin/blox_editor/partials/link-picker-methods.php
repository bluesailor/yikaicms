<?php
declare(strict_types=1);
require_once ROOT_PATH . '/includes/builder/BloxLinkCatalog.php';
// 链接选择器：候选清单在页面渲染时按被编辑内容的语言生成，前端本地搜索（见 BloxLinkCatalog）
?>
            linkPickerTarget: "",
            linkPickerSearch: "",
            linkCatalog: <?= json_encode(BloxLinkCatalog::build($bloxContentLanguage), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            linkGroupLabels: <?= json_encode([
                'page' => __('blox_link_group_page'),
                'channel' => __('blox_link_group_channel'),
                'content' => __('blox_link_group_content'),
                'product' => __('blox_link_group_product'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            openLinkPicker(id) {
                var target = (this.selEl ? this.selEl.id : '') + ':' + id;
                this.linkPickerTarget = this.linkPickerTarget === target ? '' : target;
                this.linkPickerSearch = '';
            },
            /**
             * 下拉默认右对齐到「选择页面」按钮、宽 18rem；按钮不在面板最右时左侧会伸出设置面板被裁掉。
             * 下拉自己的 x-effect 在打开、x-show 生效后（$nextTick）调用：按所在滚动容器（或侧栏）收窄并平移回可视范围内。
             */
            placeLinkPicker(menu, attempt) {
                if (!menu) return;
                if (menu.offsetParent === null) {
                    // x-show 可能还没把它显示出来：再等几帧（最多 10 帧），不在隐藏状态下量尺寸
                    var self = this;
                    if ((attempt || 0) < 10) window.requestAnimationFrame(function () { self.placeLinkPicker(menu, (attempt || 0) + 1); });
                    return;
                }
                menu.style.right = '';
                menu.style.width = '';
                var container = (menu.parentElement && menu.parentElement.closest('.overflow-y-auto, .overflow-auto, aside')) || document.documentElement;
                var bounds = container.getBoundingClientRect();
                var gutter = 8;
                var maxWidth = Math.max(160, bounds.width - gutter * 2);
                if (menu.offsetWidth > maxWidth) menu.style.width = maxWidth + 'px';
                var rect = menu.getBoundingClientRect();
                var shift = 0;
                if (rect.left < bounds.left + gutter) shift = bounds.left + gutter - rect.left;
                else if (rect.right > bounds.right - gutter) shift = bounds.right - gutter - rect.right;
                if (shift) menu.style.right = (-shift) + 'px';
            },
            linkPickerOpen(id) {
                return this.linkPickerTarget === (this.selEl ? this.selEl.id : '') + ':' + id;
            },
            linkPickerGroups() {
                var needle = String(this.linkPickerSearch || '').trim().toLowerCase();
                var groups = [];
                var byKey = {};
                var labels = this.linkGroupLabels;
                this.linkCatalog.forEach(function (item) {
                    if (needle && (item.title + ' ' + item.url).toLowerCase().indexOf(needle) === -1) return;
                    if (!byKey[item.group]) {
                        byKey[item.group] = { key: item.group, label: labels[item.group] || item.group, items: [] };
                        groups.push(byKey[item.group]);
                    }
                    // 每组最多列 50 条，其余靠搜索找到，避免下拉框长到翻不完
                    if (byKey[item.group].items.length < 50) byKey[item.group].items.push(item);
                });
                return groups;
            },
            pickLink(setter, url) {
                setter(url);
                this.linkPickerTarget = '';
            },
