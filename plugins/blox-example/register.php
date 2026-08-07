<?php
/** Blox 插件最小接入示例；插件停用后 plugin.json 仍负责保留节点声明。 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit;
}

// 后台认证会先加载插件、后加载 Builder；挂到注册钩子可避免加载顺序耦合。
add_action('builder_register_element', static function (): void {
    require_once __DIR__ . '/BloxExampleNoticeElement.php';
    BloxPluginRegistry::registerElement('blox-example', new BloxExampleNoticeElement());
    BloxPluginRegistry::registerTemplateProvider(
        'blox-example',
        static function (string $context): array {
            return [[
                'key' => 'blox-example-notice',
                'name' => '插件提示区块',
                'type' => 'section',
                'context' => $context,
                'data' => [
                    'type' => 'section',
                    'settings' => ['padding' => 'sm', 'max_width' => 'default'],
                    'columns' => [[
                        'elements' => [[
                            'type' => 'blox-example/notice',
                            'data' => ['text' => '这是由插件注册的 Blox 元素'],
                        ]],
                    ]],
                ],
            ]];
        }
    );
});
