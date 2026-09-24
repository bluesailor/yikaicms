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
