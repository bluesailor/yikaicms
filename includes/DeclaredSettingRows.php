<?php
declare(strict_types=1);

/**
 * 设置页的字段由 config/defaults.php 的声明决定，而不是由库里有没有这一行决定。
 *
 * 行缺失是正常状态：整站模板导入（SiteTemplateData::replace）有意删掉包里没带的可移植
 * 设置行——没带就用默认，中文出厂文案、旧首页数据不能留进模板站；撤销导入还要逐字节
 * 复原，所以导入侧不补行。前台读设置本来就有兜底，只有按行渲染的后台表单会把字段弄丢
 * （英文 Flow 包导入后 site_logo_max_height、header_nav_layout、页脚栏目等全从后台消失）。
 *
 * 缺行的声明键补一条「合成行」：值取 defaults.php，但含汉字的出厂文案显示为空（前台缺行时
 * 走的是各语言自己的兜底，不是这段中文）。合成值原样提交回来不落库（withoutUntouched），
 * 否则随手点一次保存就把中文种子、或与主题自带兜底不同的默认值写进站点。
 * 首页设置另有按语言的合成规则，见 HomeSettingsLanguageDefaults。
 */
final class DeclaredSettingRows
{
    /**
     * @param list<array<string,mixed>> $rows 该分组库里已有的行（getByGroup）
     * @param array<string,array<string,mixed>> $defaults 该分组的声明（getDefaults($group)）
     * @param array<string,string> $stored 全部设置（getAll）：分组字段漂移的行照样按已存值显示
     * @return list<array<string,mixed>> 按 sort_order 稳定排序；合成行带 'synthetic' => true
     */
    public static function complete(array $rows, string $group, array $defaults, array $stored): array
    {
        $present = [];
        foreach ($rows as $row) {
            $present[(string) $row['key']] = true;
        }
        foreach ($defaults as $key => $definition) {
            if (isset($present[$key])) {
                continue;
            }
            $missing = !array_key_exists($key, $stored);
            $options = $definition['options'] ?? null;
            $rows[] = [
                'key' => $key,
                'value' => $missing ? self::syntheticValue($definition) : (string) $stored[$key],
                'group' => $group,
                'name' => (string) ($definition['name'] ?? $key),
                'type' => (string) ($definition['type'] ?? 'text'),
                'tip' => (string) ($definition['tip'] ?? ''),
                'options' => is_array($options) ? json_encode($options, JSON_UNESCAPED_UNICODE) : $options,
                'sort_order' => (int) ($definition['sort_order'] ?? 0),
                'synthetic' => $missing,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => (int) ($a['sort_order'] ?? 0) <=> (int) ($b['sort_order'] ?? 0));
        return $rows;
    }

    /**
     * 去掉「没有库行、提交值就是合成值」的键：管理员没改过，保持缺行（= 前台兜底）。
     * 在语言重定向之后调用——<key>_<lang> 不是声明键，不受影响。
     *
     * @param array<array-key,mixed> $submitted
     * @param array<string,array<string,array<string,mixed>>> $allDefaults getDefaults()
     * @param array<string,string> $stored getAll()
     * @return array<array-key,mixed>
     */
    public static function withoutUntouched(array $submitted, array $allDefaults, array $stored): array
    {
        foreach ($allDefaults as $definitions) {
            if (!is_array($definitions)) {
                continue;
            }
            foreach ($definitions as $key => $definition) {
                if (array_key_exists($key, $submitted) && !array_key_exists($key, $stored)
                    && is_scalar($submitted[$key]) && (string) $submitted[$key] === self::syntheticValue($definition)) {
                    unset($submitted[$key]);
                }
            }
        }
        return $submitted;
    }

    /** @param array<string,mixed> $definition */
    public static function syntheticValue(array $definition): string
    {
        $value = (string) ($definition['value'] ?? '');
        if (preg_match('/\p{Han}/u', $value) !== 1) {
            return $value;
        }
        // 页脚栏目、联系卡片这类 JSON 列表：空列表；其余文案：空串
        return str_starts_with(ltrim($value), '[') ? '[]' : '';
    }
}
