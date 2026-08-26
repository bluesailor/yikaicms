<?php
/** 登录管理员前台所需的 Blox 草稿状态与只读预览协议。 */

declare(strict_types=1);

final class BloxPublicationStatus
{
    private const PREVIEW_PARAM = 'blox_draft';

    /**
     * @param list<string> $editorUrls
     * @return list<array{kind:string,editor_url:string,preview_url:string}>
     */
    public static function query(array $editorUrls, string $frontendUrl): array
    {
        $items = [];
        $seen = [];
        foreach ($editorUrls as $editorUrl) {
            $descriptor = self::editorDescriptor($editorUrl);
            if ($descriptor === null || isset($seen[$descriptor['key']])) {
                continue;
            }
            $seen[$descriptor['key']] = true;
            if (!self::hasUnpublishedChanges($descriptor)) {
                continue;
            }
            $items[] = [
                'kind' => $descriptor['kind'],
                'editor_url' => $editorUrl,
                'preview_url' => self::previewUrl($frontendUrl, $descriptor['key']),
            ];
        }
        return $items;
    }

    /** @return array{kind:string,key:string,id:int}|null */
    public static function requestedPreview(): ?array
    {
        if (empty($_SESSION['admin_id']) || ($_GET['preview'] ?? null) !== 'draft') {
            return null;
        }
        $raw = $_GET[self::PREVIEW_PARAM] ?? '';
        if (!is_string($raw)) {
            return null;
        }
        if ($raw === 'home') {
            return ['kind' => 'home', 'key' => 'home', 'id' => 0];
        }
        if (preg_match('/^(page|template):([1-9][0-9]*)$/', $raw, $matches) !== 1) {
            return null;
        }
        $id = (int) $matches[2];
        return $id > 0
            ? ['kind' => $matches[1], 'key' => $matches[1] . ':' . $id, 'id' => $id]
            : null;
    }

    /** @return array{kind:string,key:string,id:int}|null */
    public static function activePreview(): ?array
    {
        $target = self::requestedPreview();
        return $target !== null && self::hasUnpublishedChanges($target) ? $target : null;
    }

    /** @return array<string,mixed>|null */
    public static function homeDraftPreview(): ?array
    {
        $target = self::activePreview();
        if ($target === null || $target['kind'] !== 'home' || !self::homeHasUnpublishedChanges()) {
            return null;
        }
        return HomeBloxDocument::load();
    }

    public static function pageDraftPreview(int $channelId): ?string
    {
        $target = self::activePreview();
        if ($channelId < 1 || $target === null || $target['kind'] !== 'page' || $target['id'] !== $channelId) {
            return null;
        }
        try {
            $channel = channelModel()->find($channelId);
            $state = (string) ($channel['type'] ?? '') === 'list'
                ? ChannelBloxDocument::load($channelId)
                : PageBloxDocument::load($channelId);
            return $state['has_unpublished_changes'] ? $state['document_json'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public static function areaDraftPreview(string $area): ?array
    {
        if (!in_array($area, ['header', 'footer'], true)) {
            return null;
        }
        $target = self::activePreview();
        if ($target === null || $target['kind'] !== 'template') {
            return null;
        }
        try {
            $row = bloxTemplateModel()->findForExport($target['id']);
            return is_array($row)
                && (string) ($row['type'] ?? '') === $area
                && self::templateHasUnpublishedChanges($row)
                    ? $row
                    : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function exitPreviewUrl(string $frontendUrl): string
    {
        return self::frontendUrl($frontendUrl, false, '');
    }

    /** @return array{kind:string,key:string,id:int}|null */
    private static function editorDescriptor(string $editorUrl): ?array
    {
        $parts = parse_url($editorUrl);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])
            || (string) ($parts['path'] ?? '') !== '/admin/blox_editor.php') {
            return null;
        }
        $params = [];
        parse_str((string) ($parts['query'] ?? ''), $params);
        if (($params['home'] ?? null) === '1') {
            return ['kind' => 'home', 'key' => 'home', 'id' => 0];
        }
        $pageId = self::positiveInt($params['id'] ?? null);
        if ($pageId > 0) {
            return ['kind' => 'page', 'key' => 'page:' . $pageId, 'id' => $pageId];
        }
        $templateId = self::positiveInt($params['template'] ?? null);
        if ($templateId < 1) {
            return null;
        }
        try {
            $row = bloxTemplateModel()->findForExport($templateId);
        } catch (Throwable) {
            return null;
        }
        $type = (string) ($row['type'] ?? '');
        return is_array($row) && in_array($type, ['header', 'footer'], true)
            ? ['kind' => $type, 'key' => 'template:' . $templateId, 'id' => $templateId]
            : null;
    }

    /** @param array{kind:string,key:string,id:int} $descriptor */
    private static function hasUnpublishedChanges(array $descriptor): bool
    {
        try {
            if ($descriptor['kind'] === 'home') {
                return self::homeHasUnpublishedChanges();
            }
            if ($descriptor['kind'] === 'page') {
                $channel = channelModel()->find($descriptor['id']);
                $state = (string) ($channel['type'] ?? '') === 'list'
                    ? ChannelBloxDocument::load($descriptor['id'])
                    : PageBloxDocument::load($descriptor['id']);
                return $state['has_unpublished_changes'];
            }
            $row = bloxTemplateModel()->findForExport($descriptor['id']);
            return is_array($row) && self::templateHasUnpublishedChanges($row);
        } catch (Throwable) {
            return false;
        }
    }

    private static function homeHasUnpublishedChanges(): bool
    {
        if (!HomeBloxDocument::hasDraft()) {
            return false;
        }
        $draft = HomeBloxDocument::load();
        // 自动生成的经典首页导入稿不是用户工作，不应让所有升级站点永久出现黄灯。
        if ((string) ($draft['source'] ?? '') !== 'blox') {
            return false;
        }
        if (!HomeBloxDocument::hasPublished()) {
            return true;
        }
        return !hash_equals(
            self::documentFingerprint(HomeBloxDocument::loadPublished()),
            self::documentFingerprint($draft)
        );
    }

    /** @param array<string,mixed> $row */
    private static function templateHasUnpublishedChanges(array $row): bool
    {
        $draft = trim((string) ($row['draft_data'] ?? ''));
        if ($draft === '') {
            return false;
        }
        $published = trim((string) ($row['published_data'] ?? ''));
        if ($published === '') {
            return true;
        }
        try {
            return !hash_equals(
                BloxDocumentPipeline::fingerprint($published),
                BloxDocumentPipeline::fingerprint($draft)
            );
        } catch (Throwable) {
            return !hash_equals(hash('sha256', $published), hash('sha256', $draft));
        }
    }

    /** @param array<string,mixed> $document */
    private static function documentFingerprint(array $document): string
    {
        $json = json_encode([
            'schema' => (int) ($document['schema'] ?? BloxDocumentPipeline::SCHEMA_VERSION),
            'settings' => is_array($document['settings'] ?? null) ? $document['settings'] : [],
            'sections' => is_array($document['sections'] ?? null) ? $document['sections'] : [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return BloxDocumentPipeline::fingerprint($json);
    }

    private static function previewUrl(string $frontendUrl, string $key): string
    {
        return self::frontendUrl($frontendUrl, true, $key);
    }

    private static function frontendUrl(string $frontendUrl, bool $preview, string $key): string
    {
        $target = BloxAreaEditorTarget::frontendSourceReturnTo($frontendUrl);
        $parts = parse_url($target !== '' ? $target : '/');
        $params = [];
        if (is_array($parts)) {
            parse_str((string) ($parts['query'] ?? ''), $params);
        }
        unset($params['preview'], $params[self::PREVIEW_PARAM]);
        if ($preview) {
            $params['preview'] = 'draft';
            $params[self::PREVIEW_PARAM] = $key;
        }
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
        if ($path === '' || !str_starts_with($path, '/')) {
            $path = '/';
        }
        return $path
            . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986))
            . (is_array($parts) && isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    private static function positiveInt(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^[1-9][0-9]*$/', (string) $value) === 1
            ? (int) $value
            : 0;
    }
}
