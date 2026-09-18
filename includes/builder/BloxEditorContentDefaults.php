<?php

declare(strict_types=1);

/**
 * 编辑器元素默认值按「内容语言」下发。
 *
 * 注册表元数据在后台语言下生成：控件标签、选项名是界面文字，应跟后台语言；
 * 但插入元素时写进文档的默认值（按钮文字、新增条目的标题与正文等）是页面内容，
 * 英文/日文页面里应是英文/日文。这里用内容语言下再生成的一份元数据，只替换内容类字段。
 *
 * 唯一调用方 admin/blox_editor.php 不在 Psalm 扫描范围内，静态分析视为无人使用。
 * @psalm-suppress UnusedClass
 */
final class BloxEditorContentDefaults
{
    /** 控件里属于「写进文档的内容」的键 */
    private const CONTENT_KEYS = ['default', 'new_title', 'new_body'];

    /**
     * @param array<string,array<string,mixed>> $meta 后台语言的注册表元数据
     * @param array<string,array<string,mixed>> $contentMeta 内容语言下生成的同一份元数据
     * @return array<string,array<string,mixed>>
     */
    public static function apply(array $meta, array $contentMeta): array
    {
        foreach ($meta as $type => $entry) {
            $content = $contentMeta[$type] ?? null;
            if (!is_array($entry) || !is_array($content)) {
                continue;
            }
            if (array_key_exists('defaults', $content)) {
                $meta[$type]['defaults'] = $content['defaults'];
            }
            if (is_array($entry['controls'] ?? null) && is_array($content['controls'] ?? null)) {
                $meta[$type]['controls'] = self::controls($entry['controls'], $content['controls']);
            }
        }
        return $meta;
    }

    /**
     * 按位置对齐同一个控件（key 必须一致），嵌套 fields 递归处理。
     * @param array<int|string,mixed> $controls
     * @param array<int|string,mixed> $contentControls
     * @return array<int|string,mixed>
     */
    private static function controls(array $controls, array $contentControls): array
    {
        foreach ($controls as $index => $control) {
            $content = $contentControls[$index] ?? null;
            if (!is_array($control) || !is_array($content) || ($control['key'] ?? null) !== ($content['key'] ?? null)) {
                continue;
            }
            foreach (self::CONTENT_KEYS as $key) {
                if (array_key_exists($key, $content)) {
                    $controls[$index][$key] = $content[$key];
                }
            }
            if (is_array($control['fields'] ?? null) && is_array($content['fields'] ?? null)) {
                $controls[$index]['fields'] = self::controls($control['fields'], $content['fields']);
            }
        }
        return $controls;
    }
}
