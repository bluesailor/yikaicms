<?php
/** 示例插件元素：展示命名空间、Schema、本地 CSS/JS 与无需修改核心的注册方式。 */

declare(strict_types=1);

final class BloxExampleNoticeElement extends AbstractElement
{
    public function type(): string { return 'blox-example/notice'; }
    public function label(): string { return '插件提示条'; }
    public function icon(): string { return 'plug'; }
    public function category(): string { return 'basic'; }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'text', 'label' => '提示文字', 'default' => '这是由插件注册的 Blox 元素'],
            ['key' => 'tone', 'type' => 'select', 'label' => '色调', 'default' => 'info',
                'options' => ['info' => '信息', 'success' => '成功']],
        ];
    }

    public function scripts(): array
    {
        return ['/plugins/blox-example/assets/notice.js'];
    }

    public function styles(): array
    {
        return ['/plugins/blox-example/assets/notice.css'];
    }

    public function render(array $data, string $children = ''): string
    {
        $tone = (string) ($data['tone'] ?? 'info') === 'success' ? 'success' : 'info';
        $text = htmlspecialchars((string) ($data['text'] ?? ''), ENT_QUOTES);
        return '<div class="blox-example-notice blox-example-notice--' . $tone . '">' . $text . '</div>';
    }
}
