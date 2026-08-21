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

/**
 * 高危能力单权限。与内容/模块权限分组分开，语义是「明确知道风险才授予」：
 *   blox_code —— 在 Blox 页面使用 代码/HTML 元素（前台原样输出，等于任意
 *                HTML/JavaScript 执行能力）。默认只有超管（*）具备；
 *                edit_page 不再隐含它，服务端由 BloxElementPolicy 强制。
 */
function advancedPermKeys(): array
{
    return ['blox_code'];
}

/** 全部合法权限键（含通配 *），用于保存时过滤非法值 */
function allPermissionKeys(): array
{
    $keys = ['*'];
    foreach (contentPermTypes() as $t) {
        $keys[] = 'edit_' . $t;
        $keys[] = 'delete_' . $t;
    }
    return array_merge($keys, modulePermKeys(), advancedPermKeys());
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
        'form' => 'admin_form', 'member' => 'admin_member', 'blox_code' => 'perm_blox_code',
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
 * 上传与媒体相关的三档能力。
 *
 * 之前只有一个 canUploadMedia()，规则是「能编辑就能传图」。方向没错——写文章
 * 插不了图不成其为能写文章——但它实际放得比「图」宽得多：upload.php 的
 * `$type` 是**客户端传的**，传 `type=files` 就能上传 pdf/doc/xls/ppt/zip/rar/7z；
 * 媒体选择器又会列出全站已上传的一切。于是「能编辑就能传图」事实上变成了
 * 「能编辑就能浏览全站媒体并上传任意白名单文件」。拆成三档：
 *
 *   canUploadImage()  插图 —— 任一内容编辑权 / banner / link / media
 *   canUploadFile()   文档与压缩包 —— 只有下载编辑者与媒体管理员需要
 *   canManageMedia()  媒体库管理页、浏览全站媒体 —— 仅 media
 */

/** 能否上传图片（插图是编辑流程的一部分）。 */
function canUploadImage(): bool
{
    return hasAnyContentPerm()
        || hasPermission('media') || hasPermission('banner') || hasPermission('link');
}

/**
 * 能否上传文档 / 压缩包。
 * 比图片严得多：这类文件不是「排版需要」，而是对外分发的资料，
 * 且 zip/rar 这类容器一旦能上传，风险面和一张图完全不是一个量级。
 */
function canUploadFile(): bool
{
    return hasPermission('media') || hasPermission('edit_download');
}

/** 能否管理媒体库（浏览全站已上传文件、删除）。 */
function canManageMedia(): bool
{
    return hasPermission('media');
}

/**
 * 按上传类型选择对应的能力闸。
 * $type 取值同 uploadFile()：images / files / 其它（其它 = 合并两个白名单，按更严的算）。
 */
function canUploadType(string $type): bool
{
    return $type === 'images' ? canUploadImage() : canUploadFile();
}

/**
 * 当前账号能看见哪些回收站分类。
 *
 * 与动作权限同源：能还原/彻底删除某一类，才看得到那一类。
 * 只有 edit_ 没有 delete_ 的账号一个分类也看不到，回收站入口也不显示。
 *
 * @return list<string>
 */
function recycleVisibleTypes(): array
{
    // 与 admin/recycle.php 的 recycleModels() 保持同一份桶清单（改一处要同步另一处）
    $all = ['content', 'product', 'album', 'download', 'job'];
    if (hasPermission('*')) {
        return $all;
    }
    $out = [];
    foreach ($all as $t) {
        $ok = match ($t) {
            // content 是混合桶（文章/案例/单页/下载同表）：持有其中任一类型的
            // 删除权即可进入，具体行再按各自的 type 过滤，见 recycleFilterRows()
            'content'  => hasPermission('delete_article') || hasPermission('delete_case')
                       || hasPermission('delete_page')    || hasPermission('delete_download'),
            'product'  => hasPermission('delete_product'),
            'download' => hasPermission('delete_download'),
            'album'    => hasPermission('media'),
            'job'      => hasPermission('delete_job'),
            default    => false,
        };
        if ($ok) {
            $out[] = $t;
        }
    }
    return $out;
}

/**
 * content 混合桶的行级过滤：只留下当前账号有权删除的那些类型。
 * 只有 edit_article 的投稿者不该在回收站里看到已删的单页标题与别名。
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function recycleFilterRows(string $bucket, array $rows): array
{
    if ($bucket !== 'content' || hasPermission('*')) {
        return $rows;
    }
    return array_values(array_filter($rows, static function (array $r): bool {
        $t = (string) ($r['type'] ?? '');
        return in_array($t, contentPermTypes(), true) && hasPermission('delete_' . $t);
    }));
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
    $advanced = [];
    foreach (advancedPermKeys() as $a) {
        $advanced[$a] = permLabel($a);
    }
    return [
        'content'  => ['label' => __('perm_group_content'),  'caps' => $content],
        'module'   => ['label' => __('perm_group_module'),   'caps' => $module],
        'advanced' => ['label' => __('perm_group_advanced'), 'caps' => $advanced],
    ];
}
