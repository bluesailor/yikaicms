<?php
/**
 * Yikai CMS — list.php dispatcher.
 *
 * Maps a channel.type string to a concrete ListController instance. The
 * mapping is deliberately explicit (no auto-discovery) so the contract
 * between channels and controllers is grep-able and a controller can
 * be added/removed in one place.
 */

declare(strict_types=1);

require_once __DIR__ . '/ListController.php';
require_once __DIR__ . '/ContentController.php';
require_once __DIR__ . '/DownloadController.php';
require_once __DIR__ . '/JobController.php';
require_once __DIR__ . '/PageRedirectController.php';
require_once __DIR__ . '/ProductController.php';

final class ListRouter
{
    /**
     * @return array{0: ?array, 1: array}  [resolved channel|null, request vars]
     */
    public static function resolve(): array
    {
        $channelId = getInt('id');
        $slug      = get('slug');

        $channel = null;
        if ($slug) {
            // lang-aware：当前非源语言时跳到对应翻译行
            $channel = getChannelBySlug($slug, true);
        } elseif ($channelId > 0) {
            $channel = getChannel($channelId);
        }

        $request = [
            'channelId' => $channelId,
            'slug'      => $slug,
            'page'      => max(1, getInt('page', 1)),
            'perPage'   => 12,
            'keyword'   => trim(get('keyword', '')),
            'cat'       => get('cat', ''),
            'sort'      => get('sort', ''),
        ];

        return [$channel, $request];
    }

    /**
     * Pick a controller for the given channel type. Always returns a
     * controller now — every list.php branch has a home.
     *
     * Default falls through to ContentController, matching the legacy
     * "everything else uses the contents table" behavior.
     */
    public static function dispatch(string $type): ListController
    {
        return match ($type) {
            'product'  => new ProductController(),
            'download' => new DownloadController(),
            'job'      => new JobController(),
            'page',
            'link'     => new PageRedirectController(),
            default    => new ContentController(),
        };
    }
}
