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

/**
 * 内容类型（各自有 edit_/delete_ 两档）。
 *
 * 不等于「contents 表的 type 取值」——product / job / timeline 各有自己的表，
 * 这里列的是「值得单独授权的一类内容」。招聘与发展历程原先借用 edit_article，
 * 语义混乱（能写文章 ≠ 能改招聘岗位），2026-07-30 起独立成键。
 */
function contentPermTypes(): array
{
    return ['article', 'product', 'case', 'download', 'page', 'job', 'timeline'];
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
 * 能否上传文件 / 使用媒体选择器。
 *
 * 规则：**能编辑就该能传图**。写文章却插不了图不成其为「能写文章」，
 * 硬把上传拆成独立能力只会逼着管理员给每个内容角色都补勾 media，
 * 徒增配置负担而挡不住什么——能编辑内容的人本来就能往页面里放任意 HTML。
 *
 * 所以：任一内容编辑权，或 media / banner / link 任一模块权，即可上传。
 * （upload.php 同时被 banner.php 与 link.php 调用，那两个模块权必须在列。）
 *
 * 但**媒体库管理页 media.php 仍要 media 权限**：上传和选图是一回事，
 * 浏览并删除全站已上传文件是另一回事。
 *
 * 此前这里只判登录，任何登录账号（含只读角色）都能调上传接口。
 */
function canUploadMedia(): bool
{
    return hasAnyContentPerm()
        || hasPermission('media') || hasPermission('banner') || hasPermission('link');
}

/**
 * 能否编辑 contents 表里的某一行——按该行自身的 type 判定。
 *
 * 文章 / 案例 / 单页 / 下载 都存在同一张 contents 表里，只靠「登录了」或
 * 「有任一内容权限」是拦不住跨类型改写的：只有 edit_article 的投稿者
 * 照样能动单页。凡是按 id 写 contents 的地方都要过这里。
 */
function canEditContentRow(int $id): bool
{
    if (hasPermission('*')) {
        return true;
    }
    $row = db()->fetchOne('SELECT `type` FROM ' . DB_PREFIX . 'contents WHERE id = ?', [$id]);
    if (!$row) {
        return false;
    }
    $type = (string) $row['type'];
    return in_array($type, contentPermTypes(), true)
        ? hasPermission('edit_' . $type)
        : hasAnyContentPerm();   // 自定义模型：放宽到任一内容权限
}

/** 能否删除 contents 表里的某一行——同 canEditContentRow，但要的是 delete_ 档。 */
function canDeleteContentRow(int $id): bool
{
    if (hasPermission('*')) {
        return true;
    }
    $row = db()->fetchOne('SELECT `type` FROM ' . DB_PREFIX . 'contents WHERE id = ?', [$id]);
    if (!$row) {
        return false;
    }
    $type = (string) $row['type'];
    if (!in_array($type, contentPermTypes(), true)) {
        return false;   // 自定义模型的删除不放宽：删除是不可逆操作，宁可要超管
    }
    return hasPermission('delete_' . $type);
}

/**
 * canEditContentRow 的断言版，失败抛异常。
 *
 * 与 requirePermission() 的区别：那个会 die 一段 HTML 或直接吐 JSON 并退出，
 * 在 Abilities::execute() 这类「结果要被包成 JSON 返回给调用方」的场景里不能用。
 *
 * @throws RuntimeException
 */
function assertCanEditContentRow(int $id): void
{
    if (!canEditContentRow($id)) {
        throw new RuntimeException('Permission denied: 无权编辑该内容（#' . $id . '）');
    }
}

/**
 * 设置键是否属于「不可通过通用接口读写」的敏感项。
 *
 * 用模式匹配而不是逐个列举——黑名单只挡住了 ai_api_key / smtp_pass / license_key 三个，
 * 而库里实际还有 cron_token、translate_api_key、seo_indexnow_key 等；插件将来还会写入
 * 新的密钥项，列举法必然漏。宁可误伤 site_keywords 这类无害键（它有专门的设置页可改），
 * 也不能把 cron_token 漏出去。
 */
function isSensitiveSettingKey(string $key): bool
{
    $key = strtolower(trim($key));
    if ($key === '' || str_starts_with($key, '_')) {
        return true;
    }
    // 明确豁免：语义上撞词但确实无害，且是 AI 助手的常用问答对象
    if (in_array($key, ['site_keywords', 'site_keywords_en', 'site_keywords_ja'], true)) {
        return false;
    }
    return preg_match('/(^|_)(key|secret|token|pass|password|credential|appid|appkey|access_id)($|_)/', $key) === 1;
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
