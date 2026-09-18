<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
                            <template x-if="selEl && selEl.type === 'list-dynamic' && panelTab === 'professional' && professionalFeatures.query_loop.allowed">
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
