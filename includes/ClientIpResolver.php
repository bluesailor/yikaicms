<?php

declare(strict_types=1);

/** 只在请求确实来自可信代理时解析转发头。 */
final class ClientIpResolver
{
    /**
     * @return array{rules:list<string>,invalid:list<string>}
     */
    public static function parseRules(mixed $input): array
    {
        $tokens = is_array($input)
            ? $input
            : preg_split('/[\s,;]+/', trim((string) $input), -1, PREG_SPLIT_NO_EMPTY);
        $rules = [];
        $invalid = [];

        foreach ((array) $tokens as $token) {
            $rule = trim((string) $token);
            if ($rule === '') {
                continue;
            }
            if (!self::isValidRule($rule)) {
                $invalid[] = $rule;
                continue;
            }
            if (!in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return ['rules' => $rules, 'invalid' => $invalid];
    }

    public static function isValidRule(string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return filter_var($rule, FILTER_VALIDATE_IP) !== false;
        }

        [$network, $prefix] = array_pad(explode('/', $rule, 2), 2, '');
        if (filter_var($network, FILTER_VALIDATE_IP) === false || $prefix === '' || !ctype_digit($prefix)) {
            return false;
        }
        $maxBits = str_contains($network, ':') ? 128 : 32;
        return (int) $prefix <= $maxBits;
    }

    public static function matchesAny(string $ip, mixed $rules): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach (self::parseRules($rules)['rules'] as $rule) {
            if (self::matches($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $server */
    public static function resolve(array $server, mixed $trustedProxies): string
    {
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
        if (filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        $trusted = self::parseRules($trustedProxies)['rules'];
        if ($trusted === [] || !self::matchesAny($remote, $trusted)) {
            return $remote;
        }

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $candidate = trim((string) ($server[$header] ?? ''));
            if ($candidate !== '' && !str_contains($candidate, ',')
                && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        $forwarded = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))
        ), static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        if ($forwarded === []) {
            return $remote;
        }

        // 从离源站最近的一跳向外走，首个非可信地址才是客户端；不能信任最左值。
        $chain = array_merge($forwarded, [$remote]);
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!self::matchesAny($chain[$i], $trusted)) {
                return $chain[$i];
            }
        }
        return $forwarded[0];
    }

    private static function matches(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }

        [$network, $prefixRaw] = explode('/', $rule, 2);
        $ipBytes = inet_pton($ip);
        $networkBytes = inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix = (int) $prefixRaw;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($wholeBytes > 0 && substr($ipBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }
}
