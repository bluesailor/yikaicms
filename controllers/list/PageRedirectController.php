<?php
/**
 * Yikai CMS — list.php controller for `type=page` and `type=link`.
 *
 * Both behaviors short-circuit before normal rendering:
 *   - page → include /page.php (single-page channels reuse that template)
 *   - link → 302 to channel.link_url
 *
 * shortCircuit() returns true after taking action so the dispatcher
 * skips view rendering. prepare() is a no-op for these channels.
 */

declare(strict_types=1);

require_once __DIR__ . '/ListController.php';

final class PageRedirectController extends ListController
{
    public function prepare(array $channel, array $request): array
    {
        // No view rendering — vars are unused.
        return [];
    }

    public function shortCircuit(array $channel): bool
    {
        $type = (string) ($channel['type'] ?? '');

        if ($type === 'link' && !empty($channel['link_url'])) {
            header('Location: ' . $channel['link_url']);
            return true;
        }

        if ($type === 'page' && !defined('LOADING_FROM_LIST')) {
            // Match the legacy include-and-exit path so existing page.php
            // assumptions about $_GET['id'] / $_GET['slug'] still hold.
            if (!defined('LOADING_FROM_PAGE')) {
                define('LOADING_FROM_PAGE', true);
            }
            $_GET['id']   = (int) ($channel['id'] ?? 0);
            $_GET['slug'] = (string) ($channel['slug'] ?? '');
            // 用 ROOT_PATH 而非 dirname(__DIR__, N)：写死的目录层级一旦文件挪位就静默指错
            // （试搬到 includes/controllers/ 时它立刻指向了不存在的 includes/page.php）。
            include ROOT_PATH . '/page.php';
            return true;
        }

        return false;
    }
}
