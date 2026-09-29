<?php
/**
 * 查询循环片段响应（2.0.3）：AJAX 分页、加载更多、无限滚动与前台筛选的取数通道。
 *
 * 不另设接口：前台脚本请求**同一页面 URL** 并附 `_ykq=<循环宿主节点 id>`，页面照常
 * 渲染（栏目/详情/语言/显示条件等上下文与首屏完全一致），渲染到该循环时只把它的
 * 片段（循环项 + 分页标记）以 JSON 返回并结束请求。因此不会暴露首屏之外的任何数据，
 * 也不需要为每种文档类型（页面/首页/栏目/详情模板/页头页尾）各写一套定位逻辑。
 *
 * `_ykq` 不在整页缓存的参数白名单内（HtmlCache::isCacheable），片段请求永远实时渲染、
 * 永不落盘；分页链接生成时剔除 `_ykq`（TagEngine::pageUrl），不会泄漏进页面。
 */

declare(strict_types=1);

final class BloxQueryFragment
{
    public const PARAM = '_ykq';

    /** @var null|callable(array<string,mixed>):void 测试注入：拦截输出与 exit */
    private static $sink = null;

    public static function requestedNode(): string
    {
        $raw = $_GET[self::PARAM] ?? '';
        return is_string($raw) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $raw) ? $raw : '';
    }

    public static function isRequestedFor(string $nodeId): bool
    {
        return $nodeId !== '' && self::requestedNode() === $nodeId;
    }

    /**
     * 输出片段 JSON 并结束请求。丢弃此前页面已缓冲的全部输出（页头等）。
     *
     * @param array<string,mixed> $result BloxLoopQuery::run() 的结果（取 total/page/pages/param）
     */
    public static function deliver(string $html, array $result): void
    {
        $payload = [
            'html' => $html,
            'total' => (int) $result['total'],
            'page' => (int) $result['page'],
            'pages' => (int) $result['pages'],
            'param' => (string) $result['param'],
            'assets' => [
                'scripts' => BloxAssetCollector::scripts(),
                'styles' => BloxAssetCollector::styles(),
            ],
        ];
        if (self::$sink !== null) {
            (self::$sink)($payload);
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * @param null|callable(array<string,mixed>):void $sink
     * @psalm-suppress PossiblyUnusedMethod 测试专用
     */
    public static function setSinkForTests(?callable $sink): void
    {
        self::$sink = $sink;
    }
}
