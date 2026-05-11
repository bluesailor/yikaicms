<?php
/**
 * Yikai CMS — list.php controller for `type=download` channels.
 *
 * Pulled verbatim from the inline branch in list.php so behavior is
 * unchanged. Adds:
 *   - returns view variables instead of leaking globals
 *   - resolves DOWNLOAD_CATEGORIES + parent-channel sidebar in one place
 */

declare(strict_types=1);

require_once __DIR__ . '/ListController.php';

final class DownloadController extends ListController
{
    public function prepare(array $channel, array $request): array
    {
        $channelId = (int) ($channel['id'] ?? $request['channelId']);
        $page      = (int) $request['page'];
        $perPage   = (int) $request['perPage'];
        $offset    = ($page - 1) * $perPage;
        $keyword   = (string) $request['keyword'];
        $dlCatId   = (int) ($request['cat'] !== '' ? $request['cat'] : 0);

        $filters = ['status' => '1'];
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
        }

        $result = downloadModel()->getList($dlCatId, $filters, $perPage, $offset);

        // Right-side sidebar mirrors the parent channel's siblings, so the
        // user can hop between download sub-channels.
        $parentChannel        = null;
        $rightSidebarTitle    = '';
        $rightSidebarChannels = [];
        if ((int) ($channel['parent_id'] ?? 0) > 0) {
            $parentChannel = getChannel((int) $channel['parent_id']);
            if ($parentChannel) {
                $rightSidebarTitle    = (string) $parentChannel['name'];
                $rightSidebarChannels = getChannels((int) $parentChannel['id'], false);
            }
        }

        return [
            'channel'              => $channel,
            'channelId'            => $channelId,
            'page'                 => $page,
            'perPage'              => $perPage,
            'keyword'              => $keyword,
            'dlCatId'              => $dlCatId,
            'dlCategories'         => downloadCategoryModel()->getActive(),
            'downloads'            => $result['items'],
            'total'                => (int) $result['total'],
            'contents'             => [],
            'parentChannel'        => $parentChannel,
            'rightSidebarTitle'    => $rightSidebarTitle,
            'rightSidebarChannels' => $rightSidebarChannels,
            'subChannels'          => getChannels($channelId, false),
        ];
    }
}
