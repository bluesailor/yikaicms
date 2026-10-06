<?php
/**
 * 组件实例（v2.1）。data.component = 母版 uuid，data.props = 覆盖过的属性；渲染与规则见 BloxComponents。
 * 不进元素库：从组件库面板插入。属性由组件面板编辑，不走通用控件表单。
 */

declare(strict_types=1);

final class ComponentElement extends AbstractElement
{
    public function type(): string { return BloxComponents::TYPE; }
    public function label(): string { return __('blox_component'); }
    public function icon(): string { return 'components'; }
    public function category(): string { return 'layout'; }
    public function supportsBoxStyles(): bool { return false; }

    public function paletteVisible(string $context = 'page'): bool
    {
        return false;
    }

    public function defaults(): array
    {
        return ['component' => '', 'props' => []];
    }

    public function render(array $data, string $children = ''): string
    {
        return BloxComponents::render($data);
    }

    public function renderWithContext(array $data, string $children = '', array $context = []): string
    {
        return BloxComponents::render($data, $context);
    }
}
