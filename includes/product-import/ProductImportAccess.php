<?php
/**
 * 产品导入的权限规则（2.3.0）。五个动作分开定义、分开检查：
 *   import           读导入包、预览、建任务、分批写入产品
 *   export           把本站产品（含草稿）连图片导出成导入包
 *   undo             整批撤销：删掉这次新建的产品、分类、图片，把更新过的产品改回去
 *   create_category  导入时新建产品分类
 *   create_media     导入时把包里的图片存进媒体库
 *
 * 现在整个页面只有超级管理员能进（admin/product_import.php，所有者 2026-11-20 定），
 * 超级管理员对下面每一条都通过，所以这张表目前不改变任何人的实际权限。
 * 它的用处是：以后要放给产品编辑时，页面入口放开后每个动作各按这里的要求检查，
 * 可以只放导入不放撤销、或要求媒体权限才能带图导入——改这一处，测试跟着这一处。
 *
 * 每个动作要求「同时具备」列出的全部权限键。键都是已有的（不新增权限项、不动角色设置页）。
 */

declare(strict_types=1);

final class ProductImportAccess
{
    public const ACTIONS = ['import', 'export', 'undo', 'create_category', 'create_media'];

    /** @var array<string,list<string>> 动作 => 需要同时具备的权限键 */
    public const REQUIRES = [
        // 与产品编辑页相同：能改产品才能批量改
        'import' => ['edit_product'],
        // 导出包含未发布的产品与全部图片，同样要产品编辑权
        'export' => ['edit_product'],
        // 撤销会删产品：除了编辑权还要产品删除权
        'undo' => ['edit_product', 'delete_product'],
        // 产品分类管理页要的就是产品编辑权（permissions.php：product_categories => edit_product）
        'create_category' => ['edit_product'],
        // 批量往媒体库加图：要媒体库权限（产品编辑页里单张插图不需要，但一个包可以带上千张）
        'create_media' => ['edit_product', 'media'],
    ];

    /** @var array<string,string> 接口（?handler=）=> 动作。没列在这里的接口一律拒绝 */
    public const HANDLERS = [
        'sample' => 'import', 'upload' => 'import', 'import' => 'import',
        'pkg_upload' => 'import', 'pkg_incoming' => 'import', 'pkg_use_incoming' => 'import', 'pkg_plan' => 'import',
        'pkg_image' => 'import', 'pkg_categories' => 'import',
        'job_create' => 'import', 'job_run' => 'import', 'job_retry' => 'import',
        'job_get' => 'import', 'jobs' => 'import', 'job_errors' => 'import',
        'pkg_export' => 'export',
        'job_undo' => 'undo',
    ];

    /** @param callable(string):bool $has 当前账号有没有某个权限键（超级管理员恒真） */
    public static function can(string $action, callable $has): bool
    {
        $keys = self::REQUIRES[$action] ?? null;
        if ($keys === null) return false;
        foreach ($keys as $key) {
            if (!$has($key)) return false;
        }
        return true;
    }

    /** 这个接口属于哪个动作；不认识的接口返回空串（调用方拒绝） */
    public static function actionFor(string $handler): string
    {
        return self::HANDLERS[$handler] ?? '';
    }

    /** @param callable(string):bool $has */
    public static function handlerAllowed(string $handler, callable $has): bool
    {
        $action = self::actionFor($handler);
        return $action !== '' && self::can($action, $has);
    }

    /**
     * 这份导入计划除了 import 之外还会用到哪些动作：要新建分类、要把包里的图片存进媒体库。
     * 只算真正会执行的行（跳过的、有错的不算）。
     * @param array<string,mixed> $plan ProductImportPlanner::plan() 的结果
     * @param array<int,string> $decisions 行号 => create / update / skip（预览里的选择）
     * @return list<string>
     */
    public static function planNeeds(array $plan, array $decisions = []): array
    {
        $needs = [];
        $categories = false;
        foreach ((array) ($plan['categories'] ?? []) as $category) {
            if (($category['action'] ?? '') === 'create' && ($category['errors'] ?? []) === []) $categories = true;
            if (self::hasPackageImage((array) ($category['images'] ?? []))) $needs['create_media'] = true;
        }
        $media = isset($needs['create_media']);
        $anyRow = false;
        foreach ((array) ($plan['products'] ?? []) as $row) {
            $action = (string) ($row['action'] ?? '');
            $choice = $decisions[(int) ($row['index'] ?? -1)] ?? $action;
            if ($action === 'error' || $choice === 'skip') continue;
            $anyRow = true;
            if (!$media && self::hasPackageImage((array) ($row['images'] ?? []))) $media = true;
        }
        $out = [];
        // 没有任何一行要执行时，分类也不会建
        if ($categories && $anyRow) $out[] = 'create_category';
        if ($media && $anyRow) $out[] = 'create_media';
        return $out;
    }

    /**
     * 计划里缺哪些动作的权限（空数组 = 都有）。
     * @param list<string> $needs @param callable(string):bool $has @return list<string>
     */
    public static function missing(array $needs, callable $has): array
    {
        return array_values(array_filter($needs, static fn (string $action): bool => !self::can($action, $has)));
    }

    /** @param array<int|string,mixed> $images 一行的图片：角色 => 列表，或直接是列表 */
    private static function hasPackageImage(array $images): bool
    {
        foreach ($images as $item) {
            if (!is_array($item)) continue;
            if (($item['kind'] ?? '') === 'package') return true;
            if (!isset($item['kind']) && self::hasPackageImage($item)) return true;
        }
        return false;
    }
}
