<?php
declare(strict_types=1);
/**
 * 已有内容的站导入整站模板前的警示：会清空什么、保留什么、先去备份。
 * 本地导入页与模板市场页共用；确认勾选框由各自的表单提供（name="replace_existing"）。
 */
?>
<div role="alert" data-testid="st-replace-notice" class="border border-red-200 bg-red-50 text-red-900 p-4 rounded space-y-2">
    <p class="font-semibold"><?= e(__('st_replace_title')) ?></p>
    <p class="text-sm leading-6"><?= e(__('st_replace_warning')) ?></p>
    <p class="text-sm"><a href="/admin/database.php" class="font-medium underline"><?= e(__('st_replace_backup')) ?></a></p>
</div>
