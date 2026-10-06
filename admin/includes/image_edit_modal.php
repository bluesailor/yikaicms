<?php
/**
 * 图片编辑弹窗（裁剪 / 旋转 / 翻转 / 替代文字）：媒体库与网页构建器的图片控件共用。
 * 标记写在 PHP 里，类名才进 Tailwind 产物；行为见 assets/js/admin-image-editor.js（window.YkImageEditor）。
 * 接口 admin/media_edit.php 要求 media 权限，调用方只在 hasPermission('media') 时引入本片段。
 */
declare(strict_types=1);
?>
<div id="imageEditModal" class="fixed inset-0 z-[150] hidden bg-black/70 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="imageEditTitle">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-5xl max-h-[95vh] flex flex-col overflow-hidden">
        <div class="px-5 py-3 border-b flex items-center justify-between gap-3">
            <h3 id="imageEditTitle" class="font-bold text-gray-800"><?php echo e(__('image_edit')); ?></h3>
            <span data-ie-size class="text-sm text-gray-500 tabular-nums"></span>
            <button type="button" data-ie-close class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 text-xl leading-none w-8 h-8 rounded-full flex items-center justify-center" aria-label="<?php echo e(__('admin_close')); ?>">&times;</button>
        </div>
        <div class="flex flex-col md:flex-row min-h-0 flex-1">
            <div class="flex-1 min-h-0 bg-gray-100 flex items-center justify-center p-4 overflow-auto">
                <div data-ie-stage class="relative inline-block select-none touch-none overflow-hidden" style="line-height:0">
                    <img data-ie-preview alt="" class="max-w-full block" style="max-height:70vh">
                    <div data-ie-box hidden tabindex="0" role="group" aria-label="<?php echo e(__('image_edit_selection')); ?>"
                         class="absolute border-2 border-white cursor-move" style="box-shadow:0 0 0 9999px rgba(0,0,0,.45)">
                        <span data-ie-handle="nw" class="absolute left-0 top-0 w-3 h-3 bg-white border border-gray-500" style="cursor:nwse-resize"></span>
                        <span data-ie-handle="ne" class="absolute right-0 top-0 w-3 h-3 bg-white border border-gray-500" style="cursor:nesw-resize"></span>
                        <span data-ie-handle="sw" class="absolute left-0 bottom-0 w-3 h-3 bg-white border border-gray-500" style="cursor:nesw-resize"></span>
                        <span data-ie-handle="se" class="absolute right-0 bottom-0 w-3 h-3 bg-white border border-gray-500" style="cursor:nwse-resize"></span>
                    </div>
                    <div data-ie-loading hidden class="absolute inset-0 bg-white/60 flex items-center justify-center text-sm text-gray-600" style="line-height:normal"><?php echo e(__('admin_loading')); ?></div>
                </div>
            </div>
            <div class="w-full md:w-72 border-t md:border-t-0 md:border-l p-4 space-y-4 overflow-y-auto text-sm">
                <p data-ie-animated hidden class="text-amber-700 bg-amber-50 rounded p-2"><?php echo e(__('image_edit_gif_warning')); ?></p>
                <div>
                    <div class="font-medium text-gray-700 mb-2"><?php echo e(__('image_edit_crop')); ?></div>
                    <div class="flex flex-wrap gap-1" role="group" aria-label="<?php echo e(__('image_edit_ratio')); ?>">
                        <?php foreach (['free' => __('image_edit_ratio_free'), '1:1' => '1:1', '4:3' => '4:3', '3:2' => '3:2', '16:9' => '16:9', '3:1' => __('image_edit_ratio_banner')] as $ratio => $ratioLabel): ?>
                        <button type="button" data-ie-ratio="<?php echo e($ratio); ?>" aria-pressed="<?php echo $ratio === 'free' ? 'true' : 'false'; ?>"
                                class="px-2 py-1 border rounded hover:bg-gray-50 aria-pressed:bg-primary aria-pressed:text-white aria-pressed:border-primary"><?php echo e($ratioLabel); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-2"><?php echo e(__('image_edit_crop_hint')); ?></p>
                    <button type="button" data-ie-apply-crop disabled class="mt-2 w-full bg-gray-800 text-white rounded px-3 py-1.5 disabled:opacity-40"><?php echo e(__('image_edit_apply_crop')); ?></button>
                </div>
                <div>
                    <div class="font-medium text-gray-700 mb-2"><?php echo e(__('image_edit_transform')); ?></div>
                    <div class="grid grid-cols-4 gap-1">
                        <button type="button" data-ie-op="rotate:-90" class="border rounded py-1.5 hover:bg-gray-50" title="<?php echo e(__('image_edit_rotate_left')); ?>" aria-label="<?php echo e(__('image_edit_rotate_left')); ?>"><i class="ti ti-rotate-2"></i></button>
                        <button type="button" data-ie-op="rotate:90" class="border rounded py-1.5 hover:bg-gray-50" title="<?php echo e(__('image_edit_rotate_right')); ?>" aria-label="<?php echo e(__('image_edit_rotate_right')); ?>"><i class="ti ti-rotate-clockwise-2"></i></button>
                        <button type="button" data-ie-op="flip:h" class="border rounded py-1.5 hover:bg-gray-50" title="<?php echo e(__('image_edit_flip_h')); ?>" aria-label="<?php echo e(__('image_edit_flip_h')); ?>"><i class="ti ti-flip-vertical"></i></button>
                        <button type="button" data-ie-op="flip:v" class="border rounded py-1.5 hover:bg-gray-50" title="<?php echo e(__('image_edit_flip_v')); ?>" aria-label="<?php echo e(__('image_edit_flip_v')); ?>"><i class="ti ti-flip-horizontal"></i></button>
                    </div>
                    <div class="grid grid-cols-2 gap-1 mt-2">
                        <button type="button" data-ie-undo disabled class="border rounded py-1.5 hover:bg-gray-50 disabled:opacity-40"><i class="ti ti-arrow-back-up"></i> <?php echo e(__('image_edit_undo')); ?></button>
                        <button type="button" data-ie-redo disabled class="border rounded py-1.5 hover:bg-gray-50 disabled:opacity-40"><i class="ti ti-arrow-forward-up"></i> <?php echo e(__('image_edit_redo')); ?></button>
                    </div>
                </div>
                <div>
                    <label for="imageEditAlt" class="font-medium text-gray-700 mb-1 block"><?php echo e(__('image_edit_alt')); ?></label>
                    <input type="text" id="imageEditAlt" data-ie-alt maxlength="300" class="w-full border rounded px-2 py-1.5">
                    <p class="text-xs text-gray-500 mt-1"><?php echo e(__('image_edit_alt_hint')); ?></p>
                </div>
                <p class="text-xs text-gray-500"><?php echo e(__('image_edit_note')); ?></p>
                <div data-ie-status class="sr-only" aria-live="polite"></div>
                <div class="flex flex-col gap-2 pt-2 border-t">
                    <button type="button" data-ie-save data-testid="image-edit-save" class="bg-primary hover:bg-secondary text-white rounded px-3 py-2"><i class="ti ti-check"></i> <?php echo e(__('admin_save')); ?></button>
                    <button type="button" data-ie-restore hidden data-testid="image-edit-restore" class="border rounded px-3 py-2 hover:bg-gray-50"><i class="ti ti-history"></i> <?php echo e(__('image_edit_restore')); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.YK_IMAGE_EDIT_I18N = <?php echo json_encode([
    'discard' => __('image_edit_discard_confirm'), 'restoreConfirm' => __('image_edit_restore_confirm'),
    'saved' => __('image_edit_saved'), 'restored' => __('image_edit_restored'), 'failed' => __('image_edit_failed'),
    'size' => __('image_edit_size'), 'cropped' => __('image_edit_cropped'), 'rotated' => __('image_edit_rotated'), 'flipped' => __('image_edit_flipped'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
// 网页构建器页面没有后台头部的 fetch CSRF 包装，接口令牌随弹窗片段带上
window.YK_IMAGE_EDIT_TOKEN = <?php echo json_encode(csrfToken()); ?>;
</script>
<script src="/assets/js/admin-image-editor.js?v=<?php echo (int) filemtime(ROOT_PATH . '/assets/js/admin-image-editor.js'); ?>"></script>
