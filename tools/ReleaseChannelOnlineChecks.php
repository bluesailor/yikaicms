<?php

declare(strict_types=1);

/** Online evidence stays separate from the local upload plan. */
trait ReleaseChannelOnlineChecks
{
    private static function visibleDocument(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//comment()|//script|//style|//head|//template|//nav|//header|//footer|//*[@hidden]|//*[@aria-hidden="true"]');
        foreach ($nodes === false ? [] : iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
        return $document;
    }

    private static function hasCurrentDownload(string $html, string $expected): bool
    {
        $found = false;
        foreach (self::visibleDocument($html)->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if (preg_match('~/yikaicms-v[0-9.]+(?:-[a-z0-9.-]+)?\\.zip(?:[?#]|$)~i', $href) === 1) {
                if ($href !== $expected) {
                    return false;
                }
                $found = true;
            }
        }
        return $found;
    }

    /** @return array<string,mixed> */
    private static function onlineResponse(string $url, callable $fetcher, bool $body): array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !parse_url($url, PHP_URL_HOST)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new RuntimeException('Missing or invalid HTTPS evidence URL');
        }
        $response = $fetcher($url, $body);
        if (($response['error'] ?? '') !== '' || ($response['status'] ?? 0) !== 200 || ($response['bytes'] ?? 0) <= 0) {
            throw new RuntimeException('Online evidence unavailable: HTTP ' . (string) ($response['status'] ?? 0)
                . ' ' . (string) ($response['error'] ?? '') . ' ' . $url);
        }
        return $response;
    }

    /** @return array<string,mixed> */
    private static function onlineJson(string $url, callable $fetcher): array
    {
        $response = self::onlineResponse($url, $fetcher, true);
        $json = json_decode((string) ($response['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new RuntimeException('Expected online JSON object');
        }
        return $json;
    }

    private static function onlineHash(string $url, string $expected, callable $fetcher): void
    {
        $response = self::onlineResponse($url, $fetcher, false);
        $actual = (string) ($response['sha256'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1 || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Online SHA256 mismatch or unavailable: ' . $url);
        }
    }

    /** @return list<array<string,mixed>> */
    private static function onlineChecks(string $name, array $config, string $version, string $workspace, callable $fetcher): array
    {
        try {
            $cfg = self::section($config, $name);
            if ($name === 'website') {
                $checks = [];
                foreach (['home_pages', 'changelog_pages'] as $kind) {
                    foreach (self::pages($cfg, $kind) as $lang => $relative) {
                        $url = rtrim((string) ($cfg['base_url'] ?? ''), '/') . '/' . $relative;
                        $response = self::onlineResponse($url, $fetcher, true);
                        $html = (string) ($response['body'] ?? '');
                        $tokens = self::versionTokens($html);
                        $ok = self::highestVersion($tokens) === $version;
                        $ok = $ok && ($kind === 'home_pages'
                            ? self::hasCurrentDownload($html, str_replace('{version}', $version, (string) $cfg['download_url']))
                            : ($tokens[0] ?? '') === $version);
                        $checks[] = self::check('Online ' . $kind . ' ' . $lang, $ok, $url);
                    }
                }
                return $checks;
            }
            $archiveDir = self::resolveDir(self::section($config, 'archive'), $workspace);
            $updateDir = self::resolveDir(self::section($config, 'update_server'), $workspace);
            if ($archiveDir === null || $updateDir === null) {
                throw new RuntimeException('Local evidence directory unavailable');
            }
            if ($name === 'update_server') {
                require_once __DIR__ . '/ReleaseUploadGuard.php';
                $plan = ReleaseUploadGuard::inspect($updateDir, $archiveDir, $version);
                $packageChecks = [];
                foreach ($plan['packages'] as $package) {
                    $url = str_replace('{package}', basename($package), (string) $cfg['package_url']);
                    self::onlineHash($url, (string) hash_file('sha256', $package), $fetcher);
                    $packageChecks[] = self::check('Online package SHA256', true, $url);
                }
                if (trim((string) ($cfg['catalog_url'] ?? '')) === '') {
                    $packageChecks[] = self::check('Online release catalog', null,
                        'Set YK_RELEASE_CATALOG_URL to a dedicated read-only metadata endpoint. Local files do not prove online catalog synchronization.');
                    return $packageChecks;
                }
                $local = self::readJson($updateDir . '/' . (string) $cfg['catalog']);
                $remote = self::onlineJson((string) ($cfg['catalog_url'] ?? ''), $fetcher);
                if ($local === null || ($remote['latest'] ?? null) !== ($local['latest'] ?? null)) {
                    throw new RuntimeException('Online release catalog latest mismatch');
                }
                $expectedRelease = self::releaseEntry($local, $version);
                $remoteRelease = self::releaseEntry($remote, $version);
                foreach ($expectedRelease as $field => $expectedValue) {
                    if ($expectedValue !== ($remoteRelease[$field] ?? null)) {
                        throw new RuntimeException('Online release catalog mismatch: ' . $field);
                    }
                }
                if (array_diff_key($remoteRelease, $expectedRelease) !== []) {
                    throw new RuntimeException('Unexpected online release metadata fields');
                }
                $packageChecks[] = self::check('Online release catalog', true, 'Target release metadata matches local catalog');
                return $packageChecks;
            } elseif ($name === 'market') {
                $response = self::onlineJson((string) ($cfg['registry_url'] ?? ''), $fetcher);
                if (($response['code'] ?? null) !== 0 || !is_array($response['data']['themes'] ?? null)) {
                    throw new RuntimeException('Invalid online theme registry');
                }
                $local = self::readJson($updateDir . '/' . (string) $cfg['registry']);
                $expected = [];
                foreach ($local['themes'] ?? [] as $theme) {
                    $expected[(string) $theme['slug']] = $theme;
                }
                $seen = [];
                foreach ($response['data']['themes'] as $theme) {
                    $slug = (string) ($theme['slug'] ?? '');
                    if (isset($seen[$slug]) || !in_array($slug, (array) $cfg['approved'], true) || !isset($expected[$slug])) {
                        return [self::check('Online registry', false, 'Unexpected or duplicate online theme: ' . $slug, null, true)];
                    }
                    $seen[$slug] = true;
                    foreach (['version', 'hash', 'sig', 'requires_cms'] as $field) {
                        if (($theme[$field] ?? null) !== ($expected[$slug][$field] ?? null)) {
                            throw new RuntimeException('Online theme metadata mismatch: ' . $slug . '/' . $field);
                        }
                    }
                    $url = str_replace('{package}', (string) $expected[$slug]['package'], (string) $cfg['package_url']);
                    if (($theme['download_url'] ?? '') !== $url) {
                        throw new RuntimeException('Online theme download URL mismatch: ' . $slug);
                    }
                    self::onlineHash($url, (string) preg_replace('/^sha256:/', '', (string) $theme['hash']), $fetcher);
                }
                if (array_diff((array) $cfg['approved'], array_keys($seen)) !== []) {
                    throw new RuntimeException('Approved online theme missing');
                }
            } elseif ($name === 'github') {
                $api = 'https://api.github.com/repos/' . (string) $cfg['repo'];
                $release = self::onlineJson($api . '/releases/tags/v' . $version, $fetcher);
                if (($release['draft'] ?? true) !== false || ($release['prerelease'] ?? true) !== false
                    || ($release['tag_name'] ?? '') !== 'v' . $version || empty($release['published_at'])) {
                    throw new RuntimeException('GitHub release is not the published stable target');
                }
                $evidence = self::readJson($archiveDir . '/yikaicms-v' . $version . '.evidence.json');
                $commit = self::onlineJson($api . '/commits/v' . $version, $fetcher);
                if (empty($evidence['source_commit']) || ($commit['sha'] ?? '') !== $evidence['source_commit']) {
                    throw new RuntimeException('GitHub tag source commit mismatch');
                }
                $assets = [];
                foreach ($release['assets'] ?? [] as $asset) {
                    $assets[(string) ($asset['name'] ?? '')] = $asset;
                }
                foreach (['zip', 'sha256'] as $extension) {
                    $file = 'yikaicms-v' . $version . '.' . $extension;
                    if (!isset($assets[$file]) || ($assets[$file]['state'] ?? '') !== 'uploaded') {
                        throw new RuntimeException('GitHub release asset missing: ' . $file);
                    }
                    self::onlineHash('https://github.com/' . (string) $cfg['repo'] . '/releases/download/v' . $version . '/' . $file,
                        is_file($archiveDir . '/' . $file) ? (string) hash_file('sha256', $archiveDir . '/' . $file) : '', $fetcher);
                }
            }
            return [self::check('Online verification', true, 'Remote metadata and artifact hashes match local release evidence')];
        } catch (Throwable $e) {
            return [self::check('Online verification', false, $e->getMessage())];
        }
    }

    /** @return array<string,mixed> */
    private static function releaseEntry(array $catalog, string $version): array
    {
        foreach ($catalog['releases'] ?? [] as $release) {
            if (($release['version'] ?? '') === $version) {
                return $release;
            }
        }
        throw new RuntimeException('Target release missing from catalog');
    }
}
