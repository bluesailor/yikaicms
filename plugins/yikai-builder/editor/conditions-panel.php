<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
                    <template x-if="displayConditionsEnabled && panelTab === 'condition' && conditionTarget()">
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
