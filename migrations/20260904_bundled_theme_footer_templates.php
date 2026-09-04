<?php
/** 为安装包内置主题补齐各自的可编辑 Blox 页脚起步模板。 */

declare(strict_types=1);

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$themeFooterPresets = ['clean-site-footer', 'business-site-footer', 'minimal-site-footer'];

return [
    'id' => '20260904_bundled_theme_footer_templates',
    'title' => '补齐内置主题的可编辑网页脚',
    'desc' => '为 Default、Business、Minimal 安装各自的 Blox 网页脚起步模板；只补缺失项，不覆盖已有草稿或已发布内容。',
    'title_en' => 'Add editable footers for bundled themes',
    'title_ja' => '同梱テーマに編集可能なフッターを追加',
    'desc_en' => 'Installs a matching Blox footer starter for Default, Business, and Minimal only when missing. Existing drafts and published content are preserved.',
    'desc_ja' => 'Default、Business、Minimal に対応する Blox フッターを未登録の場合のみ追加します。既存の下書きと公開内容は保持されます。',
    'check' => static function () use ($themeFooterPresets): bool {
        if (!db()->tableExists('blox_templates')) {
            return true;
        }
        foreach ($themeFooterPresets as $slug) {
            if (bloxTemplateModel()->findWhere(['source' => 'builtin', 'source_ref' => $slug]) === null) {
                return false;
            }
        }
        return true;
    },
    'php' => static function () use ($themeFooterPresets): string {
        if (!db()->tableExists('blox_templates')) {
            return 'Blox 模板表尚未创建，已跳过。';
        }
        $installed = 0;
        foreach ($themeFooterPresets as $slug) {
            if (bloxTemplateModel()->findWhere(['source' => 'builtin', 'source_ref' => $slug]) !== null) {
                continue;
            }
            BloxAreaTemplatePresets::install($slug);
            $installed++;
        }
        return '已补齐 ' . $installed . ' 个内置主题网页脚模板。';
    },
];
