<?php
/** 站点配置图片的统一可用性判断：前台渲染、设置页与站点健康共用。 */

declare(strict_types=1);

final class SiteAsset
{
    public const EMPTY = 'empty';
    public const LOCAL_AVAILABLE = 'local_available';
    public const LOCAL_MISSING = 'local_missing';
    public const REMOTE = 'remote';
    public const INVALID = 'invalid';

    /** @return array{state:string,url:string,path:string} */
    public static function inspect(string $url, ?string $root = null): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['state' => self::EMPTY, 'url' => '', 'path' => ''];
        }
        if (mb_strlen($url) > 2000 || preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return ['state' => self::INVALID, 'url' => $url, 'path' => ''];
        }

        $candidate = str_starts_with($url, '//') ? 'https:' . $url : $url;
        $parts = parse_url($candidate);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return ['state' => self::INVALID, 'url' => $url, 'path' => ''];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '') {
            $remote = in_array($scheme, ['http', 'https'], true)
                && trim((string) ($parts['host'] ?? '')) !== '';
            return ['state' => $remote ? self::REMOTE : self::INVALID, 'url' => $url, 'path' => ''];
        }

        $path = rawurldecode((string) ($parts['path'] ?? ''));
        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', trim($normalized, '/'));
        if ($normalized === ''
            || preg_match('/[\x00-\x1f\x7f]/', $normalized) === 1
            || in_array('..', $segments, true)) {
            return ['state' => self::INVALID, 'url' => $url, 'path' => $normalized];
        }

        $normalized = '/' . ltrim($normalized, '/');
        $renderUrl = $normalized;
        if (isset($parts['query'])) {
            $renderUrl .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $renderUrl .= '#' . $parts['fragment'];
        }

        $root ??= defined('ROOT_PATH') ? (string) ROOT_PATH : '';
        $root = rtrim($root, '/\\');
        $file = $root === '' ? '' : $root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($normalized, '/'));

        return [
            'state' => $file !== '' && is_file($file) ? self::LOCAL_AVAILABLE : self::LOCAL_MISSING,
            'url' => $renderUrl,
            'path' => $normalized,
        ];
    }

    public static function availableUrl(string $url, ?string $root = null): string
    {
        $asset = self::inspect($url, $root);
        return in_array($asset['state'], [self::LOCAL_AVAILABLE, self::REMOTE], true)
            ? $asset['url']
            : '';
    }

}
