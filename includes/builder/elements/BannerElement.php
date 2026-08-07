<?php
/** 轮播图元素 —— {yk:banner group=} 的可拖拽版。自包含，无内层模板。 */

declare(strict_types=1);

final class BannerElement extends AbstractElement
{
    public function type(): string { return 'banner'; }
    public function label(): string { return '轮播图'; }
    public function icon(): string { return 'carousel-horizontal'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return false; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'group', 'type' => 'text', 'label' => '轮播分组', 'default' => '', 'placeholder' => 'banner group 标识'],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $markup = $this->buildMarkup($data);
        return $markup === '' || !class_exists('TagEngine') ? $markup : \TagEngine::render($markup);
    }

    public function buildMarkup(array $data): string
    {
        $group = trim((string) ($data['group'] ?? ''));
        if ($group === '') {
            return '';
        }
        return '{yk:banner group=' . preg_replace('/[^a-zA-Z0-9_-]/', '', $group) . ' /}';
    }
}
