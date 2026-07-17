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

    /**
     * 设置项 schema（后台构建器据此自动生成设置表单）。返回控件定义数组，每项：
     *   ['key'=>字段名, 'type'=>控件类型, 'label'=>标签, 'default'=>默认值, ...]
     * 控件类型：text/textarea/number/select/checkbox/color（通用，自动生成表单）；
     *           richtext/image/icon（富控件，由构建器专用编辑器接管，见 hasCustomUI）。
     * select 需 'options'=>['值'=>'显示', ...]；number 可 'min'/'max'；text 可 'placeholder'。
     * 无控件的元素返回 []。
     */
    public function controls(): array
    {
        return [];
    }

    /** 由 controls() 推导默认 data（后台新增元素用） */
    public function defaults(): array
    {
        $d = [];
        foreach ($this->controls() as $c) {
            if (isset($c['key'])) {
                $d[$c['key']] = $c['default'] ?? '';
            }
        }
        return $d;
    }

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
