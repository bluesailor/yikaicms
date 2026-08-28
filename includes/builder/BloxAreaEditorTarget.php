<?php
/** 根据前台实际渲染来源，解析 Header/Footer 的编辑器入口。 */

declare(strict_types=1);

final class BloxAreaEditorTarget
{
    private const RETURN_TO_MAX = 2048;
    private const RETURN_RECEIPT_KEY = '_blox_return_receipts';
    private const RETURN_RECEIPT_LIMIT = 8;
    private const RETURN_RECEIPT_TTL = 600;

    /** @param array<string,mixed> $template */
    public static function isThemeFallbackTemplate(array $template, string $area): bool
    {
        $sourceRef = self::defaultThemeSourceRef($area);
        return $sourceRef !== ''
            && (string) ($template['type'] ?? '') === $area
            && (string) ($template['source'] ?? '') === 'builtin'
            && (string) ($template['source_ref'] ?? '') === $sourceRef;
    }

    /**
     * @param array{home?:bool,channel_id?:int,page_id?:int,lang?:string} $context
     * @param string $back 返回目的地白名单标记（如 'home'=首页编辑器）。
     *                     从首页/页面编辑器画布跳来的用户，编辑完页头要能一键回到
     *                     出发点——此前固定回模板列表页，是「编辑页头很绕」的主断点。
     */
    public static function url(string $area, array $context = [], string $back = ''): string
    {
        if (!in_array($area, ['header', 'footer'], true)) {
            return '/admin/site_design.php';
        }

        $fallback = '/admin/site_design.php#site-design-area-' . $area;
        try {
            if (!db()->tableExists('blox_templates')) {
                return $fallback;
            }

            if (self::customAreaEnabled($area)) {
                $templates = bloxTemplateModel()->publishedAreaTemplates($area);
                $resolved = $templates === [] ? null : BloxAreaResolver::resolve($templates, [
                    'home' => (bool) ($context['home'] ?? false),
                    'channel_id' => max(0, (int) ($context['channel_id'] ?? 0)),
                    'page_id' => max(0, (int) ($context['page_id'] ?? 0)),
                    'lang' => trim((string) ($context['lang'] ?? siteLang())),
                ]);
                $resolvedId = (int) ($resolved['id'] ?? 0);
                if ($resolvedId > 0) {
                    return self::editorUrl($area, $resolvedId, false, $back);
                }
            }

            // 自定义区域停用或当前上下文没有命中时，前台实际显示主题布局。
            // default 主题的内置起步模板是该布局的可编辑对应物；不能退回去编辑
            // 一个仍在数据库中、但当前没有参与渲染的已发布模板。
            $sourceRef = self::defaultThemeSourceRef($area);
            if ($sourceRef === '') {
                return $fallback;
            }
            $themeDraft = bloxTemplateModel()->findWhere([
                'source' => 'builtin',
                'source_ref' => $sourceRef,
            ]);
            $themeDraftId = (int) ($themeDraft['id'] ?? 0);
            return $themeDraftId > 0
                ? self::editorUrl($area, $themeDraftId, $area === 'header', $back)
                : $fallback;
        } catch (Throwable $e) {
            error_log('[BloxAreaEditorTarget] ' . $e->getMessage());
            return $fallback;
        }
    }

    public static function normalizeReturnTo(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $target = trim($value);
        if ($target === '' || strlen($target) > self::RETURN_TO_MAX
            || preg_match('/[\x00-\x1F\x7F]/', $target) === 1
            || str_contains($target, '\\')) {
            return '';
        }

        $parts = parse_url($target);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return '';
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '';
        }

        $decodedPath = $path;
        for ($i = 0; $i < 2; $i++) {
            $decoded = rawurldecode($decodedPath);
            if ($decoded === $decodedPath) {
                break;
            }
            $decodedPath = $decoded;
        }
        if (str_starts_with($decodedPath, '//') || str_contains($decodedPath, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $decodedPath) === 1
            || preg_match('#^/admin(?:/|$)#i', $decodedPath) === 1) {
            return '';
        }
        foreach (explode('/', $decodedPath) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return '';
            }
        }

        return $path
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public static function frontendSourceReturnTo(mixed $value): string
    {
        $target = self::normalizeReturnTo($value);
        if ($target === '') {
            return '';
        }
        $parts = parse_url($target);
        if (!is_array($parts)) {
            return '';
        }

        $query = [];
        foreach (explode('&', (string) ($parts['query'] ?? '')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $key = rawurldecode(str_replace('+', ' ', explode('=', $segment, 2)[0]));
            if (in_array($key, ['yk_focus_section', 'yk_focus_element', 'yk_edit_receipt'], true)) {
                continue;
            }
            $query[] = $segment;
        }

        return (string) ($parts['path'] ?? '')
            . ($query === [] ? '' : '?' . implode('&', $query))
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public static function withReturnTo(string $editorUrl, string $returnTo): string
    {
        $returnTo = self::normalizeReturnTo($returnTo);
        if ($returnTo === '') {
            return $editorUrl;
        }

        $parts = parse_url($editorUrl);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])
            || (string) ($parts['path'] ?? '') !== '/admin/blox_editor.php') {
            return $editorUrl;
        }
        $params = [];
        parse_str((string) ($parts['query'] ?? ''), $params);
        $params['return_to'] = $returnTo;

        return '/admin/blox_editor.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public static function issueReturnReceipt(string $result): string
    {
        if (!in_array($result, ['draft', 'published'], true)
            || !isset($_SESSION) || !is_array($_SESSION)) {
            return '';
        }

        try {
            $token = bin2hex(random_bytes(24));
        } catch (Throwable) {
            return '';
        }
        $receipts = self::activeReturnReceipts();
        $receipts[$token] = [
            'result' => $result,
            'expires' => time() + self::RETURN_RECEIPT_TTL,
        ];
        if (count($receipts) > self::RETURN_RECEIPT_LIMIT) {
            uasort($receipts, static fn (array $left, array $right): int => $left['expires'] <=> $right['expires']);
            $receipts = array_slice($receipts, -self::RETURN_RECEIPT_LIMIT, null, true);
        }
        $_SESSION[self::RETURN_RECEIPT_KEY] = $receipts;
        return $token;
    }

    public static function consumeReturnReceipt(mixed $token): string
    {
        if (!is_string($token) || preg_match('/^[a-f0-9]{48}$/', $token) !== 1
            || !isset($_SESSION) || !is_array($_SESSION)) {
            return '';
        }

        $receipts = self::activeReturnReceipts();
        $receipt = $receipts[$token] ?? null;
        unset($receipts[$token]);
        if ($receipts === []) {
            unset($_SESSION[self::RETURN_RECEIPT_KEY]);
        } else {
            $_SESSION[self::RETURN_RECEIPT_KEY] = $receipts;
        }
        return is_array($receipt) ? (string) ($receipt['result'] ?? '') : '';
    }

    /** @return array<string,array{result:string,expires:int}> */
    private static function activeReturnReceipts(): array
    {
        $stored = $_SESSION[self::RETURN_RECEIPT_KEY] ?? [];
        if (!is_array($stored)) {
            return [];
        }

        $now = time();
        $active = [];
        foreach ($stored as $token => $receipt) {
            if (!is_string($token) || preg_match('/^[a-f0-9]{48}$/', $token) !== 1
                || !is_array($receipt)
                || !in_array((string) ($receipt['result'] ?? ''), ['draft', 'published'], true)
                || (int) ($receipt['expires'] ?? 0) < $now) {
                continue;
            }
            $active[$token] = [
                'result' => (string) $receipt['result'],
                'expires' => (int) $receipt['expires'],
            ];
        }
        return $active;
    }

    private static function customAreaEnabled(string $area): bool
    {
        $key = $area === 'header' ? 'blox_custom_header_enabled' : 'blox_custom_footer_enabled';
        return (string) config($key, '1') === '1';
    }

    private static function defaultThemeSourceRef(string $area): string
    {
        if ((string) config('current_theme', 'default') !== 'default') {
            return '';
        }
        if ($area === 'footer') {
            return 'clean-site-footer';
        }

        // 该记录仅作为保存目标；编辑首屏由 BloxThemeHeaderDocument 按当前主题设置生成，
        // 因此 Logo 右侧与 Logo 下方两种布局都能从真实生效状态开始编辑。
        return 'clean-site-header';
    }

    private static function editorUrl(string $area, int $id, bool $currentThemeHeader = false, string $back = ''): string
    {
        $url = '/admin/blox_editor.php?template=' . $id;
        if ($currentThemeHeader) {
            $url .= '&current_header=1';
        }
        if (self::isAllowedBack($back)) {
            $url .= '&back=' . $back;
        }
        return $area === 'header' ? $url . '&open=header-settings' : $url;
    }

    /** back 值白名单（防 open redirect）；blox_editor.php 读取时同一判定 */
    public static function isAllowedBack(string $back): bool
    {
        return $back === 'home';
    }
}
