<?php
/** Header/Footer 显示条件的实体目录、摘要与发布前重叠检测。 */

declare(strict_types=1);

final class BloxAreaConditions
{
    /** @return list<array{main:string,ids:list<int>,langs:list<string>,exclude:bool}> */
    /** @param array{channel?:array<int,mixed>,page?:array<int,mixed>}|null $entities */
    public static function parseForSave(string $json, ?array $entities = null): array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(__('blox_cond_invalid_payload'));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(__('blox_cond_invalid_payload'));
        }
        $conditions = BloxAreaResolver::parse($decoded);
        if (count($conditions) !== count($decoded)) {
            throw new RuntimeException(__('blox_cond_invalid_payload'));
        }
        foreach ($conditions as $condition) {
            if ($condition['main'] === 'page' && $condition['ids'] === []) {
                throw new RuntimeException(__('blox_cond_page_required'));
            }
        }
        $enabledLanguages = array_fill_keys(array_keys(self::languageLabels(true)), true);
        foreach ($conditions as $condition) {
            foreach ($condition['langs'] as $language) {
                if (!isset($enabledLanguages[$language])) {
                    throw new RuntimeException(__('blox_cond_unknown_language', ['lang' => $language]));
                }
            }
        }
        if ($entities !== null) {
            $lookups = self::entityLookups($entities);
            foreach ($conditions as $condition) {
                if (!in_array($condition['main'], ['channel', 'page'], true)) {
                    continue;
                }
                $unknown = array_values(array_filter(
                    $condition['ids'],
                    static fn (int $id): bool => !isset($lookups[$condition['main']][$id])
                ));
                if ($unknown !== []) {
                    throw new RuntimeException(__('blox_cond_unknown_entities', ['ids' => implode(', ', $unknown)]));
                }
            }
        }
        return $conditions;
    }

    /** @return array{channel:list<array{id:int,label:string,search:string,lang:string}>,page:list<array{id:int,label:string,search:string,lang:string}>} */
    public static function entityOptions(): array
    {
        $result = ['channel' => [], 'page' => []];
        try {
            $rows = channelModel()->getFlatList();
        } catch (Throwable) {
            return $result;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['status'])) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            if ($type === 'link') {
                continue;
            }
            $bucket = $type === 'page' ? 'page' : 'channel';
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? '')) ?: '#' . $id;
            $slug = trim((string) ($row['slug'] ?? ''));
            $lang = trim((string) ($row['lang'] ?? ''));
            $depth = max(0, min(4, (int) ($row['_level'] ?? 0)));
            $label = str_repeat('— ', $depth) . $name;
            if ($lang !== '') {
                $label .= ' [' . $lang . ']';
            }
            $result[$bucket][] = [
                'id' => $id,
                'label' => $label,
                'search' => implode(' ', array_filter([$name, $slug, $lang, (string) $id, $type])),
                'lang' => $lang,
            ];
        }
        return $result;
    }

    /** @param array{channel?:array<int,mixed>,page?:array<int,mixed>}|null $entities */
    public static function summary(mixed $raw, ?array $entities = null): string
    {
        $conditions = BloxAreaResolver::parse($raw);
        if ($conditions === []) {
            return BloxAreaResolver::hasConditionInput($raw)
                ? __('blox_cond_summary_invalid')
                : __('blox_cond_summary_default');
        }

        $lookups = self::entityLookups($entities ?? self::entityOptions());
        $parts = [];
        foreach ($conditions as $condition) {
            $label = match ($condition['main']) {
                'any' => __('blox_cond_any'),
                'home' => __('blox_cond_home'),
                'channel' => $condition['ids'] === []
                    ? __('blox_cond_all_channels')
                    : self::selectedSummary($condition['ids'], $lookups['channel'], __('blox_cond_channel')),
                'page' => $condition['ids'] === []
                    ? __('blox_cond_page_missing')
                    : self::selectedSummary($condition['ids'], $lookups['page'], __('blox_cond_page')),
                default => '',
            };
            if ($label !== '') {
                if ($condition['langs'] !== []) {
                    $available = self::languageLabels(false);
                    $languageLabels = array_map(
                        static fn (string $lang): string => $available[$lang] ?? $lang,
                        $condition['langs']
                    );
                    $label .= ' [' . implode(', ', $languageLabels) . ']';
                }
                $parts[] = $condition['exclude'] ? __('blox_cond_summary_exclude', ['scope' => $label]) : $label;
            }
        }
        return $parts !== [] ? implode(' · ', $parts) : __('blox_cond_summary_invalid');
    }

    /**
     * @param array<string,mixed> $current
     * @param list<array<string,mixed>> $published
     * @return list<array{id:int,name:string,outcome:string}>
     */
    public static function conflicts(array $current, array $published): array
    {
        $type = (string) ($current['type'] ?? '');
        if (!BloxTemplateModel::conditionalType($type)) {
            return [];
        }
        $currentConditions = BloxAreaResolver::parse($current['conditions'] ?? null);
        if ($currentConditions === [] && BloxAreaResolver::hasConditionInput($current['conditions'] ?? null)) {
            return [];
        }

        $conflicts = [];
        foreach ($published as $other) {
            if (!is_array($other) || (int) ($other['id'] ?? 0) === (int) ($current['id'] ?? 0)) {
                continue;
            }
            $otherConditions = BloxAreaResolver::parse($other['conditions'] ?? null);
            if ($otherConditions === [] && BloxAreaResolver::hasConditionInput($other['conditions'] ?? null)) {
                continue;
            }
            $outcomes = [];
            foreach (self::sampleContexts($currentConditions, $otherConditions) as $context) {
                $currentScore = BloxAreaResolver::score($currentConditions, $context);
                $otherScore = BloxAreaResolver::score($otherConditions, $context);
                if ($currentScore === null || $otherScore === null) {
                    continue;
                }
                $currentWins = $currentScore > $otherScore
                    || ($currentScore === $otherScore && (int) ($current['id'] ?? 0) > (int) ($other['id'] ?? 0));
                $outcomes[$currentWins ? 'current' : 'other'] = true;
            }
            if ($outcomes === []) {
                continue;
            }
            $conflicts[] = [
                'id' => (int) ($other['id'] ?? 0),
                'name' => (string) ($other['name'] ?? ('#' . (int) ($other['id'] ?? 0))),
                'outcome' => count($outcomes) > 1 ? 'mixed' : (string) array_key_first($outcomes),
            ];
        }
        return $conflicts;
    }

    /** @param list<array{id:int,name:string,outcome:string}> $conflicts */
    public static function conflictSummary(array $conflicts): string
    {
        if ($conflicts === []) {
            return '';
        }
        $parts = [];
        foreach ($conflicts as $conflict) {
            $name = $conflict['name'] . ' (#' . $conflict['id'] . ')';
            $parts[] = match ($conflict['outcome']) {
                'current' => __('blox_cond_conflict_current_wins', ['name' => $name]),
                'other' => __('blox_cond_conflict_other_wins', ['name' => $name]),
                default => __('blox_cond_conflict_mixed', ['name' => $name]),
            };
        }
        return implode(' · ', $parts) . ' ' . __('blox_cond_priority_rule');
    }

    /** @param array<string,mixed> $template */
    public static function publishConflictMessage(array $template): string
    {
        $type = (string) ($template['type'] ?? '');
        if (!BloxTemplateModel::conditionalType($type)) {
            return '';
        }
        $conflicts = self::conflicts($template, bloxTemplateModel()->publishedAreaTemplates($type));
        return $conflicts === []
            ? ''
            : __('blox_cond_publish_confirm') . ' ' . self::conflictSummary($conflicts);
    }

    /** @param array{channel?:array<int,mixed>,page?:array<int,mixed>} $entities @return array{channel:array<int,string>,page:array<int,string>} */
    private static function entityLookups(array $entities): array
    {
        $lookups = ['channel' => [], 'page' => []];
        foreach (['channel', 'page'] as $type) {
            foreach (is_array($entities[$type] ?? null) ? $entities[$type] : [] as $item) {
                if (is_array($item) && (int) ($item['id'] ?? 0) > 0) {
                    $lookups[$type][(int) $item['id']] = (string) ($item['label'] ?? ('#' . (int) $item['id']));
                }
            }
        }
        return $lookups;
    }

    /** @param list<int> $ids @param array<int,string> $lookup */
    private static function selectedSummary(array $ids, array $lookup, string $prefix): string
    {
        $labels = array_map(static fn (int $id): string => $lookup[$id] ?? ('#' . $id), $ids);
        $visible = array_slice($labels, 0, 3);
        $text = $prefix . ': ' . implode('、', $visible);
        if (count($labels) > 3) {
            $text .= __('blox_cond_more_count', ['count' => count($labels) - 3]);
        }
        return $text;
    }

    /**
     * @param list<array{main:string,ids:list<int>,langs:list<string>,exclude:bool}> $a
     * @param list<array{main:string,ids:list<int>,langs:list<string>,exclude:bool}> $b
     * @return list<array{home:bool,channel_id:int,page_id:int,lang:string}>
     */
    private static function sampleContexts(array $a, array $b): array
    {
        $channelIds = [];
        $pageIds = [];
        $languages = [];
        foreach (array_merge($a, $b) as $condition) {
            if ($condition['main'] === 'channel') {
                $channelIds = array_merge($channelIds, $condition['ids']);
            } elseif ($condition['main'] === 'page') {
                $pageIds = array_merge($pageIds, $condition['ids']);
            }
            $languages = array_merge($languages, $condition['langs']);
        }
        $channelIds = array_values(array_unique(array_map('intval', $channelIds)));
        $pageIds = array_values(array_unique(array_map('intval', $pageIds)));
        $languages = array_values(array_unique(array_filter(array_map('strval', $languages))));
        if ($languages === []) {
            $languages = ['zh-CN'];
        } elseif (!in_array('zh-CN', $languages, true)) {
            $languages[] = 'zh-CN';
        }
        $representative = 2_000_000_000;
        while (in_array($representative, $channelIds, true) || in_array($representative, $pageIds, true)) {
            $representative--;
        }

        $baseContexts = [
            ['home' => false, 'channel_id' => 0, 'page_id' => 0],
            ['home' => true, 'channel_id' => 0, 'page_id' => 0],
            ['home' => false, 'channel_id' => $representative, 'page_id' => 0],
            ['home' => false, 'channel_id' => $representative, 'page_id' => $representative],
        ];
        foreach ($channelIds as $id) {
            $baseContexts[] = ['home' => false, 'channel_id' => $id, 'page_id' => 0];
        }
        foreach ($pageIds as $id) {
            $baseContexts[] = ['home' => false, 'channel_id' => $id, 'page_id' => $id];
        }
        $contexts = [];
        foreach ($languages as $language) {
            foreach ($baseContexts as $context) {
                $context['lang'] = $language;
                $contexts[] = $context;
            }
        }
        return $contexts;
    }

    /** @return array<string,string> */
    private static function languageLabels(bool $enabledOnly): array
    {
        if ($enabledOnly && function_exists('enabledLanguages')) {
            return enabledLanguages();
        }
        if (function_exists('availableLanguages')) {
            return availableLanguages();
        }
        return ['zh-CN' => 'zh-CN', 'en' => 'en', 'ja' => 'ja'];
    }
}
