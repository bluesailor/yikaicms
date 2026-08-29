<?php
/** Header/Footer 的栏目与单页专用分配生命周期。 */

declare(strict_types=1);

final class BloxAreaAssignmentManager
{
    /**
     * @param array{channel?:list<array<string,mixed>>,page?:list<array<string,mixed>>} $entities
     * @return array{key:string,label:string,context:array{home:bool,channel_id:int,page_id:int,lang:string}}
     */
    public static function contextFromKey(string $key, array $entities, string $defaultLanguage): array
    {
        if (preg_match('/^(channel|page):(\d+)$/', trim($key), $matches) !== 1) {
            throw new RuntimeException(__('blox_assignment_target_invalid'));
        }
        $scope = $matches[1];
        $id = (int) $matches[2];
        foreach (is_array($entities[$scope] ?? null) ? $entities[$scope] : [] as $entity) {
            if (!is_array($entity) || (int) ($entity['id'] ?? 0) !== $id) {
                continue;
            }
            $language = trim((string) ($entity['lang'] ?? '')) ?: $defaultLanguage;
            return [
                'key' => $scope . ':' . $id,
                'label' => trim((string) ($entity['label'] ?? '')) ?: ('#' . $id),
                'context' => [
                    'home' => false,
                    'channel_id' => $scope === 'channel' ? $id : 0,
                    'page_id' => $scope === 'page' ? $id : 0,
                    'lang' => $language,
                ],
            ];
        }
        throw new RuntimeException(__('blox_assignment_target_invalid'));
    }

    /** @param array<string,mixed> $context @return list<array{main:string,ids:list<int>,langs:list<string>,exclude:bool}> */
    public static function conditionsFor(array $context): array
    {
        $pageId = max(0, (int) ($context['page_id'] ?? 0));
        $channelId = max(0, (int) ($context['channel_id'] ?? 0));
        $language = trim((string) ($context['lang'] ?? ''));
        if ($pageId > 0) {
            $main = 'page';
            $id = $pageId;
        } elseif ($channelId > 0) {
            $main = 'channel';
            $id = $channelId;
        } else {
            throw new RuntimeException(__('blox_assignment_target_invalid'));
        }
        $languageAvailable = function_exists('availableLanguages')
            ? isset(availableLanguages()[$language])
            : preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) === 1;
        if (!$languageAvailable) {
            throw new RuntimeException(__('blox_assignment_target_invalid'));
        }
        return [[
            'main' => $main,
            'ids' => [$id],
            'langs' => [$language],
            'exclude' => false,
        ]];
    }

    /** @param array<string,mixed> $template @param array<string,mixed> $context */
    public static function isDedicatedTemplate(array $template, string $area, array $context): bool
    {
        if ((string) ($template['type'] ?? $area) !== $area || !in_array($area, ['header', 'footer'], true)) {
            return false;
        }
        try {
            return BloxAreaResolver::parse($template['conditions'] ?? null) === self::conditionsFor($context);
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @param list<array<string,mixed>> $templates
     * @param array<string,mixed> $context
     * @return list<array<string,mixed>>
     */
    public static function dedicatedTemplates(array $templates, string $area, array $context): array
    {
        return array_values(array_filter(
            $templates,
            static fn (array $template): bool => self::isDedicatedTemplate($template, $area, $context)
        ));
    }

    /**
     * @param array<string,mixed> $context
     * @return array{id:int,reused:bool}
     */
    public static function createDedicatedDraft(
        int $sourceId,
        string $area,
        array $context,
        string $targetLabel,
        int $adminId
    ): array {
        self::assertArea($area);
        $conditions = self::conditionsFor($context);
        foreach (bloxTemplateModel()->catalog($area) as $template) {
            if (self::isDedicatedTemplate($template, $area, $context)) {
                return ['id' => (int) $template['id'], 'reused' => true];
            }
        }

        $source = bloxTemplateModel()->findForExport($sourceId);
        if (!$source || (string) ($source['type'] ?? '') !== $area
            || (int) ($source['status'] ?? 0) !== 1
            || trim((string) ($source['published_data'] ?? '')) === '') {
            throw new RuntimeException(__('blox_assignment_source_missing'));
        }
        $document = BloxAreaDocument::decode($area, (string) $source['published_data']);
        $draftJson = json_encode([
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => $document['settings'],
            'sections' => $document['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $name = mb_substr(trim((string) $source['name']) . ' · ' . trim($targetLabel), 0, 150);

        db()->beginTransaction();
        try {
            $id = bloxTemplateModel()->createDraft(
                $area,
                $name,
                $draftJson,
                'user',
                BloxDocumentPipeline::SCHEMA_VERSION,
                self::decodeArray($source['requirements'] ?? null),
                (string) ($source['thumbnail'] ?? ''),
                max(0, $adminId),
                '',
                self::decodeArray($source['metadata'] ?? null)
            );
            bloxTemplateModel()->saveConditions($id, $conditions);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }
        return ['id' => $id, 'reused' => false];
    }

    /** @param array<string,mixed> $context @return list<int> */
    public static function restoreInheritance(string $area, array $context): array
    {
        self::assertArea($area);
        $ids = [];
        foreach (bloxTemplateModel()->catalog($area) as $template) {
            if ((int) ($template['status'] ?? 0) === 1
                && self::isDedicatedTemplate($template, $area, $context)) {
                $ids[] = (int) $template['id'];
            }
        }
        if ($ids === []) {
            throw new RuntimeException(__('blox_assignment_restore_missing'));
        }
        db()->beginTransaction();
        try {
            foreach ($ids as $id) {
                bloxTemplateModel()->unpublish($id);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }
        return $ids;
    }

    private static function assertArea(string $area): void
    {
        if (!in_array($area, ['header', 'footer'], true)) {
            throw new RuntimeException(__('blox_invalid_action'));
        }
    }

    /** @return array<string,mixed> */
    private static function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }
}
