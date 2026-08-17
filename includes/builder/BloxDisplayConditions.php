<?php
/** Blox 区块/元素显示条件：OR 组、组内 AND 规则、运行时布尔求值与授权边界。 */

declare(strict_types=1);

final class BloxDisplayConditions
{
    private const MAX_GROUPS = 10;
    private const MAX_RULES = 10;

    /** @return array{logged_in:bool,date:string,channel_id:int,url:string} */
    public static function currentContext(): array
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $url = parse_url($requestUri, PHP_URL_PATH);
        $url = is_string($url) && $url !== '' ? $url : '/';
        $query = parse_url($requestUri, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $url .= '?' . $query;
        }

        return [
            'logged_in' => !empty($_SESSION['member_id']),
            'date' => date('Y-m-d'),
            'channel_id' => (int) ($GLOBALS['currentChannelId'] ?? 0),
            'url' => $url,
        ];
    }

    /**
     * 空条件始终显示；有输入但结构或规则非法时 fail-closed。
     *
     * @param array{logged_in?:bool,date?:string,channel_id?:int,url?:string}|null $context
     */
    public static function matches(mixed $raw, ?array $context = null): bool
    {
        if (!self::hasInput($raw)) {
            return true;
        }
        $groups = self::parse($raw);
        if ($groups === null || $groups === []) {
            return false;
        }
        $context = self::normalizeContext($context ?? self::currentContext());
        foreach ($groups as $group) {
            $matches = true;
            foreach ($group['rules'] as $rule) {
                if (!self::ruleMatches($rule, $context)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    public static function hasInput(mixed $raw): bool
    {
        return $raw !== null && $raw !== [];
    }

    /** 画布角标显示“组数/规则数”，不泄露具体条件值。 */
    public static function badge(mixed $raw): string
    {
        $groups = self::parse($raw);
        if ($groups === null || $groups === []) {
            return self::hasInput($raw) ? '!' : '';
        }
        $rules = array_sum(array_map(static fn (array $group): int => count($group['rules']), $groups));
        return count($groups) . '/' . $rules;
    }

    /**
     * @return list<array{rules:list<array{type:string,operator:string,value:string|int}>}>|null
     */
    public static function parse(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_GROUPS) {
            return null;
        }
        $groups = [];
        foreach ($raw as $group) {
            if (!is_array($group) || !is_array($group['rules'] ?? null)
                || !array_is_list($group['rules']) || $group['rules'] === []
                || count($group['rules']) > self::MAX_RULES) {
                return null;
            }
            $rules = [];
            foreach ($group['rules'] as $rule) {
                $normalized = self::parseRule($rule);
                if ($normalized === null) {
                    return null;
                }
                $rules[] = $normalized;
            }
            $groups[] = ['rules' => $rules];
        }
        return $groups;
    }

    /** @param array<int,mixed> $sections */
    public static function assertSectionsAllowed(array $sections, ?bool $advanced = null): void
    {
        $hasConditions = false;
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            self::assertRaw($section['settings']['_conditions'] ?? null, $hasConditions);
            foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                    if (is_array($element)) {
                        self::assertElement($element, $hasConditions);
                    }
                }
            }
        }
        if ($hasConditions && !($advanced ?? BloxQueryLoopPolicy::advancedEnabled())) {
            throw new RuntimeException(__('blox_display_conditions_license_required'));
        }
    }

    public static function assertJsonAllowed(string $json, ?bool $advanced = null): void
    {
        $document = BloxDocumentPipeline::decode($json);
        self::assertSectionsAllowed($document['sections'], $advanced);
    }

    /** @param array<string,mixed> $element */
    private static function assertElement(array $element, bool &$hasConditions): void
    {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        self::assertRaw($data['_conditions'] ?? null, $hasConditions);
        foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
            if (is_array($child)) {
                self::assertElement($child, $hasConditions);
            }
        }
    }

    private static function assertRaw(mixed $raw, bool &$hasConditions): void
    {
        if ($raw === null || $raw === []) {
            return;
        }
        $hasConditions = true;
        if (self::parse($raw) === null) {
            throw new RuntimeException(__('blox_display_conditions_invalid'));
        }
    }

    /** @return array{type:string,operator:string,value:string|int}|null */
    private static function parseRule(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $type = trim((string) ($raw['type'] ?? ''));
        $operator = trim((string) ($raw['operator'] ?? ''));
        $value = $raw['value'] ?? '';

        if ($type === 'login' && $operator === 'is' && in_array($value, ['logged_in', 'logged_out'], true)) {
            return ['type' => $type, 'operator' => $operator, 'value' => (string) $value];
        }
        if ($type === 'date' && in_array($operator, ['before', 'on', 'after'], true)) {
            $value = trim((string) $value);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                return ['type' => $type, 'operator' => $operator, 'value' => $value];
            }
            return null;
        }
        if ($type === 'channel' && in_array($operator, ['is', 'is_not'], true)) {
            $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $value === false ? null : ['type' => $type, 'operator' => $operator, 'value' => (int) $value];
        }
        if ($type === 'url' && in_array($operator, ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with'], true)) {
            $value = trim((string) $value);
            if ($value !== '' && strlen($value) <= 500) {
                return ['type' => $type, 'operator' => $operator, 'value' => $value];
            }
        }
        return null;
    }

    /**
     * @param array{type:string,operator:string,value:string|int} $rule
     * @param array{logged_in:bool,date:string,channel_id:int,url:string} $context
     */
    private static function ruleMatches(array $rule, array $context): bool
    {
        $value = $rule['value'];
        return match ($rule['type']) {
            'login' => $context['logged_in'] === ($value === 'logged_in'),
            'date' => match ($rule['operator']) {
                'before' => $context['date'] < $value,
                'on' => $context['date'] === $value,
                'after' => $context['date'] > $value,
            },
            'channel' => $rule['operator'] === 'is'
                ? $context['channel_id'] === $value
                : $context['channel_id'] !== $value,
            'url' => match ($rule['operator']) {
                'equals' => $context['url'] === $value,
                'not_equals' => $context['url'] !== $value,
                'contains' => str_contains($context['url'], (string) $value),
                'not_contains' => !str_contains($context['url'], (string) $value),
                'starts_with' => str_starts_with($context['url'], (string) $value),
            },
        };
    }

    /**
     * @param array{logged_in?:bool,date?:string,channel_id?:int,url?:string} $context
     * @return array{logged_in:bool,date:string,channel_id:int,url:string}
     */
    private static function normalizeContext(array $context): array
    {
        $date = (string) ($context['date'] ?? date('Y-m-d'));
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            $date = date('Y-m-d');
        }
        return [
            'logged_in' => !empty($context['logged_in']),
            'date' => $date,
            'channel_id' => max(0, (int) ($context['channel_id'] ?? 0)),
            'url' => (string) ($context['url'] ?? '/'),
        ];
    }
}
