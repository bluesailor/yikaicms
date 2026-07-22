<?php
/**
 * Yikai CMS — list.php controller for `type=list / article / case` channels.
 *
 * Handles the legacy "else" branch in list.php — the catch-all for
 * channels that store rows in the unified `contents` table.
 */

declare(strict_types=1);

require_once __DIR__ . '/ListController.php';

final class ContentController extends ListController
{
    public function prepare(array $channel, array $request): array
    {
        $channelId = (int) ($channel['id'] ?? $request['channelId']);
        $page      = (int) $request['page'];
        $perPage   = (int) $request['perPage'];
        $offset    = ($page - 1) * $perPage;
        $keyword   = (string) $request['keyword'];

        // 父栏目聚合所有子分类的内容：例如"全部案例"页应含各子分类(如 icafeshop)下的案例，
        // "全部新闻"含各新闻子栏目的文章。否则只查直接挂在本栏目下的内容，父栏目页会空。
        $filters = ['include_children' => true];
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
        }

        $total    = contentModel()->getCount($channelId, $filters);
        $contents = contentModel()->getList($channelId, $perPage, $offset, $filters);

        return [
            'channel'     => $channel,
            'channelId'   => $channelId,
            'page'        => $page,
            'perPage'     => $perPage,
            'keyword'     => $keyword,
            'contents'    => $contents,
            'total'       => $total,
            // Sidebars/sub-channels are still computed downstream in
            // list.php for now; once views/list/* lands the controller
            // can take responsibility for them.
            'subChannels' => [],
        ];
    }
}
