<?php
/**
 * YikaiCMS 页面构建器 —— 元素抽象基类。
 *
 * 一个元素 = 一个类，声明元数据 + render()。取代原 renderBlocksToHtml 里写死的 switch，
 * 使元素可扩展、插件可注册（见 BuilderRegistry）。设计见 yikaicms-docs/design-page-builder.md。
 *
 * 迁移期红线：内置元素的 render() 输出必须与旧 renderBlocksToHtml 逐字节一致（黄金对拍锁定）。
 */

declare(strict_types=1);

abstract class AbstractElement
{
    /** 元素类型标识（对应 blocks_data 里 element.type） */
    abstract public function type(): string;

    /** 渲染前台 HTML。$data = element['data']；$children = 子元素渲染结果（容器类用） */
    abstract public function render(array $data, string $children = ''): string;

    /** 后台显示名 */
    public function label(): string
    {
        return $this->type();
    }

    /** 分类（basic / media / dynamic / layout…），供后台 palette 分组 */
    public function category(): string
    {
        return 'basic';
    }

    /** Tabler 图标名（后台 palette 用），无则空 */
    public function icon(): string
    {
        return '';
    }

    /** 是否动态元素（渲染时拉实时数据，view-time 渲染 + 缓存策略据此区分；P1-E 用） */
    public function isDynamic(): bool
    {
        return false;
    }
}
