<?php
/**
 * 相册照片网格（网格 / 瀑布流）与空状态。page.php 的相册栏目与 album.php 共用。
 * 需要：$albumData（相册行，可为 null）、$albumPhotos（照片行）。
 */
?>
                <?php if (!empty($albumPhotos)): ?>
                <?php $__albumMasonry = (($albumData['layout'] ?? 'grid') === 'masonry'); ?>
                <div class="bg-white rounded-lg shadow p-6">
                    <?php if ($__albumMasonry): ?>
                    <?php /* 流布局（瀑布流）：保留图片原始比例 */ ?>
                    <div data-album-masonry style="columns:2;column-gap:1rem">
                        <?php foreach ($albumPhotos as $photo): ?>
                        <div class="group" style="break-inside:avoid;margin-bottom:1rem">
                            <a href="<?php echo e($photo['image']); ?>"
                               data-lightbox="album"
                               data-title="<?php echo e($photo['title']); ?>"
                               class="block rounded-lg overflow-hidden bg-gray-100">
                                <img loading="lazy" decoding="async" <?php echo responsiveImageAttributes($photo['image'], 'medium', '(min-width: 1024px) 25vw, (min-width: 768px) 33vw, 50vw'); ?>
                                     alt="<?php echo e($photo['title']); ?>"
                                     class="w-full h-auto group-hover:opacity-90 transition duration-300">
                            </a>
                            <?php if ($photo['title']): ?>
                            <p class="text-center text-sm text-gray-600 mt-2"><?php echo e($photo['title']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <style>@media(min-width:768px){[data-album-masonry]{columns:3}}@media(min-width:1024px){[data-album-masonry]{columns:4}}</style>
                    <?php else: ?>
                    <?php /* 网格：等比方形缩略图 */ ?>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($albumPhotos as $photo): ?>
                        <div class="group">
                            <a href="<?php echo e($photo['image']); ?>"
                               data-lightbox="album"
                               data-title="<?php echo e($photo['title']); ?>"
                               class="block aspect-square rounded-lg overflow-hidden bg-gray-100">
                                <img loading="lazy" decoding="async" <?php echo responsiveImageAttributes($photo['image'], 'medium', '(min-width: 1024px) 25vw, (min-width: 768px) 33vw, 50vw'); ?>
                                     alt="<?php echo e($photo['title']); ?>"
                                     class="w-full h-full object-cover group-hover:scale-110 transition duration-300">
                            </a>
                            <?php if ($photo['title']): ?>
                            <p class="text-center text-sm text-gray-600 mt-2"><?php echo e($photo['title']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                    <?php echo e(__('no_image')); ?>
                </div>
                <?php endif; ?>
