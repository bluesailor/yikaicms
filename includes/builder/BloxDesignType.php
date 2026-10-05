<?php
/**
 * 设计系统的排版 token（2.0.5 设计系统 2.0，路线图 §5.3「排版 token」）。
 *
 * 与颜色、尺寸 token 同存 blox_design_system 设置（typography 列表），由 BloxDesignSystem 读写；这里只放纯函数。
 * 每项：字号（桌面必填，平板 / 手机可选，缺省继承上一档）、行高、字距、字重（均可选）。
 * 输出两部分：
 *   - :root 变量 --yk-typo-<id>-size / -lh / -ls / -weight，平板（<1024）、手机（<768）用媒体查询改写字号变量；
 *   - 类规则 .yk-typo-<id>:not(.yk-r-<属性>){…}：元素选了这个 token 就挂这个类。
 *     特异性 (0,2,0)：压过主题标题样式（h2.yk-type-h2 是 0,1,1）与字号档位工具类；元素上单独设的精确字号 / 行高
 *     （.yk-r-font-size 等编译类或内联样式）仍然优先——:not() 让 token 不碰已单独设置的属性。
 *     token 被删除时类规则随之消失，元素回到原样；归档的照常输出（墓碑），存量页面不变。
 * 全部免费。
 */

declare(strict_types=1);

final class BloxDesignType
{
    public const MAX_ITEMS = 24;
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,47}$/';
    private const MEDIA = ['t' => '@media (max-width:1023.98px)', 'm' => '@media (max-width:767.98px)'];

    /** 出厂刻度（站点从未保存过时使用） @return list<array<string,mixed>> */
    public static function seeds(): array
    {
        $item = static fn (string $id, string $d, ?string $t, ?string $m, string $lh, string $weight = '', string $ls = ''): array =>
            ['id' => $id, 'size' => array_filter(['d' => $d, 't' => $t, 'm' => $m]), 'line_height' => $lh, 'weight' => $weight, 'letter_spacing' => $ls];
        return [
            $item('text-xs', '12px', null, null, '1.5'),
            $item('text-sm', '14px', null, null, '1.5'),
            $item('text-base', '16px', null, null, '1.6'),
            $item('text-lg', '18px', null, null, '1.6'),
            $item('text-xl', '20px', null, '18px', '1.5'),
            $item('heading-sm', '20px', null, '18px', '1.4', '600'),
            $item('heading-md', '24px', '22px', '20px', '1.3', '700'),
            $item('heading-lg', '32px', '28px', '24px', '1.25', '700'),
            $item('heading-xl', '40px', '34px', '28px', '1.2', '700', '-0.01em'),
        ];
    }

    public static function seedName(string $id): string
    {
        return __('blox_typo_' . str_replace('-', '_', $id));
    }

    public static function normalizeSize(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,3}$/', trim($value)) === 1)) {
            $value = ((int) $value) . 'px';
        }
        if (!is_string($value)) return null;
        $value = strtolower(trim($value));
        if (preg_match('/^(\d{1,3}(?:\.\d{1,2})?)px$/', $value, $m) === 1 && (float) $m[1] >= 8 && (float) $m[1] <= 160) return $m[1] . 'px';
        if (preg_match('/^(\d{1,2}(?:\.\d{1,3})?)rem$/', $value, $m) === 1 && (float) $m[1] >= 0.5 && (float) $m[1] <= 10) return $m[1] . 'rem';
        return null;
    }

    /** @return array{id:string,name:string,size:array<string,string>,line_height:string,letter_spacing:string,weight:string,status:string,locked:bool,version:int}|null */
    public static function normalizeItem(mixed $item): ?array
    {
        if (!is_array($item)) return null;
        $id = trim((string) ($item['id'] ?? ''));
        $name = mb_substr(trim((string) ($item['name'] ?? '')), 0, 60);
        $rawSize = is_array($item['size'] ?? null) ? $item['size'] : ['d' => $item['size'] ?? null];
        $size = [];
        foreach (['d', 't', 'm'] as $device) {
            if (($rawSize[$device] ?? '') === '' || ($rawSize[$device] ?? null) === null) continue;
            $value = self::normalizeSize($rawSize[$device]);
            if ($value === null) return null;
            $size[$device] = $value;
        }
        if (preg_match(self::ID_PATTERN, $id) !== 1 || $name === '' || !isset($size['d'])) return null;
        $lineHeight = trim((string) ($item['line_height'] ?? ''));
        if ($lineHeight !== '' && (preg_match('/^\d(?:\.\d{1,2})?$/', $lineHeight) !== 1 || (float) $lineHeight < 0.8 || (float) $lineHeight > 3)) return null;
        $letter = strtolower(trim((string) ($item['letter_spacing'] ?? '')));
        if ($letter !== '' && preg_match('/^-?0?\.\d{1,3}em$|^-?\d(?:\.\d{1,2})?px$|^0$/', $letter) !== 1) return null;
        $weight = trim((string) ($item['weight'] ?? ''));
        if ($weight !== '' && preg_match('/^[1-9]00$/', $weight) !== 1) return null;
        return [
            'id' => $id, 'name' => $name, 'size' => $size, 'line_height' => $lineHeight, 'letter_spacing' => $letter, 'weight' => $weight,
            'status' => ($item['status'] ?? '') === 'archived' ? 'archived' : 'active',
            'locked' => !empty($item['locked']),
            'version' => max(1, (int) ($item['version'] ?? 1)),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function normalizeList(mixed $raw): array
    {
        $source = is_array($raw) ? $raw : array_map(static fn (array $seed): array => $seed + ['name' => self::seedName($seed['id'])], self::seeds());
        $items = [];
        foreach ($source as $item) {
            $normalized = self::normalizeItem($item);
            if ($normalized !== null && !isset($items[$normalized['id']]) && count($items) < self::MAX_ITEMS) {
                $items[$normalized['id']] = $normalized;
            }
        }
        return array_values($items);
    }

    /** :root 声明 + 平板 / 手机字号媒体查询 + 类规则。 @param list<array<string,mixed>> $items */
    public static function css(array $items): string
    {
        $root = '';
        $media = ['t' => '', 'm' => ''];
        $rules = '';
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $size = is_array($item['size'] ?? null) ? $item['size'] : [];
            if (preg_match(self::ID_PATTERN, $id) !== 1 || !isset($size['d'])) continue;
            $var = '--yk-typo-' . $id;
            $root .= $var . '-size:' . $size['d'] . ';';
            foreach (['t', 'm'] as $device) {
                if (isset($size[$device])) $media[$device] .= $var . '-size:' . $size[$device] . ';';
            }
            $class = '.yk-typo-' . $id;
            $rules .= $class . ':not(.yk-r-font-size){font-size:var(' . $var . '-size)}';
            foreach (['line_height' => ['lh', 'line-height'], 'letter_spacing' => ['ls', 'letter-spacing'], 'weight' => ['weight', 'font-weight']] as $key => [$suffix, $property]) {
                $value = (string) ($item[$key] ?? '');
                if ($value === '') continue;
                $root .= $var . '-' . $suffix . ':' . $value . ';';
                $rules .= $class . ':not(.yk-r-' . $property . '){' . $property . ':var(' . $var . '-' . $suffix . ')}';
            }
        }
        if ($root === '') return '';
        $css = ':root{' . $root . '}';
        foreach (['t', 'm'] as $device) {
            if ($media[$device] !== '') $css .= self::MEDIA[$device] . '{:root{' . $media[$device] . '}}';
        }
        return $css . $rules;
    }

    /** 元素选了排版 token 时挂到根标签上的类名；id 不合法返回 null。 */
    public static function className(mixed $id): ?string
    {
        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? 'yk-typo-' . $id : null;
    }
}
