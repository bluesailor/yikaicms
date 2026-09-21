<?php
/**
 * 权限能力目录（单一数据源）
 *
 * 细粒度 RBAC，借鉴 WordPress capabilities：命名 {动作}_{类型}。
 *   内容类：每类型分 编辑/删除 两档（edit_article / delete_article …）
 *   辅助模块：单权限（media / banner / link / form / member）
 *   Blox：编辑器 / 首页 / 全站设计 / 高危代码元素分别授权
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

/** Blox 场景能力；内容编辑范围仍由 edit_page 等内容权限约束。 */
function bloxPermKeys(): array
{
    return ['blox_edit', 'blox_home', 'blox_global', 'blox_code'];
}

/** 全部合法权限键（含通配 *），用于保存时过滤非法值 */
function allPermissionKeys(): array
{
    $keys = ['*'];
    foreach (contentPermTypes() as $t) {
        $keys[] = 'edit_' . $t;
        $keys[] = 'delete_' . $t;
    }
    return array_merge($keys, modulePermKeys(), bloxPermKeys(), pluginPermissionKeys());
}

/**
 * 插件声明的权限键（G1，商城立项报告 §六；首个消费者：shop 商城插件）。
 *
 * 为什么走 plugin.json 声明而不是插件运行时注册：后台页面（role.php /
 * plugin_page.php）的引导链只到 functions.php + auth.php，不加载
 * includes/plugin.php——插件代码在这些页面上没机会执行，运行时注册必然取不到。
 * plugin.json 是文件级事实，任何上下文可读，也没有加载顺序问题。
 *
 * 声明形状（plugin.json）：
 *   "permissions": {
 *     "shop_manage": {"label": "商城管理", "label_en": "Shop", "label_ja": "ショップ"}
 *   },
 *   "admin_permission": "shop_manage"    // 插件后台页所需权限；缺省仍为超管专属
 *
 * 只收集**已启用**插件的键：停用插件的权限不应继续出现在角色勾选界面
 * （role.php 保存时按 allPermissionKeys() 过滤，停用后角色残留键自然清除）。
 *
 * @param list<string> $activeSlugs
 * @return array<string, array<string, mixed>> key => 声明信息（label/label_en/…）
 */
function pluginPermissionManifest(string $pluginsDir, array $activeSlugs): array
{
    $manifest = [];
    foreach ($activeSlugs as $slug) {
        if (!is_string($slug) || preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug) !== 1) {
            continue;
        }
        $file = rtrim($pluginsDir, '/\\') . '/' . $slug . '/plugin.json';
        if (!is_file($file)) {
            continue;
        }
        $meta = json_decode((string) file_get_contents($file), true);
        $declared = is_array($meta) ? ($meta['permissions'] ?? null) : null;
        if (!is_array($declared)) {
            continue;
        }
        foreach ($declared as $key => $info) {
            // 键名从严：小写字母开头、仅小写字母/数字/下划线，杜绝对勾选界面的注入面
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
                continue;
            }
            $manifest[$key] = is_array($info) ? $info : [];
        }
    }

    return $manifest;
}

/** 已启用插件声明的权限清单（进程内缓存；DB 不可用时退化为空——只收紧不放宽）。 */
function pluginPermissions(): array
{
    /** @var array<string, array<string, mixed>>|null $cache */
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $active = db()->tableExists('plugins') ? pluginModel()->getActiveSlugs() : [];
    } catch (\Throwable $e) {
        $active = [];
    }

    return $cache = pluginPermissionManifest(ROOT_PATH . '/plugins', $active);
}

/** @return list<string> */
function pluginPermissionKeys(): array
{
    return array_keys(pluginPermissions());
}

/** 插件权限键 → 按当前语言取 label；未声明/未启用时原样返回键名。 */
function pluginPermissionLabel(string $key): string
{
    $info = pluginPermissions()[$key] ?? null;
    if (!is_array($info)) {
        return $key;
    }
    $lang = function_exists('getLang') ? getLang() : 'zh-CN';
    if ($lang !== 'zh-CN') {
        $suffixed = $info['label_' . str_replace('-', '_', $lang)] ?? null;
        if (is_string($suffixed) && $suffixed !== '') {
            return $suffixed;
        }
    }
    $label = $info['label'] ?? null;

    return is_string($label) && $label !== '' ? $label : $key;
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
        'blox_edit' => 'perm_blox_edit', 'blox_home' => 'perm_blox_home',
        'blox_global' => 'perm_blox_global', 'blox_code' => 'perm_blox_code',
    ];
    if (isset($mod[$key])) {
        return __($mod[$key]);
    }
    // 插件声明的键：label 来自 plugin.json（后台引导链不加载插件语言包，故不走 __()）
    return pluginPermissionLabel($key);
}

/** Blox 权限用途说明；角色授权界面只为这组高影响能力显示详细描述。 */
function permDescription(string $key): string
{
    $descriptions = [
        'blox_edit' => 'perm_blox_edit_desc',
        'blox_home' => 'perm_blox_home_desc',
        'blox_global' => 'perm_blox_global_desc',
        'blox_code' => 'perm_blox_code_desc',
    ];
    return isset($descriptions[$key]) ? __($descriptions[$key]) : '';
}

/**
 * 返回权限组合错误；null 表示组合有效。
 *
 * @param list<string> $permissions
 */
function permissionSetError(array $permissions): ?string
{
    if (in_array('*', $permissions, true)) {
        return null;
    }
    if (in_array('blox_edit', $permissions, true) && !in_array('edit_page', $permissions, true)) {
        return __('role_blox_edit_requires_page');
    }
    if (in_array('blox_code', $permissions, true)
        && array_intersect(['blox_edit', 'blox_home', 'blox_global'], $permissions) === []) {
        return __('role_blox_code_requires_scope');
    }
    return null;
}

/**
 * 允许写进 contents.type 的类型键：内置类型 + 后台登记过的自定义模型。
 *
 * 共享编辑器过去直接采信 POST 里的 type，任何字符串都能落库；列表页又对未知类型
 * 原样回显，于是 type 成了后台 HTML 注入的入口（2026-09-18 复审 R02）。
 *
 * @return list<string>
 */
function registeredContentTypes(): array
{
    $types = contentPermTypes();
    if (function_exists('contentModelModel')) {
        try {
            $types = array_merge($types, contentModelModel()->keys());
        } catch (\Throwable $e) {
            // content_models 表可能尚未创建（老站升级中）：退回内置类型即可
        }
    }
    $clean = [];
    foreach ($types as $type) {
        $type = (string) $type;
        if ($type !== '' && preg_match('/^[a-z][a-z0-9_-]*$/', $type)) {
            $clean[$type] = $type;
        }
    }
    return array_values($clean);
}

/** type 是否为已登记的内容类型键。 */
function isRegisteredContentType(string $type): bool
{
    return in_array($type, registeredContentTypes(), true);
}

/**
 * 是否拥有任一内容类型的**编辑**权限（超管恒真）。
 *
 * 与 hasAnyContentPerm() 的区别：那个连"只有删除文章权"也算数，自定义模型的编辑
 * 兜底用它等于让 delete-only 角色能创建和改写内容。
 */
function hasAnyContentEditPerm(): bool
{
    if (hasPermission('*')) {
        return true;
    }
    foreach (contentPermTypes() as $t) {
        if (hasPermission('edit_' . $t)) {
            return true;
        }
    }
    return false;
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

/** 是否拥有任一可独立进入 Blox 工作区的场景能力（超管恒真）。 */
function hasAnyBloxPermission(): bool
{
    // blox_code 只是高危元素的附加开关，不能单独打开编辑器或清缓存。
    foreach (['blox_edit', 'blox_home', 'blox_global'] as $permission) {
        if (hasPermission($permission)) {
            return true;
        }
    }
    return false;
}

/** 要求至少拥有一个可进入 Blox 工作区的场景权限。 */
function requireAnyBloxPermission(): void
{
    if (!hasAnyBloxPermission()) {
        // 走统一权限拒绝响应；此分支已确认 blox_edit 不存在，因此必然拒绝。
        requirePermission('blox_edit');
    }
}

/**
 * 共享内容编辑器守卫：已知内容类型精确要求 edit_{type}（保证类型隔离——
 * 产品编辑者不能借共享编辑器改文章）；后台登记过的自定义模型要求任一**编辑**权限；
 * 未登记的类型一律拒绝，不再放行任意字符串。
 */
function requireContentEditPerm(?string $type): void
{
    $type = (string) $type;
    if (in_array($type, contentPermTypes(), true)) {
        requirePermission('edit_' . $type);
        return;
    }
    if ($type !== '' && !isRegisteredContentType($type)) {
        permissionDenied();
    }
    if (!hasAnyContentEditPerm()) {
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

/** 视频用于 Banner / 页面排版，权限边界与图片一致。 */
function canUploadVideo(): bool
{
    return canUploadImage();
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
    return match ($type) {
        'images' => canUploadImage(),
        'videos' => canUploadVideo(),
        default => canUploadFile(),
    };
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
 * 统一的权限拒绝响应：AJAX 走 403 JSON，页面走提示页。
 *
 * requirePermission() 只能表达「缺少某个权限键」，但越界访问未必是缺权限——
 * 超管拿文章入口去改案例同样应当拒绝（固定类型入口不做类型转换）。
 */
function permissionDenied(): void
{
    if (isAjax()) {
        error(__('perm_denied'), 403);
    }
    die('<div style="padding:50px;text-align:center;"><h2>' . e(__('perm_denied')) . '</h2><a href="/admin/">返回首页</a></div>');
}

/**
 * 固定类型入口（article.php / case.php …）的行级守卫。
 *
 * 文章、案例、单页、下载同住 contents 表。这些入口过去只在文件顶部检查模块级
 * 权限（edit_article），随后把 POST 里的任意 id 交给共享的 contents 模型：只有
 * edit_article 的投稿者因此能经文章入口下架、改写、删除案例（2026-09-17 发版前
 * 审计实测复现 F01）。
 *
 * 规则：提交的 id 必须全部属于本入口的类型，任一越界即整批拒绝，不做「跳过越界项」
 * 式的部分执行——部分执行会让越权者通过成功/失败的差异探测其它类型的 id 是否存在。
 * 查不到的 id 直接丢弃（没有行可操作）。
 *
 * @param array<array-key, mixed> $ids POST 原样传入即可：非正整数的项会被丢弃
 * @param string $mode edit|delete
 * @return list<int> 去重后确实存在且类型正确的 id
 */
function requireContentRowsOfType(array $ids, string $type, string $mode = 'edit'): array
{
    $wanted = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $wanted[$id] = $id;
        }
    }
    $wanted = array_values($wanted);
    if (!$wanted) {
        return [];
    }
    requirePermission(($mode === 'delete' ? 'delete_' : 'edit_') . $type);

    // 回收站里的行不算「可操作」：Model::find() 会过滤掉它们，守卫若放行，
    // 单条版随后拿到 null，撞出 TypeError 而不是受控错误（复审 R06）。
    // 两个管理员先后操作同一条记录，或在旧列表里再删一次，都会走到这里。
    $placeholders = implode(',', array_fill(0, count($wanted), '?'));
    $rows = db()->fetchAll(
        'SELECT id, `type` FROM ' . DB_PREFIX . 'contents WHERE id IN (' . $placeholders . ') AND deleted_at IS NULL',
        $wanted
    );
    $allowed = [];
    foreach ($rows as $row) {
        if ((string) $row['type'] !== $type) {
            permissionDenied();
        }
        $allowed[] = (int) $row['id'];
    }
    return $allowed;
}

/**
 * 单条版：跨类型一律拒绝；记录不存在或已在回收站时返回受控的「数据不存在」。
 * 批量版对同样的 id 是直接跳过（幂等），两者都不以 500 收场。
 */
function requireContentRowOfType(int $id, string $type, string $mode = 'edit'): array
{
    if ($id <= 0 || !requireContentRowsOfType([$id], $type, $mode)) {
        error(__('admin_no_data'));
    }
    $row = contentModel()->find($id);
    if ($row === null) {
        // 守卫与 find() 之间被别人删掉了：同样按「已不存在」处理
        error(__('admin_no_data'));
    }
    return $row;
}

/**
 * 翻译创建的授权：按"源记录到底是什么"判，而不是按"打开的是哪个页面"判。
 *
 * 翻译处理器有自己的 src_id，走在固定类型入口的行级守卫之前，因此只有 edit_article
 * 的账号曾能借文章编辑页为案例、单页创建译文，并改动源记录的翻译分组
 *（2026-09-18 发版前复审 R01 实测复现）。
 *
 * @param array<string,mixed> $row       源记录
 * @param string              $boundType 固定类型入口声明的类型（article_edit → article）；空串表示共享入口
 */
function requireTranslationPermission(string $table, array $row, string $boundType = ''): void
{
    if ($table === 'contents') {
        $type = (string) ($row['type'] ?? '');
        if ($boundType !== '' && $type !== $boundType) {
            permissionDenied();   // 固定类型入口不给别的类型开翻译口子
        }
        requireContentEditPerm($type);
        return;
    }

    // 各自有独立表的内容：表名 → 能力键
    $tablePermissions = [
        'products'           => 'edit_product',
        'product_categories' => 'edit_product',
        'jobs'               => 'edit_job',
        'downloads'          => 'edit_download',
        'albums'             => 'media',
        'banners'            => 'banner',
        'links'              => 'link',
    ];
    if (isset($tablePermissions[$table])) {
        requirePermission($tablePermissions[$table]);
        return;
    }

    // 栏目、设置一类的结构数据：未登记的表一律要求超管，新增表默认收紧而不是默认放行
    requirePermission('*');
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
    return preg_match('/(^|_)(key|secret|token|pass|passwd|password|credential|authorization|auth|appid|appkey|access_id)($|_)/', $key) === 1;
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
    $blox = [];
    foreach (bloxPermKeys() as $permission) {
        $blox[$permission] = permLabel($permission);
    }
    $catalog = [
        'content'  => ['label' => __('perm_group_content'),  'caps' => $content],
        'module'   => ['label' => __('perm_group_module'),   'caps' => $module],
        'blox'     => ['label' => __('perm_group_blox'),     'caps' => $blox],
    ];
    // 插件声明的权限键（G1）：有才出现该分组——没装插件的站点角色界面保持原样
    $plugin = [];
    foreach (pluginPermissionKeys() as $permission) {
        $plugin[$permission] = pluginPermissionLabel($permission);
    }
    if ($plugin !== []) {
        $catalog['plugin'] = ['label' => __('perm_group_plugin'), 'caps' => $plugin];
    }

    return $catalog;
}
