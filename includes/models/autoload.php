<?php
/**
 * Yikai CMS - Model 自动加载
 *
 * 注册 spl_autoload，按类名加载 /includes/models/{ClassName}.php
 * 提供单例访问函数（与 db() 模式一致）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 自动加载 Model 类文件
spl_autoload_register(function (string $class): void {
    // 只处理 Model 结尾的类名或 Model 本身
    if ($class !== 'Model' && !str_ends_with($class, 'Model')) {
        return;
    }
    $file = __DIR__ . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ═══════════════════════════════════════════════════════
// 单例访问函数（与 db() 风格一致）
// ═══════════════════════════════════════════════════════

function channelModel(): ChannelModel {
    static $i; return $i ??= new ChannelModel();
}

function contentModel(): ContentModel {
    static $i; return $i ??= new ContentModel();
}

function articleModel(): ArticleModel {
    static $i; return $i ??= new ArticleModel();
}

function articleCategoryModel(): ArticleCategoryModel {
    static $i; return $i ??= new ArticleCategoryModel();
}

function productModel(): ProductModel {
    static $i; return $i ??= new ProductModel();
}

function productCategoryModel(): ProductCategoryModel {
    static $i; return $i ??= new ProductCategoryModel();
}

function albumModel(): AlbumModel {
    static $i; return $i ??= new AlbumModel();
}

function albumPhotoModel(): AlbumPhotoModel {
    static $i; return $i ??= new AlbumPhotoModel();
}

function bannerModel(): BannerModel {
    static $i; return $i ??= new BannerModel();
}

function bannerGroupModel(): BannerGroupModel {
    static $i; return $i ??= new BannerGroupModel();
}

function linkModel(): LinkModel {
    static $i; return $i ??= new LinkModel();
}

function settingModel(): SettingModel {
    static $i; return $i ??= new SettingModel();
}

function formModel(): FormModel {
    static $i; return $i ??= new FormModel();
}

function formTemplateModel(): FormTemplateModel {
    static $i; return $i ??= new FormTemplateModel();
}

function userModel(): UserModel {
    static $i; return $i ??= new UserModel();
}

function roleModel(): RoleModel {
    static $i; return $i ??= new RoleModel();
}

function adminLogModel(): AdminLogModel {
    static $i; return $i ??= new AdminLogModel();
}

function mediaModel(): MediaModel {
    static $i; return $i ??= new MediaModel();
}

function downloadModel(): DownloadModel {
    static $i; return $i ??= new DownloadModel();
}

function jobModel(): JobModel {
    static $i; return $i ??= new JobModel();
}

function downloadCategoryModel(): DownloadCategoryModel {
    static $i; return $i ??= new DownloadCategoryModel();
}

function timelineModel(): TimelineModel {
    static $i; return $i ??= new TimelineModel();
}

function pluginModel(): PluginModel {
    static $i; return $i ??= new PluginModel();
}

function memberModel(): MemberModel {
    static $i; return $i ??= new MemberModel();
}
