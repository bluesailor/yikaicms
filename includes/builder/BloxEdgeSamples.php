<?php
/**
 * 边界样本预览（E10）。
 *
 * 详情模板此前只能拿真实内容做预览，而真实内容通常是"正常"的：标题不长、图片齐全、
 * 字段都填了。模板真正崩的场合恰恰是极端内容——标题长到换三行把按钮挤下去、
 * 产品没有图留下空框、参数一条都没有。等这些内容上线才发现，就得回头改模板。
 *
 * 这里提供合成样本，让作者在设计时就能切过去看一眼。三条边界：
 *   · **绝不写库**：样本是内存里拼的数组，`id` 恒为 0，任何路径都不该拿它去更新真实记录；
 *   · 字段形状与真实记录保持一致，否则预览不出真实的崩法；
 *   · 只在编辑器预览端点使用，前台渲染永远拿不到它们。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class BloxEdgeSamples
{
    /** 合成样本的 id：真实记录的 id 都是正数，0 表示"这不是一条记录"。 */
    public const SYNTHETIC_ID = 0;

    public const EMPTY = 'empty';
    public const LONG = 'long';
    public const RICH = 'rich';

    /** @return list<string> */
    public static function keys(): array
    {
        return [self::EMPTY, self::LONG, self::RICH];
    }

    public static function isEdgeKey(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * 供编辑器下拉展示。唯一调用方是编辑器 partial（psalm.xml 忽略 admin/blox_editor），
     * 所以静态分析看不到调用点。
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function options(): array
    {
        return [
            self::EMPTY => __('blox_edge_sample_empty'),
            self::LONG => __('blox_edge_sample_long'),
            self::RICH => __('blox_edge_sample_rich'),
        ];
    }

    /**
     * 合成一条产品记录。字段名与 products 表一致，缺的字段让模板自己走空值路径。
     *
     * @return array<string,mixed>
     */
    public static function product(string $key, string $lang): array
    {
        $base = [
            'id' => self::SYNTHETIC_ID,
            'lang' => $lang,
            'status' => 1,
            'slug' => 'edge-sample',
            'created_at' => time(),
            'updated_at' => time(),
        ];
        return array_merge($base, match ($key) {
            // 什么都没填：看模板会不会留下空标题、空图框、空参数表
            self::EMPTY => [
                'title' => __('blox_edge_sample_empty_title'),
                'subtitle' => '', 'summary' => '', 'model' => '', 'price' => '',
                'market_price' => '', 'cover' => '', 'images' => '', 'specs' => '', 'content' => '',
            ],
            // 长到极限：标题、摘要、型号都给超长值，看换行与截断
            self::LONG => [
                'title' => self::longText(__('blox_edge_sample_long_title'), 120),
                'subtitle' => self::longText(__('blox_edge_sample_long_title'), 80),
                'summary' => self::longText(__('blox_edge_sample_long_title'), 400),
                'model' => self::longText('YK-MODEL-EDGE-CASE', 64),
                'price' => '1234567.89', 'market_price' => '2345678.99',
                'cover' => '', 'images' => '', 'specs' => '', 'content' => '',
            ],
            // 内容饱满：多图 + 多条参数，看图库与参数表在满载时的版面
            default => [
                'title' => __('blox_edge_sample_rich_title'),
                'subtitle' => __('blox_edge_sample_rich_title'),
                'summary' => self::longText(__('blox_edge_sample_rich_title'), 160),
                'model' => 'YK-2026-RICH', 'price' => '999.00', 'market_price' => '1299.00',
                'cover' => '', 'images' => '', 'specs' => self::specs(), 'content' => '',
            ],
        });
    }

    /**
     * 合成一条文章记录。
     *
     * @return array<string,mixed>
     */
    public static function article(string $key, string $lang): array
    {
        $base = [
            'id' => self::SYNTHETIC_ID,
            'lang' => $lang,
            'type' => 'article',
            'status' => 1,
            'channel_id' => 0,
            'slug' => 'edge-sample',
            'publish_time' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ];
        return array_merge($base, match ($key) {
            self::EMPTY => [
                'title' => __('blox_edge_sample_empty_title'),
                'subtitle' => '', 'summary' => '', 'author' => '', 'source' => '',
                'cover' => '', 'images' => '', 'content' => '',
            ],
            self::LONG => [
                'title' => self::longText(__('blox_edge_sample_long_title'), 120),
                'subtitle' => self::longText(__('blox_edge_sample_long_title'), 80),
                'summary' => self::longText(__('blox_edge_sample_long_title'), 400),
                'author' => self::longText('EditorEdgeCase', 40), 'source' => '',
                'cover' => '', 'images' => '', 'content' => '',
            ],
            default => [
                'title' => __('blox_edge_sample_rich_title'),
                'subtitle' => __('blox_edge_sample_rich_title'),
                'summary' => self::longText(__('blox_edge_sample_rich_title'), 160),
                'author' => __('blox_edge_sample_rich_title'), 'source' => '',
                'cover' => '', 'images' => '', 'content' => '',
            ],
        });
    }

    /** 重复到指定长度：不依赖任何外部素材，三语下都能撑出真实的换行压力。 */
    private static function longText(string $seed, int $length): string
    {
        $seed = $seed === '' ? 'Edge' : $seed;
        $text = '';
        while (mb_strlen($text) < $length) {
            $text .= ($text === '' ? '' : ' ') . $seed;
        }
        return mb_substr($text, 0, $length);
    }

    /** 满载参数表：12 行，足以让"参数很多"的版面问题显形。 */
    private static function specs(): string
    {
        $rows = [];
        for ($i = 1; $i <= 12; $i++) {
            $rows[] = ['name' => 'Spec ' . $i, 'value' => 'Value ' . $i];
        }
        return (string) json_encode($rows, JSON_UNESCAPED_UNICODE);
    }
}
