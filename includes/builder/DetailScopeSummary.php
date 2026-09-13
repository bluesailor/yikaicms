<?php
/**
 * 详情模板作用域的**只读摘要**（TASK-005 B）。
 *
 * 为什么单独一个类：`detail_template` 的完整规则（含 `kind=category` 与 `exclude`）在
 * v1 形态投影里会被丢掉，后台展示若沿用投影就会漏报/误报。这里只做**读取与描述**：
 * 不新增规则、不计算命中数量、不改写任何字段；命名解析由调用方一次查表传入（避免 N+1）。
 */
declare(strict_types=1);

final class DetailScopeSummary
{
    /**
     * 抽出纳入/排除两侧的分类规则，**逐条**保留各自的 include_children。
     *
     * @param array<string,mixed> $scope 归一化后的作用域（DetailTemplateResolver::normalizeScope）
     * @return array{include:list<array{ids:list<int>,include_children:bool}>,exclude:list<array{ids:list<int>,include_children:bool}>}
     */
    public static function categoryRules(array $scope): array
    {
        $out = ['include' => [], 'exclude' => []];
        foreach (['include', 'exclude'] as $side) {
            $rules = is_array($scope[$side] ?? null) ? $scope[$side] : [];
            foreach ($rules as $rule) {
                if (!is_array($rule) || (string) ($rule['kind'] ?? '') !== 'category') {
                    continue;
                }
                $ids = [];
                foreach (is_array($rule['ids'] ?? null) ? $rule['ids'] : [] as $id) {
                    $ids[] = (int) $id;
                }
                $out[$side][] = [
                    'ids' => array_values(array_unique($ids)),
                    // 逐条读取：不能拿任意一条的 include_children 推断其它规则
                    'include_children' => ($rule['include_children'] ?? false) === true,
                ];
            }
        }
        return $out;
    }

    /** 是否含分类规则（纳入或排除；两条写路径都可能只写入一侧）。 */
    public static function hasCategory(array $scope): bool
    {
        $rules = self::categoryRules($scope);
        return $rules['include'] !== [] || $rules['exclude'] !== [];
    }

    /**
     * 给每条规则解析名称：缺失/空名回退 `ID:<id>`，不隐藏未命中的引用。
     *
     * @param list<array{ids:list<int>,include_children:bool}> $rules
     * @param array<int,string> $names id => 名称（调用方一次查表）
     * @return list<array{labels:list<string>,include_children:bool}>
     */
    public static function labelled(array $rules, array $names): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $labels = [];
            foreach ($rule['ids'] as $id) {
                $name = isset($names[$id]) ? trim((string) $names[$id]) : '';
                $labels[] = $name !== '' ? $name : 'ID:' . $id;
            }
            $out[] = ['labels' => $labels, 'include_children' => $rule['include_children']];
        }
        return $out;
    }
}
