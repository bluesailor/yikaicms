<?php
/**
 * Yikai CMS — list.php controller for `type=job` channels.
 *
 * Carved off from the inline `elseif ($channel['type']==='job')` branch
 * in list.php. JobModel.getList already returns ['items', 'total'].
 */

declare(strict_types=1);

require_once __DIR__ . '/ListController.php';

final class JobController extends ListController
{
    public function prepare(array $channel, array $request): array
    {
        $channelId = (int) ($channel['id'] ?? $request['channelId']);
        $page      = (int) $request['page'];
        $perPage   = (int) $request['perPage'];
        $offset    = ($page - 1) * $perPage;
        $keyword   = (string) $request['keyword'];

        $filters = ['status' => '1'];
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
        }

        $result = jobModel()->getList($filters, $perPage, $offset);

        // Same sidebar pattern as the download controller: parent channel's
        // siblings sit on the right.
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
            'jobs'                 => $result['items'],
            'total'                => (int) $result['total'],
            'contents'             => [],
            'parentChannel'        => $parentChannel,
            'rightSidebarTitle'    => $rightSidebarTitle,
            'rightSidebarChannels' => $rightSidebarChannels,
            'subChannels'          => getChannels($channelId, false),
        ];
    }
}
