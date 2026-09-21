<?php
/**
 * 导入完成报告（E04）。
 *
 * 只读检查：不发网络请求、不执行主题 PHP、不提交表单——计划明确要求"不发送真实邮件
 * 或虚假咨询"，所以表单只看定义是否存在，绝不触发投递。
 *
 * 分级口径：
 *   failed  —— 结构性问题，站点当下不可用（无可见栏目、首页空白）；
 *   warning —— 可交付但有待办（演示文案没换、品牌资料没填、媒体缺失、外来域名残留）；
 *   ok      —— 未发现问题。
 * 拿不准的一律归 warning 让人自己看，不因为"可能没事"就静默放过。
 *
 * 检查项尽量复用 SiteContentChecks（演示文案／媒体落盘／空栏目），本文件只补导入特有的
 * 三项：结构可用性、品牌绑定是否生效、外来域名残留。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/SiteContentChecks.php';

final class SiteImportReport
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const FAILED = 'failed';

    /** 每一级最多列这么多条，避免一次导入把整页刷满。 */
    private const MAX_PER_LEVEL = 60;

    /** 外来域名残留最多列这么多条。 */
    private const MAX_FOREIGN = 20;

    /**
     * @return array{status:string,counts:array<string,int>,items:list<array<string,string>>,scanned:int,limited:bool}
     */
    public static function build(string $root): array
    {
        $items = [];
        $counts = [self::FAILED => 0, self::WARNING => 0];

        foreach (self::structure() as $item) {
            self::push($items, $counts, $item);
        }
        foreach (self::brand() as $item) {
            self::push($items, $counts, $item);
        }
        foreach (self::foreignOrigins() as $item) {
            self::push($items, $counts, $item);
        }

        $content = SiteContentChecks::run($root);
        foreach ($content['issues'] as $issue) {
            self::push($items, $counts, [
                'level' => self::WARNING,
                'code' => (string) ($issue['kind'] ?? ''),
                'label' => (string) ($issue['label'] ?? ''),
                'detail' => (string) ($issue['detail'] ?? ''),
                'url' => (string) ($issue['url'] ?? ''),
            ]);
        }

        $status = $counts[self::FAILED] > 0
            ? self::FAILED
            : ($counts[self::WARNING] > 0 ? self::WARNING : self::OK);

        return [
            'status' => $status,
            'counts' => $counts,
            'items' => $items,
            'scanned' => (int) $content['scanned'],
            'limited' => (bool) $content['limited'],
        ];
    }

    /**
     * 结构可用性：没有可见栏目、或首页没有任何内容，站点当下就打不开——这属于失败而非待办。
     *
     * @return list<array<string,string>>
     */
    private static function structure(): array
    {
        $items = [];
        $channels = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'channels WHERE status = ?', [1]);
        if ($channels === 0) {
            $items[] = ['level' => self::FAILED, 'code' => 'ir_no_channel', 'label' => '', 'detail' => '',
                'url' => '/admin/channel.php'];
        }
        $hasHome = false;
        foreach (['home_blox_published', 'home_blox_data', 'home_modules'] as $key) {
            if (trim((string) config($key, '')) !== '') {
                $hasHome = true;
                break;
            }
        }
        if (!$hasHome) {
            $items[] = ['level' => self::FAILED, 'code' => 'ir_no_home', 'label' => '', 'detail' => '',
                'url' => '/admin/setting_home.php'];
        }
        return $items;
    }

    /**
     * 品牌绑定：导入只写明确字段（不做全站文字替换），所以只能逐字段看有没有填。
     *
     * @return list<array<string,string>>
     */
    private static function brand(): array
    {
        $items = [];
        if (trim((string) config('site_name', '')) === '') {
            $items[] = ['level' => self::WARNING, 'code' => 'ir_brand_name', 'label' => '', 'detail' => '',
                'url' => '/admin/setting.php?tab=basic'];
        }
        if (trim((string) configRawLang('site_logo', '')) === '') {
            $items[] = ['level' => self::WARNING, 'code' => 'ir_brand_logo', 'label' => '', 'detail' => '',
                'url' => '/admin/setting.php?tab=basic'];
        }
        $filled = 0;
        foreach (['contact_phone', 'contact_email', 'contact_address'] as $key) {
            if (trim((string) configRawLang($key, '')) !== '') {
                $filled++;
            }
        }
        if ($filled === 0) {
            $items[] = ['level' => self::WARNING, 'code' => 'ir_brand_contact', 'label' => '', 'detail' => '',
                'url' => '/admin/setting_contact.php'];
        }
        return $items;
    }

    /**
     * 外来域名残留：导出只归一化作者配置里的那**一个** site_url，作者的第二域名、
     * 测试域名或 CDN 仍会以绝对地址留在内容里，指回模板作者的站点。
     *
     * 判据收得很紧——只报**指向外部域名的 /uploads/ 或 /themes/ 地址**。这类几乎不可能
     * 是有意为之，而普通外链（合作伙伴、社交账号）必须放过，否则报告会被误报淹没。
     *
     * @return list<array<string,string>>
     */
    private static function foreignOrigins(): array
    {
        $host = strtolower((string) parse_url((string) config('site_url', ''), PHP_URL_HOST));
        $items = [];
        $seen = [];
        foreach (['channels' => 'name', 'contents' => 'title', 'products' => 'title'] as $table => $labelColumn) {
            if (!db()->tableExists($table)) {
                continue;
            }
            $rows = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE status = ? ORDER BY id LIMIT 500', [1]);
            foreach ($rows as $row) {
                $editUrl = match ($table) {
                    'channels' => '/admin/channel.php?edit=' . (int) ($row['id'] ?? 0),
                    'contents' => '/admin/content_edit.php?id=' . (int) ($row['id'] ?? 0),
                    default => '/admin/product_edit.php?id=' . (int) ($row['id'] ?? 0),
                };
                foreach (self::foreignAssetLinks($row, $host) as $link) {
                    $key = $link . '|' . $editUrl;
                    if (isset($seen[$key]) || count($seen) >= self::MAX_FOREIGN) {
                        continue;
                    }
                    $seen[$key] = true;
                    $items[] = ['level' => self::WARNING, 'code' => 'ir_foreign_origin',
                        'label' => mb_substr((string) ($row[$labelColumn] ?? ''), 0, 120),
                        'detail' => $link, 'url' => $editUrl];
                }
            }
        }
        return $items;
    }

    /** @return list<string> */
    private static function foreignAssetLinks(mixed $value, string $host): array
    {
        if (is_array($value)) {
            $found = [];
            foreach ($value as $item) {
                foreach (self::foreignAssetLinks($item, $host) as $link) {
                    $found[] = $link;
                }
            }
            return $found;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return self::foreignAssetLinks($decoded, $host);
        }
        // 结束字符要排掉 ) ' 与引号：CSS 的 url(...) 和单引号属性都会把界定符吃进链接里
        $pattern = '~https?://[^\s"\'<>)]+?/(?:uploads|themes)/[^\s"\'<>)]*~i';
        if (!preg_match_all($pattern, $value, $matches)) {
            return [];
        }
        $found = [];
        foreach (array_unique($matches[0]) as $link) {
            $linkHost = strtolower((string) parse_url($link, PHP_URL_HOST));
            if ($linkHost === '' || $linkHost === $host) {
                continue;
            }
            $found[] = mb_substr($link, 0, 200);
        }
        return $found;
    }

    /**
     * @param list<array<string,string>> $items
     * @param array<string,int> $counts
     * @param array<string,string> $item
     */
    private static function push(array &$items, array &$counts, array $item): void
    {
        $level = $item['level'] ?? self::WARNING;
        if (($counts[$level] ?? 0) >= self::MAX_PER_LEVEL) {
            return;
        }
        $counts[$level] = ($counts[$level] ?? 0) + 1;
        $items[] = $item;
    }
}
