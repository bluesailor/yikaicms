<?php
/**
 * 权限能力目录（单一数据源）
 *
 * 细粒度 RBAC，借鉴 WordPress capabilities：命名 {动作}_{类型}。
 *   内容类：每类型分 编辑/删除 两档（edit_article / delete_article …）
 *   辅助模块：单权限（media / banner / link / form / member）
 *   超管专属（栏目/设置/主题/插件/用户/系统…）：不进此目录，一律 requirePermission('*')
 *
 * role.php 勾选界面、页面 guard、权限迁移 都读这里，避免多处漂移。
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/** 内容类型（各自有 edit_/delete_ 两档） */
function contentPermTypes(): array
{
    return ['article', 'product', 'case', 'download', 'page'];
}

/** 辅助模块单权限 */
function modulePermKeys(): array
{
    return ['media', 'banner', 'link', 'form', 'member'];
}

/** 全部合法权限键（含通配 *），用于保存时过滤非法值 */
function allPermissionKeys(): array
{
    $keys = ['*'];
    foreach (contentPermTypes() as $t) {
        $keys[] = 'edit_' . $t;
        $keys[] = 'delete_' . $t;
    }
    return array_merge($keys, modulePermKeys());
}

/** 类型 → 短名 lang 键（勾选界面与徽章显示用） */
function permTypeLabel(string $type): string
{
    return __('perm_type_' . $type);
}

/** 单个权限键 → 人类可读标签（用于角色列表徽章） */
function permLabel(string $key): string
{
    if ($key === '*') {
        return __('perm_all');
    }
    foreach (['edit_' => 'perm_edit', 'delete_' => 'perm_delete'] as $prefix => $actLang) {
        if (str_starts_with($key, $prefix)) {
            return permTypeLabel(substr($key, strlen($prefix))) . ' · ' . __($actLang);
        }
    }
    $mod = [
        'media' => 'admin_media', 'banner' => 'admin_banner', 'link' => 'admin_link',
        'form' => 'admin_form', 'member' => 'admin_member',
    ];
    return isset($mod[$key]) ? __($mod[$key]) : $key;
}

/** 是否拥有任一内容类型的编辑或删除权限（超管恒真） */
function hasAnyContentPerm(): bool
{
    if (hasPermission('*')) {
        return true;
    }
    foreach (contentPermTypes() as $t) {
        if (hasPermission('edit_' . $t) || hasPermission('delete_' . $t)) {
            return true;
        }
    }
    return false;
}

/**
 * 共享内容编辑器守卫：已知内容类型精确要求 edit_{type}（保证类型隔离——
 * 产品编辑者不能借共享编辑器改文章）；未知/自定义类型（faq/模型）放宽到任一内容权限。
 */
function requireContentEditPerm(?string $type): void
{
    $type = (string) $type;
    if (in_array($type, contentPermTypes(), true)) {
        requirePermission('edit_' . $type);
        return;
    }
    if (!hasAnyContentPerm()) {
        requirePermission('edit_article');   // 必失败，走统一「无操作权限」提示
    }
}

/**
 * 分组能力目录（供角色勾选界面）：
 *   [ 组键 => ['label'=>组名, 'caps'=>[权限键=>标签, ...]], ... ]
 */
function permissionCatalog(): array
{
    $content = [];
    foreach (contentPermTypes() as $t) {
        $content['edit_' . $t]   = permLabel('edit_' . $t);
        $content['delete_' . $t] = permLabel('delete_' . $t);
    }
    $module = [];
    foreach (modulePermKeys() as $m) {
        $module[$m] = permLabel($m);
    }
    return [
        'content' => ['label' => __('perm_group_content'), 'caps' => $content],
        'module'  => ['label' => __('perm_group_module'),  'caps' => $module],
    ];
}
