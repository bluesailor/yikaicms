<?php
/**
 * Yikai CMS - 导航菜单组 Model（多组菜单，WP menus 语义）
 *
 * 组 = {name, items JSON}。项两种：栏目引用（channel_id，名称/链接跟随栏目，
 * label 非空时覆盖显示名）或自定义链接（label + url）。最多三级嵌套（配 mega：
 * 子=面板列、孙=列内链接）。默认导航（栏目树 is_nav 投影）不是组——组是补充，
 * 元素不选组时走既有投影，存量零迁移。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

class NavMenuModel extends Model
{
    protected string $table = 'nav_menus';
    protected string $defaultOrder = 'sort_order ASC, id ASC';

    public const MAX_DEPTH = 3;
    public const MAX_ITEMS = 200;

    /** 后台/元素控件的组下拉：[id => name] */
    public function asMap(): array
    {
        $map = [];
        foreach ($this->all() as $row) {
            $map[(int) $row['id']] = (string) $row['name'];
        }
        return $map;
    }

    /**
     * 页脚栏投影：组的顶层项 → 扁平链接列表 [{name,url,target}]。
     * 子级忽略——嵌套结构是给 mega menu 用的，页脚一栏就是一列链接；
     * 组不存在/项全失效时返回 []，主题据此回退到自定义内容。
     *
     * 调用方在各主题的 layouts/footer.php——Psalm 不分析 themes 目录，认不出调用。
     *
     * @return array<int,array{name:string,url:string,target:string}>
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function footerLinks(int $id): array
    {
        $links = [];
        foreach ($this->treeFor($id) as $node) {
            $links[] = [
                'name'   => (string) $node['name'],
                'url'    => (string) $node['url'],
                'target' => (string) ($node['link_target'] ?? ''),
            ];
        }
        return $links;
    }

    /**
     * 渲染树：items JSON → 与 getNavChannels() 同构的节点列表。
     * 栏目引用解析活数据（改栏目名/链接全站生效）；引用失效（栏目删除/停用）
     * 的项连同其子树跳过；节点同时给 url 与 _url 键（兼容两类既有消费者）。
     *
     * @return array<int,array<string,mixed>>
     */
    public function treeFor(int $id): array
    {
        $row = $this->findWhere(['id' => $id]);
        if (!$row) {
            return [];
        }
        $items = json_decode((string) ($row['items'] ?? '[]'), true);
        if (!is_array($items)) {
            return [];
        }
        return $this->buildNodes($items, 1);
    }

    /**
     * 保存前清洗：白名单字段、深度/数量上限、类型归一。
     * fail-closed：超限/畸形项丢弃，不入库。
     *
     * @return array<int,array<string,mixed>>
     */
    public function sanitizeItems(mixed $items, int $depth = 1, int &$count = 0): array
    {
        if (!is_array($items) || $depth > self::MAX_DEPTH) {
            return [];
        }
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item) || $count >= self::MAX_ITEMS) {
                continue;
            }
            $channelId = (int) ($item['channel_id'] ?? 0);
            $label = mb_substr(trim((string) ($item['label'] ?? '')), 0, 100);
            $url = mb_substr(trim((string) ($item['url'] ?? '')), 0, 500);
            // 自定义链接禁 javascript: 等危险协议（渲染端还有 e() 转义兜底）
            if ($url !== '' && preg_match('~^(?:https?:)?//|^/~i', $url) !== 1) {
                $url = '';
            }
            if ($channelId <= 0 && ($label === '' || $url === '')) {
                continue; // 既不是栏目引用也不是完整自定义链接
            }
            $count++;
            $clean[] = [
                'channel_id' => max(0, $channelId),
                'label' => $label,
                'url' => $channelId > 0 ? '' : $url,
                'target' => ($item['target'] ?? '') === '_blank' ? '_blank' : '',
                'children' => $this->sanitizeItems($item['children'] ?? [], $depth + 1, $count),
            ];
        }
        return $clean;
    }

    /** @param array<int,mixed> $items @return array<int,array<string,mixed>> */
    private function buildNodes(array $items, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }
        $nodes = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $channelId = (int) ($item['channel_id'] ?? 0);
            $label = trim((string) ($item['label'] ?? ''));
            $target = ($item['target'] ?? '') === '_blank' ? '_blank' : '';

            if ($channelId > 0) {
                $channel = channelModel()->findWhere(['id' => $channelId, 'status' => 1]);
                if (!$channel) {
                    continue; // 引用失效：连子树一起跳过
                }
                $name = $label !== '' ? $label : (string) $channel['name'];
                $url = function_exists('channelUrl') ? channelUrl($channel) : '#';
                $node = $channel;
            } else {
                $name = $label;
                $url = (string) ($item['url'] ?? '');
                $node = [];
            }
            if ($name === '') {
                continue;
            }
            $node['name'] = $name;
            $node['url'] = $url;
            $node['_url'] = $url;
            $node['link_target'] = $target;
            $node['children'] = $this->buildNodes(is_array($item['children'] ?? null) ? $item['children'] : [], $depth + 1);
            $nodes[] = $node;
        }
        return $nodes;
    }
}
