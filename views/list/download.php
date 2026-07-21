<?php
/**
 * View partial: list page body for `type=download` channels.
 *
 * Required-included from list.php after DownloadController has populated:
 *   \$channel, \$dlCategories, \$dlCatId, \$keyword, \$total,
 *   \$downloads, \$rightSidebarChannels.
 *
 * Scope is shared with the parent (no isolation), matching how Yikai
 * already mounts theme partials via `require theme_path(...)`.
 */
$hasDlSidebar = !empty($rightSidebarChannels) || !empty($rightSidebarItems);
?>
<!-- 下载：表格 + 右侧分类导航（数据来自 yikai_downloads 表；分类来自 download_categories） -->
        <div class="flex flex-wrap lg:flex-nowrap gap-8">
            <div class="w-full <?php echo $hasDlSidebar ? 'lg:flex-1' : ''; ?>">
                <div class="flex flex-wrap items-center justify-end gap-3 mb-6">
                    <form method="get" action="<?php echo channelUrl($channel); ?>" class="flex items-center gap-2">
                        <?php if ($dlCatId > 0): ?>
                        <input type="hidden" name="cat" value="<?php echo $dlCatId; ?>">
                        <?php endif; ?>
                        <div class="relative">
                            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                                   placeholder="搜索下载..."
                                   class="w-48 border rounded-full pl-4 pr-9 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </button>
                        </div>
                        <?php if ($keyword !== ''): ?>
                        <a href="<?php echo channelUrl($channel); ?><?php echo $dlCatId > 0 ? '?cat=' . $dlCatId : ''; ?>" class="text-gray-400 hover:text-red-500" title="<?php echo __('search_clear'); ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
                <?php if ($keyword !== ''): ?>
                <div class="mb-4 text-sm text-gray-500">
                    <?php echo __('search_total', ['count' => '<span class="text-primary font-medium">' . $total . '</span>']); ?> — "<span class="text-primary"><?php echo e($keyword); ?></span>"
                </div>
                <?php endif; ?>
                <?php if (!empty($downloads)): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-sm font-medium text-gray-500"><?php echo __('download_filename'); ?></th>
                                <th class="px-6 py-3 text-center text-sm font-medium text-gray-500 hidden md:table-cell"><?php echo __('download_date'); ?></th>
                                <th class="px-6 py-3 text-center text-sm font-medium text-gray-500 hidden md:table-cell"><?php echo __('download_count'); ?></th>
                                <th class="px-6 py-3 text-center text-sm font-medium text-gray-500"><?php echo __('download_action'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($downloads as $item): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <?php
                                        $extClass = match(strtolower($item['file_ext'])) {
                                            'pdf' => 'bg-red-100 text-red-600',
                                            'doc', 'docx' => 'bg-blue-100 text-blue-600',
                                            'xls', 'xlsx' => 'bg-green-100 text-green-600',
                                            'zip', 'rar', '7z' => 'bg-purple-100 text-purple-600',
                                            'exe', 'msi' => 'bg-gray-100 text-gray-600',
                                            default => 'bg-gray-100 text-gray-500',
                                        };
                                        ?>
                                        <span class="flex-shrink-0 w-9 h-9 <?php echo $extClass; ?> rounded flex items-center justify-center text-xs font-bold">
                                            <?php echo strtoupper($item['file_ext']) ?: '?'; ?>
                                        </span>
                                        <div>
                                            <span class="text-dark hover:text-primary font-medium"><?php echo e($item['title']); ?></span>
                                            <?php if (!empty($item['description'])): ?>
                                            <div class="text-xs text-gray-400 mt-0.5 line-clamp-1"><?php echo e($item['description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center text-sm text-gray-500 hidden md:table-cell">
                                    <?php echo $item['created_at'] > 0 ? date('Y-m-d', (int)$item['created_at']) : '-'; ?>
                                </td>
                                <td class="px-6 py-4 text-center text-sm text-gray-500 hidden md:table-cell">
                                    <?php echo number_format((int)$item['download_count']); ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php if ($item['file_url']): ?>
                                    <a href="/download.php?fid=<?php echo $item['id']; ?>"
                                       class="inline-flex items-center gap-1 text-primary hover:underline text-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                        </svg>
                                        <?php echo __('download_btn'); ?>
                                    </a>
                                    <?php if (!empty($item['require_login'])): ?>
                                    <div class="text-xs text-orange-500 mt-1">需登录</div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-gray-400 text-sm">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-16 text-gray-500 bg-white rounded-lg">
                    <?php echo __('no_content'); ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($hasDlSidebar): ?>
            <?php require theme_path('partials/right_sidebar.php'); ?>
            <?php endif; ?>
        </div>

