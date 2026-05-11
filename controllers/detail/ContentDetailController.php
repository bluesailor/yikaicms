<?php
/**
 * Yikai CMS — detail.php controller for the unified `contents` table.
 *
 * Loads the published row, increments views (side-effect: caller decides
 * whether to do it), looks up channel + prev/next/related and the
 * download-sidebar special case.
 *
 * Returns null when the id is missing or the row isn't published; the
 * caller (detail.php) issues the appropriate 404 / redirect.
 */

declare(strict_types=1);

require_once __DIR__ . '/DetailController.php';

final class ContentDetailController extends DetailController
{
    /**
     * @return array<string,mixed>|null
     */
    public function prepare(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $content = contentModel()->getPublished($id);
        if (!$content) {
            return null;
        }

        // Side effect: bump the view counter once per render.
        contentModel()->incrementViews($id);

        $channelId = (int) $content['channel_id'];
        $channel   = getChannel($channelId);

        $prev    = contentModel()->getPrev($channelId, $id);
        $next    = contentModel()->getNext($channelId, $id);
        $related = contentModel()->getRelated($channelId, $id);

        // Download channels reuse the sibling-channel sidebar pattern.
        $downloadSidebarCats = [];
        if ($channel && ($channel['type'] ?? '') === 'download') {
            $parentId = (int) ($channel['parent_id'] ?? 0);
            if ($parentId > 0) {
                $downloadSidebarCats = getChannels($parentId, false);
            }
        }

        return [
            'content'             => $content,
            'channel'             => $channel,
            'channelId'           => $channelId,
            'prevContent'         => $prev,
            'nextContent'         => $next,
            'relatedContents'     => $related,
            'downloadSidebarCats' => $downloadSidebarCats,
        ];
    }
}
