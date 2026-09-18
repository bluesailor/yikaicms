<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxEditorContentDefaults.php';

final class BloxEditorContentDefaultsTest extends TestCase
{
    public function testOnlyContentValuesFollowTheContentLanguage(): void
    {
        $admin = [
            'button' => [
                'label' => '按钮',
                'defaults' => ['text' => '按钮', 'variant' => 'primary'],
                'controls' => [
                    ['key' => 'text', 'label' => '按钮文字', 'default' => '按钮'],
                    ['key' => 'variant', 'label' => '样式', 'default' => 'primary', 'options' => ['primary' => '主要']],
                ],
            ],
            'tabs' => [
                'label' => '选项卡',
                'defaults' => ['items' => []],
                'controls' => [[
                    'key' => 'items', 'label' => '条目', 'new_title' => '新选项卡', 'new_body' => '内容',
                    'fields' => [['key' => 'title', 'label' => '标题', 'default' => '标题']],
                ]],
            ],
            'plugin-only' => ['label' => '插件', 'defaults' => ['x' => '中文']],
        ];
        $content = [
            'button' => [
                'label' => 'Button',
                'defaults' => ['text' => 'Button', 'variant' => 'primary'],
                'controls' => [
                    ['key' => 'text', 'label' => 'Button text', 'default' => 'Button'],
                    ['key' => 'variant', 'label' => 'Style', 'default' => 'primary', 'options' => ['primary' => 'Primary']],
                ],
            ],
            'tabs' => [
                'label' => 'Tabs',
                'defaults' => ['items' => []],
                'controls' => [[
                    'key' => 'items', 'label' => 'Items', 'new_title' => 'New tab', 'new_body' => 'Content',
                    'fields' => [['key' => 'title', 'label' => 'Title', 'default' => 'Title']],
                ]],
            ],
        ];

        $merged = BloxEditorContentDefaults::apply($admin, $content);

        self::assertSame('Button', $merged['button']['defaults']['text']);
        self::assertSame('Button', $merged['button']['controls'][0]['default']);
        // 界面文字仍是后台语言
        self::assertSame('按钮', $merged['button']['label']);
        self::assertSame('按钮文字', $merged['button']['controls'][0]['label']);
        self::assertSame(['primary' => '主要'], $merged['button']['controls'][1]['options']);
        self::assertSame(['New tab', 'Content'], [$merged['tabs']['controls'][0]['new_title'], $merged['tabs']['controls'][0]['new_body']]);
        self::assertSame('Title', $merged['tabs']['controls'][0]['fields'][0]['default']);
        self::assertSame('标题', $merged['tabs']['controls'][0]['fields'][0]['label']);
        self::assertSame($admin['plugin-only'], $merged['plugin-only'], '内容语言里没有的类型保持原样');

        // 控件顺序或键对不上时不串位
        $shifted = $content;
        $shifted['button']['controls'] = array_reverse($shifted['button']['controls']);
        self::assertSame('按钮', BloxEditorContentDefaults::apply($admin, $shifted)['button']['controls'][0]['default']);
    }
}
