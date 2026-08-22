<?php

declare(strict_types=1);
?>
    <!-- ===== 三栏主体 ===== -->
    <div class="flex" style="height: calc(100vh - 3.5rem);">

        <?php // 左栏（Bricks 式）：元素库 ↔ 设置 同容器切换。选中区块/元素自动进设置，
              // 「＋ 元素」把 libOpen 置真强制回元素库；结构树移右栏常驻。 ?>
        <aside class="blox-mobile-panel w-72 shrink-0 bg-white border-r border-gray-200 flex flex-col" :class="mobilePanel === 'library' || mobilePanel === 'settings' ? 'is-open' : ''">

            <!-- ── 元素库（无选中或 libOpen） ── -->
            <div x-show="!sel || libOpen" class="flex-1 flex flex-col min-h-0">
                <div class="h-10 px-3 flex items-center justify-between border-b border-gray-100 shrink-0">
                    <span class="text-xs font-semibold text-gray-500 tracking-wide inline-flex items-center gap-1">
                        <i class="ti ti-category text-sm"></i><?= __('blox_element_library') ?>
                    </span>
                    <button type="button" x-show="sel && libOpen" @click="libOpen = false"
                            class="text-[10px] text-gray-400 hover:text-blue-500 inline-flex items-center gap-0.5">
                        <i class="ti ti-arrow-back-up text-xs"></i><?= __('blox_back_to_settings') ?>
                    </button>
                </div>
                <div class="p-2 border-b border-gray-100 shrink-0">
                    <div class="relative">
                        <i class="ti ti-search text-sm text-gray-300 absolute left-2 top-1/2 -translate-y-1/2"></i>
                        <input type="text" x-ref="libSearch" x-model="libQuery" placeholder="<?= e(__('blox_search_elements')) ?>"
                               class="w-full border border-gray-200 rounded pl-7 pr-2 py-1.5 text-xs">
                    </div>
                                        <?php // 插入目标：空白页可直接点元素，已有区块但未选中时才提示选择目标 ?>
                    <template x-if="sections.length === 0">
                        <p class="text-[10px] text-blue-500 mt-1.5 leading-relaxed">
                            <?= __('blox_blank_auto_section') ?>
                        </p>
                    </template>
                    <template x-if="selectedSi < 0 && sections.length > 0">
                        <p class="text-[10px] text-amber-600 mt-1.5 leading-relaxed">
                            <?= __('blox_pick_section_first') ?>
                        </p>
                    </template>
                    <template x-if="selTopEl && elSchema(selTopEl.type).container && (!isHomeBlockHost(selTopEl) || isHomeBannerHost(selTopEl))">
                        <p class="text-[10px] text-blue-500 mt-1.5 leading-relaxed">
                            <i class="ti ti-corner-down-right"></i> <?= __('blox_insert_into_container') ?>
                        </p>
                    </template>
                    <template x-if="sel && sel.columns.length > 1">
                        <div class="mt-2">
                            <div class="text-[10px] text-gray-400 mb-1"><?= __('blox_insert_which_column') ?></div>
                            <div class="flex gap-1">
                                <template x-for="(col, ci) in sel.columns" :key="col.id">
                                    <button type="button" @click="targetCi = ci"
                                            class="flex-1 h-7 rounded text-[11px] border transition"
                                            :class="colIndex() === ci ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                            x-text="<?= e($jt('blox_col_word')) ?>.replace(':n', ci + 1)"></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <?php // pb-24：滚动到底时给最后一排瓦片留出充足空位（用户 2026-08-20 反馈 pb-16 仍紧） ?>
                <div class="flex-1 overflow-y-auto blox-scroll p-2 pb-24">
                    <template x-for="grp in filteredLib()" :key="grp.cat">
                        <div class="mb-3">
                            <?php // 分类标题可折叠（Bricks 式）；搜索时忽略折叠态全部展开 ?>
                            <button type="button" @click="catOpen[grp.cat] = !isCatOpen(grp.cat)"
                                    class="w-full flex items-center justify-between px-1 mb-1.5 text-[11px] font-medium text-gray-500 hover:text-gray-700">
                                <span x-text="grp.label"></span>
                                <i class="ti ti-chevron-down text-xs transition-transform" :class="isCatOpen(grp.cat) || libQuery.trim() ? '' : '-rotate-90'"></i>
                            </button>
                            <div x-show="isCatOpen(grp.cat) || libQuery.trim()" class="grid grid-cols-2 gap-1.5">
                                <template x-for="el in grp.items" :key="el.type">
                                    <?php // 点击=插入到选中目标；拖拽=拖到结构树或画布的目标位置（路线图③）。
                                          // 拖拽自带目标，所以瓦片不再因未选中而禁用 ?>
                                    <button type="button" @click="addElement(el)" :data-testid="'blox-add-element-' + el.type"
                                            draggable="true"
                                            @dragstart="dragEl = el; $event.dataTransfer.effectAllowed = 'copy'; $event.dataTransfer.setData('application/x-yikai-blox', JSON.stringify({version: 1, source: 'palette', type: el.type})); $event.dataTransfer.setData('text/plain', el.type); canvasBridge().post({ ykDragType: el.type })"
                                            @dragend="dragEl = null; dragOver = ''; canvasBridge().post({ ykDragType: '' })"
                                            class="h-16 rounded-md border border-gray-200 text-gray-700 hover:border-blue-400 hover:text-blue-500 hover:bg-blue-50/40 transition flex flex-col items-center justify-center gap-1 cursor-grab active:cursor-grabbing"
                                            :title="el.label + <?= e($jt('blox_el_insert_hint')) ?>">
                                        <i class="ti text-lg" :class="'ti-' + el.icon"></i>
                                        <span class="text-[11px] leading-none" x-text="el.label"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                    <template x-if="filteredLib().length === 0">
                        <p class="text-xs text-gray-400 text-center py-8"><?= __('blox_no_matching_elements') ?></p>
                    </template>
                    <p class="text-[10px] text-gray-400 leading-relaxed border-t border-gray-100 pt-2 mt-1">
                        <?= __('blox_library_hint') ?>
                        <?= __('blox_banner_hint') ?>
                    </p>
                </div>
            </div>

            <!-- ── 设置（选中区块/元素且未打开元素库） ── -->
            <div x-show="sel && !libOpen" class="flex-1 flex flex-col min-h-0">
                <div class="h-10 px-3 flex items-center gap-2 border-b border-gray-100 shrink-0">
                    <span class="text-xs font-semibold text-gray-500 tracking-wide inline-flex items-center gap-1 min-w-0">
                        <i class="ti ti-adjustments text-sm shrink-0"></i>
                        <span class="truncate" x-text="panelTitle()"></span>
                    </span>
                    <?php // 元素设置里给「回到区块」出口，否则选了元素就没法改区块本身 ?>
                    <button type="button"
                            x-show="selEl && selEl.type === 'home-banner-item'"
                            @click="selectElement(selectedSi, selectedCi, selectedEi)"
                            data-testid="blox-banner-overall-settings"
                            class="text-[10px] text-amber-600 hover:text-amber-700 inline-flex items-center gap-0.5 shrink-0">
                        <i class="ti ti-arrow-left text-xs"></i><?= __('blox_banner_overall_settings') ?>
                    </button>
                    <button type="button" x-show="selEl && selEl.type !== 'home-banner-item'" @click="selectSection(selectedSi)"
                            class="text-[10px] text-gray-400 hover:text-blue-500 inline-flex items-center gap-0.5 shrink-0">
                        <i class="ti ti-arrow-back-up text-xs"></i><?= __('blox_section_label') ?>
                    </button>
                    <button type="button" @click="libOpen = true" data-testid="blox-library-open"
                            class="ml-auto shrink-0 text-xs font-medium text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2.5 py-1 inline-flex items-center gap-1">
                        <i class="ti ti-plus text-sm"></i><?= __('blox_element_label') ?>
                    </button>
                </div>

                <!-- 内容 / 样式 页签 -->
                <div class="flex items-stretch border-b border-gray-100 shrink-0">
                    <button type="button" @click="panelTab = 'content'" data-testid="blox-content-tab"
                            class="flex-1 h-9 text-xs font-semibold border-b-2 transition"
                            :class="panelTab === 'content' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-600'"><?= __('blox_tab_content') ?></button>
                    <button type="button" @click="panelTab = 'style'" data-testid="blox-style-tab"
                            class="flex-1 h-9 text-xs font-semibold border-b-2 transition"
                            :class="panelTab === 'style' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-600'"><?= __('blox_tab_style') ?></button>
                    <button type="button" x-show="advancedMode && (selEl || (sel && !selectedSectionField && selLayer === 'sec'))"
                            @click="panelTab = 'condition'" data-testid="blox-condition-tab"
                            class="flex-1 h-9 text-xs font-semibold border-b-2 transition"
                            :class="panelTab === 'condition' ? 'border-violet-500 text-violet-600' : 'border-transparent text-gray-400 hover:text-gray-600'">
                        <?= __('blox_tab_conditions') ?>
                    </button>
                </div>

                <?php // 设置搜索 + 只看已修改：仅元素设置（数据驱动才筛得动）；区块设置项少不筛 ?>
                <div x-show="selEl && panelTab !== 'condition'" class="p-2 border-b border-gray-100 shrink-0 flex items-center gap-1">
                    <div class="relative flex-1">
                        <i class="ti ti-search text-sm text-gray-300 absolute left-2 top-1/2 -translate-y-1/2"></i>
                        <input type="text" x-model="ctrlQuery" placeholder="<?= e(__('blox_search_settings')) ?>"
                               class="w-full border border-gray-200 rounded pl-7 pr-2 py-1.5 text-xs">
                    </div>
                    <button type="button" @click="modifiedOnly = !modifiedOnly" title="<?= e(__('blox_modified_only')) ?>"
                            class="w-7 h-7 rounded border inline-flex items-center justify-center transition shrink-0"
                            :class="modifiedOnly ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-400 hover:text-gray-600'">
                        <i class="ti ti-adjustments-check text-sm"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto blox-scroll p-4">
                    <template x-if="panelTab === 'content' && isNavigationElementSelected()">
                        <div class="mb-4 space-y-3 border-b border-gray-100 pb-4" data-testid="blox-navigation-quick-settings">
                            <div>
                                <div class="mb-1.5 text-[10px] font-semibold text-gray-500"><?= e(__('blox_nav_type')) ?></div>
                                <div class="grid grid-cols-2 rounded border border-gray-200 bg-gray-50 p-0.5">
                                    <button type="button" @click="switchNavigationType('nav')"
                                            data-testid="blox-nav-type-normal"
                                            class="h-8 min-w-0 rounded-sm px-2 text-[11px] font-medium inline-flex items-center justify-center gap-1.5 transition"
                                            :class="selEl && selEl.type === 'nav' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                                        <i class="ti ti-menu-2 text-sm shrink-0"></i>
                                        <span class="truncate"><?= e(__('blox_nav_type_normal')) ?></span>
                                    </button>
                                    <button type="button" @click="switchNavigationType('nav-mega')"
                                            data-testid="blox-nav-type-mega"
                                            class="h-8 min-w-0 rounded-sm px-2 text-[11px] font-medium inline-flex items-center justify-center gap-1.5 transition"
                                            :class="selEl && selEl.type === 'nav-mega' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                                        <i class="ti ti-layout-navbar-expand text-sm shrink-0"></i>
                                        <span class="truncate"><?= e(__('blox_nav_type_mega')) ?></span>
                                    </button>
                                </div>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[10px] font-semibold text-gray-500"><?= e(__('blox_element_order')) ?></span>
                                <div class="inline-flex rounded border border-gray-200 bg-white overflow-hidden">
                                    <button type="button" @click="moveSelectedElement(-1)" :disabled="!canMoveSelectedElement(-1)"
                                            data-testid="blox-selected-element-up"
                                            class="w-8 h-8 inline-flex items-center justify-center text-gray-500 hover:bg-gray-50 hover:text-blue-600 disabled:opacity-25 disabled:pointer-events-none"
                                            title="<?= e(__('blox_ctx_move_up')) ?>" aria-label="<?= e(__('blox_ctx_move_up')) ?>">
                                        <i class="ti ti-arrow-up text-sm"></i>
                                    </button>
                                    <button type="button" @click="moveSelectedElement(1)" :disabled="!canMoveSelectedElement(1)"
                                            data-testid="blox-selected-element-down"
                                            class="w-8 h-8 border-l border-gray-200 inline-flex items-center justify-center text-gray-500 hover:bg-gray-50 hover:text-blue-600 disabled:opacity-25 disabled:pointer-events-none"
                                            title="<?= e(__('blox_ctx_move_down')) ?>" aria-label="<?= e(__('blox_ctx_move_down')) ?>">
                                        <i class="ti ti-arrow-down text-sm"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template x-if="panelTab === 'condition' && conditionTarget()">
                        <div class="space-y-3" data-testid="blox-condition-editor">
                            <div class="rounded border border-violet-200 bg-violet-50/60 p-3">
                                <div class="flex items-start gap-2">
                                    <i class="ti ti-adjustments-code text-base text-violet-600 mt-0.5"></i>
                                    <p class="text-[10px] leading-relaxed text-gray-500" x-text="conditionText.hint"></p>
                                </div>
                            </div>

                            <template x-if="conditionGroups().length === 0">
                                <button type="button" @click="addConditionGroup()" data-testid="blox-condition-empty-add"
                                        class="w-full min-h-24 rounded border-2 border-dashed border-gray-200 text-gray-400 hover:border-violet-300 hover:text-violet-600 inline-flex flex-col items-center justify-center gap-2 transition">
                                    <i class="ti ti-adjustments-plus text-xl"></i>
                                    <span class="text-xs" x-text="conditionText.empty"></span>
                                </button>
                            </template>

                            <template x-for="(group, groupIndex) in conditionGroups()" :key="groupIndex">
                                <div>
                                    <div x-show="groupIndex > 0" class="flex items-center gap-2 py-1.5">
                                        <span class="h-px flex-1 bg-gray-200"></span>
                                        <span class="text-[10px] font-semibold text-violet-500" x-text="conditionText.or"></span>
                                        <span class="h-px flex-1 bg-gray-200"></span>
                                    </div>
                                    <div class="rounded border border-gray-200 bg-white overflow-hidden" :data-testid="'blox-condition-group-' + groupIndex">
                                        <div class="h-8 px-2.5 flex items-center border-b border-gray-100 bg-gray-50">
                                            <span class="text-[10px] font-semibold text-gray-500"
                                                  x-text="conditionText.group.replace(':n', groupIndex + 1)"></span>
                                            <button type="button" @click="removeConditionGroup(groupIndex)"
                                                    class="ml-auto w-6 h-6 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 inline-flex items-center justify-center"
                                                    title="<?= e(__('admin_delete')) ?>"><i class="ti ti-trash text-sm"></i></button>
                                        </div>
                                        <div class="p-2.5 space-y-2">
                                            <template x-for="(rule, ruleIndex) in group.rules" :key="ruleIndex">
                                                <div>
                                                    <div x-show="ruleIndex > 0" class="text-center text-[9px] font-semibold text-gray-400 py-0.5"
                                                         x-text="conditionText.and"></div>
                                                    <div class="rounded border border-gray-200 p-2 space-y-1.5" :data-testid="'blox-condition-rule-' + groupIndex + '-' + ruleIndex">
                                                        <div class="flex gap-1.5">
                                                            <select x-model="rule.type" @change="conditionTypeChanged(rule)" data-testid="blox-condition-type"
                                                                    class="min-w-0 flex-1 border border-gray-200 rounded px-1.5 py-1.5 text-[11px] bg-white">
                                                                <option value="login" x-text="conditionText.login"></option>
                                                                <option value="date" x-text="conditionText.date"></option>
                                                                <option value="channel" x-text="conditionText.channel"></option>
                                                                <option value="url" x-text="conditionText.url"></option>
                                                            </select>
                                                            <select x-model="rule.operator" data-testid="blox-condition-operator"
                                                                    class="min-w-0 flex-1 border border-gray-200 rounded px-1.5 py-1.5 text-[11px] bg-white">
                                                                <template x-for="option in conditionOperators(rule.type)" :key="option.value">
                                                                    <option :value="option.value" x-text="option.label"></option>
                                                                </template>
                                                            </select>
                                                            <button type="button" @click="removeConditionRule(groupIndex, ruleIndex)"
                                                                    class="w-7 h-7 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 inline-flex items-center justify-center shrink-0"
                                                                    title="<?= e(__('admin_delete')) ?>"><i class="ti ti-x text-sm"></i></button>
                                                        </div>
                                                        <select x-show="rule.type === 'login'" x-model="rule.value" data-testid="blox-condition-value-login"
                                                                class="w-full border border-gray-200 rounded px-2 py-1.5 text-[11px] bg-white">
                                                            <option value="logged_in" x-text="conditionText.loggedIn"></option>
                                                            <option value="logged_out" x-text="conditionText.loggedOut"></option>
                                                        </select>
                                                        <input x-show="rule.type === 'date'" type="date" x-model="rule.value" data-testid="blox-condition-value-date"
                                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-[11px]">
                                                        <select x-show="rule.type === 'channel'" x-model.number="rule.value" data-testid="blox-condition-value-channel"
                                                                class="w-full border border-gray-200 rounded px-2 py-1.5 text-[11px] bg-white">
                                                            <option value="" x-text="conditionText.selectChannel"></option>
                                                            <template x-for="channel in conditionChannels" :key="channel.value">
                                                                <option :value="channel.value" x-text="channel.label"></option>
                                                            </template>
                                                        </select>
                                                        <input x-show="rule.type === 'url'" type="text" x-model="rule.value" data-testid="blox-condition-value-url"
                                                               :placeholder="conditionText.urlPlaceholder"
                                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-[11px]">
                                                    </div>
                                                </div>
                                            </template>
                                            <button type="button" @click="addConditionRule(groupIndex)" data-testid="blox-condition-add-rule"
                                                    class="w-full h-8 rounded border border-dashed border-violet-200 text-violet-600 hover:bg-violet-50 text-[10px] font-medium inline-flex items-center justify-center gap-1">
                                                <i class="ti ti-plus text-sm"></i><span x-text="conditionText.addRule"></span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <button x-show="conditionGroups().length > 0" type="button" @click="addConditionGroup()"
                                    data-testid="blox-condition-add-group"
                                    class="w-full h-9 rounded border border-violet-200 text-violet-600 hover:bg-violet-50 text-xs font-medium inline-flex items-center justify-center gap-1.5">
                                <i class="ti ti-folders text-sm"></i><span x-text="conditionText.addGroup"></span>
                            </button>
                        </div>
                    </template>

                    <!-- ── 元素设置：按 BuilderRegistry 的 controls() 生成 ── -->
                    <template x-if="selEl && panelTab !== 'condition'">
                        <div class="space-y-4">
                            <?php // 元素重命名：标题即输入框（借鉴思路来自可视化构建器惯例）；
                                  // 存 el.name（blocks_data 顶层扩展键，渲染器只读 type/data 不受影响） ?>
                            <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                                <i class="ti text-base text-blue-500 shrink-0" :class="'ti-' + elIcon(selEl ? selEl.type : '')"></i>
                                <input type="text" :value="selEl ? (selEl.name || '') : ''"
                                       @input="selEl && (selEl.name = $event.target.value)"
                                       :placeholder="selEl ? (elSchema(selEl.type).label || selEl.type) : ''"
                                       title="<?= e(__('blox_el_name_hint')) ?>"
                                       class="flex-1 min-w-0 text-sm font-medium text-gray-700 border-0 border-b border-transparent focus:border-blue-300 outline-none p-0 bg-transparent">
                            </div>

                            <template x-if="selEl && contactManage[selEl.type] && ['contact_cards', 'contact_form'].indexOf(selEl.type) === -1 && panelTab === 'content'">
                                <div data-testid="blox-contact-source" class="rounded border border-emerald-200 bg-emerald-50/60 p-3 space-y-2.5">
                                    <div class="flex items-start gap-2.5">
                                        <span class="w-8 h-8 rounded bg-white border border-emerald-200 text-emerald-600 inline-flex items-center justify-center shrink-0">
                                            <i class="ti text-base" :class="'ti-' + contactManage[selEl.type].icon"></i>
                                        </span>
                                        <p class="text-[10px] leading-relaxed text-gray-500"><?= e(__('page_contact_dynamic_source')) ?></p>
                                    </div>
                                    <a :href="contactManage[selEl.type].url" target="_blank" rel="noopener"
                                       class="w-full h-8 rounded border border-emerald-200 bg-white text-emerald-700 hover:bg-emerald-600 hover:text-white text-xs font-medium inline-flex items-center justify-center gap-1.5 transition">
                                        <i class="ti ti-external-link text-sm"></i>
                                        <span x-text="contactManage[selEl.type].label"></span>
                                    </a>
                                </div>
                            </template>

                            <template x-if="selEl && selEl.type === 'contact_cards' && panelTab === 'content'">
                                <div data-testid="blox-contact-cards-editor" class="space-y-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-xs font-semibold text-gray-700"><?= e(__('setting_contact_cards')) ?></p>
                                            <p class="text-[10px] text-gray-400">
                                                <span x-text="contactCards.length"></span>/4 <?= e(__('contact_cards_unit')) ?>
                                            </p>
                                        </div>
                                        <button type="button" @click="addContactCard()" :disabled="contactCards.length >= 4"
                                                data-testid="blox-contact-card-add"
                                                class="h-7 px-2 rounded border border-blue-200 bg-white text-blue-600 hover:bg-blue-50 disabled:opacity-40 text-[10px] font-medium inline-flex items-center gap-1">
                                            <i class="ti ti-plus text-sm"></i><?= e(__('blox_contact_cards_add')) ?>
                                        </button>
                                    </div>

                                    <template x-if="contactCards.length === 0">
                                        <button type="button" @click="addContactCard()"
                                                class="w-full min-h-20 rounded border border-dashed border-gray-300 text-gray-400 hover:border-blue-300 hover:text-blue-500 text-xs inline-flex flex-col items-center justify-center gap-1.5">
                                            <i class="ti ti-address-book-off text-xl"></i>
                                            <?= e(__('blox_contact_cards_empty')) ?>
                                        </button>
                                    </template>

                                    <div class="space-y-2.5">
                                        <template x-for="(card, cardIndex) in contactCards" :key="card._key">
                                            <div class="rounded border border-gray-200 bg-white overflow-hidden" :data-testid="'blox-contact-card-' + cardIndex">
                                                <div class="h-8 px-2 flex items-center gap-1 border-b border-gray-100 bg-gray-50">
                                                    <span class="w-5 h-5 rounded bg-blue-50 text-blue-600 text-[10px] font-semibold inline-flex items-center justify-center"
                                                          x-text="cardIndex + 1"></span>
                                                    <span class="min-w-0 flex-1 text-[10px] font-medium text-gray-500 truncate"
                                                          x-text="card.label || <?= e($jt('blox_contact_cards_new')) ?>"></span>
                                                    <button type="button" @click="moveContactCard(cardIndex, -1)" :disabled="cardIndex === 0"
                                                            class="w-6 h-6 rounded text-gray-400 hover:text-blue-600 disabled:opacity-25 inline-flex items-center justify-center"
                                                            title="<?= e(__('admin_move')) ?>"><i class="ti ti-arrow-up text-sm"></i></button>
                                                    <button type="button" @click="moveContactCard(cardIndex, 1)" :disabled="cardIndex === contactCards.length - 1"
                                                            class="w-6 h-6 rounded text-gray-400 hover:text-blue-600 disabled:opacity-25 inline-flex items-center justify-center"
                                                            title="<?= e(__('admin_move')) ?>"><i class="ti ti-arrow-down text-sm"></i></button>
                                                    <button type="button" @click="removeContactCard(cardIndex)"
                                                            class="w-6 h-6 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 inline-flex items-center justify-center"
                                                            title="<?= e(__('admin_delete')) ?>"><i class="ti ti-trash text-sm"></i></button>
                                                </div>
                                                <div class="p-2.5 space-y-2">
                                                    <div>
                                                        <label class="block text-[10px] font-medium text-gray-500 mb-1"><?= e(__('contact_icon')) ?></label>
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="w-8 h-8 rounded border border-gray-200 bg-gray-50 text-blue-600 inline-flex items-center justify-center shrink-0">
                                                                <i class="ti text-base" :class="'ti-' + contactCardIcon(card.icon)"></i>
                                                            </span>
                                                            <select x-model="card.icon" @change="contactCardsChanged = true"
                                                                    class="min-w-0 flex-1 border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                                <template x-for="option in contactCardIconOptions" :key="option.value">
                                                                    <option :value="option.value" x-text="option.label"></option>
                                                                </template>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label class="block text-[10px] font-medium text-gray-500 mb-1"><?= e(__('label_title')) ?></label>
                                                        <input type="text" x-model="card.label" @input="contactCardsChanged = true" maxlength="80"
                                                               placeholder="<?= e(__('scontact_card_label_ph')) ?>"
                                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                    </div>
                                                    <div>
                                                        <div class="flex items-center justify-between gap-2 mb-1">
                                                            <label class="text-[10px] font-medium text-gray-500"><?= e(__('contact_value')) ?></label>
                                                            <button type="button" @click="pickContactCardImage(card)"
                                                                    class="text-[10px] text-blue-500 hover:text-blue-600 inline-flex items-center gap-1">
                                                                <i class="ti ti-photo text-xs"></i><?= e(__('admin_media')) ?>
                                                            </button>
                                                        </div>
                                                        <textarea x-model="card.value" @input="contactCardsChanged = true" maxlength="1000" rows="2"
                                                                  placeholder="<?= e(__('scontact_card_value_ph')) ?>"
                                                                  class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs resize-y"></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <button type="button" @click="saveContactCards()"
                                            :disabled="contactCardsSaving || !contactCardsChanged"
                                            data-testid="blox-contact-cards-save"
                                            class="w-full h-9 rounded bg-blue-600 hover:bg-blue-500 disabled:bg-gray-200 disabled:text-gray-400 text-white text-xs font-medium inline-flex items-center justify-center gap-1.5 transition">
                                        <i class="ti text-sm" :class="contactCardsSaving ? 'ti-loader-2 animate-spin' : 'ti-device-floppy'"></i>
                                        <span x-text="contactCardsSaving ? <?= e($jt('blox_saving')) ?> : <?= e($jt('blox_contact_cards_save')) ?>"></span>
                                    </button>

                                    <a :href="contactManage.contact_cards.url" target="_blank" rel="noopener"
                                       class="w-full h-8 rounded border border-gray-200 bg-white text-gray-500 hover:border-blue-200 hover:text-blue-600 text-[10px] inline-flex items-center justify-center gap-1.5">
                                        <i class="ti ti-adjustments text-sm"></i><?= e(__('page_contact_manage_cards')) ?>
                                    </a>
                                </div>
                            </template>

                            <template x-if="selEl && selEl.type === 'contact_form' && panelTab === 'content'">
                                <div data-testid="blox-contact-form-editor" class="space-y-3">
                                    <div class="flex items-start gap-2.5 rounded border border-emerald-200 bg-emerald-50/60 p-3">
                                        <span class="w-8 h-8 rounded bg-white border border-emerald-200 text-emerald-600 inline-flex items-center justify-center shrink-0">
                                            <i class="ti ti-forms text-base"></i>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-xs font-semibold text-gray-700"><?= e(__('blox_contact_form_editor_title')) ?></p>
                                            <p class="mt-0.5 text-[10px] leading-relaxed text-gray-500"><?= e(__('blox_contact_form_editor_desc')) ?></p>
                                        </div>
                                    </div>

                                    <template x-if="!contactFormCanEdit">
                                        <div class="rounded border border-amber-200 bg-amber-50 p-3 text-[10px] leading-relaxed text-amber-700">
                                            <?= e(__('blox_contact_form_permission')) ?>
                                        </div>
                                    </template>

                                    <template x-if="contactFormCanEdit && !contactFormVisual">
                                        <div class="rounded border border-amber-200 bg-amber-50 p-3 space-y-2">
                                            <p class="text-[10px] leading-relaxed text-amber-700"><?= e(__('blox_contact_form_advanced_locked')) ?></p>
                                            <a :href="contactManage.contact_form.url" target="_blank" rel="noopener"
                                               class="w-full h-8 rounded border border-amber-200 bg-white text-amber-700 hover:bg-amber-100 text-[10px] font-medium inline-flex items-center justify-center gap-1.5">
                                                <i class="ti ti-external-link text-sm"></i><?= e(__('page_contact_manage_form')) ?>
                                            </a>
                                        </div>
                                    </template>

                                    <template x-if="contactFormCanEdit && contactFormVisual">
                                        <div class="space-y-3">
                                            <div class="space-y-2">
                                                <div>
                                                    <label class="block text-[10px] font-medium text-gray-500 mb-1"><?= e(__('setting_contact_form_title')) ?></label>
                                                    <input type="text" x-model="contactForm.title" @input="contactFormChanged = true" maxlength="100"
                                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                </div>
                                                <div>
                                                    <label class="block text-[10px] font-medium text-gray-500 mb-1"><?= e(__('setting_contact_form_desc')) ?></label>
                                                    <textarea x-model="contactForm.description" @input="contactFormChanged = true" maxlength="1000" rows="2"
                                                              class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs resize-y"></textarea>
                                                </div>
                                            </div>

                                            <div class="flex items-center justify-between gap-2 pt-1 border-t border-gray-100">
                                                <div>
                                                    <p class="text-[10px] font-semibold text-gray-600"><?= e(__('setting_contact_form_fields')) ?></p>
                                                    <p class="text-[9px] text-gray-400"><span x-text="contactForm.fields.length"></span>/12</p>
                                                </div>
                                                <button type="button" @click="addContactFormField()" :disabled="contactForm.fields.length >= 12"
                                                        data-testid="blox-contact-form-field-add"
                                                        class="h-7 px-2 rounded border border-blue-200 bg-white text-blue-600 hover:bg-blue-50 disabled:opacity-40 text-[10px] font-medium inline-flex items-center gap-1">
                                                    <i class="ti ti-plus text-sm"></i><?= e(__('blox_contact_form_add_field')) ?>
                                                </button>
                                            </div>

                                            <div class="space-y-2.5">
                                                <template x-for="(field, fieldIndex) in contactForm.fields" :key="field._key">
                                                    <div class="rounded border border-gray-200 bg-white overflow-hidden" :data-testid="'blox-contact-form-field-' + fieldIndex">
                                                        <div class="h-8 px-2 flex items-center gap-1 border-b border-gray-100 bg-gray-50">
                                                            <span class="w-5 h-5 rounded bg-emerald-50 text-emerald-600 text-[10px] font-semibold inline-flex items-center justify-center" x-text="fieldIndex + 1"></span>
                                                            <span class="min-w-0 flex-1 text-[10px] font-medium text-gray-500 truncate" x-text="field.label || contactFormText.newField"></span>
                                                            <span class="text-[9px]" :class="field.enabled ? 'text-emerald-600' : 'text-gray-400'" x-text="field.enabled ? <?= e($jt('status_enabled')) ?> : <?= e($jt('status_disabled')) ?>"></span>
                                                            <button type="button" @click="moveContactFormField(fieldIndex, -1)" :disabled="fieldIndex === 0"
                                                                    class="w-6 h-6 rounded text-gray-400 hover:text-blue-600 disabled:opacity-25 inline-flex items-center justify-center"
                                                                    title="<?= e(__('admin_move')) ?>"><i class="ti ti-arrow-up text-sm"></i></button>
                                                            <button type="button" @click="moveContactFormField(fieldIndex, 1)" :disabled="fieldIndex === contactForm.fields.length - 1"
                                                                    class="w-6 h-6 rounded text-gray-400 hover:text-blue-600 disabled:opacity-25 inline-flex items-center justify-center"
                                                                    title="<?= e(__('admin_move')) ?>"><i class="ti ti-arrow-down text-sm"></i></button>
                                                            <button type="button" @click="removeContactFormField(fieldIndex)"
                                                                    class="w-6 h-6 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 inline-flex items-center justify-center"
                                                                    title="<?= e(__('admin_delete')) ?>"><i class="ti ti-trash text-sm"></i></button>
                                                        </div>
                                                        <div class="p-2.5 space-y-2">
                                                            <div class="grid grid-cols-2 gap-2">
                                                                <div>
                                                                    <label class="block text-[10px] font-medium text-gray-500 mb-1"><?= e(__('label_title')) ?></label>
                                                                    <input type="text" x-model="field.label" @input="contactFormChanged = true" maxlength="80"
                                                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                                </div>
                                                                <div>
                                                                    <label class="block text-[10px] font-medium text-gray-500 mb-1"><?= e(__('blox_contact_form_key')) ?></label>
                                                                    <input type="text" x-model="field.key" @input="contactFormChanged = true" maxlength="40" spellcheck="false"
                                                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs font-mono">
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label class="block text-[10px] font-medium text-gray-500 mb-1"><?= e(__('admin_type')) ?></label>
                                                                <select x-model="field.type" @change="contactFormChanged = true"
                                                                        class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                                    <template x-for="option in contactFormFieldTypes" :key="option.value">
                                                                        <option :value="option.value" x-text="option.label"></option>
                                                                    </template>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label class="block text-[10px] font-medium text-gray-500 mb-1"><?= e(__('blox_contact_form_placeholder')) ?></label>
                                                                <input type="text" x-model="field.placeholder" @input="contactFormChanged = true" maxlength="160"
                                                                       class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                            </div>
                                                            <div class="grid grid-cols-2 gap-2">
                                                                <button type="button" role="switch" :aria-checked="field.required" @click="field.required = !field.required; contactFormChanged = true"
                                                                        class="h-8 px-2 rounded border text-[10px] inline-flex items-center justify-between gap-2"
                                                                        :class="field.required ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-gray-500'">
                                                                    <span><?= e(__('required')) ?></span>
                                                                    <i class="ti text-sm" :class="field.required ? 'ti-toggle-right' : 'ti-toggle-left'"></i>
                                                                </button>
                                                                <button type="button" role="switch" :aria-checked="field.enabled" @click="field.enabled = !field.enabled; contactFormChanged = true"
                                                                        class="h-8 px-2 rounded border text-[10px] inline-flex items-center justify-between gap-2"
                                                                        :class="field.enabled ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-white text-gray-500'">
                                                                    <span><?= e(__('status_enabled')) ?></span>
                                                                    <i class="ti text-sm" :class="field.enabled ? 'ti-toggle-right' : 'ti-toggle-left'"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>

                                            <div class="space-y-2 pt-1 border-t border-gray-100">
                                                <div>
                                                    <label class="block text-[10px] font-medium text-gray-500 mb-1"><?= e(__('setting_contact_form_success')) ?></label>
                                                    <input type="text" x-model="contactForm.success_message" @input="contactFormChanged = true" maxlength="255"
                                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                </div>
                                                <button type="button" role="switch" :aria-checked="contactForm.captcha" @click="contactForm.captcha = !contactForm.captcha; contactFormChanged = true"
                                                        class="w-full h-8 px-2 rounded border text-[10px] inline-flex items-center justify-between gap-2"
                                                        :class="contactForm.captcha ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-white text-gray-500'">
                                                    <span><?= e(__('fd_enable_captcha')) ?></span>
                                                    <i class="ti text-sm" :class="contactForm.captcha ? 'ti-toggle-right' : 'ti-toggle-left'"></i>
                                                </button>
                                            </div>

                                            <button type="button" @click="saveContactForm()" :disabled="contactFormSaving || !contactFormChanged"
                                                    data-testid="blox-contact-form-save"
                                                    class="w-full h-9 rounded bg-blue-600 hover:bg-blue-500 disabled:bg-gray-200 disabled:text-gray-400 text-white text-xs font-medium inline-flex items-center justify-center gap-1.5 transition">
                                                <i class="ti text-sm" :class="contactFormSaving ? 'ti-loader-2 animate-spin' : 'ti-device-floppy'"></i>
                                                <span x-text="contactFormSaving ? <?= e($jt('blox_saving')) ?> : <?= e($jt('blox_contact_form_save')) ?>"></span>
                                            </button>

                                            <a :href="contactManage.contact_form.url" target="_blank" rel="noopener"
                                               class="w-full h-8 rounded border border-gray-200 bg-white text-gray-500 hover:border-blue-200 hover:text-blue-600 text-[10px] inline-flex items-center justify-center gap-1.5">
                                                <i class="ti ti-adjustments text-sm"></i><?= e(__('page_contact_manage_form')) ?>
                                            </a>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="selEl && selEl.type === 'home-block' && panelTab === 'content'">
                                <div class="rounded border border-blue-200 bg-blue-50/60 p-3">
                                    <div class="flex items-start gap-3">
                                        <span class="w-9 h-9 rounded bg-white border border-blue-200 text-blue-600 inline-flex items-center justify-center shrink-0">
                                            <i class="ti text-lg" :class="'ti-' + homeBlockSourceIcon()"></i>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <p class="text-xs font-semibold text-gray-700 truncate" x-text="homeBlockSourceLabel()"></p>
                                                <span class="text-[9px] px-1.5 py-0.5 rounded border"
                                                      :class="selEl.data.enabled !== false ? 'border-emerald-200 bg-emerald-50 text-emerald-600' : 'border-gray-200 bg-white text-gray-400'"
                                                      x-text="selEl.data.enabled === false ? homeDynamicText.disabled : (hasCustomBannerItems() ? homeDynamicText.customItems : homeDynamicText.liveData)"></span>
                                            </div>
                                            <p class="mt-1 text-[10px] text-gray-500 leading-relaxed" x-text="homeBlockSummary()"></p>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <template x-if="selEl && selectedHomeColumn && !selectedHomeField && selEl.type === 'home-block' && String((selEl.data || {}).block_type || '') === 'about' && panelTab === 'content'">
                                <div data-home-column-editor class="rounded border border-cyan-200 bg-cyan-50/60 p-3">
                                    <div class="flex items-center gap-2">
                                        <span class="w-8 h-8 rounded bg-white border border-cyan-200 text-cyan-600 inline-flex items-center justify-center shrink-0">
                                            <i class="ti ti-columns-2 text-base"></i>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-xs font-semibold text-gray-700 truncate" x-text="selectedHomeColumnLabel()"></p>
                                            <p class="mt-0.5 text-[10px] text-gray-400" x-text="homeGroupSpanLabel(selEl, {key: selectedHomeColumn})"></p>
                                        </div>
                                        <button type="button" @click="swapAboutColumns()"
                                                :title="homeDynamicText.swapColumns"
                                                class="w-8 h-8 rounded border border-cyan-200 bg-white text-cyan-600 hover:bg-blue-600 hover:text-white inline-flex items-center justify-center transition">
                                            <i class="ti ti-arrows-exchange text-base"></i>
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <template x-if="isCustomHomeBlock(selEl) && (customHomeColumnGroups(selEl).length || customFaqRepeaters(selEl).length) && panelTab === 'content'">
                                <div data-custom-home-columns data-testid="blox-custom-columns" class="rounded border border-cyan-200 bg-cyan-50/40 p-2.5 space-y-1.5">
                                    <div class="flex items-center justify-between gap-2 px-1">
                                        <p class="text-[10px] font-semibold uppercase text-cyan-700"><?= e(__('blox_home_custom_columns')) ?></p>
                                        <template x-if="customFaqRepeaters(selEl).length">
                                            <div class="flex items-center gap-1">
                                                <button type="button"
                                                        x-show="customFaqIsCustomized(selEl, customFaqRepeaters(selEl)[0])"
                                                        @click="restoreCustomFaq(customFaqRepeaters(selEl)[0].key)"
                                                        data-testid="blox-faq-restore"
                                                        :title="homeDynamicText.faqRestore"
                                                        class="w-7 h-7 rounded border border-cyan-200 bg-white text-cyan-600 hover:bg-cyan-50 inline-flex items-center justify-center">
                                                    <i class="ti ti-history text-sm"></i>
                                                </button>
                                                <button type="button"
                                                        @click="addCustomFaqItem(customFaqRepeaters(selEl)[0].key)"
                                                        data-testid="blox-faq-add"
                                                        class="h-7 px-2 rounded border border-cyan-200 bg-white text-[10px] font-medium text-cyan-700 hover:bg-cyan-50 inline-flex items-center gap-1">
                                                    <i class="ti ti-plus text-sm"></i>
                                                    <span x-text="homeDynamicText.faqAdd"></span>
                                                </button>
                                            </div>
                                        </template>
                                        <template x-if="customColumnRepeaters(selEl).length">
                                            <div class="flex items-center gap-1">
                                                <button type="button"
                                                        x-show="customColumnIsCustomized(selEl, customColumnRepeaters(selEl)[0])"
                                                        @click="restoreCustomColumns(customColumnRepeaters(selEl)[0].key)"
                                                        data-testid="blox-plan-restore"
                                                        :title="homeDynamicText.planRestore"
                                                        :aria-label="homeDynamicText.planRestore"
                                                        class="w-7 h-7 rounded border border-cyan-200 bg-white text-cyan-600 hover:bg-cyan-50 inline-flex items-center justify-center">
                                                    <i class="ti ti-history text-sm"></i>
                                                </button>
                                                <button type="button"
                                                        @click="duplicateCustomColumn(null, customColumnRepeaters(selEl)[0].key)"
                                                        data-testid="blox-plan-add"
                                                        class="h-7 px-2 rounded border border-cyan-200 bg-white text-[10px] font-medium text-cyan-700 hover:bg-cyan-50 inline-flex items-center gap-1">
                                                    <i class="ti ti-plus text-sm"></i>
                                                    <span x-text="homeDynamicText.planAdd"></span>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                    <template x-for="group in customHomeColumnGroups(selEl)" :key="group.key">
                                        <div class="rounded border border-cyan-100 bg-white overflow-hidden">
                                            <div class="flex items-stretch">
                                                <button type="button" @click="selectCustomHomeGroup(group)"
                                                        :data-testid="'blox-custom-column-' + group.key"
                                                        class="min-w-0 flex-1 flex items-center gap-2 px-2.5 py-2 text-left transition"
                                                        :class="selectedHomeColumn === group.key ? 'bg-cyan-50 text-cyan-700' : 'text-gray-600 hover:bg-gray-50'">
                                                    <i class="ti text-sm shrink-0" :class="'ti-' + (group.icon || 'columns-1')"></i>
                                                    <span class="min-w-0 flex-1 text-xs font-medium truncate" x-text="group.displayLabel"></span>
                                                    <i class="ti text-xs" :class="selectedHomeColumn === group.key ? 'ti-chevron-up' : 'ti-chevron-down'"></i>
                                                </button>
                                                <button type="button" x-show="group.faqRepeaterKey"
                                                        @click.stop="moveCustomFaqItem(group, -1)"
                                                        :disabled="!customFaqCanMove(group, -1)"
                                                        data-testid="blox-faq-move-up"
                                                        title="<?= e(__('blox_ctx_move_up')) ?>"
                                                        aria-label="<?= e(__('blox_ctx_move_up')) ?>"
                                                        class="w-8 border-l border-cyan-100 text-gray-400 hover:bg-cyan-50 hover:text-cyan-700 disabled:cursor-not-allowed disabled:text-gray-200 disabled:hover:bg-white inline-flex items-center justify-center shrink-0">
                                                    <i class="ti ti-arrow-up text-sm"></i>
                                                </button>
                                                <button type="button" x-show="group.faqRepeaterKey"
                                                        @click.stop="moveCustomFaqItem(group, 1)"
                                                        :disabled="!customFaqCanMove(group, 1)"
                                                        data-testid="blox-faq-move-down"
                                                        title="<?= e(__('blox_ctx_move_down')) ?>"
                                                        aria-label="<?= e(__('blox_ctx_move_down')) ?>"
                                                        class="w-8 border-l border-cyan-100 text-gray-400 hover:bg-cyan-50 hover:text-cyan-700 disabled:cursor-not-allowed disabled:text-gray-200 disabled:hover:bg-white inline-flex items-center justify-center shrink-0">
                                                    <i class="ti ti-arrow-down text-sm"></i>
                                                </button>
                                                <button type="button" x-show="group.faqRepeaterKey"
                                                        @click.stop="deleteCustomFaqItem(group)"
                                                        data-testid="blox-faq-delete"
                                                        :title="homeDynamicText.faqDelete"
                                                        :aria-label="homeDynamicText.faqDelete"
                                                        class="w-8 border-l border-cyan-100 text-gray-400 hover:bg-red-50 hover:text-red-600 inline-flex items-center justify-center shrink-0">
                                                    <i class="ti ti-trash text-sm"></i>
                                                </button>
                                                <button type="button" x-show="group.columnRepeaterKey"
                                                        @click.stop="moveCustomColumn(group, -1)"
                                                        :disabled="!customColumnCanMove(group, -1)"
                                                        data-testid="blox-plan-move-up"
                                                        title="<?= e(__('blox_ctx_move_up')) ?>"
                                                        aria-label="<?= e(__('blox_ctx_move_up')) ?>"
                                                        class="w-8 border-l border-cyan-100 text-gray-400 hover:bg-cyan-50 hover:text-cyan-700 disabled:cursor-not-allowed disabled:text-gray-200 disabled:hover:bg-white inline-flex items-center justify-center shrink-0">
                                                    <i class="ti ti-arrow-up text-sm"></i>
                                                </button>
                                                <button type="button" x-show="group.columnRepeaterKey"
                                                        @click.stop="moveCustomColumn(group, 1)"
                                                        :disabled="!customColumnCanMove(group, 1)"
                                                        data-testid="blox-plan-move-down"
                                                        title="<?= e(__('blox_ctx_move_down')) ?>"
                                                        aria-label="<?= e(__('blox_ctx_move_down')) ?>"
                                                        class="w-8 border-l border-cyan-100 text-gray-400 hover:bg-cyan-50 hover:text-cyan-700 disabled:cursor-not-allowed disabled:text-gray-200 disabled:hover:bg-white inline-flex items-center justify-center shrink-0">
                                                    <i class="ti ti-arrow-down text-sm"></i>
                                                </button>
                                                <button type="button" x-show="group.columnRepeaterKey"
                                                        @click.stop="duplicateCustomColumn(group)"
                                                        data-testid="blox-plan-duplicate"
                                                        :title="homeDynamicText.planDuplicate"
                                                        :aria-label="homeDynamicText.planDuplicate"
                                                        class="w-8 border-l border-cyan-100 text-gray-400 hover:bg-cyan-50 hover:text-cyan-700 inline-flex items-center justify-center shrink-0">
                                                    <i class="ti ti-copy text-sm"></i>
                                                </button>
                                                <button type="button" x-show="group.columnRepeaterKey"
                                                        @click.stop="deleteCustomColumn(group)"
                                                        data-testid="blox-plan-delete"
                                                        :title="homeDynamicText.planDelete"
                                                        :aria-label="homeDynamicText.planDelete"
                                                        class="w-8 border-l border-cyan-100 text-gray-400 hover:bg-red-50 hover:text-red-600 inline-flex items-center justify-center shrink-0">
                                                    <i class="ti ti-trash text-sm"></i>
                                                </button>
                                            </div>
                                            <div x-show="selectedHomeColumn === group.key" x-collapse class="border-t border-cyan-100 p-1">
                                                <template x-for="field in group.fields" :key="field.key">
                                                    <button type="button" @click="selectHomeField(selectedPath(), field.key)"
                                                            data-testid="blox-custom-field" :data-home-custom-field="field.key"
                                                            class="w-full flex items-center gap-2 rounded px-2 py-1.5 text-left transition"
                                                            :class="selectedHomeField === field.key ? 'bg-blue-50 text-blue-700' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'">
                                                        <i class="ti text-xs shrink-0" :class="'ti-' + field.icon"></i>
                                                        <span class="min-w-0 flex-1 text-[11px] truncate" x-text="field.label"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="selectedHomeFieldDefinition() && selectedHomeField.indexOf('.') !== -1 && panelTab === 'content'">
                                <div data-home-field-editor class="rounded border border-cyan-200 bg-cyan-50/60 p-3 space-y-3">
                                    <div class="flex items-start gap-2">
                                        <span class="w-8 h-8 rounded bg-white border border-cyan-200 text-cyan-600 inline-flex items-center justify-center shrink-0">
                                            <i class="ti text-base" :class="'ti-' + selectedHomeFieldDefinition().icon"></i>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-xs font-semibold text-gray-700" x-text="selectedHomeFieldDefinition().label"></p>
                                            <p class="mt-0.5 text-[10px] text-gray-400" x-text="selectedHomeFieldDefinition().groupLabel"></p>
                                        </div>
                                        <span x-show="selectedHomeFieldInherited()" class="text-[9px] px-1.5 py-0.5 rounded border border-cyan-200 bg-white text-cyan-600"
                                              x-text="homeDynamicText.inherit"></span>
                                        <button type="button"
                                                data-testid="blox-custom-field-reset"
                                                x-show="!selectedHomeFieldInherited() && !selectedCustomStructuralField() && selectedHomeField.indexOf('custom_overrides.') === 0"
                                                @click="resetSelectedCustomHomeField()"
                                                :title="homeDynamicText.inherit"
                                                class="w-7 h-7 rounded border border-cyan-200 bg-white text-cyan-600 hover:bg-cyan-50 inline-flex items-center justify-center shrink-0">
                                            <i class="ti ti-history text-sm"></i>
                                        </button>
                                    </div>
                                    <template x-if="selectedHomeFieldDefinition().control === 'icon'">
                                        <div class="space-y-2" data-home-icon-library>
                                            <div class="flex items-center gap-2">
                                                <span class="w-10 h-10 rounded border border-cyan-200 bg-white text-cyan-600 inline-flex items-center justify-center shrink-0">
                                                    <i class="text-xl" :class="iconClass(selectedHomeFieldValue())"></i>
                                                </span>
                                                <input type="text" :value="selectedHomeFieldValue()"
                                                       @input="setSelectedHomeFieldValue($event.target.value)"
                                                       :placeholder="selectedHomeFieldDefinition().label"
                                                       class="min-w-0 flex-1 border border-cyan-200 bg-white rounded px-2.5 py-2 text-sm">
                                                <button type="button"
                                                        @click="toggleIconPicker('home:' + selectedHomeField, selectedHomeFieldValue())"
                                                        class="shrink-0 h-9 px-2.5 rounded border border-cyan-200 bg-white text-xs text-cyan-600 hover:border-cyan-300 hover:bg-cyan-50 hover:text-cyan-700 transition"
                                                        x-text="iconPick === ('home:' + selectedHomeField) ? homeDynamicText.iconLibraryClose : homeDynamicText.iconLibrary"></button>
                                            </div>
                                            <div>
                                                <p class="mb-1.5 text-[10px] font-medium text-gray-500" x-text="homeDynamicText.iconRecommended"></p>
                                                <div class="grid grid-cols-6 gap-1.5 max-h-32 overflow-y-auto blox-scroll">
                                                    <template x-for="icon in selectedHomeFieldDefinition().options || []" :key="'recommended-' + icon">
                                                        <button type="button" @click="setSelectedHomeFieldValue(icon)"
                                                                :title="icon"
                                                                class="h-9 rounded border inline-flex items-center justify-center transition"
                                                                :class="selectedHomeFieldValue() === icon ? 'border-cyan-500 bg-cyan-500 text-white' : 'border-gray-200 bg-white text-gray-500 hover:border-cyan-300 hover:text-cyan-600'">
                                                            <i class="text-lg" :class="iconClass(icon)"></i>
                                                        </button>
                                                    </template>
                                                </div>
                                            </div>
                                            <div x-show="iconPick === ('home:' + selectedHomeField)" x-cloak
                                                 class="rounded border border-cyan-200 bg-white p-2">
                                                <div class="grid grid-cols-2 gap-1 mb-2" role="group" aria-label="Icon library">
                                                    <button type="button" @click="setIconProvider('tabler')" data-testid="blox-home-icon-provider-tabler"
                                                            class="h-7 rounded border text-[10px] font-medium transition"
                                                            :class="iconProvider === 'tabler' ? 'border-blue-500 bg-blue-500 text-white' : 'border-gray-200 text-gray-600 hover:border-blue-300'">Tabler</button>
                                                    <button type="button" @click="setIconProvider('bootstrap')" data-testid="blox-home-icon-provider-bootstrap"
                                                            class="h-7 rounded border text-[10px] font-medium transition"
                                                            :class="iconProvider === 'bootstrap' ? 'border-blue-500 bg-blue-500 text-white' : 'border-gray-200 text-gray-600 hover:border-blue-300'">Bootstrap</button>
                                                </div>
                                                <input type="text" x-model="iconQuery"
                                                       :placeholder="homeDynamicText.iconSearch"
                                                       class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs mb-2">
                                                <div class="grid grid-cols-6 gap-1 max-h-48 overflow-y-auto blox-scroll">
                                                    <template x-for="icon in iconMatches()" :key="'library-' + icon">
                                                        <button type="button" @click="setSelectedHomeFieldValue(icon)"
                                                                :title="icon"
                                                                class="h-8 rounded border inline-flex items-center justify-center transition"
                                                                :class="selectedHomeFieldValue() === icon ? 'border-blue-500 bg-blue-500 text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-blue-400 hover:text-blue-600'">
                                                            <i class="text-base" :class="iconClass(icon)"></i>
                                                        </button>
                                                    </template>
                                                </div>
                                                <p class="mt-1.5 text-[10px] text-gray-400" x-text="iconHint()"></p>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="selectedHomeFieldDefinition().control === 'textarea'">
                                        <textarea rows="4" :value="selectedHomeFieldValue()"
                                                  @input="setSelectedHomeFieldValue($event.target.value)"
                                                  class="w-full border border-cyan-200 bg-white rounded px-2.5 py-2 text-sm"></textarea>
                                    </template>
                                    <template x-if="selectedHomeFieldDefinition().control === 'richtext'">
                                        <div class="space-y-2">
                                            <button type="button"
                                                    @click="openRte(() => String(selectedHomeFieldValue() || ''), v => setSelectedHomeFieldValue(v))"
                                                    class="w-full inline-flex items-center justify-center gap-1.5 text-sm text-white bg-blue-600 hover:bg-blue-500 rounded py-2 transition">
                                                <i class="ti ti-edit text-base"></i><?= __('blox_edit_content') ?>
                                            </button>
                                            <p class="text-xs text-gray-500 bg-white border border-cyan-100 rounded px-2 py-1.5 leading-relaxed break-words"
                                               x-text="(String(selectedHomeFieldValue() || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || <?= e($jt('blox_no_content_yet')) ?>).slice(0, 100)"></p>
                                        </div>
                                    </template>
                                    <template x-if="selectedHomeFieldDefinition().control === 'color'">
                                        <div>
                                            <div class="flex items-center gap-1 mb-2">
                                                <select :value="colorTokenId(selectedHomeFieldValue())"
                                                        @change="$event.target.value && setSelectedHomeFieldValue(colorTokenRef($event.target.value))"
                                                        class="flex-1 min-w-0 border border-cyan-200 bg-white rounded px-2 py-1 text-xs">
                                                    <option value=""><?= e(__('blox_design_custom')) ?></option>
                                                    <template x-for="token in colorTokenOptions(selectedHomeFieldValue())" :key="'home-color-'+token.id">
                                                        <option :value="token.id" x-text="colorTokenLabel(token)"></option>
                                                    </template>
                                                </select>
                                                <template x-for="token in activeColorTokens().slice(0, 4)" :key="'home-color-swatch-'+token.id">
                                                    <button type="button" @click="setSelectedHomeFieldValue(colorTokenRef(token.id))" :title="token.name"
                                                            class="w-6 h-6 rounded border border-cyan-200 shrink-0"
                                                            :class="colorTokenId(selectedHomeFieldValue()) === token.id ? 'ring-2 ring-cyan-400' : ''"
                                                            :style="'background:' + token.value"></button>
                                                </template>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <input type="color" :value="colorPickerValue(selectedHomeFieldValue(), '#ffffff')"
                                                       @input="setSelectedHomeFieldValue($event.target.value)"
                                                       class="w-10 h-9 border border-cyan-200 bg-white rounded p-1">
                                                <input type="text" :value="selectedHomeFieldValue()"
                                                       @input="setSelectedHomeFieldValue($event.target.value)" placeholder="#ffffff"
                                                       class="min-w-0 flex-1 border border-cyan-200 bg-white rounded px-2.5 py-2 text-sm">
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="selectedHomeFieldDefinition().control === 'text' || selectedHomeFieldDefinition().control === 'url'">
                                        <input type="text" :value="selectedHomeFieldValue()"
                                               @input="setSelectedHomeFieldValue($event.target.value)"
                                               :placeholder="selectedHomeFieldDefinition().control === 'url' ? '/contact.html' : ''"
                                               class="w-full border border-cyan-200 bg-white rounded px-2.5 py-2 text-sm">
                                    </template>
                                </div>
                            </template>

                            <template x-if="isHomeBannerHost(selTopEl) && panelTab === 'content'">
                                <div class="rounded border border-amber-200 bg-amber-50/60 p-3 space-y-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-semibold text-amber-700 inline-flex items-center gap-1.5">
                                            <i class="ti ti-carousel-horizontal text-sm"></i>
                                            <?php echo e(__('blox_home_banner_items')); ?>
                                        </span>
                                        <span class="text-[10px] rounded border border-amber-200 bg-white text-amber-700 px-1.5 py-0.5"
                                              x-text="hasCustomBannerItems() ? (homeBannerItemCount() + ' ' + homeDynamicText.items) : homeDynamicText.inherit"></span>
                                    </div>
                                    <template x-if="!hasCustomBannerItems()">
                                        <div class="space-y-2">
                                            <p class="text-[10px] leading-relaxed text-gray-500"><?php echo e(__('blox_home_banner_items_help')); ?></p>
                                            <button type="button" @click="adoptBannerItems()"
                                                    class="w-full h-8 rounded border border-amber-200 bg-white text-amber-700 hover:border-amber-300 text-xs inline-flex items-center justify-center gap-1.5">
                                                <i class="ti ti-copy-plus text-sm"></i>
                                                <span x-text="homeBannerSeeds.length ? homeDynamicText.adoptItems : homeDynamicText.createItem"></span>
                                            </button>
                                        </div>
                                    </template>
                                    <template x-if="bannerPreviewItems().length">
                                        <div class="space-y-2.5" data-banner-manager>
                                            <div class="grid grid-cols-3 gap-1.5">
                                                <template x-for="(item, bi) in bannerPreviewItems()" :key="item.id">
                                                    <div class="relative min-w-0 group/banner" data-banner-thumb>
                                                        <button type="button" @click="selectBannerItem(bi)"
                                                                class="w-full overflow-hidden rounded border-2 bg-white text-left transition"
                                                                :class="selectedSubEi === bi ? 'border-blue-500 ring-2 ring-blue-100' : 'border-white hover:border-amber-300'">
                                                            <span class="block aspect-[16/10] bg-gray-100">
                                                                <template x-if="item.data && item.data.image">
                                                                    <img :src="item.data.image" class="w-full h-full object-cover" alt="">
                                                                </template>
                                                                <template x-if="!item.data || !item.data.image">
                                                                    <span class="w-full h-full flex items-center justify-center text-gray-300"><i class="ti ti-photo-off text-lg"></i></span>
                                                                </template>
                                                            </span>
                                                            <span class="block px-1.5 py-1 text-[10px] text-gray-600 truncate"
                                                                  x-text="homeDynamicText.slide + ' ' + (bi + 1)"></span>
                                                        </button>
                                                        <button type="button" @click.stop="replaceBannerImage(bi)" data-banner-replace
                                                                :title="homeDynamicText.replaceImage"
                                                                class="absolute top-1 right-1 w-6 h-6 rounded bg-white/95 shadow text-blue-600 hover:bg-blue-600 hover:text-white inline-flex items-center justify-center transition">
                                                            <i class="ti ti-photo-edit text-sm"></i>
                                                        </button>
                                                    </div>
                                                </template>
                                            </div>
                                            <div class="grid grid-cols-2 gap-1.5">
                                                <button type="button" @click="addBannerItem()"
                                                        class="h-8 rounded border border-amber-200 bg-white text-amber-700 hover:border-amber-300 text-xs inline-flex items-center justify-center gap-1">
                                                    <i class="ti ti-plus text-sm"></i><?php echo e(__('blox_home_banner_add')); ?>
                                                </button>
                                                <button type="button" @click="restoreBannerSource()" :disabled="!hasCustomBannerItems()"
                                                        class="h-8 rounded border border-gray-200 bg-white text-gray-500 hover:border-red-200 hover:text-red-500 disabled:opacity-40 disabled:hover:border-gray-200 disabled:hover:text-gray-500 text-xs inline-flex items-center justify-center gap-1">
                                                    <i class="ti ti-database text-sm"></i><?php echo e(__('blox_home_banner_restore')); ?>
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="selEl && selEl.type === 'list-dynamic' && panelTab === 'content'">
                                <div class="rounded border border-violet-200 bg-violet-50/60 p-3 space-y-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-semibold text-violet-700 inline-flex items-center gap-1.5">
                                            <i class="ti ti-repeat text-sm"></i>
                                            <?php echo e(__('blox_loop_template_title')); ?>
                                        </span>
                                        <span class="text-[10px] rounded border px-1.5 py-0.5"
                                              :class="hasLoopTemplate() ? 'border-violet-200 bg-white text-violet-600' : 'border-gray-200 bg-white text-gray-500'"
                                              x-text="hasLoopTemplate() ? <?php echo htmlspecialchars(json_encode(__('blox_loop_template_custom'), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?> : <?php echo htmlspecialchars(json_encode(__('blox_loop_template_preset'), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"></span>
                                    </div>
                                    <p class="text-[10px] leading-relaxed text-gray-500"><?php echo e(__('blox_loop_template_help')); ?></p>
                                    <button type="button" @click="libOpen = true" data-testid="blox-library-open"
                                            class="w-full h-8 rounded border border-violet-200 bg-white text-violet-600 hover:border-violet-300 text-xs inline-flex items-center justify-center gap-1.5">
                                        <i class="ti ti-plus text-sm"></i>
                                        <?php echo e(__('blox_loop_add_child')); ?>
                                    </button>
                                </div>
                            </template>

                            <template x-if="isSelectedContainerEl() && panelTab === 'style'">
                                <div class="space-y-4">
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-xs font-medium text-gray-600"><?= __('blox_layout_preview') ?></span>
                                            <span class="text-[10px] text-gray-400" x-text="<?= e($jt('blox_n_children')) ?>.replace(':n', containerChildCount())"></span>
                                        </div>
                                        <div class="rounded border border-dashed border-gray-300 p-3 min-h-24 transition"
                                             :class="containerPreviewClass()"
                                             :style="containerPreviewStyle()">
                                            <template x-for="n in Math.max(containerChildCount(), 3)" :key="n">
                                                <div class="rounded bg-white/90 border border-gray-200 shadow-sm h-8 flex items-center justify-center text-[10px] text-gray-400 px-2"
                                                     x-text="n <= containerChildCount() ? <?= e($jt('blox_element_word')) ?>.replace(':n', n) : <?= e($jt('blox_placeholder')) ?>"></div>
                                            </template>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-medium text-gray-600"><?= e(__('blox_responsive_layout')) ?></span>
                                        <div class="inline-flex rounded border border-gray-200 bg-gray-50 p-0.5">
                                            <template x-for="d in devices" :key="'container-responsive-' + d.key">
                                                <button type="button" @click="previewDevice = d.key" :title="d.label" :aria-label="d.label"
                                                        :data-testid="'blox-container-responsive-device-' + d.key"
                                                        :data-responsive-state="containerHasResponsiveOverride(d.key) ? 'override' : 'inherit'"
                                                        class="relative w-7 h-6 rounded inline-flex items-center justify-center"
                                                        :class="previewDevice === d.key ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-blue-600'">
                                                    <i class="ti text-xs" :class="d.icon"></i>
                                                    <span x-show="containerHasResponsiveOverride(d.key)"
                                                          class="absolute top-0 right-0 w-1.5 h-1.5 rounded-full bg-amber-400 ring-1 ring-white"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>

                                    <div x-show="selEl && selEl.type === 'div'">
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_display_mode') ?></label>
                                        <div class="grid grid-cols-2 gap-1">
                                            <button type="button" @click="selEl.data.display = 'block'"
                                                    class="h-9 rounded border inline-flex items-center justify-center gap-1.5 text-xs transition"
                                                    :class="(selEl.data.display || 'block') === 'block' ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'">
                                                <i class="ti ti-square text-base"></i><span><?= __('blox_block_level') ?></span>
                                            </button>
                                            <button type="button" @click="selEl.data.display = 'flex'"
                                                    class="h-9 rounded border inline-flex items-center justify-center gap-1.5 text-xs transition"
                                                    :class="selEl.data.display === 'flex' ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'">
                                                <i class="ti ti-layout-columns text-base"></i><span>Flex</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div x-show="selEl && (selEl.type !== 'div' || (selEl.data.display || 'block') === 'flex')">
                                        <div class="flex items-center justify-between gap-2 mb-1.5">
                                            <label class="block text-xs font-medium text-gray-600"><?= __('blox_direction') ?></label>
                                            <div class="flex items-center gap-1">
                                                <span x-show="previewDevice !== 'desktop'" class="text-[10px] text-gray-400"
                                                      x-text="responsiveStatusText(containerControlState('direction'))"></span>
                                                <button type="button" x-show="previewDevice !== 'desktop' && containerControlState('direction').overridden"
                                                        @click="inheritContainerControl('direction')" :title="responsiveText.resetInherit"
                                                        data-testid="blox-container-direction-inherit"
                                                        class="w-6 h-6 rounded border border-gray-200 text-gray-400 hover:border-blue-300 hover:text-blue-600 inline-flex items-center justify-center">
                                                    <i class="ti ti-link text-xs"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-2 gap-1">
                                            <template x-for="opt in containerDirectionOptions" :key="'dir'+opt.k">
                                                <button type="button" @click="setContainerControlValue('direction', opt.k)" :title="opt.label"
                                                        class="h-9 rounded border inline-flex items-center justify-center gap-1.5 text-xs transition"
                                                        :class="containerControlValue('direction') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200 hover:text-blue-500'">
                                                    <i class="ti text-base" :class="'ti-' + opt.icon"></i><span x-text="opt.short"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>

                                    <div x-show="selEl && (selEl.type !== 'div' || (selEl.data.display || 'block') === 'flex')">
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5"><?php echo e(__('blox_flex_wrap')); ?></label>
                                        <div class="grid grid-cols-3 gap-1">
                                            <template x-for="opt in containerWrapOptions" :key="'cw'+opt.k">
                                                <button type="button" @click="selEl.data.wrap = opt.k" :title="opt.label"
                                                        class="h-8 rounded border text-xs transition"
                                                        :class="(selEl.data.wrap || 'auto') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                        x-text="opt.short"></button>
                                            </template>
                                        </div>
                                    </div>

                                    <div x-show="selEl && (selEl.type !== 'div' || (selEl.data.display || 'block') === 'flex')">
                                        <div class="flex items-center justify-between gap-2 mb-1.5">
                                            <label class="block text-xs font-medium text-gray-600"><?= __('blox_child_gap') ?></label>
                                            <div class="flex items-center gap-1">
                                                <span x-show="previewDevice !== 'desktop'" class="text-[10px] text-gray-400"
                                                      x-text="responsiveStatusText(containerControlState('gap'))"></span>
                                                <button type="button" x-show="previewDevice !== 'desktop' && containerControlState('gap').overridden"
                                                        @click="inheritContainerControl('gap')" :title="responsiveText.resetInherit"
                                                        data-testid="blox-container-gap-inherit"
                                                        class="w-6 h-6 rounded border border-gray-200 text-gray-400 hover:border-blue-300 hover:text-blue-600 inline-flex items-center justify-center">
                                                    <i class="ti ti-link text-xs"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-5 gap-1">
                                            <template x-for="opt in containerSizeOptions" :key="'cg'+opt.k">
                                                <button type="button" @click="setContainerControlValue('gap', opt.k)"
                                                        :data-testid="'blox-container-gap-' + opt.k"
                                                        class="h-8 rounded text-xs border transition"
                                                        :class="containerControlValue('gap') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                        x-text="opt.label"></button>
                                            </template>
                                        </div>
                                    </div>

                                    <div x-show="selEl && (selEl.type !== 'div' || (selEl.data.display || 'block') === 'flex')" class="space-y-3">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_cross_align') ?></label>
                                            <div class="grid grid-cols-5 gap-1">
                                                <template x-for="opt in containerAlignOptions" :key="'ca'+opt.k">
                                                    <button type="button" @click="selEl.data.align = opt.k" :title="opt.label"
                                                            class="h-8 rounded border inline-flex items-center justify-center transition"
                                                            :class="(selEl.data.align || 'stretch') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200 hover:text-blue-500'">
                                                        <i class="ti text-base" :class="'ti-' + opt.icon"></i>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_main_distribute') ?></label>
                                            <div class="grid grid-cols-6 gap-1">
                                                <template x-for="opt in containerJustifyOptions" :key="'cj'+opt.k">
                                                    <button type="button" @click="selEl.data.justify = opt.k" :title="opt.label"
                                                            class="h-8 rounded border inline-flex items-center justify-center transition"
                                                            :class="(selEl.data.justify || 'start') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200 hover:text-blue-500'">
                                                        <i class="ti text-base" :class="'ti-' + opt.icon"></i>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_bg_color') ?></label>
                                        <div class="flex items-center gap-1 mb-2">
                                            <select :value="colorTokenId(selEl.data.bg_color)"
                                                    @change="selEl.data.bg_color = $event.target.value ? colorTokenRef($event.target.value) : selEl.data.bg_color"
                                                    class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1 text-xs bg-white">
                                                <option value=""><?= e(__('blox_design_custom')) ?></option>
                                                <template x-for="token in colorTokenOptions(selEl.data.bg_color)" :key="'div-bg-'+token.id">
                                                    <option :value="token.id" x-text="colorTokenLabel(token)"></option>
                                                </template>
                                            </select>
                                            <template x-for="token in activeColorTokens().slice(0, 4)" :key="'div-bg-swatch-'+token.id">
                                                <button type="button" @click="selEl.data.bg_color = colorTokenRef(token.id)" :title="token.name"
                                                        class="w-6 h-6 rounded border border-gray-200 shrink-0"
                                                        :class="colorTokenId(selEl.data.bg_color) === token.id ? 'ring-2 ring-blue-400' : ''"
                                                        :style="'background:' + token.value"></button>
                                            </template>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <input type="color" class="w-9 h-9 rounded border border-gray-200 cursor-pointer p-0.5"
                                                   :value="colorPickerValue(selEl.data.bg_color, '#ffffff')"
                                                   @input="selEl.data.bg_color = $event.target.value">
                                            <input type="text" x-model="selEl.data.bg_color" placeholder="<?= e(__('blox_empty_transparent')) ?>"
                                                   class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                            <button type="button" @click="selEl.data.bg_color = ''"
                                                    class="text-gray-400 hover:text-red-500 p-1" title="<?= e(__('blox_clear')) ?>">
                                                <i class="ti ti-x text-sm"></i></button>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <div class="flex items-center justify-between gap-1 mb-1.5">
                                                <label class="block text-xs font-medium text-gray-600"><?= __('blox_padding') ?></label>
                                                <button type="button" x-show="previewDevice !== 'desktop' && containerControlState('padding').overridden"
                                                        @click="inheritContainerControl('padding')" :title="responsiveText.resetInherit"
                                                        data-testid="blox-container-padding-inherit"
                                                        class="w-6 h-6 rounded border border-gray-200 text-gray-400 hover:border-blue-300 hover:text-blue-600 inline-flex items-center justify-center">
                                                    <i class="ti ti-link text-xs"></i>
                                                </button>
                                            </div>
                                            <div class="grid grid-cols-4 gap-1">
                                                <template x-for="opt in containerSizeOptions" :key="'cp'+opt.k">
                                                    <button type="button" @click="setContainerControlValue('padding', opt.k)"
                                                            :data-testid="'blox-container-padding-' + opt.k"
                                                            class="h-8 rounded text-xs border transition"
                                                            :class="containerControlValue('padding') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                            x-text="opt.label"></button>
                                                </template>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_radius') ?></label>
                                            <div class="grid grid-cols-3 gap-1">
                                                <template x-for="opt in containerRadiusOptions" :key="'er'+opt.k">
                                                    <button type="button" @click="selEl.data.radius = opt.k"
                                                            class="h-8 rounded text-xs border transition"
                                                            :class="(selEl.data.radius || 'none') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                            x-text="opt.label"></button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <template x-if="selEl && panelTab === 'style' && supportsBoxStyles(selEl.type)">
                                <div class="rounded border border-gray-200 bg-gray-50 p-3 space-y-3">
                                    <div x-show="advancedMode" class="pb-3 border-b border-gray-200">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <label class="text-xs font-semibold text-gray-600 inline-flex items-center gap-1.5">
                                                <i class="ti ti-components text-sm text-emerald-500"></i><?= e(__('blox_global_style')) ?>
                                            </label>
                                            <button x-show="canManageDesign" type="button" @click="openDesignSystem('styles')"
                                                    class="w-7 h-7 inline-flex items-center justify-center rounded text-gray-400 hover:text-emerald-600 hover:bg-white"
                                                    title="<?= e(__('blox_design_system')) ?>">
                                                <i class="ti ti-settings text-sm"></i>
                                            </button>
                                        </div>
                                        <select :value="selEl.data._global_style || ''" @change="applyGlobalStyle($event.target.value)"
                                                data-testid="blox-global-style-select"
                                                class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                            <option value=""><?= e(__('blox_design_no_style')) ?></option>
                                            <template x-for="style in globalStyleOptions(selEl.data._global_style)" :key="style.id">
                                                <option :value="style.id" x-text="globalStyleLabel(style)"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold text-gray-600 inline-flex items-center gap-1.5">
                                            <i class="ti ti-box-margin text-sm text-blue-500"></i>
                                            <?php echo e(__('blox_spacing')); ?>
                                        </span>
                                        <button type="button" @click="resetBoxSpacing()"
                                                title="<?php echo e(__('blox_reset_spacing')); ?>"
                                                class="w-7 h-7 inline-flex items-center justify-center rounded text-gray-400 hover:text-red-500 hover:bg-white transition">
                                            <i class="ti ti-restore text-sm"></i>
                                        </button>
                                    </div>

                                    <template x-for="kind in boxKinds" :key="'all-'+kind.key">
                                        <div>
                                            <label class="grid grid-cols-[5.5rem_1fr] items-center gap-2">
                                                <span class="text-[11px] font-medium inline-flex items-center gap-1.5"
                                                      :class="kind.key === 'margin' ? 'text-amber-700' : 'text-blue-700'">
                                                    <span class="w-2 h-2 rounded-sm"
                                                          :class="kind.key === 'margin' ? 'bg-amber-400' : 'bg-blue-400'"></span>
                                                    <span x-text="kind.label"></span>
                                                </span>
                                                <select :value="spacingSelectValue(kind.key, '')"
                                                        @change="setBoxSpacing(kind.key, '', $event.target.value)"
                                                        class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                    <template x-for="opt in boxSpacingOptions(kind.allowAuto)" :key="kind.key+'-'+opt.k">
                                                        <option :value="opt.k" x-text="opt.label"></option>
                                                    </template>
                                                </select>
                                            </label>
                                            <div x-show="kindExactVisible(kind.key)" x-collapse
                                                 class="mt-1.5 grid grid-cols-[5.5rem_1fr] items-center gap-2">
                                                <span class="text-[10px] text-gray-400"><?php echo e(__('blox_spacing_exact')); ?></span>
                                                <input type="text" :value="boxOverallDisplay(kind.key)"
                                                       @change="setBoxOverall(kind.key, $event)"
                                                       placeholder="<?php echo e(__('blox_spacing_custom_ph')); ?>"
                                                       :title="kind.key"
                                                       class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white font-mono">
                                            </div>
                                            <?php // 该类的四边独立编辑环：选「四边独立」展开；margin 琥珀 / padding 蓝 ?>
                                            <div x-show="kindBoxVisible(kind.key)" x-collapse class="mt-1">
                                                <div class="rounded-lg border p-1 relative"
                                                     :class="kind.key === 'margin' ? 'border-amber-300 bg-amber-50/70' : 'border-blue-300 bg-blue-50/80'">
                                                    <span class="absolute top-1 left-2 text-[8px] font-bold tracking-wider uppercase pointer-events-none"
                                                          :class="kind.key === 'margin' ? 'text-amber-500' : 'text-blue-500'" x-text="kind.key"></span>
                                                    <div class="flex justify-center mb-1">
                                                        <input type="text" :value="boxSideDisplay(kind.key,'top')" @change="setBoxSide(kind.key,'top',$event)"
                                                               placeholder="—" :title="kind.key + '-top'" class="yk-box-in"
                                                               :class="kind.key === 'margin' ? 'border-amber-300' : 'border-blue-300'">
                                                    </div>
                                                    <div class="flex items-center gap-0.5">
                                                        <input type="text" :value="boxSideDisplay(kind.key,'left')" @change="setBoxSide(kind.key,'left',$event)"
                                                               placeholder="—" :title="kind.key + '-left'" class="yk-box-in"
                                                               :class="kind.key === 'margin' ? 'border-amber-300' : 'border-blue-300'">
                                                        <div class="flex-1 min-w-0 h-8 rounded bg-white border border-gray-200 flex items-center justify-center">
                                                            <i class="ti text-sm text-gray-300" :class="'ti-' + elIcon(selEl.type)"></i>
                                                        </div>
                                                        <input type="text" :value="boxSideDisplay(kind.key,'right')" @change="setBoxSide(kind.key,'right',$event)"
                                                               placeholder="—" :title="kind.key + '-right'" class="yk-box-in"
                                                               :class="kind.key === 'margin' ? 'border-amber-300' : 'border-blue-300'">
                                                    </div>
                                                    <div class="flex justify-center mt-1">
                                                        <input type="text" :value="boxSideDisplay(kind.key,'bottom')" @change="setBoxSide(kind.key,'bottom',$event)"
                                                               placeholder="—" :title="kind.key + '-bottom'" class="yk-box-in"
                                                               :class="kind.key === 'margin' ? 'border-amber-300' : 'border-blue-300'">
                                                    </div>
                                                </div>
                                                <div class="flex items-start justify-between gap-2 mt-1">
                                                    <p class="text-[9px] text-gray-400 leading-relaxed flex-1"><?php echo e(__('blox_spacing_box_hint')); ?></p>
                                                    <button type="button" x-show="!hasKindSides(kind.key)" @click="boxOpen[kind.key] = false"
                                                            class="text-[9px] text-gray-400 hover:text-gray-600 shrink-0"><?= __('blox_collapse') ?></button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>

                                                                    </div>
                            </template>

                            <template x-if="selEl && elSchema(selEl.type).missing">
                                <div class="rounded border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                                    <div class="font-medium"><?= __('blox_plugin_missing_title') ?></div>
                                    <div class="mt-1 text-amber-700"><?= __('blox_plugin_missing_body') ?></div>
                                </div>
                            </template>

                            <template x-if="selEl && visibleCtrls().length === 0 && !elSchema(selEl.type).missing && !(isSelectedContainerEl() && panelTab === 'style') && !(panelTab === 'style' && supportsBoxStyles(selEl.type))">
                                <p class="text-xs text-gray-400 leading-relaxed"
                                   x-text="ctrlQuery.trim() || modifiedOnly ? <?= e($jt('blox_no_matching_settings')) ?>
                                       : (elSchema(selEl.type).container && panelTab === 'content'
                                           ? (elSchema(selEl.type).label + <?= e($jt('blox_container_content_hint')) ?>)
                                           : (panelTab === 'style' ? <?= e($jt('blox_no_style_settings')) ?> : <?= e($jt('blox_no_settings')) ?>))"></p>
                            </template>

                            <template x-for="ctrl in visibleCtrls()" :key="ctrl.key">
                                <div :data-control-key="ctrl.key">
                                    <template x-if="ctrl.type !== 'checkbox'">
                                        <div class="flex items-center justify-between gap-2 mb-1.5">
                                            <label class="block text-xs font-medium text-gray-600" x-text="ctrl.label"></label>
                                            <div x-show="ctrl.responsive" class="flex items-center gap-1">
                                                <div class="inline-flex rounded border border-gray-200 bg-gray-50 p-0.5">
                                                    <template x-for="d in devices" :key="ctrl.key + '-' + d.key">
                                                        <button type="button" @click="previewDevice = d.key" :title="d.label" :aria-label="d.label"
                                                                class="relative w-6 h-5 rounded inline-flex items-center justify-center"
                                                                :data-testid="'blox-control-' + ctrl.key + '-device-' + d.key"
                                                                :data-responsive-state="controlResponsiveState(ctrl, d.key).overridden ? 'override' : 'inherit'"
                                                                :class="previewDevice === d.key ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-blue-600'">
                                                            <i class="ti text-[11px]" :class="d.icon"></i>
                                                            <span x-show="controlResponsiveState(ctrl, d.key).overridden"
                                                                  class="absolute top-0 right-0 w-1.5 h-1.5 rounded-full bg-amber-400 ring-1 ring-white"></span>
                                                        </button>
                                                    </template>
                                                </div>
                                                <button type="button"
                                                        x-show="previewDevice !== 'desktop' && controlResponsiveState(ctrl).overridden"
                                                        @click="inheritControlValue(ctrl)"
                                                        :title="responsiveText.resetInherit" :aria-label="responsiveText.resetInherit"
                                                        :data-testid="'blox-control-' + ctrl.key + '-inherit'"
                                                        class="w-6 h-6 rounded border border-gray-200 text-gray-400 hover:border-blue-300 hover:text-blue-600 inline-flex items-center justify-center">
                                                    <i class="ti ti-link text-xs"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </template>

                                    <p x-show="ctrl.responsive && previewDevice !== 'desktop'"
                                       class="-mt-0.5 mb-1.5 text-[10px] text-gray-400 flex items-center gap-1">
                                        <i class="ti" :class="controlResponsiveState(ctrl).overridden ? 'ti-adjustments' : 'ti-link'"></i>
                                        <span x-text="responsiveStatusText(controlResponsiveState(ctrl))"></span>
                                    </p>

                                    <template x-if="ctrl.type === 'about_layout'">
                                        <div data-about-layout-control class="space-y-3 rounded border border-blue-200 bg-blue-50/60 p-3">
                                            <div class="relative flex gap-3">
                                                <template x-for="column in aboutColumnCards()" :key="column.key">
                                                    <div class="flex-1 min-w-0 h-16 rounded border border-blue-200 bg-white px-3 flex flex-col items-center justify-center gap-1 text-blue-700">
                                                        <i class="ti text-lg" :class="'ti-' + column.icon"></i>
                                                        <span class="text-[11px] font-medium truncate max-w-full" x-text="column.label"></span>
                                                    </div>
                                                </template>
                                                <button type="button" @click="swapAboutColumns()"
                                                        :title="homeDynamicText.swapColumns"
                                                        class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-8 h-8 rounded-full border border-blue-300 bg-white shadow-sm text-blue-600 hover:bg-blue-600 hover:text-white inline-flex items-center justify-center transition">
                                                    <i class="ti ti-arrows-exchange text-base"></i>
                                                </button>
                                            </div>
                                            <div>
                                                <p class="mb-1.5 text-[10px] font-medium text-gray-500" x-text="homeDynamicText.aboutRatio"></p>
                                                <div class="grid grid-cols-5 gap-1">
                                                    <template x-for="ratio in aboutRatioOptions" :key="ratio.value">
                                                        <button type="button" @click="setAboutRatio(ratio.value)" :title="ratio.label"
                                                                class="h-11 rounded border bg-white px-1.5 py-1 transition"
                                                                :class="aboutRatioValue() === ratio.value ? 'border-blue-500 ring-1 ring-blue-200 text-blue-700' : 'border-gray-200 text-gray-500 hover:border-blue-300'">
                                                            <span class="flex h-4 gap-0.5">
                                                                <span class="rounded-sm bg-current opacity-70" :style="'flex:' + aboutRatioPreviewSpans(ratio)[0]"></span>
                                                                <span class="rounded-sm bg-current opacity-35" :style="'flex:' + aboutRatioPreviewSpans(ratio)[1]"></span>
                                                            </span>
                                                            <span class="mt-0.5 block text-[9px]" x-text="ratio.label"></span>
                                                        </button>
                                                    </template>
                                                </div>                                                <div class="mt-2 flex items-center gap-2">
                                                    <button type="button" @click="adjustAboutRatio(-1)"
                                                            :disabled="aboutRatioIndex() <= 0"
                                                            title="<?php echo e(__('blox_home_about_text_narrower')); ?>"
                                                            class="w-8 h-8 shrink-0 rounded border border-blue-200 bg-white text-blue-600 hover:bg-blue-600 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center justify-center transition">
                                                        <i class="ti ti-chevron-left text-sm"></i>
                                                    </button>
                                                    <input type="range" min="0" max="4" step="1"
                                                           :value="aboutRatioIndex()"
                                                           @change="setAboutRatioIndex($event.target.value)"
                                                           :aria-label="homeDynamicText.aboutRatio"
                                                           class="block h-4 min-w-0 flex-1 cursor-ew-resize accent-blue-600">
                                                    <button type="button" @click="adjustAboutRatio(1)"
                                                            :disabled="aboutRatioIndex() >= aboutRatioOptions.length - 1"
                                                            title="<?php echo e(__('blox_home_about_text_wider')); ?>"
                                                            class="w-8 h-8 shrink-0 rounded border border-blue-200 bg-white text-blue-600 hover:bg-blue-600 hover:text-white disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center justify-center transition">
                                                        <i class="ti ti-chevron-right text-sm"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>

                                    <template x-if="ctrl.type === 'about_breakpoint'">
                                        <div class="grid grid-cols-2 gap-1 rounded border border-gray-200 bg-gray-50 p-1">
                                            <button type="button" @click="selEl.data[ctrl.key] = 'md'"
                                                    class="h-9 rounded text-xs transition inline-flex items-center justify-center gap-1.5"
                                                    :class="String(selEl.data[ctrl.key] || 'lg') === 'md' ? 'bg-white text-blue-600 shadow-sm ring-1 ring-blue-200' : 'text-gray-500 hover:text-blue-600'">
                                                <i class="ti ti-layout-columns text-sm"></i><?= __('blox_keep_two_columns') ?>
                                            </button>
                                            <button type="button" @click="selEl.data[ctrl.key] = 'lg'"
                                                    class="h-9 rounded text-xs transition inline-flex items-center justify-center gap-1.5"
                                                    :class="String(selEl.data[ctrl.key] || 'lg') === 'lg' ? 'bg-white text-blue-600 shadow-sm ring-1 ring-blue-200' : 'text-gray-500 hover:text-blue-600'">
                                                <i class="ti ti-layout-list text-sm"></i><?= __('blox_stack_single') ?>
                                            </button>
                                        </div>
                                    </template>

                                    <?php // url / video_url 是数据契约类型（保存管线定向清洗），编辑体验同普通文本框 ?>
                                    <template x-if="['text','url','video_url'].indexOf(ctrl.type) !== -1">
                                        <input type="text" x-model="selEl.data[ctrl.key]" :placeholder="ctrl.placeholder || ''"
                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm">
                                    </template>

                                    <template x-if="ctrl.type === 'textarea'">
                                        <textarea x-model="selEl.data[ctrl.key]" rows="3" :placeholder="ctrl.placeholder || ''"
                                                  class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm"></textarea>
                                    </template>

                                    <template x-if="ctrl.type === 'faq_repeater'">
                                        <div data-testid="blox-accordion-items" class="space-y-2">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="text-[10px] text-gray-400"
                                                      x-text="accordionItems(selEl).length + ' / ' + (ctrl.max || 30)"></span>
                                                <button type="button" @click="addAccordionItem()"
                                                        data-testid="blox-accordion-add"
                                                        class="h-7 rounded border border-blue-200 bg-white px-2 text-[10px] font-medium text-blue-600 hover:border-blue-400 hover:bg-blue-50 inline-flex items-center gap-1">
                                                    <i class="ti ti-plus text-sm"></i>
                                                    <span x-text="homeDynamicText.faqAdd"></span>
                                                </button>
                                            </div>
                                            <template x-for="(item, index) in accordionItems(selEl)" :key="index">
                                                <div data-testid="blox-accordion-item" class="rounded border border-gray-200 bg-gray-50/70 p-2.5 space-y-2">
                                                    <div class="flex items-center gap-1">
                                                        <span class="min-w-0 flex-1 text-[10px] font-semibold text-gray-500"
                                                              x-text="(index + 1) + '. ' + (item.question || homeDynamicText.faqNewQuestion)"></span>
                                                        <button type="button" @click.stop="moveAccordionItem(index, -1)"
                                                                :disabled="!accordionItemCanMove(index, -1)"
                                                                data-testid="blox-accordion-move-up"
                                                                title="<?= e(__('blox_ctx_move_up')) ?>" aria-label="<?= e(__('blox_ctx_move_up')) ?>"
                                                                class="w-7 h-7 rounded text-gray-400 hover:bg-white hover:text-blue-600 disabled:cursor-not-allowed disabled:text-gray-200 inline-flex items-center justify-center">
                                                            <i class="ti ti-arrow-up text-sm"></i>
                                                        </button>
                                                        <button type="button" @click.stop="moveAccordionItem(index, 1)"
                                                                :disabled="!accordionItemCanMove(index, 1)"
                                                                data-testid="blox-accordion-move-down"
                                                                title="<?= e(__('blox_ctx_move_down')) ?>" aria-label="<?= e(__('blox_ctx_move_down')) ?>"
                                                                class="w-7 h-7 rounded text-gray-400 hover:bg-white hover:text-blue-600 disabled:cursor-not-allowed disabled:text-gray-200 inline-flex items-center justify-center">
                                                            <i class="ti ti-arrow-down text-sm"></i>
                                                        </button>
                                                        <button type="button" @click.stop="deleteAccordionItem(index)"
                                                                data-testid="blox-accordion-delete"
                                                                :title="homeDynamicText.faqDelete" :aria-label="homeDynamicText.faqDelete"
                                                                class="w-7 h-7 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 inline-flex items-center justify-center">
                                                            <i class="ti ti-trash text-sm"></i>
                                                        </button>
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-[10px] font-medium text-gray-500"
                                                               x-text="homeDynamicText.faqQuestion"></label>
                                                        <input type="text" :value="item.question"
                                                               @input="setAccordionItem(index, 'question', $event.target.value)"
                                                               data-testid="blox-accordion-question"
                                                               class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-sm">
                                                    </div>
                                                    <div>
                                                        <label class="mb-1 block text-[10px] font-medium text-gray-500"
                                                               x-text="homeDynamicText.faqAnswer"></label>
                                                        <textarea rows="3" :value="item.answer"
                                                                  @input="setAccordionItem(index, 'answer', $event.target.value)"
                                                                  data-testid="blox-accordion-answer"
                                                                  class="w-full resize-y rounded border border-gray-200 bg-white px-2 py-1.5 text-sm"></textarea>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    <template x-if="ctrl.type === 'org_repeater'">
                                        <div data-testid="blox-org-nodes" class="space-y-2">
                                            <div class="flex items-center justify-between gap-2 rounded border border-blue-100 bg-blue-50/60 px-2.5 py-2">
                                                <span class="text-[10px] leading-relaxed text-blue-700"
                                                      x-text="orgNodes(selEl).length + ' / ' + (ctrl.max || 100)"></span>
                                                <span class="text-[10px] text-blue-500"><?= e(__('blox_org_add_child')) ?> / <?= e(__('blox_org_add_sibling')) ?></span>
                                            </div>
                                            <template x-for="(item, index) in orgNodes(selEl)" :key="item.id">
                                                <div data-testid="blox-org-node"
                                                     class="rounded border border-gray-200 bg-gray-50/70 p-2.5 space-y-2"
                                                     :style="'margin-left:' + Math.min(orgNodeDepth(index) * 12, 36) + 'px'">
                                                    <div class="flex items-center gap-1">
                                                        <i class="ti text-sm text-gray-400"
                                                           :class="index === 0 ? 'ti-crown' : 'ti-git-branch'"></i>
                                                        <span class="min-w-0 flex-1 truncate text-[10px] font-semibold text-gray-500"
                                                              x-text="orgNodeLevelText(index) + ' · ' + (item.name || orgText.newName)"></span>
                                                        <button type="button" @click.stop="moveOrgNode(index, -1)"
                                                                :disabled="!orgNodeCanMove(index, -1)"
                                                                data-testid="blox-org-move-up"
                                                                title="<?= e(__('blox_ctx_move_up')) ?>" aria-label="<?= e(__('blox_ctx_move_up')) ?>"
                                                                class="w-6 h-6 rounded text-gray-400 hover:bg-white hover:text-blue-600 disabled:cursor-not-allowed disabled:text-gray-200 inline-flex items-center justify-center">
                                                            <i class="ti ti-arrow-up text-xs"></i>
                                                        </button>
                                                        <button type="button" @click.stop="moveOrgNode(index, 1)"
                                                                :disabled="!orgNodeCanMove(index, 1)"
                                                                data-testid="blox-org-move-down"
                                                                title="<?= e(__('blox_ctx_move_down')) ?>" aria-label="<?= e(__('blox_ctx_move_down')) ?>"
                                                                class="w-6 h-6 rounded text-gray-400 hover:bg-white hover:text-blue-600 disabled:cursor-not-allowed disabled:text-gray-200 inline-flex items-center justify-center">
                                                            <i class="ti ti-arrow-down text-xs"></i>
                                                        </button>
                                                        <button type="button" @click.stop="deleteOrgNode(index)"
                                                                :disabled="index === 0"
                                                                data-testid="blox-org-delete"
                                                                :title="orgText.deleteNode" :aria-label="orgText.deleteNode"
                                                                class="w-6 h-6 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:cursor-not-allowed disabled:text-gray-200 inline-flex items-center justify-center">
                                                            <i class="ti ti-trash text-xs"></i>
                                                        </button>
                                                    </div>
                                                    <div class="grid grid-cols-1 gap-1.5">
                                                        <label class="block">
                                                            <span class="mb-1 block text-[9px] font-medium text-gray-400" x-text="orgText.name"></span>
                                                            <input type="text" :value="item.name"
                                                                   @input="setOrgNode(index, 'name', $event.target.value)"
                                                                   data-testid="blox-org-name"
                                                                   class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-xs">
                                                        </label>
                                                        <label class="block">
                                                            <span class="mb-1 block text-[9px] font-medium text-gray-400" x-text="orgText.title"></span>
                                                            <input type="text" :value="item.title"
                                                                   @input="setOrgNode(index, 'title', $event.target.value)"
                                                                   data-testid="blox-org-title"
                                                                   class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-xs">
                                                        </label>
                                                    </div>
                                                    <div class="flex gap-1.5">
                                                        <button type="button" @click.stop="addOrgNode(index, 'child')"
                                                                data-testid="blox-org-add-child"
                                                                class="flex-1 h-7 rounded border border-blue-200 bg-white text-[10px] font-medium text-blue-600 hover:border-blue-400 hover:bg-blue-50 inline-flex items-center justify-center gap-1">
                                                            <i class="ti ti-corner-down-right text-xs"></i><span x-text="orgText.addChild"></span>
                                                        </button>
                                                        <button type="button" @click.stop="addOrgNode(index, 'sibling')"
                                                                :disabled="index === 0"
                                                                data-testid="blox-org-add-sibling"
                                                                class="flex-1 h-7 rounded border border-gray-200 bg-white text-[10px] font-medium text-gray-600 hover:border-blue-300 hover:text-blue-600 disabled:cursor-not-allowed disabled:text-gray-300 inline-flex items-center justify-center gap-1">
                                                            <i class="ti ti-git-branch text-xs"></i><span x-text="orgText.addSibling"></span>
                                                        </button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    <?php // richtext：系统编辑器是主入口（点开即 TinyMCE 弹窗），
                                          // 下方摘要预览让人不点开也知道内容；HTML 源码收进折叠当备用 ?>
                                    <template x-if="ctrl.type === 'richtext'">
                                        <div x-data="{ showSrc: false }">
                                            <button type="button"
                                                    @click="openRte(() => selEl.data[ctrl.key], v => selEl.data[ctrl.key] = v)"
                                                    class="w-full inline-flex items-center justify-center gap-1.5 text-sm text-white bg-blue-600 hover:bg-blue-500 rounded-lg py-2 transition">
                                                <i class="ti ti-edit text-base"></i><?= __('blox_edit_content') ?>
                                            </button>
                                            <p class="mt-1.5 text-xs text-gray-500 bg-gray-50 border border-gray-100 rounded px-2 py-1.5 leading-relaxed break-words"
                                               x-text="(String(selEl.data[ctrl.key] || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || <?= e($jt('blox_no_content_yet')) ?>).slice(0, 80)"></p>
                                            <button type="button" @click="showSrc = !showSrc"
                                                    class="mt-1 text-[10px] text-gray-400 hover:text-gray-600"
                                                    x-text="showSrc ? <?= e($jt('blox_collapse_html')) ?> : 'HTML'"></button>
                                            <textarea x-show="showSrc" x-cloak x-model="selEl.data[ctrl.key]" rows="5"
                                                      class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs font-mono mt-1"></textarea>
                                        </div>
                                    </template>

                                    <template x-if="ctrl.type === 'select' && !ctrl.option_icons">
                                        <select :value="controlValue(ctrl)"
                                                @change="setControlValue(ctrl, $event.target.value)"
                                                :data-testid="'blox-control-' + ctrl.key"
                                                class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                                            <template x-for="(lbl, val) in controlOptions(ctrl)" :key="val">
                                                <option :value="val" :selected="controlValue(ctrl) === val" x-text="lbl"></option>
                                            </template>
                                        </select>
                                    </template>

                                    <?php // schema 带 option_icons 的 select → 图标按钮组（方向/对齐这类
                                          // 方位语义选项，图标比文字下拉直观；悬停出完整文字说明） ?>
                                    <template x-if="ctrl.type === 'select' && ctrl.option_icons">
                                        <div :class="ctrl.key === 'animation' ? 'grid grid-cols-4 gap-1' : 'flex gap-1'">
                                            <template x-for="(lbl, val) in controlOptions(ctrl)" :key="val">
                                                <button type="button" @click="setControlValue(ctrl, val)" :title="lbl"
                                                        :aria-label="lbl"
                                                        :aria-pressed="controlValue(ctrl) === val"
                                                        class="flex-1 h-8 rounded border inline-flex items-center justify-center transition"
                                                        :class="controlValue(ctrl) === val ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200 hover:text-blue-500'">
                                                    <i class="ti text-base" :class="'ti-' + ctrl.option_icons[val]"></i>
                                                </button>
                                            </template>
                                        </div>
                                    </template>

                                    <template x-if="ctrl.type === 'number'">
                                        <input type="number" :value="controlValue(ctrl)" @input="setControlValue(ctrl, Number($event.target.value))"
                                               :min="ctrl.min ?? null" :max="ctrl.max ?? null" :step="ctrl.step ?? null"
                                               :placeholder="ctrl.placeholder ?? (ctrl.default ?? '')"
                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm">
                                    </template>

                                    <template x-if="ctrl.type === 'range'">
                                        <div class="flex items-center gap-3" :data-testid="'blox-control-' + ctrl.key">
                                            <input type="range" :value="controlValue(ctrl)"
                                                   @input="setControlValue(ctrl, Number($event.target.value))"
                                                   :min="ctrl.min ?? 0" :max="ctrl.max ?? 100" :step="ctrl.step ?? 1"
                                                   class="block h-4 min-w-0 flex-1 cursor-ew-resize accent-blue-600">
                                            <output class="w-11 shrink-0 rounded border border-gray-200 bg-gray-50 px-1.5 py-1 text-center text-xs text-gray-600"
                                                    x-text="controlValue(ctrl) + '%'">0%</output>
                                        </div>
                                    </template>

                                    <template x-if="ctrl.type === 'checkbox'">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" class="rounded border-gray-300"
                                                   :checked="!!controlValue(ctrl)"
                                                   @change="setControlValue(ctrl, $event.target.checked)">
                                            <span class="text-xs font-medium text-gray-600" x-text="ctrl.label"></span>
                                        </label>
                                    </template>

                                    <template x-if="ctrl.type === 'color'">
                                        <div>
                                            <div class="flex items-center gap-1 mb-2">
                                                <select :value="colorTokenId(controlValue(ctrl))"
                                                        @change="$event.target.value && setControlValue(ctrl, colorTokenRef($event.target.value))"
                                                        data-testid="blox-color-token-select"
                                                        class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1 text-xs bg-white">
                                                    <option value=""><?= e(__('blox_design_custom')) ?></option>
                                                    <template x-for="token in colorTokenOptions(controlValue(ctrl))" :key="ctrl.key+'-'+token.id">
                                                        <option :value="token.id" x-text="colorTokenLabel(token)"></option>
                                                    </template>
                                                </select>
                                                <template x-for="token in activeColorTokens().slice(0, 4)" :key="ctrl.key+'-swatch-'+token.id">
                                                    <button type="button" @click="setControlValue(ctrl, colorTokenRef(token.id))" :title="token.name"
                                                            class="w-6 h-6 rounded border border-gray-200 shrink-0"
                                                            :class="colorTokenId(controlValue(ctrl)) === token.id ? 'ring-2 ring-blue-400' : ''"
                                                            :style="'background:' + token.value"></button>
                                                </template>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <input type="color" class="w-9 h-9 rounded border border-gray-200 cursor-pointer p-0.5"
                                                       :value="colorPickerValue(controlValue(ctrl), '#000000')"
                                                       @input="setControlValue(ctrl, $event.target.value)">
                                                <input type="text" :value="controlValue(ctrl)" @input="setControlValue(ctrl, $event.target.value)" placeholder="<?= e(__('blox_empty_default')) ?>"
                                                       class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                                <button type="button" @click="setControlValue(ctrl, '')"
                                                        class="text-gray-400 hover:text-red-500 p-1" title="<?= e(__('blox_clear')) ?>">
                                                    <i class="ti ti-x text-sm"></i></button>
                                            </div>
                                        </div>
                                    </template>

                                    <?php // icon：旧值无前缀时使用 Tabler；Bootstrap 图标保存为 bi:<name>。 ?>
                                    <template x-if="ctrl.type === 'icon'">
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="w-9 h-9 rounded border border-gray-200 flex items-center justify-center text-gray-600 shrink-0">
                                                    <i class="text-lg" :class="iconClass(selEl.data[ctrl.key])"></i>
                                                </span>
                                                <input type="text" x-model="selEl.data[ctrl.key]" data-testid="blox-icon-value" placeholder="<?= e(__('blox_icon_ph')) ?>"
                                                       class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                                <button type="button" data-testid="blox-icon-library-toggle"
                                                        @click="toggleIconPicker(ctrl.key, selEl.data[ctrl.key])"
                                                        class="shrink-0 text-xs text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2 py-1.5 transition"
                                                        x-text="iconPick === ctrl.key ? <?= e($jt('blox_collapse')) ?> : <?= e($jt('blox_icon_library')) ?>"></button>
                                            </div>
                                            <div x-show="iconPick === ctrl.key" x-cloak
                                                 class="mt-2 border border-gray-200 rounded-lg p-2 bg-gray-50">
                                                <div class="grid grid-cols-2 gap-1 mb-2" role="group" aria-label="Icon library">
                                                    <button type="button" @click="setIconProvider('tabler')" data-testid="blox-icon-provider-tabler"
                                                            class="h-7 rounded border text-[10px] font-medium transition"
                                                            :class="iconProvider === 'tabler' ? 'border-blue-500 bg-blue-500 text-white' : 'border-gray-200 text-gray-600 hover:border-blue-300'">Tabler</button>
                                                    <button type="button" @click="setIconProvider('bootstrap')" data-testid="blox-icon-provider-bootstrap"
                                                            class="h-7 rounded border text-[10px] font-medium transition"
                                                            :class="iconProvider === 'bootstrap' ? 'border-blue-500 bg-blue-500 text-white' : 'border-gray-200 text-gray-600 hover:border-blue-300'">Bootstrap</button>
                                                </div>
                                                <input type="text" x-model="iconQuery" data-testid="blox-icon-search" placeholder="<?= e(__('blox_icon_search_ph')) ?>"
                                                       class="w-full border border-gray-200 rounded px-2 py-1 text-xs mb-2">
                                                <div class="flex flex-wrap gap-1 max-h-40 overflow-y-auto blox-scroll">
                                                    <template x-for="ic in iconMatches()" :key="ic">
                                                        <button type="button" @click="selEl.data[ctrl.key] = ic" :title="ic" :data-testid="'blox-icon-option-' + ic.replace(':', '-')"
                                                                class="w-8 h-8 flex items-center justify-center rounded border transition"
                                                                :class="selEl.data[ctrl.key] === ic ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 bg-white text-gray-600 hover:border-blue-400 hover:text-blue-500'">
                                                            <i class="text-base" :class="iconClass(ic)"></i>
                                                        </button>
                                                    </template>
                                                </div>
                                                <p class="text-[10px] text-gray-400 mt-1.5" x-text="iconHint()"></p>
                                            </div>
                                        </div>
                                    </template>

                                    <?php // image：图片地址 + 缩略预览 + 媒体库选图（复用 openMedia 弹窗） ?>
                                    <template x-if="ctrl.type === 'image'">
                                        <div>
                                            <template x-if="selEl && selEl.type === 'home-block' && String((selEl.data || {}).block_type || '') === 'cta' && ctrl.key === 'bg_image'">
                                                <div class="space-y-2" data-testid="blox-cta-background-control">
                                                    <div class="relative aspect-[3/2] rounded overflow-hidden border border-gray-200 bg-gray-100">
                                                        <template x-if="selEl.data[ctrl.key]">
                                                            <img :src="selEl.data[ctrl.key]" class="w-full h-full object-cover" alt="">
                                                        </template>
                                                        <template x-if="!selEl.data[ctrl.key]">
                                                            <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-400">
                                                                <i class="ti ti-photo-off text-2xl"></i>
                                                                <span class="mt-1 text-[10px]" x-text="homeDynamicText.noImage"></span>
                                                            </div>
                                                        </template>
                                                        <button type="button" @click="openMedia(u => selEl.data[ctrl.key] = u)"
                                                                data-testid="blox-cta-background-media"
                                                                class="absolute inset-x-2 bottom-2 h-8 rounded bg-gray-900/80 hover:bg-blue-600 text-white text-xs inline-flex items-center justify-center gap-1.5 transition">
                                                            <i class="ti ti-photo-edit text-sm"></i><span x-text="homeDynamicText.replaceImage"></span>
                                                        </button>
                                                    </div>
                                                    <details class="rounded border border-gray-200 bg-gray-50">
                                                        <summary class="px-2.5 py-1.5 text-[10px] text-gray-500 cursor-pointer" x-text="homeDynamicText.imageUrl"></summary>
                                                        <div class="px-2 pb-2">
                                                            <input type="text" x-model="selEl.data[ctrl.key]" placeholder="/uploads/images/xx.jpg"
                                                                   data-testid="blox-cta-background-url"
                                                                   class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                        </div>
                                                    </details>
                                                </div>
                                            </template>
                                            <template x-if="selEl && selEl.type === 'home-banner-item'">
                                                <div class="space-y-2" data-banner-image-control>
                                                    <div class="relative aspect-[16/7] rounded overflow-hidden border border-gray-200 bg-gray-100">
                                                        <template x-if="selEl.data[ctrl.key]">
                                                            <img :src="selEl.data[ctrl.key]" class="w-full h-full object-cover" alt="">
                                                        </template>
                                                        <template x-if="!selEl.data[ctrl.key]">
                                                            <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-400">
                                                                <i class="ti ti-photo-off text-2xl"></i>
                                                                <span class="text-[10px] mt-1" x-text="homeDynamicText.noImage"></span>
                                                            </div>
                                                        </template>
                                                        <button type="button" @click="openMedia(u => selEl.data[ctrl.key] = u)"
                                                                class="absolute inset-x-2 bottom-2 h-8 rounded bg-gray-900/80 hover:bg-blue-600 text-white text-xs inline-flex items-center justify-center gap-1.5 transition">
                                                            <i class="ti ti-photo-edit text-sm"></i><span x-text="homeDynamicText.replaceImage"></span>
                                                        </button>
                                                    </div>
                                                    <details class="rounded border border-gray-200 bg-gray-50">
                                                        <summary class="px-2.5 py-1.5 text-[10px] text-gray-500 cursor-pointer" x-text="homeDynamicText.imageUrl"></summary>
                                                        <div class="px-2 pb-2">
                                                            <input type="text" x-model="selEl.data[ctrl.key]" placeholder="/uploads/images/xx.jpg"
                                                                   class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                        </div>
                                                    </details>
                                                </div>
                                            </template>
                                            <template x-if="selEl && selEl.type !== 'home-banner-item' && !(selEl.type === 'home-block' && String((selEl.data || {}).block_type || '') === 'cta' && ctrl.key === 'bg_image')">
                                                <div class="flex items-center gap-2">
                                                    <template x-if="selEl.data[ctrl.key]">
                                                        <img :src="selEl.data[ctrl.key]" class="w-9 h-9 rounded border border-gray-200 object-cover shrink-0" alt="">
                                                    </template>
                                                    <input type="text" x-model="selEl.data[ctrl.key]" placeholder="/uploads/images/xx.jpg"
                                                           class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                                    <button type="button" @click="openMedia(u => selEl.data[ctrl.key] = u)"
                                                            class="shrink-0 text-xs text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2 py-1.5 transition"><?= __('admin_media') ?></button>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    <?php // 未覆盖的控件类型：明说，而不是静默留空 ?>
                                    <template x-if="['text','url','video_url','textarea','richtext','select','number','range','checkbox','color','icon','image','about_layout','about_breakpoint'].indexOf(ctrl.type) === -1">
                                        <p class="text-[10px] text-amber-600 leading-relaxed">
                                            <?= __('blox_ctrl_unsupported_pre') ?>（<span x-text="ctrl.type"></span>）<?= __('blox_ctrl_unsupported_post') ?>
                                        </p>
                                    </template>
                                    <template x-if="ctrl.help">
                                        <p class="mt-1 text-[10px] text-gray-400 leading-relaxed" x-text="ctrl.help"></p>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- 区块标题字段：按元素式交互单独编辑，不再混入整块设置 -->
                    <template x-if="sel && selectedSectionField">
                        <div class="space-y-5">
                            <div x-show="panelTab === 'content'" class="space-y-4">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"
                                           x-text="sectionFieldName(selectedSectionField)"></label>
                                    <textarea rows="4" x-model="sel.settings[selectedSectionField]"
                                              :placeholder="selectedSectionField === 'subtitle' ? <?= e($jt('blox_ph_section_subtitle')) ?> : <?= e($jt('blox_ph_section_title')) ?>"
                                              class="w-full resize-y border border-gray-200 rounded px-2.5 py-2 text-sm leading-relaxed"></textarea>
                                </div>
                                <div x-show="selectedSectionField === 'title'">
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_heading_level') ?></label>
                                    <div class="grid grid-cols-3 gap-1">
                                        <template x-for="tag in ['h2', 'h3', 'h4']" :key="tag">
                                            <button type="button" @click="sel.settings.title_tag = tag"
                                                    class="h-8 rounded border text-xs font-semibold uppercase transition"
                                                    :class="(sel.settings.title_tag || 'h2') === tag ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                    x-text="tag"></button>
                                        </template>
                                    </div>
                                </div>
                                <button type="button" @click="sel.settings[selectedSectionField] = ''"
                                        class="w-full h-9 rounded border border-red-200 text-xs text-red-500 hover:bg-red-50 transition inline-flex items-center justify-center gap-1.5">
                                    <i class="ti ti-trash text-sm"></i><?= __('blox_clear_field') ?>
                                </button>
                            </div>

                            <div x-show="panelTab === 'style'" class="space-y-5">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_align') ?></label>
                                    <div class="grid grid-cols-3 gap-1">
                                        <template x-for="opt in [{k:'left',i:'align-left'},{k:'center',i:'align-center'},{k:'right',i:'align-right'}]" :key="opt.k">
                                            <button type="button" @click="sel.settings.title_align = opt.k"
                                                    class="h-8 rounded border inline-flex items-center justify-center transition"
                                                    :class="(sel.settings.title_align || 'center') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'">
                                                <i class="ti text-base" :class="'ti-' + opt.i"></i>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_font_size') ?></label>
                                    <div class="grid grid-cols-4 gap-1">
                                        <template x-for="opt in (selectedSectionField === 'subtitle' ? [{k:'sm',l:<?= e($jt('blox_spacing_sm')) ?>},{k:'md',l:<?= e($jt('blox_spacing_md')) ?>},{k:'lg',l:<?= e($jt('blox_spacing_lg')) ?>}] : [{k:'sm',l:<?= e($jt('blox_spacing_sm')) ?>},{k:'md',l:<?= e($jt('blox_spacing_md')) ?>},{k:'lg',l:<?= e($jt('blox_spacing_lg')) ?>},{k:'xl',l:<?= e($jt('blox_spacing_xl')) ?>}])" :key="opt.k">
                                            <button type="button" @click="sel.settings[selectedSectionField + '_size'] = opt.k"
                                                    class="h-8 rounded border text-xs transition"
                                                    :class="(sel.settings[selectedSectionField + '_size'] || 'md') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                    x-text="opt.l"></button>
                                        </template>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_text_color') ?></label>
                                    <div class="flex items-center gap-1 mb-2">
                                        <select :value="colorTokenId(sel.settings[selectedSectionField + '_color'])"
                                                @change="sel.settings[selectedSectionField + '_color'] = $event.target.value ? colorTokenRef($event.target.value) : sel.settings[selectedSectionField + '_color']"
                                                class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1 text-xs bg-white">
                                            <option value=""><?= e(__('blox_design_custom')) ?></option>
                                            <template x-for="token in colorTokenOptions(sel.settings[selectedSectionField + '_color'])" :key="'sec-title-'+token.id">
                                                <option :value="token.id" x-text="colorTokenLabel(token)"></option>
                                            </template>
                                        </select>
                                        <template x-for="token in activeColorTokens().slice(0, 4)" :key="'sec-title-swatch-'+token.id">
                                            <button type="button" @click="sel.settings[selectedSectionField + '_color'] = colorTokenRef(token.id)" :title="token.name"
                                                    class="w-6 h-6 rounded border border-gray-200 shrink-0"
                                                    :class="colorTokenId(sel.settings[selectedSectionField + '_color']) === token.id ? 'ring-2 ring-blue-400' : ''"
                                                    :style="'background:' + token.value"></button>
                                        </template>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="color" class="w-9 h-9 rounded border border-gray-200 cursor-pointer p-0.5"
                                               :value="colorPickerValue(sel.settings[selectedSectionField + '_color'], selectedSectionField === 'subtitle' ? '#6b7280' : '#111827')"
                                               @input="sel.settings[selectedSectionField + '_color'] = $event.target.value">
                                        <input type="text" x-model="sel.settings[selectedSectionField + '_color']" placeholder="<?= e(__('blox_empty_theme_default')) ?>"
                                               class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                        <button type="button" @click="sel.settings[selectedSectionField + '_color'] = ''"
                                                class="text-gray-400 hover:text-red-500 p-1" title="<?= e(__('blox_clear')) ?>">
                                            <i class="ti ti-x text-sm"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- ── 区块设置：内容 / 样式 两页签 ── -->
                    <template x-if="sel && !selEl && !selectedSectionField && panelTab !== 'condition'">
                        <div>
                            <div x-show="panelTab === 'content'" class="space-y-5">
                                <template x-if="selLayer === 'con'">
                                    <p class="text-xs text-gray-400 leading-relaxed">
                                        <?= __('blox_container_help') ?>
                                    </p>
                                </template>
                                <template x-if="selLayer === 'col'">
                                    <p class="text-xs text-gray-400 leading-relaxed">
                                        <?= __('blox_column_help') ?>
                                    </p>
                                </template>
                                <!-- 区块标题 / 副标题：渲染器会输出成居中的段落头 -->
                                <div x-show="selLayer === 'sec'">
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_field_section_title') ?></label>
                                    <input type="text" x-model="sel.settings.title" placeholder="<?= e(__('blox_empty_hidden')) ?>"
                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm">
                                    <input type="text" x-model="sel.settings.subtitle" placeholder="<?= e(__('blox_subtitle_optional')) ?>"
                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm mt-1.5">
                                </div>
                                <div x-show="selLayer === 'sec'">
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= e(__('blox_section_anchor')) ?></label>
                                    <div class="flex items-center">
                                        <span class="h-8 px-2 border border-r-0 border-gray-200 rounded-l bg-gray-50 text-xs text-gray-400 inline-flex items-center">#</span>
                                        <input type="text" x-model.trim="sel.settings.anchor_id" maxlength="64"
                                               data-testid="blox-section-anchor" placeholder="features"
                                               class="w-full h-8 border border-gray-200 rounded-r px-2 text-sm font-mono">
                                    </div>
                                    <p class="mt-1 text-[10px] text-gray-400"><?= e(__('blox_section_anchor_hint')) ?></p>
                                    <p x-show="sel.settings.anchor_id && !anchorIdValid(sel.settings.anchor_id)"
                                       class="mt-1 text-[10px] text-red-500"><?= e(__('blox_section_anchor_invalid')) ?></p>
                                    <p x-show="anchorIdDuplicate(sel.settings.anchor_id)"
                                       class="mt-1 text-[10px] text-amber-600"><?= e(__('blox_section_anchor_duplicate')) ?></p>
                                </div>
                                <!-- 当前区块的元素概览；具体元素在右侧结构树中选择。 -->
                                <div x-show="selLayer === 'sec'" class="pt-3 border-t border-gray-100">
                                    <div class="text-xs text-gray-400" x-text="<?= e($jt('blox_section_el_hint')) ?>.replace(':n', elCount(sel))"></div>
                                </div>
                            </div>

                            <div x-show="panelTab === 'style'" class="space-y-5">
                                <?php // 分层随结构树选中（Bricks 心智）：树里选「区块」→ 全宽背景层设置，
                                      // 选「容器」节点 → 内容层设置。一次只显示当前层。 ?>
                                <div x-show="selLayer === 'sec'" class="space-y-5">
                                <!-- 背景色 -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_bg_color') ?></label>
                                    <div class="flex items-center gap-1 mb-2">
                                        <select :value="colorTokenId(sel.settings.bg_color)"
                                                @change="sel.settings.bg_color = $event.target.value ? colorTokenRef($event.target.value) : sel.settings.bg_color"
                                                class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1 text-xs bg-white">
                                            <option value=""><?= e(__('blox_design_custom')) ?></option>
                                            <template x-for="token in colorTokenOptions(sel.settings.bg_color)" :key="'sec-bg-'+token.id">
                                                <option :value="token.id" x-text="colorTokenLabel(token)"></option>
                                            </template>
                                        </select>
                                        <template x-for="token in activeColorTokens().slice(0, 4)" :key="'sec-bg-swatch-'+token.id">
                                            <button type="button" @click="sel.settings.bg_color = colorTokenRef(token.id)" :title="token.name"
                                                    class="w-6 h-6 rounded border border-gray-200 shrink-0"
                                                    :class="colorTokenId(sel.settings.bg_color) === token.id ? 'ring-2 ring-blue-400' : ''"
                                                    :style="'background:' + token.value"></button>
                                        </template>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="color" class="w-9 h-9 rounded border border-gray-200 cursor-pointer p-0.5"
                                               :value="colorPickerValue(sel.settings.bg_color, '#ffffff')"
                                               @input="sel.settings.bg_color = $event.target.value">
                                        <input type="text" class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm"
                                               placeholder="<?= e(__('blox_empty_transparent')) ?>" x-model="sel.settings.bg_color">
                                        <button type="button" @click="sel.settings.bg_color = ''"
                                                class="text-gray-400 hover:text-red-500 p-1" title="<?= e(__('blox_clear')) ?>">
                                            <i class="ti ti-x text-sm"></i></button>
                                    </div>
                                </div>
                                <!-- 渐变背景：无/预置色板/自定义双色。叠在背景色/背景图之上 -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_gradient_bg') ?></label>
                                    <div class="grid grid-cols-5 gap-1.5">
                                        <?php // 显式「无」清除项：比「再点一次取消」可发现得多 ?>
                                        <button type="button" title="<?= e(__('blox_no_gradient')) ?>" @click="sel.settings.bg_gradient = ''"
                                                class="h-8 rounded border transition inline-flex items-center justify-center"
                                                :class="!sel.settings.bg_gradient ? 'border-blue-500 ring-2 ring-blue-200 text-blue-500' : 'border-gray-200 text-gray-400 hover:border-blue-300'">
                                            <i class="ti ti-ban text-sm"></i>
                                        </button>
                                        <template x-for="g in gradientPresets" :key="g.label">
                                            <button type="button" :title="g.label"
                                                    @click="sel.settings.bg_gradient = g.css"
                                                    class="h-8 rounded border transition"
                                                    :class="sel.settings.bg_gradient === g.css ? 'border-blue-500 ring-2 ring-blue-200' : 'border-gray-200 hover:border-blue-300'"
                                                    :style="'background:' + g.css"></button>
                                        </template>
                                    </div>
                                    <?php // 自定义双色渐变：改任意一项立即生效（画布实时预览即所见） ?>
                                    <div class="mt-2 flex items-center gap-1.5">
                                        <span class="text-[10px] text-gray-400 shrink-0"><?= __('admin_custom') ?></span>
                                        <input type="color" x-model="gradA" @input="applyCustomGrad()"
                                               class="w-8 h-8 rounded border border-gray-200 cursor-pointer p-0.5" title="<?= e(__('blox_grad_from')) ?>">
                                        <input type="color" x-model="gradB" @input="applyCustomGrad()"
                                               class="w-8 h-8 rounded border border-gray-200 cursor-pointer p-0.5" title="<?= e(__('blox_grad_to')) ?>">
                                        <select x-model="gradDir" @change="applyCustomGrad()"
                                                class="flex-1 border border-gray-200 rounded px-1.5 py-1.5 text-xs bg-white">
                                            <option value="135">↘ <?= __('blox_grad_diag') ?></option>
                                            <option value="90">→ <?= __('blox_dir_row_short') ?></option>
                                            <option value="180">↓ <?= __('blox_dir_column_short') ?></option>
                                        </select>
                                        <span class="w-8 h-8 rounded border border-gray-200 shrink-0"
                                              :style="'background:linear-gradient(' + gradDir + 'deg,' + gradA + ' 0%,' + gradB + ' 100%)'"
                                              title="<?= e(__('blox_custom_preview')) ?>"></span>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-1" x-show="sel.settings.bg_gradient"
                                       x-text="<?= e($jt('blox_current_gradient')) ?>.replace(':g', (gradientPresets.find(g => g.css === sel.settings.bg_gradient) || {}).label || <?= e($jt('blox_custom_gradient')) ?>)"></p>
                                </div>
                                <!-- 背景图 + 独立遮罩 + 焦点 -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_bg_image') ?></label>
                                    <div class="flex items-center gap-2">
                                        <template x-if="sel.settings.bg_image">
                                            <img :src="sel.settings.bg_image" class="w-9 h-9 rounded border border-gray-200 object-cover shrink-0" alt="">
                                        </template>
                                        <input type="text" :value="sel.settings.bg_image || ''" placeholder="/uploads/images/xx.jpg"
                                               @change="setSectionBackgroundImage($event.target.value)"
                                               data-testid="blox-section-bg-image"
                                               class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                        <button type="button" @click="openMedia(u => setSectionBackgroundImage(u))"
                                                class="shrink-0 text-xs text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2 py-1.5 transition"><?= __('admin_media') ?></button>
                                        <button type="button" @click="setSectionBackgroundImage('')"
                                                class="text-gray-400 hover:text-red-500 p-1 shrink-0" title="<?= e(__('blox_clear')) ?>">
                                            <i class="ti ti-x text-sm"></i></button>
                                    </div>
                                    <div x-show="sel.settings.bg_image" class="mt-3 space-y-3">
                                        <div>
                                            <label class="block text-[10px] text-gray-400 mb-1"><?= e(__('blox_bg_overlay_color')) ?></label>
                                            <div class="flex items-center gap-2">
                                                <input type="color" class="w-8 h-8 rounded border border-gray-200 cursor-pointer p-0.5"
                                                       :value="colorPickerValue(sel.settings.bg_overlay_color, '#000000')"
                                                       @input="sel.settings.bg_overlay_color = $event.target.value">
                                                <input type="text" x-model="sel.settings.bg_overlay_color" placeholder="#000000"
                                                       class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-between text-[10px] text-gray-400 mb-1">
                                            <span><?= __('blox_overlay_opacity') ?></span>
                                            <span x-text="(sel.settings.bg_overlay_opacity ?? 0) + '%'"></span>
                                        </div>
                                        <input type="range" min="0" max="100" step="5" class="w-full"
                                               :value="sel.settings.bg_overlay_opacity ?? 0"
                                               @input="sel.settings.bg_overlay_opacity = parseInt($event.target.value, 10)"
                                               data-testid="blox-section-overlay-opacity">
                                        <div>
                                            <label class="block text-[10px] text-gray-400 mb-1.5"><?= e(__('blox_bg_focal_point')) ?></label>
                                            <div class="grid grid-cols-3 gap-1 w-24">
                                                <template x-for="position in bgPositionOptions" :key="position.key">
                                                    <button type="button" @click="sel.settings.bg_position = position.key"
                                                            :title="position.label" :aria-label="position.label"
                                                            :data-testid="'blox-bg-position-' + position.key"
                                                            class="h-7 rounded border inline-flex items-center justify-center transition"
                                                            :class="(sel.settings.bg_position || 'center') === position.key ? 'border-blue-500 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-300 hover:border-blue-300'">
                                                        <i class="ti ti-point text-[9px]"></i>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= e(__('blox_section_min_height')) ?></label>
                                    <select :value="sel.settings.min_height || ''" @change="sel.settings.min_height = $event.target.value"
                                            data-testid="blox-section-min-height"
                                            class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                                        <option value=""><?= e(__('blox_height_auto')) ?></option>
                                        <option value="sm"><?= e(__('blox_height_compact')) ?></option>
                                        <option value="md"><?= e(__('blox_height_medium')) ?></option>
                                        <option value="lg"><?= e(__('blox_height_tall')) ?></option>
                                        <option value="screen"><?= e(__('blox_height_screen')) ?></option>
                                    </select>
                                    <div x-show="sel.settings.min_height" class="mt-2">
                                        <label class="block text-[10px] text-gray-400 mb-1.5"><?= e(__('blox_content_vertical_align')) ?></label>
                                        <div class="grid grid-cols-3 gap-1">
                                            <button type="button" @click="sel.settings.content_v_align = 'start'"
                                                    class="h-8 rounded border text-xs" title="<?= e(__('blox_align_top')) ?>" aria-label="<?= e(__('blox_align_top')) ?>"
                                                    :class="sel.settings.content_v_align === 'start' ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500'">
                                                <i class="ti ti-align-box-top-center"></i>
                                            </button>
                                            <button type="button" @click="sel.settings.content_v_align = 'center'"
                                                    class="h-8 rounded border text-xs" title="<?= e(__('blox_align_vcenter')) ?>" aria-label="<?= e(__('blox_align_vcenter')) ?>"
                                                    :class="(sel.settings.content_v_align || 'center') === 'center' ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500'">
                                                <i class="ti ti-align-box-center-middle"></i>
                                            </button>
                                            <button type="button" @click="sel.settings.content_v_align = 'end'"
                                                    class="h-8 rounded border text-xs" title="<?= e(__('blox_align_bottom')) ?>" aria-label="<?= e(__('blox_align_bottom')) ?>"
                                                    :class="sel.settings.content_v_align === 'end' ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500'">
                                                <i class="ti ti-align-box-bottom-center"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <!-- 上下内边距 -->
                                <div>
                                    <div class="flex items-center justify-between gap-2 mb-1.5">
                                        <label class="block text-xs font-medium text-gray-600"><?= __('blox_section_spacing') ?></label>
                                        <div class="flex items-center gap-1">
                                            <div class="inline-flex rounded border border-gray-200 bg-gray-50 p-0.5">
                                                <template x-for="d in devices" :key="'section-padding-' + d.key">
                                                    <button type="button" @click="previewDevice = d.key" :title="d.label" :aria-label="d.label"
                                                            :data-testid="'blox-section-padding-device-' + d.key"
                                                            :data-responsive-state="sectionResponsiveState('padding', 'md', d.key).overridden ? 'override' : 'inherit'"
                                                            class="relative w-6 h-5 rounded inline-flex items-center justify-center"
                                                            :class="previewDevice === d.key ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-blue-600'">
                                                        <i class="ti text-[11px]" :class="d.icon"></i>
                                                        <span x-show="sectionResponsiveState('padding', 'md', d.key).overridden"
                                                              class="absolute top-0 right-0 w-1.5 h-1.5 rounded-full bg-amber-400 ring-1 ring-white"></span>
                                                    </button>
                                                </template>
                                            </div>
                                            <button type="button" x-show="previewDevice !== 'desktop' && sectionResponsiveState('padding', 'md').overridden"
                                                    @click="inheritSectionResponsiveValue('padding', 'md')"
                                                    :title="responsiveText.resetInherit" :aria-label="responsiveText.resetInherit"
                                                    data-testid="blox-section-padding-inherit"
                                                    class="w-6 h-6 rounded border border-gray-200 text-gray-400 hover:border-blue-300 hover:text-blue-600 inline-flex items-center justify-center">
                                                <i class="ti ti-link text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-5 gap-1">
                                        <template x-for="opt in padOptions" :key="opt.k">
                                            <button type="button" @click="setSectionResponsiveValue('padding', opt.k, 'md')"
                                                    :data-testid="'blox-section-padding-' + opt.k"
                                                    class="h-8 rounded text-xs border transition"
                                                    :class="sectionResponsiveValue('padding', 'md') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                    x-text="opt.label"></button>
                                        </template>
                                    </div>
                                    <p x-show="previewDevice !== 'desktop'" class="mt-1 text-[10px] text-gray-400 flex items-center gap-1">
                                        <i class="ti" :class="sectionResponsiveState('padding', 'md').overridden ? 'ti-adjustments' : 'ti-link'"></i>
                                        <span x-text="responsiveStatusText(sectionResponsiveState('padding', 'md'))"></span>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_visible_devices') ?></label>
                                    <div class="flex gap-1">
                                        <template x-for="dev in [{k:'d',l:<?= e($jt('blox_device_desktop')) ?>},{k:'t',l:<?= e($jt('blox_device_tablet')) ?>},{k:'m',l:<?= e($jt('blox_device_mobile')) ?>}]" :key="'secvis'+dev.k">
                                            <button type="button" @click="toggleDevice(sel, dev.k, false)"
                                                    class="flex-1 h-8 rounded text-xs border transition"
                                                    :class="deviceVisible(sel, dev.k, false) ? 'border-green-400 bg-green-50 text-green-700' : 'border-gray-200 text-gray-400 line-through'"
                                                    x-text="dev.l"></button>
                                        </template>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-1"><?= __('blox_visible_hint') ?></p>
                                </div>

                                </div>

                                <div x-show="selLayer === 'col'" class="space-y-5">
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="text-xs font-medium text-gray-600 inline-flex items-center gap-1">
                                                <i class="ti ti-columns-1 text-sm text-green-500"></i><?= __('blox_col_settings') ?>
                                            </span>
                                            <span class="text-[10px] text-gray-400" x-text="<?= e($jt('blox_col_word')) ?>.replace(':n', selectedCi + 1)"></span>
                                        </div>
                                        <div class="grid grid-cols-12 gap-1 h-8 rounded bg-white border border-gray-200 p-1">
                                            <span class="rounded bg-green-400" :style="'grid-column: span ' + columnSpan(selectedCol()) + ' / span ' + columnSpan(selectedCol())"></span>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_col_ratio') ?></label>
                                        <div class="grid grid-cols-4 gap-1">
                                            <template x-for="n in [2,3,4,6,8,9,10,12]" :key="'span'+n">
                                                <button type="button" @click="setColumnSpan(n)"
                                                        class="h-8 rounded text-xs border transition"
                                                        :class="columnSpan(selectedCol()) === n ? 'border-green-400 bg-green-50 text-green-700' : 'border-gray-200 text-gray-500 hover:border-green-300'"
                                                        x-text="n + '/12'"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_col_bg') ?></label>
                                        <div class="flex items-center gap-1 mb-2">
                                            <select :value="colorTokenId(selectedColData().card_bg)"
                                                    @change="selectedColData().card_bg = $event.target.value ? colorTokenRef($event.target.value) : selectedColData().card_bg"
                                                    class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1 text-xs bg-white">
                                                <option value=""><?= e(__('blox_design_custom')) ?></option>
                                                <template x-for="token in colorTokenOptions(selectedColData().card_bg)" :key="'col-bg-'+token.id">
                                                    <option :value="token.id" x-text="colorTokenLabel(token)"></option>
                                                </template>
                                            </select>
                                            <template x-for="token in activeColorTokens().slice(0, 4)" :key="'col-bg-swatch-'+token.id">
                                                <button type="button" @click="selectedColData().card_bg = colorTokenRef(token.id)" :title="token.name"
                                                        class="w-6 h-6 rounded border border-gray-200 shrink-0"
                                                        :class="colorTokenId(selectedColData().card_bg) === token.id ? 'ring-2 ring-green-400' : ''"
                                                        :style="'background:' + token.value"></button>
                                            </template>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <input type="color" class="w-9 h-9 rounded border border-gray-200 cursor-pointer p-0.5"
                                                   :value="colorPickerValue(selectedColData().card_bg, '#ffffff')"
                                                   @input="selectedColData().card_bg = $event.target.value">
                                            <input type="text" x-model="selectedColData().card_bg" placeholder="<?= e(__('blox_empty_default')) ?>"
                                                   class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                            <button type="button" @click="selectedColData().card_bg = ''"
                                                    class="text-gray-400 hover:text-red-500 p-1" title="<?= e(__('blox_clear')) ?>">
                                                <i class="ti ti-x text-sm"></i></button>
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-1"><?= __('blox_col_bg_hint') ?></p>
                                        <div class="mt-3" data-testid="blox-column-background-image">
                                            <label class="block text-[10px] font-medium text-gray-500 mb-1.5"><?= __('blox_bg_image') ?></label>
                                            <div class="flex items-center gap-2">
                                                <template x-if="selectedColData().card_bg_image">
                                                    <img :src="selectedColData().card_bg_image"
                                                         class="w-10 h-10 rounded border border-gray-200 object-cover shrink-0" alt="">
                                                </template>
                                                <input type="text" x-model="selectedColData().card_bg_image"
                                                       @change="setColumnBackgroundImage($event.target.value)"
                                                       placeholder="/uploads/images/xx.jpg"
                                                       data-testid="blox-column-background-image-url"
                                                       class="min-w-0 flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                                <button type="button" @click="pickColumnBackgroundImage()"
                                                        data-testid="blox-column-background-image-media"
                                                        class="shrink-0 text-xs text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2 py-1.5 transition"><?= __('admin_media') ?></button>
                                                <button type="button" @click="setColumnBackgroundImage('')"
                                                        :disabled="!selectedColData().card_bg_image"
                                                        class="w-8 h-8 shrink-0 rounded text-gray-400 hover:bg-red-50 hover:text-red-500 disabled:opacity-30 inline-flex items-center justify-center"
                                                        title="<?= e(__('blox_clear')) ?>" aria-label="<?= e(__('blox_clear')) ?>">
                                                    <i class="ti ti-x text-sm"></i>
                                                </button>
                                            </div>
                                            <div x-show="selectedColData().card_bg_image" class="mt-3 space-y-3">
                                                <div>
                                                    <label class="block text-[10px] text-gray-400 mb-1"><?= e(__('blox_bg_overlay_color')) ?></label>
                                                    <div class="flex items-center gap-2">
                                                        <input type="color" class="w-8 h-8 rounded border border-gray-200 cursor-pointer p-0.5"
                                                               :value="colorPickerValue(selectedColData().card_bg_overlay_color, '#000000')"
                                                               @input="selectedColData().card_bg_overlay_color = $event.target.value">
                                                        <input type="text" x-model="selectedColData().card_bg_overlay_color" placeholder="#000000"
                                                               data-testid="blox-column-overlay-color"
                                                               class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="flex items-center justify-between text-[10px] text-gray-400 mb-1">
                                                        <span><?= __('blox_overlay_opacity') ?></span>
                                                        <span x-text="(selectedColData().card_bg_overlay_opacity ?? 0) + '%'"></span>
                                                    </div>
                                                    <input type="range" min="0" max="100" step="5" class="w-full"
                                                           :value="selectedColData().card_bg_overlay_opacity ?? 0"
                                                           @input="selectedColData().card_bg_overlay_opacity = parseInt($event.target.value, 10)"
                                                           data-testid="blox-column-overlay-opacity">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div x-show="sel.columns.length > 1 && !sel.settings.tablet_stack">
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_span_tablet') ?></label>
                                        <select class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm"
                                                :value="columnSpanT(selectedCol()) === null ? '' : columnSpanT(selectedCol())"
                                                @change="setColumnSpanT($event.target.value)">
                                            <option value=""><?= e(__('blox_inherit_desktop')) ?></option>
                                            <template x-for="n in 12" :key="'tspan'+n"><option :value="n" x-text="n + '/12'"></option></template>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_visible_devices') ?></label>
                                        <div class="flex gap-1">
                                            <template x-for="dev in [{k:'d',l:<?= e($jt('blox_device_desktop')) ?>},{k:'t',l:<?= e($jt('blox_device_tablet')) ?>},{k:'m',l:<?= e($jt('blox_device_mobile')) ?>}]" :key="'colvis'+dev.k">
                                                <button type="button" @click="toggleDevice(selectedColData(), dev.k, true)"
                                                        class="flex-1 h-8 rounded text-xs border transition"
                                                        :class="deviceVisible(selectedColData(), dev.k, true) ? 'border-green-400 bg-green-50 text-green-700' : 'border-gray-200 text-gray-400 line-through'"
                                                        x-text="dev.l"></button>
                                            </template>
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-1"><?= __('blox_visible_hint') ?></p>
                                    </div>
                                </div>
                                <div x-show="selLayer === 'con'" class="space-y-5">
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs font-medium text-gray-600 inline-flex items-center gap-1">
                                            <i class="ti ti-layout-columns text-sm text-blue-500"></i><?= __('blox_layout_section_label') ?>
                                        </span>
                                        <span class="text-[10px] text-gray-400" x-text="<?= e($jt('blox_n_cols')) ?>.replace(':n', sel.columns.length)"></span>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <template x-for="preset in layoutPresets" :key="'con-layout-'+preset.label">
                                            <button type="button" @click="applyLayoutToSelected(preset.spans)" :title="preset.label"
                                                    class="h-12 rounded border p-2 transition bg-white"
                                                    :class="layoutPresetActive(preset.spans) ? 'border-blue-400 ring-1 ring-blue-200' : 'border-gray-200 hover:border-blue-300 hover:bg-blue-50'">
                                                <span class="flex h-full gap-1">
                                                    <template x-for="(span, idx) in preset.spans" :key="idx">
                                                        <span class="rounded bg-gray-300" :class="layoutPresetActive(preset.spans) ? 'bg-blue-400' : ''" :style="'flex:' + span"></span>
                                                    </template>
                                                </span>
                                            </button>
                                        </template>
                                    </div>
                                    <template x-if="sel.columns.length === 2">
                                        <div class="mt-3 rounded border border-gray-200 bg-white p-2.5">
                                            <div class="mb-3">
                                                <div class="mb-1.5 flex items-center justify-between gap-2">
                                                    <span class="text-[10px] font-medium text-gray-500"><?php echo e(__('blox_columns_ratio')); ?></span>
                                                    <span class="text-[10px] font-semibold text-blue-600 tabular-nums" x-text="twoColumnRatioLabel()"></span>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <button type="button" @click="adjustTwoColumnDivider(-1)"
                                                            :disabled="twoColumnLeftSpan() <= 2"
                                                            title="<?php echo e(__('blox_columns_decrease_left')); ?>"
                                                            class="w-8 h-8 shrink-0 rounded border border-gray-200 text-gray-500 hover:border-blue-300 hover:text-blue-600 disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center justify-center transition">
                                                        <i class="ti ti-chevron-left text-sm"></i>
                                                    </button>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="mb-1 flex h-6 gap-1 rounded bg-gray-100 p-1 text-[9px] font-medium text-white">
                                                            <span class="min-w-0 rounded bg-blue-500 text-center leading-4" :style="'flex:' + twoColumnLeftSpan()">1</span>
                                                            <span class="min-w-0 rounded bg-cyan-500 text-center leading-4" :style="'flex:' + (12 - twoColumnLeftSpan())">2</span>
                                                        </div>
                                                        <input type="range" min="2" max="10" step="1"
                                                               :value="twoColumnLeftSpan()"
                                                               @change="setTwoColumnDivider($event.target.value)"
                                                               aria-label="<?php echo e(__('blox_columns_ratio')); ?>"
                                                               class="block h-4 w-full cursor-ew-resize accent-blue-600">
                                                    </div>
                                                    <button type="button" @click="adjustTwoColumnDivider(1)"
                                                            :disabled="twoColumnLeftSpan() >= 10"
                                                            title="<?php echo e(__('blox_columns_increase_left')); ?>"
                                                            class="w-8 h-8 shrink-0 rounded border border-gray-200 text-gray-500 hover:border-blue-300 hover:text-blue-600 disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center justify-center transition">
                                                        <i class="ti ti-chevron-right text-sm"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="mb-2 flex items-center justify-between">
                                                <span class="text-[10px] font-medium text-gray-500"><?php echo e(__('blox_columns_order')); ?></span>
                                                <button type="button" @click="swapSelectedColumns()"
                                                        title="<?php echo e(__('blox_columns_swap')); ?>"
                                                        class="h-7 px-2 rounded border border-blue-200 text-blue-600 hover:bg-blue-600 hover:text-white inline-flex items-center gap-1 text-[10px] transition">
                                                    <i class="ti ti-arrows-exchange text-sm"></i><?php echo e(__('blox_columns_swap')); ?>
                                                </button>
                                            </div>
                                            <div class="relative flex gap-2">
                                                <template x-for="(col, ci) in sel.columns" :key="'order-' + col.id">
                                                    <div class="flex-1 min-w-0 rounded border border-gray-200 bg-gray-50 px-2 py-2 text-center">
                                                        <i class="ti ti-columns-1 text-sm text-gray-400"></i>
                                                        <p class="mt-0.5 truncate text-[10px] text-gray-600"
                                                           x-text="col.elements && col.elements.length ? elLabel(col.elements[0]) : <?= e($jt('blox_col_word')) ?>.replace(':n', ci + 1)"></p>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                    <div x-show="sel.columns.length > 1" class="mt-3 border-t border-gray-200 pt-3">
                                        <div class="mb-1.5 flex items-center justify-between gap-2">
                                            <span class="text-[10px] font-medium text-gray-500"><?= __('blox_tablet_layout') ?></span>
                                            <span class="text-[10px] text-gray-400"><?= __('blox_mobile_single') ?></span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-1">
                                            <button type="button" @click="sel.settings.tablet_stack = false"
                                                    class="h-9 rounded border text-xs transition inline-flex items-center justify-center gap-1.5"
                                                    :class="!sel.settings.tablet_stack ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 bg-white text-gray-500 hover:border-blue-200'">
                                                <i class="ti ti-layout-columns text-sm"></i><?= __('blox_keep_columns') ?>
                                            </button>
                                            <button type="button" @click="sel.settings.tablet_stack = true"
                                                    class="h-9 rounded border text-xs transition inline-flex items-center justify-center gap-1.5"
                                                    :class="sel.settings.tablet_stack ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 bg-white text-gray-500 hover:border-blue-200'">
                                                <i class="ti ti-layout-list text-sm"></i><?= __('blox_stack_single') ?>
                                            </button>
                                        </div>
                                    </div>
                                    <p class="mt-2 text-[10px] text-gray-400 leading-relaxed"><?= __('blox_container_layout_hint') ?></p>
                                </div>
                                <!-- 容器宽度：预设四档 + 自定义 px -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_container_width') ?></label>
                                    <select x-model="sel.settings.max_width"
                                            class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                                        <option value="default"><?= __('blox_width_default') ?></option>
                                        <option value="narrow"><?= __('blox_width_narrow') ?></option>
                                        <option value="wide"><?= __('blox_width_wide') ?></option>
                                        <option value="full"><?= __('blox_width_full') ?></option>
                                        <option value="custom"><?= __('blox_width_custom') ?></option>
                                    </select>
                                    <div x-show="sel.settings.max_width === 'custom'" class="mt-1.5 flex items-center gap-2">
                                        <input type="number" min="320" max="3840" step="10" placeholder="1280"
                                               x-model.number="sel.settings.max_width_px"
                                               class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                        <span class="text-xs text-gray-400 shrink-0">px（320–3840）</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?php echo e(__('blox_container_gutter')); ?></label>
                                    <div class="grid grid-cols-2 gap-1">
                                        <button type="button" @click="sel.settings.container_gutter = 'default'"
                                                class="h-8 rounded text-xs border transition"
                                                :class="(sel.settings.container_gutter || 'default') === 'default' ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'">
                                            <?php echo e(__('blox_container_gutter_default')); ?>
                                        </button>
                                        <button type="button" @click="sel.settings.container_gutter = 'none'"
                                                class="h-8 rounded text-xs border transition"
                                                :class="sel.settings.container_gutter === 'none' ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'">
                                            <?php echo e(__('blox_container_gutter_none')); ?>
                                        </button>
                                    </div>
                                </div>
                                <!-- 容器背景：与区块背景分层，常用「区块深色 + 容器白底圆角」 -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_container_bg') ?></label>
                                    <div class="flex items-center gap-1 mb-2">
                                        <select :value="colorTokenId(sel.settings.container_bg)"
                                                @change="sel.settings.container_bg = $event.target.value ? colorTokenRef($event.target.value) : sel.settings.container_bg"
                                                class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1 text-xs bg-white">
                                            <option value=""><?= e(__('blox_design_custom')) ?></option>
                                            <template x-for="token in colorTokenOptions(sel.settings.container_bg)" :key="'container-bg-'+token.id">
                                                <option :value="token.id" x-text="colorTokenLabel(token)"></option>
                                            </template>
                                        </select>
                                        <template x-for="token in activeColorTokens().slice(0, 4)" :key="'container-bg-swatch-'+token.id">
                                            <button type="button" @click="sel.settings.container_bg = colorTokenRef(token.id)" :title="token.name"
                                                    class="w-6 h-6 rounded border border-gray-200 shrink-0"
                                                    :class="colorTokenId(sel.settings.container_bg) === token.id ? 'ring-2 ring-blue-400' : ''"
                                                    :style="'background:' + token.value"></button>
                                        </template>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="color" class="w-9 h-9 rounded border border-gray-200 cursor-pointer p-0.5"
                                               :value="colorPickerValue(sel.settings.container_bg, '#ffffff')"
                                               @input="sel.settings.container_bg = $event.target.value">
                                        <input type="text" x-model="sel.settings.container_bg" placeholder="<?= e(__('blox_empty_transparent')) ?>"
                                               class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                        <button type="button" @click="sel.settings.container_bg = ''"
                                                class="text-gray-400 hover:text-red-500 p-1" title="<?= e(__('blox_clear')) ?>">
                                            <i class="ti ti-x text-sm"></i></button>
                                    </div>
                                    <div class="mt-3" data-testid="blox-container-background-image">
                                        <label class="block text-[10px] font-medium text-gray-500 mb-1.5"><?= __('blox_bg_image') ?></label>
                                        <div class="flex items-center gap-2">
                                            <template x-if="sel.settings.container_bg_image">
                                                <img :src="sel.settings.container_bg_image"
                                                     class="w-10 h-10 rounded border border-gray-200 object-cover shrink-0" alt="">
                                            </template>
                                            <input type="text" x-model="sel.settings.container_bg_image"
                                                   @change="setContainerBackgroundImage($event.target.value)"
                                                   placeholder="/uploads/images/xx.jpg"
                                                   data-testid="blox-container-background-image-url"
                                                   class="min-w-0 flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                            <button type="button" @click="pickContainerBackgroundImage()"
                                                    data-testid="blox-container-background-image-media"
                                                    class="shrink-0 text-xs text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2 py-1.5 transition"><?= __('admin_media') ?></button>
                                            <button type="button" @click="setContainerBackgroundImage('')"
                                                   :disabled="!sel.settings.container_bg_image"
                                                   class="w-8 h-8 shrink-0 rounded text-gray-400 hover:bg-red-50 hover:text-red-500 disabled:opacity-30 inline-flex items-center justify-center"
                                                   title="<?= e(__('blox_clear')) ?>" aria-label="<?= e(__('blox_clear')) ?>">
                                                <i class="ti ti-x text-sm"></i>
                                            </button>
                                        </div>
                                        <div x-show="sel.settings.container_bg_image" class="mt-3 space-y-3">
                                            <div>
                                                <label class="block text-[10px] text-gray-400 mb-1"><?= e(__('blox_bg_overlay_color')) ?></label>
                                                <div class="flex items-center gap-2">
                                                    <input type="color" class="w-8 h-8 rounded border border-gray-200 cursor-pointer p-0.5"
                                                           :value="colorPickerValue(sel.settings.container_bg_overlay_color, '#000000')"
                                                           @input="sel.settings.container_bg_overlay_color = $event.target.value">
                                                    <input type="text" x-model="sel.settings.container_bg_overlay_color" placeholder="#000000"
                                                           data-testid="blox-container-overlay-color"
                                                           class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                                </div>
                                            </div>
                                            <div>
                                                <div class="flex items-center justify-between text-[10px] text-gray-400 mb-1">
                                                    <span><?= __('blox_overlay_opacity') ?></span>
                                                    <span x-text="(sel.settings.container_bg_overlay_opacity ?? 0) + '%'"></span>
                                                </div>
                                                <input type="range" min="0" max="100" step="5" class="w-full"
                                                       :value="sel.settings.container_bg_overlay_opacity ?? 0"
                                                       @input="sel.settings.container_bg_overlay_opacity = parseInt($event.target.value, 10)"
                                                       data-testid="blox-container-overlay-opacity">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_container_padding') ?></label>
                                        <div class="grid grid-cols-4 gap-1">
                                            <template x-for="opt in [{k:'',l:<?= e($jt('blox_spacing_none')) ?>},{k:'sm',l:<?= e($jt('blox_spacing_sm')) ?>},{k:'md',l:<?= e($jt('blox_spacing_md')) ?>},{k:'lg',l:<?= e($jt('blox_spacing_lg')) ?>}]" :key="'cp'+opt.k">
                                                <button type="button" @click="sel.settings.container_padding = opt.k"
                                                        class="h-8 rounded text-xs border transition"
                                                        :class="(sel.settings.container_padding || '') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                        x-text="opt.l"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_container_radius') ?></label>
                                        <div class="grid grid-cols-3 gap-1">
                                            <template x-for="opt in [{k:'',l:<?= e($jt('blox_spacing_none')) ?>},{k:'md',l:<?= e($jt('blox_spacing_md')) ?>},{k:'xl',l:<?= e($jt('blox_spacing_lg')) ?>}]" :key="'cr'+opt.k">
                                                <button type="button" @click="sel.settings.container_radius = opt.k"
                                                        class="h-8 rounded text-xs border transition"
                                                        :class="(sel.settings.container_radius || '') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                        x-text="opt.l"></button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                                <!-- 列间距 -->
                                <div>
                                    <div class="flex items-center justify-between gap-2 mb-1.5">
                                        <label class="block text-xs font-medium text-gray-600"><?= __('blox_col_gap') ?></label>
                                        <div class="flex items-center gap-1">
                                            <div class="inline-flex rounded border border-gray-200 bg-gray-50 p-0.5">
                                                <template x-for="d in devices" :key="'section-gap-' + d.key">
                                                    <button type="button" @click="previewDevice = d.key" :title="d.label" :aria-label="d.label"
                                                            :data-testid="'blox-section-gap-device-' + d.key"
                                                            :data-responsive-state="sectionResponsiveState('gap', 'lg', d.key).overridden ? 'override' : 'inherit'"
                                                            class="relative w-6 h-5 rounded inline-flex items-center justify-center"
                                                            :class="previewDevice === d.key ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-blue-600'">
                                                        <i class="ti text-[11px]" :class="d.icon"></i>
                                                        <span x-show="sectionResponsiveState('gap', 'lg', d.key).overridden"
                                                              class="absolute top-0 right-0 w-1.5 h-1.5 rounded-full bg-amber-400 ring-1 ring-white"></span>
                                                    </button>
                                                </template>
                                            </div>
                                            <button type="button" x-show="previewDevice !== 'desktop' && sectionResponsiveState('gap', 'lg').overridden"
                                                    @click="inheritSectionResponsiveValue('gap', 'lg')"
                                                    :title="responsiveText.resetInherit" :aria-label="responsiveText.resetInherit"
                                                    data-testid="blox-section-gap-inherit"
                                                    class="w-6 h-6 rounded border border-gray-200 text-gray-400 hover:border-blue-300 hover:text-blue-600 inline-flex items-center justify-center">
                                                <i class="ti ti-link text-xs"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-5 gap-1">
                                        <template x-for="opt in padOptions" :key="'g'+opt.k">
                                            <button type="button" @click="setSectionResponsiveValue('gap', opt.k, 'lg')"
                                                    :data-testid="'blox-section-gap-' + opt.k"
                                                    class="h-8 rounded text-xs border transition"
                                                    :class="sectionResponsiveValue('gap', 'lg') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                    x-text="opt.label"></button>
                                        </template>
                                    </div>
                                    <p x-show="previewDevice !== 'desktop'" class="mt-1 text-[10px] text-gray-400 flex items-center gap-1">
                                        <i class="ti" :class="sectionResponsiveState('gap', 'lg').overridden ? 'ti-adjustments' : 'ti-link'"></i>
                                        <span x-text="responsiveStatusText(sectionResponsiveState('gap', 'lg'))"></span>
                                    </p>
                                </div>

                                <!-- 对齐 -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= __('blox_col_align') ?></label>
                                    <div class="text-[10px] text-gray-400 mb-1"><?= __('blox_v_axis') ?></div>
                                    <div class="grid grid-cols-4 gap-1">
                                        <template x-for="opt in alignVOptions" :key="'a'+opt.k">
                                            <button type="button" @click="sel.settings.align_items = opt.k" :title="opt.label"
                                                    class="h-8 rounded border inline-flex items-center justify-center transition"
                                                    :class="(sel.settings.align_items || 'stretch') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200 hover:text-blue-500'">
                                                <i class="ti text-base" :class="'ti-' + opt.icon"></i>
                                            </button>
                                        </template>
                                    </div>
                                    <div class="text-[10px] text-gray-400 mb-1 mt-2"><?= __('blox_h_axis') ?></div>
                                    <div class="grid grid-cols-4 gap-1">
                                        <template x-for="opt in alignHOptions" :key="'j'+opt.k">
                                            <button type="button" @click="sel.settings.justify_items = opt.k" :title="opt.label"
                                                    class="h-8 rounded border inline-flex items-center justify-center transition"
                                                    :class="(sel.settings.justify_items || 'stretch') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200 hover:text-blue-500'">
                                                <i class="ti text-base" :class="'ti-' + opt.icon"></i>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                                <!-- 列卡片化：渲染器仅在列数 > 1 时生效 -->
                                <div x-show="sel.columns.length > 1">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" class="rounded border-gray-300"
                                               :checked="!!sel.settings.col_card"
                                               @change="sel.settings.col_card = $event.target.checked">
                                        <span class="text-xs font-medium text-gray-600"><?= __('blox_col_card') ?></span>
                                    </label>
                                    <p class="text-[10px] text-gray-400 mt-1"><?= __('blox_col_card_hint') ?></p>
                                </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </aside>

        <!-- 中：画布 -->
        <main x-ref="canvasHost" data-testid="blox-canvas-host" class="flex-1 min-w-0 bg-gray-200 overflow-auto flex flex-col" @contextmenu.prevent="openCtx($event, 'canvas', {})">
            <!-- r14 面包屑：选择模型的另一个视图（稳定派生自 selected*，重排/undo 后天然正确）；点父级只改选择 -->
            <div x-show="breadcrumb().length > 0" x-cloak data-testid="blox-breadcrumb"
                 class="sticky top-0 z-40 flex items-center gap-0.5 px-3 py-1 bg-white/95 backdrop-blur border-b border-gray-200 text-xs shrink-0">
                <template x-for="(c, idx) in breadcrumb()" :key="idx">
                    <span class="flex items-center gap-0.5">
                        <span x-show="idx > 0" class="text-gray-300">›</span>
                        <button type="button" @click="crumbGo(c)"
                                class="px-1.5 py-0.5 rounded hover:bg-gray-100 max-w-[10rem] truncate"
                                :class="idx === breadcrumb().length - 1 ? 'text-blue-600 font-medium' : 'text-gray-500'"
                                x-text="c.label"></button>
                    </span>
                </template>
            </div>
            <div class="flex-1 flex items-start justify-center p-3" @click.self="deselectAll()">
            <div class="relative" :style="previewShellStyle()">
                <iframe x-ref="canvas" data-testid="blox-canvas"
                        class="bg-white shadow-xl border-0 rounded"
                        :style="previewFrameStyle()"></iframe>
                <div x-show="sections.length === 0"
                     x-transition.opacity
                     class="absolute inset-0 flex items-start justify-center pt-24 pointer-events-none">
                    <div class="pointer-events-auto text-center">
                        <div class="inline-flex items-center gap-2">
                            <button type="button" @click="addSection(1)" title="<?= e(__('blox_add_module')) ?>"
                                    class="w-9 h-9 rounded bg-gray-100 hover:bg-blue-50 hover:text-blue-600 border border-gray-200 text-gray-500 inline-flex items-center justify-center transition">
                                <i class="ti ti-plus text-lg"></i>
                            </button>
                            <div class="relative group">
                                <button type="button" @click="addLayout([6, 6])" title="<?= e(__('blox_layout')) ?>"
                                        class="w-9 h-9 rounded bg-gray-100 group-hover:bg-yellow-300 group-hover:border-yellow-300 group-hover:text-gray-900 hover:bg-yellow-300 hover:border-yellow-300 hover:text-gray-900 border border-gray-200 text-gray-500 inline-flex items-center justify-center transition">
                                    <i class="ti ti-layout-columns text-lg"></i>
                                </button>
                                <div class="hidden group-hover:block absolute left-1/2 top-11 -translate-x-1/2 z-30 w-[420px] rounded-md bg-gray-900 shadow-2xl p-3 text-left">
                                    <div class="grid grid-cols-2 gap-2">
                                        <template x-for="preset in layoutPresets" :key="preset.label">
                                            <button type="button" @click="addLayout(preset.spans)" :title="preset.label"
                                                    class="h-12 rounded bg-gray-800 hover:bg-gray-700 p-2 transition">
                                                <span class="flex h-full gap-1">
                                                    <template x-for="(span, idx) in preset.spans" :key="idx">
                                                        <span class="rounded bg-gray-600 hover:bg-gray-500" :style="'flex:' + span"></span>
                                                    </template>
                                                </span>
                                            </button>
                                        </template>
                                    </div>
                                    <div class="absolute left-1/2 -bottom-7 -translate-x-1/2 rounded bg-gray-900 px-2 py-1 text-xs font-semibold text-white whitespace-nowrap"><?= __('blox_layout_section') ?></div>
                                </div>
                            </div>
                            <button type="button" @click="libOpen = true" data-testid="blox-canvas-library-open" title="<?= e(__('blox_open_library')) ?>"
                                    class="w-9 h-9 rounded bg-gray-100 hover:bg-blue-50 hover:text-blue-600 border border-gray-200 text-gray-500 inline-flex items-center justify-center transition">
                                <i class="ti ti-category text-lg"></i>
                            </button>
                            <button type="button" @click="toast(<?= e($jt('blox_click_to_add')) ?>)" title="<?= e(__('blox_tip')) ?>"
                                    class="w-9 h-9 rounded bg-gray-100 hover:bg-blue-50 hover:text-blue-600 border border-gray-200 text-gray-500 inline-flex items-center justify-center transition">
                                <i class="ti ti-help-circle text-lg"></i>
                            </button>
                        </div>
                        <p class="mt-3 text-xs font-medium text-gray-600"><?= __('blox_click_any_element') ?></p>
                    </div>
                </div>
            </div>
            </div>
        </main>

        <!-- 右：结构树（常驻，Bricks / PS 图层式） -->
        <aside class="blox-mobile-panel w-64 shrink-0 bg-white border-l border-gray-200 flex flex-col" :class="mobilePanel === 'structure' ? 'is-open' : ''">
            <div class="h-10 px-3 flex items-center border-b border-gray-100 shrink-0">
                <span class="text-xs font-semibold text-gray-500 tracking-wide inline-flex items-center gap-1">
                    <i class="ti ti-list-tree text-sm"></i><?= __('blox_mobile_structure') ?>
                    <span class="text-[10px] font-normal opacity-70" x-text="sections.length"></span>
                </span>
            </div>
            <div class="flex-1 overflow-y-auto blox-scroll p-2 space-y-1" x-ref="tree" data-sort-sections data-testid="blox-tree">
                <template x-if="sections.length === 0">
                    <p class="text-xs text-gray-400 text-center py-8"><?= __('blox_click_any_element') ?></p>
                </template>
                <template x-for="(section, si) in sections" :key="section.id">
                    <div @click="selectSection(si)"
                         @contextmenu.prevent.stop="openCtx($event, 'section', {si: si})"
                         :data-section-id="section.id" data-testid="blox-tree-section"
                         class="rounded-lg border cursor-pointer transition group"
                         :class="selectedSi === si ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:border-blue-200'">
                        <div data-section-drag-handle class="flex items-center gap-2 px-2.5 py-2">
                            <i class="ti ti-layout-board text-sm shrink-0"
                               :class="selectedSi === si ? 'text-blue-500' : 'text-gray-400'"></i>
                            <span class="text-sm truncate flex-1" x-text="sectionLabel(section, si)"></span>
                            <span class="text-[10px] text-gray-400" x-text="<?= e($jt('blox_n_elements')) ?>.replace(':n', elCount(section))"></span>
                        </div>
                        <!-- 该区块展开：容器节点 → 列 → 元素（Bricks 的 Section/Container 分层树） -->
                        <div x-show="selectedSi === si" x-collapse>
                            <div class="px-2 pb-1">
                                <div @click.stop="selectContainer(si)"
                                     @contextmenu.prevent.stop="openCtx($event, 'container', {si: si})"
                                     @dragover.prevent="dragOver = 'con' + si" @dragleave="dragOver = ''"
                                     @drop.prevent="treeDrop(si, 0, null)"
                                     class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded cursor-pointer transition"
                                     :class="dragOver === 'con' + si ? 'ring-2 ring-blue-300 bg-blue-50' : (isContainerSelected(si) ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600')">
                                    <i class="ti ti-box-margin text-xs shrink-0"></i>
                                    <span class="text-xs flex-1"><?= __('blox_tree_container') ?></span>
                                    <span class="text-[10px] text-gray-400" x-text="section.columns.length > 1 ? <?= e($jt('blox_n_cols')) ?>.replace(':n', section.columns.length) : ''"></span>
                                </div>
                            <div class="ml-3 pl-1.5 border-l border-gray-100 space-y-1">
                                <template x-if="section.settings && section.settings.title">
                                    <div @click.stop="selectSectionField(si, 'title')"
                                         @dblclick.stop.prevent="editSectionField(si, 'title')"
                                         @contextmenu.prevent.stop="openCtx($event, 'sectionField', {si: si, field: 'title'})"
                                         class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded cursor-pointer transition"
                                         :class="isSectionFieldSelected(si, 'title') ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600'"
                                         title="<?= e(__('blox_dbl_edit_title')) ?>">
                                        <i class="ti ti-h-2 text-xs shrink-0"></i>
                                        <span class="text-[9px] text-gray-400 shrink-0"><?= __('blox_field_title_short') ?></span>
                                        <span class="text-xs truncate flex-1" x-text="section.settings.title"></span>
                                    </div>
                                </template>
                                <template x-if="section.settings && section.settings.subtitle">
                                    <div @click.stop="selectSectionField(si, 'subtitle')"
                                         @dblclick.stop.prevent="editSectionField(si, 'subtitle')"
                                         @contextmenu.prevent.stop="openCtx($event, 'sectionField', {si: si, field: 'subtitle'})"
                                         class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded cursor-pointer transition"
                                         :class="isSectionFieldSelected(si, 'subtitle') ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600'"
                                         title="<?= e(__('blox_dbl_edit_subtitle')) ?>">
                                        <i class="ti ti-text-caption text-xs shrink-0"></i>
                                        <span class="text-[9px] text-gray-400 shrink-0"><?= __('blox_field_subtitle') ?></span>
                                        <span class="text-xs truncate flex-1" x-text="section.settings.subtitle"></span>
                                    </div>
                                </template>
                                <template x-if="isProjectedHomeColumnsSection(section)">
                                    <div class="space-y-1 py-0.5">
                                        <template x-for="(group, gi) in homeFieldGroups(projectedHomeElement(section))" :key="'about-column-' + group.key">
                                            <div class="rounded border border-gray-100 bg-white/70">
                                                <div @click.stop="selectProjectedHomeColumn(si, group)"
                                                     :data-home-column-tree="si + '.0.0.' + group.key"
                                                     @contextmenu.prevent.stop="openCtx($event, 'element', {si: si, ci: 0, ei: 0})"
                                                     class="flex items-center gap-1.5 px-2 py-1.5 rounded cursor-pointer transition"
                                                     :class="isProjectedHomeGroupSelected(si, group) ? 'bg-green-100 text-green-700' : 'hover:bg-gray-100 text-gray-600'">
                                                    <i class="ti ti-columns-1 text-xs shrink-0"></i>
                                                    <span class="text-xs font-medium" x-text="<?= e($jt('blox_col_word')) ?>.replace(':n', gi + 1)"></span>
                                                    <span class="text-[10px] truncate flex-1 text-gray-400" x-text="group.label"></span>
                                                    <span class="text-[10px] text-gray-400" x-text="homeGroupSpanLabel(projectedHomeElement(section), group)"></span>
                                                </div>
                                                <div class="ml-3 pl-1.5 border-l border-gray-200 pb-1">
                                                    <template x-for="field in group.fields" :key="field.key">
                                                        <button type="button"
                                                                @click.stop="selectHomeField(si + '.0.0', field.key)"
                                                                class="w-full flex items-center gap-1.5 pl-2 pr-1 py-1 rounded text-left transition"
                                                                :class="isElSelected(si,0,0) && selectedHomeField === field.key ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600'">
                                                            <i class="ti text-xs shrink-0" :class="'ti-' + field.icon"></i>
                                                            <span class="text-xs truncate flex-1" x-text="field.label"></span>
                                                        </button>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-for="(col, ci) in section.columns" :key="col.id">
                                    <div x-show="!isProjectedHomeColumnsSection(section)"
                                         @dragover.prevent="dragOver = 'c' + si + '-' + ci" @dragleave="dragOver = ''"
                                         @drop.prevent="treeDrop(si, ci, null)"
                                         :data-si="si" :data-ci="ci" data-sort-elements data-testid="blox-tree-column"
                                         class="rounded transition"
                                         :class="dragOver === 'c' + si + '-' + ci ? 'ring-2 ring-blue-300 bg-blue-50' : ''">
                                        <?php // 单列时不显示列标题——只有一列，说「列1」是噪音 ?>
                                        <div @click.stop="selectColumn(si, ci)"
                                             @contextmenu.prevent.stop="openCtx($event, 'column', {si: si, ci: ci})"
                                             class="flex items-center gap-1 pl-1 pr-1 py-1 rounded cursor-pointer transition"
                                             :class="isColumnSelected(si, ci) ? 'bg-green-100 text-green-700' : 'hover:bg-gray-100 text-gray-500'">
                                            <i class="ti ti-columns-1 text-[11px] shrink-0"></i>
                                            <span class="text-[10px] flex-1" x-text="<?= e($jt('blox_col_word')) ?>.replace(':n', ci + 1)"></span>
                                            <span class="text-[10px] text-gray-400" x-text="columnSpan(col) + '/12'"></span>
                                        </div>
                                        <template x-if="col.elements.length === 0">
                                            <p class="text-[10px] text-gray-300 pl-2 py-1"><?= __('blox_tree_empty') ?></p>
                                        </template>
                                        <template x-for="(el, ei) in col.elements" :key="el.id">
                                            <div :data-item-id="el.id"
                                                 :data-element-type="el.type"
                                                 :data-home-block-type="el.type === 'home-block' ? (((el.data || {}).block_type) || '') : ''"
                                                 data-sort-el-item data-testid="blox-tree-element">
                                                <div data-element-drag-handle @click.stop="selectElement(si, ci, ei)"
                                                     @contextmenu.prevent.stop="openCtx($event, 'element', {si: si, ci: ci, ei: ei})"
                                                     @dragover.prevent.stop="elSchema(el.type).container && (dragOver = 'ce' + si + '-' + ci + '-' + ei)"
                                                     @drop.prevent.stop="elSchema(el.type).container ? treeDrop(si, ci, ei) : treeDrop(si, ci, null)"
                                                     class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded cursor-pointer group/el transition"
                                                     :class="dragOver === 'ce' + si + '-' + ci + '-' + ei ? 'ring-2 ring-blue-300 bg-blue-50' : (isElSelected(si,ci,ei) ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600')">
                                                    <i class="ti text-xs shrink-0" :class="'ti-' + elIcon(el.type)"></i>
                                                    <span class="text-xs truncate flex-1" x-text="elLabel(el)"></span>
                                                    <span class="hidden group-hover/el:flex items-center gap-0.5 shrink-0">
                                                        <button type="button" @click.stop="moveElement(si,ci,ei,-1)" :disabled="ei===0"
                                                                class="p-0.5 hover:text-blue-600 disabled:opacity-25" title="<?= e(__('blox_ctx_move_up')) ?>">
                                                            <i class="ti ti-arrow-up text-xs"></i></button>
                                                        <button type="button" @click.stop="moveElement(si,ci,ei,1)" :disabled="ei===col.elements.length-1"
                                                                class="p-0.5 hover:text-blue-600 disabled:opacity-25" title="<?= e(__('blox_ctx_move_down')) ?>">
                                                            <i class="ti ti-arrow-down text-xs"></i></button>
                                                        <button type="button" @click.stop="deleteElement(si,ci,ei)"
                                                                class="p-0.5 hover:text-red-500" title="<?= e(__('delete')) ?>">
                                                            <i class="ti ti-trash text-xs"></i></button>
                                                    </span>
                                                </div>
                                                <!-- 容器：子元素嵌套一层（图层式） -->
                                                <template x-if="elSchema(el.type).container && (el.type !== 'home-block' || String((el.data || {}).block_type || '') === 'banner')">
                                                    <div class="ml-3 pl-1.5 border-l border-gray-200" :data-si="si" :data-ci="ci" :data-ei="ei" data-sort-children>
                                                        <template x-if="(el.data.children || []).length === 0">
                                                            <p class="text-[10px] text-gray-300 pl-2 py-0.5" x-text="el.type === 'list-dynamic' ? <?php echo htmlspecialchars(json_encode(__('blox_loop_template_empty'), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?> : (el.type === 'home-block' ? homeDynamicText.inherit : <?= e($jt('blox_empty_container')) ?>)"></p>
                                                        </template>
                                                        <template x-for="(cel, cei) in (el.data.children || [])" :key="cel.id">
                                                            <div data-child-drag-handle @click.stop="selectChild(si, ci, ei, cei)"
                                                                 @contextmenu.prevent.stop="openCtx($event, 'child', {si: si, ci: ci, ei: ei, cei: cei})"
                                                                 :data-item-id="cel.id" :data-element-type="cel.type" data-sort-child-item
                                                                 class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded cursor-pointer group/cel transition"
                                                                 :class="isChildSelected(si,ci,ei,cei) ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600'">
                                                                <i class="ti text-xs shrink-0" :class="'ti-' + elIcon(cel.type)"></i>
                                                                <span class="text-xs truncate flex-1" x-text="elLabel(cel)"></span>
                                                                <span class="hidden group-hover/cel:flex items-center gap-0.5 shrink-0">
                                                                    <button type="button" @click.stop="moveChild(si,ci,ei,cei,-1)" :disabled="cei===0"
                                                                            class="p-0.5 hover:text-blue-600 disabled:opacity-25" title="<?= e(__('blox_ctx_move_up')) ?>">
                                                                        <i class="ti ti-arrow-up text-xs"></i></button>
                                                                    <button type="button" @click.stop="moveChild(si,ci,ei,cei,1)" :disabled="cei===(el.data.children||[]).length-1"
                                                                            class="p-0.5 hover:text-blue-600 disabled:opacity-25" title="<?= e(__('blox_ctx_move_down')) ?>">
                                                                        <i class="ti ti-arrow-down text-xs"></i></button>
                                                                    <button type="button" @click.stop="deleteChild(si,ci,ei,cei)"
                                                                            class="p-0.5 hover:text-red-500" title="<?= e(__('delete')) ?>">
                                                                        <i class="ti ti-trash text-xs"></i></button>
                                                                </span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                                <template x-if="homeFieldGroups(el).length > 0">
                                                    <div class="ml-3 pl-1.5 border-l border-blue-200 space-y-1 py-1">
                                                        <div class="flex items-center gap-1.5 px-2 py-1 text-[10px] text-blue-600">
                                                            <i class="ti ti-box-multiple shrink-0"></i>
                                                            <span class="font-medium" x-text="homeFieldBlueprint(el).summary"></span>
                                                            <span x-show="String((el.data || {}).block_type || '') === 'about'"
                                                                  class="truncate text-gray-400" x-text="homeAboutLayoutLabel(el)"></span>
                                                        </div>
                                                        <template x-for="group in homeFieldGroups(el)" :key="group.key">
                                                            <details class="pl-1 group/home-fields" :open="homeFieldGroupOpen(group)">
                                                                <summary @click.stop class="list-none flex items-center gap-1.5 px-2 py-1 rounded text-[10px] font-medium text-gray-500 cursor-pointer hover:bg-gray-100">
                                                                    <i class="ti text-[11px] shrink-0" :class="'ti-' + group.icon"></i>
                                                                    <span class="truncate flex-1" x-text="group.displayLabel"></span>
                                                                    <i class="ti ti-chevron-right text-[10px] transition group-open/home-fields:rotate-90"></i>
                                                                </summary>
                                                                <template x-for="field in group.fields" :key="field.key">
                                                                    <button type="button"
                                                                            @click.stop="selectHomeField(si + '.' + ci + '.' + ei, field.key)"
                                                                            class="w-full flex items-center gap-1.5 pl-5 pr-1 py-1 rounded text-left transition"
                                                                            :class="isElSelected(si,ci,ei) && selectedHomeField === field.key ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600'">
                                                                        <i class="ti text-xs shrink-0" :class="'ti-' + field.icon"></i>
                                                                        <span class="text-xs truncate flex-1" x-text="field.label"></span>
                                                                    </button>
                                                                </template>
                                                            </details>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                            </div>
                        </div>
                        <!-- 该区块的操作（选中时展开） -->
                        <div x-show="selectedSi === si" class="flex items-center gap-1 px-2 pb-2 border-t border-gray-100 pt-1.5" x-collapse>
                            <button type="button" @click.stop="moveSection(si,-1)" :disabled="si===0"
                                    class="p-1 text-gray-400 hover:text-blue-500 disabled:opacity-30" title="<?= e(__('blox_ctx_move_up')) ?>">
                                <i class="ti ti-arrow-up text-sm"></i></button>
                            <button type="button" @click.stop="moveSection(si,1)" :disabled="si===sections.length-1"
                                    class="p-1 text-gray-400 hover:text-blue-500 disabled:opacity-30" title="<?= e(__('blox_ctx_move_down')) ?>">
                                <i class="ti ti-arrow-down text-sm"></i></button>
                            <button type="button" @click.stop="duplicateSection(si)"
                                    class="p-1 text-gray-400 hover:text-blue-500" title="<?= e(__('blox_copy')) ?>">
                                <i class="ti ti-copy text-sm"></i></button>
                            <div class="flex-1"></div>
                            <button type="button" @click.stop="deleteSection(si)"
                                    class="p-1 text-gray-400 hover:text-red-500" title="<?= e(__('delete')) ?>">
                                <i class="ti ti-trash text-sm"></i></button>
                        </div>
                    </div>
                </template>
            </div>
            <!-- 加区块 -->
            <div class="border-t border-gray-100 p-2 shrink-0">
                <div class="flex items-center justify-between mb-1.5 px-1">
                    <span class="text-[10px] text-gray-400"><?= __('blox_add_section_cols') ?></span>
                    <span class="text-[10px] text-blue-500" x-text="insertHint()"></span>
                </div>
                <div class="grid grid-cols-6 gap-1">
                    <template x-for="n in [1,2,3,4,5,6]" :key="n">
                        <button type="button" @click="addSection(n)" :title="n + <?= e($jt('blox_n_col_section')) ?>" :data-testid="'blox-add-section-' + n"
                                class="h-9 rounded-md border border-gray-200 text-gray-500 hover:border-blue-400 hover:text-blue-500 text-xs font-medium transition"
                                x-text="n"></button>
                    </template>
                </div>
                <button type="button" x-show="selectedSi >= 0" @click="selectedSi = -1" data-testid="blox-clear-selection"
                        class="w-full mt-1.5 text-[10px] text-gray-400 hover:text-gray-600 py-1">
                    <?= __('blox_deselect_append') ?>
                </button>
            </div>
        </aside>
    </div>

    <nav class="blox-mobile-toolbar" aria-label="<?= e(__('blox_mobile_panel_aria')) ?>">
        <button type="button" :class="mobilePanel === 'library' ? 'is-active' : ''" data-testid="blox-mobile-library"
                @click="mobilePanel = mobilePanel === 'library' ? '' : 'library'; libOpen = true">
            <i class="ti ti-category"></i><span x-text="mobileText.library"></span>
        </button>
        <button type="button" :class="mobilePanel === '' ? 'is-active' : ''" data-testid="blox-mobile-canvas-view"
                @click="mobilePanel = ''">
            <i class="ti ti-device-desktop"></i><span x-text="mobileText.canvas"></span>
        </button>
        <button type="button" :class="mobilePanel === 'structure' ? 'is-active' : ''" data-testid="blox-mobile-structure"
                @click="mobilePanel = mobilePanel === 'structure' ? '' : 'structure'">
            <i class="ti ti-list-tree"></i><span x-text="mobileText.structure"></span>
        </button>
        <button type="button" :disabled="!sel" data-testid="blox-mobile-settings"
                :class="mobilePanel === 'settings' ? 'is-active' : ''"
                @click="if (!sel) { mobilePanel = 'library'; libOpen = true; } else { mobilePanel = mobilePanel === 'settings' ? '' : 'settings'; libOpen = false; }">
            <i class="ti ti-adjustments"></i><span x-text="mobileText.settings"></span>
        </button>
    </nav>
    <div class="blox-mobile-backdrop" x-cloak x-show="mobilePanel !== ''" @click="mobilePanel = ''"></div>
