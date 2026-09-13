<?php
/**
 * 详情模板「候选 + 内容上下文」准备层（模型层唯一取数处）。
 *
 * DetailTemplateResolver 是纯判定，不查库；本类负责一次性备好：
 * - 候选模板（读取已发布模板，取出并归一化其条件作用域；v1 的 product_template 经
 *   legacyScope() 只读适配，进入同一判定路径，避免两套排序分叉）
 * - 内容上下文（把「内容所属分类/栏目 + 祖先链 + 距离」算好，使 matcher 不必遍历分类树）
 *
 * 分类链遍历带访问去重、深度与规模上限，循环数据不会死循环。
 */

declare(strict_types=1);

final class DetailTemplateProvider
{
    public const MAX_CATEGORY_DEPTH = 12;
    public const MAX_CATEGORY_NODES = 50;

    /**
     * 候选模板列表（含已归一化 scope 与 published_data，供渲染直接使用）。
     *
     * @return list<array<string,mixed>>
     */
    public static function candidates(string $contentType): array
    {
        $templateType = DetailTemplateResolver::templateTypeFor($contentType);
        if ($templateType === '') {
            return [];
        }

        $rows = [];
        foreach (bloxTemplateModel()->publishedDetailTemplates($templateType) as $row) {
            try {
                $document = BloxDocumentPipeline::decode((string) ($row['published_data'] ?? ''));
            } catch (Throwable) {
                continue;   // 文档损坏的模板不参与判定（渲染阶段本就有安全回退）
            }
            $settings = is_array($document['settings'] ?? null) ? $document['settings'] : [];

            $scope = null;
            if (array_key_exists('detail_template', $settings)) {
                $scope = DetailTemplateResolver::normalizeScope($settings['detail_template']);
            } elseif ($contentType === 'product' && array_key_exists('product_template', $settings)) {
                // 旧规则只读适配：不写回、不发布，仅参与同一判定
                $legacy = is_array($settings['product_template']) ? $settings['product_template'] : [];
                $scope = DetailTemplateResolver::legacyScope($legacy);
            }
            if ($scope === null) {
                continue;   // 没有条件声明的模板不参与自动匹配（不等于 all）
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'status' => (int) $row['status'],
                'lang' => (string) $scope['lang'],
                'source' => (string) $scope['source'],
                'priority' => (int) $scope['priority'],
                'scope' => $scope,
                'published_data' => (string) ($row['published_data'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * 内容上下文。content_type 取 contents.type（product/article）；
     * categories 带「与内容的距离」（0=直属），供判定具体度。
     *
     * @param array<string,mixed> $content 产品行或内容行
     * @return array<string,mixed>
     */
    public static function context(string $contentType, array $content): array
    {
        $lang = is_string($content['lang'] ?? null) && (string) $content['lang'] !== ''
            ? (string) $content['lang']
            : siteLang();

        if ($contentType === 'product') {
            $categoryId = (int) ($content['category_id'] ?? 0);
            return [
                'content_type' => 'product',
                'content_id' => (int) ($content['id'] ?? 0),
                'lang' => $lang,
                'channel_type' => 'product',
                'categories' => self::ancestorChain($categoryId, true),
                'ancestors' => array_column(self::ancestorChain($categoryId, true), 'id'),
            ];
        }

        $channelId = (int) ($content['channel_id'] ?? 0);
        $chain = self::ancestorChain($channelId, false);
        return [
            'content_type' => $contentType,
            'content_id' => (int) ($content['id'] ?? 0),
            'lang' => $lang,
            'channel_type' => (string) ($content['channel_type'] ?? ''),
            'categories' => $chain,
            'ancestors' => array_column($chain, 'id'),
        ];
    }

    /**
     * 便捷入口：渲染 / 后台预览 / 「为什么使用它」共用同一结果。
     *
     * @param array<string,mixed> $content
     * @param array<string,mixed>|null $binding 内容级手动绑定
     * @return array<string,mixed> 见 DetailTemplateResolver::resolve()
     */
    public static function resolveFor(string $contentType, array $content, ?array $binding = null): array
    {
        return DetailTemplateResolver::resolve(
            self::candidates($contentType),
            self::context($contentType, $content),
            $binding
        );
    }

    /**
     * 分类/栏目祖先链（含自身，distance 从 0 起）。
     * 带访问去重（防循环）、深度上限与规模上限；查不到的行直接停止。
     *
     * @return list<array{id:int,distance:int}>
     */
    private static function ancestorChain(int $id, bool $isProduct): array
    {
        if ($id <= 0) {
            return [];   // 未分类：不伪造分类条件
        }

        $chain = [];
        $seen = [];
        $cursor = $id;
        $distance = 0;

        while ($cursor > 0 && $distance < self::MAX_CATEGORY_DEPTH && count($chain) < self::MAX_CATEGORY_NODES) {
            if (isset($seen[$cursor])) {
                break;   // 循环数据保护
            }
            $seen[$cursor] = true;

            $row = $isProduct ? getProductCategory($cursor) : getChannel($cursor);
            if (!is_array($row)) {
                if ($distance === 0) {
                    return [];   // 直属分类不可用时不把条件建立在猜测上
                }
                break;
            }

            $chain[] = ['id' => $cursor, 'distance' => $distance];
            $cursor = (int) ($row['parent_id'] ?? 0);
            $distance++;
        }

        return $chain;
    }
}
