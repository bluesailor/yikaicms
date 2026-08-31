<?php

declare(strict_types=1);

function install_domain_is_local(mixed $domain): bool
{
    if (!is_string($domain) || trim($domain) === '') {
        return false;
    }
    $domain = trim($domain);
    if (filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $host = $domain;
    } else {
        $url = str_contains($domain, '://') || str_starts_with($domain, '//') ? $domain : '//' . $domain;
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return false;
        }
    }
    $host = strtolower(rtrim(trim($host, '[]'), '.'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return str_starts_with($host, '127.') || $host === '0.0.0.0';
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $packed = inet_pton($host);
        // Compare bytes so expanded and IPv4-mapped loopback addresses behave consistently.
        return $packed === str_repeat("\0", 15) . "\1"
            || $packed === str_repeat("\0", 16)
            || (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff" && ord($packed[12]) === 127);
    }
    return false;
}
