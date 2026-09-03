<?php

declare(strict_types=1);

/**
 * 为分批覆盖生成稳定、安全的文件顺序：依赖先于调用者，升级入口和版本号最后切换。
 */
final class UpgradeEntryOrder
{
    /**
     * @param list<array<string, string>> $entries
     * @param callable(array<string, string>): string $read
     * @return list<array<string, string>>
     */
    public static function sort(array $entries, callable $read): array
    {
        $byPath = [];
        foreach ($entries as $index => $entry) {
            $rel = self::normalize((string) ($entry['rel'] ?? ''));
            if ($rel === '') {
                continue;
            }
            $entry['rel'] = $rel;
            $entry['_order'] = (string) $index;
            $byPath[$rel] = $entry;
        }

        $outgoing = array_fill_keys(array_keys($byPath), []);
        $indegree = array_fill_keys(array_keys($byPath), 0);
        foreach ($byPath as $rel => $entry) {
            if (!self::isRuntimePhp($rel)) {
                continue;
            }
            foreach (self::dependencies($rel, $read($entry)) as $dependency) {
                if ($dependency === $rel || !isset($byPath[$dependency])) {
                    continue;
                }
                $outgoing[$dependency][] = $rel;
                $indegree[$rel]++;
            }
        }

        $ready = [];
        foreach ($indegree as $rel => $degree) {
            if ($degree === 0) {
                $ready[] = $rel;
            }
        }

        $ordered = [];
        while ($ready !== []) {
            usort($ready, static fn (string $a, string $b): int => self::compare($a, $b, $byPath));
            $rel = array_shift($ready);
            if ($rel === null) {
                break;
            }
            $ordered[] = $byPath[$rel];
            foreach ($outgoing[$rel] as $consumer) {
                $indegree[$consumer]--;
                if ($indegree[$consumer] === 0) {
                    $ready[] = $consumer;
                }
            }
            unset($indegree[$rel]);
        }

        // 防御性处理循环引用：保持稳定优先级，不丢文件。
        if ($indegree !== []) {
            $remaining = array_keys($indegree);
            usort($remaining, static fn (string $a, string $b): int => self::compare($a, $b, $byPath));
            foreach ($remaining as $rel) {
                $ordered[] = $byPath[$rel];
            }
        }

        foreach ($ordered as &$entry) {
            unset($entry['_order']);
        }
        unset($entry);
        return array_values($ordered);
    }

    /** @return list<string> */
    public static function dependencies(string $rel, string $source): array
    {
        $dependencies = [];
        $patterns = [
            [
                '/^(?:\(\s*)?ROOT_PATH\s*\.\s*([\'\"])([^\'\"]+\.php)\1/i',
                static fn (array $match): string => ltrim((string) $match[2], '/'),
            ],
            [
                '/^(?:\(\s*)?dirname\(__DIR__(?:\s*,\s*(\d+))?\)\s*\.\s*([\'\"])([^\'\"]+\.php)\2/i',
                static function (array $match) use ($rel): string {
                    $base = dirname($rel);
                    $levels = isset($match[1]) && $match[1] !== '' ? (int) $match[1] : 1;
                    for ($i = 0; $i < $levels; $i++) {
                        $base = dirname($base);
                    }
                    return $base . '/' . ltrim((string) $match[3], '/');
                },
            ],
            [
                '/^(?:\(\s*)?__DIR__\s*\.\s*([\'\"])([^\'\"]+\.php)\1/i',
                static fn (array $match): string => dirname($rel) . '/' . ltrim((string) $match[2], '/'),
            ],
            [
                '/^(?:\(\s*)?([\'\"])([^\'\"]+\.php)\1/i',
                static fn (array $match): string => dirname($rel) . '/' . (string) $match[2],
            ],
        ];

        foreach (self::topLevelRequireExpressions($source) as $expression) {
            foreach ($patterns as [$pattern, $resolve]) {
                if (preg_match($pattern, $expression, $match) !== 1) {
                    continue;
                }
                $dependency = self::normalize($resolve($match));
                if ($dependency !== '') {
                    $dependencies[] = $dependency;
                }
                break;
            }
        }
        return array_values(array_unique($dependencies));
    }

    /** @return list<string> */
    private static function topLevelRequireExpressions(string $source): array
    {
        $tokens = token_get_all($source);
        $scopeStack = [];
        $scopeDepth = 0;
        $pendingScope = false;
        $expressions = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $pendingScope = true;
                continue;
            }
            if ($token === '{') {
                $opensScope = $pendingScope;
                $scopeStack[] = $opensScope;
                if ($opensScope) {
                    $scopeDepth++;
                }
                $pendingScope = false;
                continue;
            }
            if ($token === '}') {
                $opensScope = array_pop($scopeStack);
                if ($opensScope === true) {
                    $scopeDepth--;
                }
                continue;
            }
            if ($token === ';') {
                $pendingScope = false;
                continue;
            }
            if (!is_array($token)
                || !in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)
                || $scopeDepth > 0) {
                continue;
            }

            $expression = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if ($next === ';') {
                    break;
                }
                $expression .= is_array($next) ? $next[1] : $next;
            }
            $expressions[] = trim($expression);
        }
        return $expressions;
    }

    /** @param array<string, array<string, string>> $entries */
    private static function compare(string $a, string $b, array $entries): int
    {
        $priority = self::priority($a) <=> self::priority($b);
        if ($priority !== 0) {
            return $priority;
        }
        $path = strcmp($a, $b);
        if ($path !== 0) {
            return $path;
        }
        return ((int) ($entries[$a]['_order'] ?? 0)) <=> ((int) ($entries[$b]['_order'] ?? 0));
    }

    private static function priority(string $rel): int
    {
        if ($rel === '.delta-manifest.json') {
            return 0;
        }
        if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'php') {
            return 10;
        }
        if ($rel === 'includes/UpgradeEntryOrder.php') {
            return 15;
        }
        if (str_starts_with($rel, 'includes/')) {
            return $rel === 'includes/UpgradeRunner.php' ? 80 : 20;
        }
        if (str_starts_with($rel, 'config/')) {
            return $rel === 'config/version.php' ? 100 : 30;
        }
        if (substr_count($rel, '/') === 0) {
            return 50;
        }
        if ($rel === 'admin/upgrade_online.php') {
            return 90;
        }
        return 40;
    }

    private static function isRuntimePhp(string $rel): bool
    {
        return strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'php'
            && !str_starts_with($rel, 'install/')
            && !str_ends_with($rel, '.sample.php');
    }

    private static function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', trim($path))) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }
}
