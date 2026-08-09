<?php
/**
 * robots.txt 的 Sitemap 行改成绝对 URL。
 *
 * 发行包里的 robots.txt 写的是 `Sitemap: /sitemap.xml`。robots.txt 规范要求
 * Sitemap 指令必须是**绝对 URL**，Google / Bing 对相对路径直接忽略 ——
 * 也就是说至今所有站点的 sitemap 都没有通过 robots.txt 被发现过
 * （多数站长另外在 Search Console 手动提交了，所以一直没人察觉）。
 *
 * 只改**仍是出厂默认那一行**的站点：客户在「SEO 设置」里自己改过 robots.txt 的，
 * 一个字都不动。判断依据是精确匹配 `Sitemap: /sitemap.xml`（含常见空格变体）。
 *
 * 另一个前提是「站点域名」已在基础设置里填过。没填就无事可做 —— 绝不用
 * 请求头里的 HTTP_HOST 去猜：那在反代、多域名、命令行下都可能是错的，
 * 把一个错域名写进 robots.txt 比留着相对路径更糟。这也让全新安装（site_url 出厂为空）
 * 天然「无待跑迁移」，符合 install SQL 对拍测试的契约。
 *
 * 配套改动：robots.txt 已加入在线升级的 UO_EXCLUDES，此后不再被发行包覆盖，
 * 所以这次改完就是站点自己的了。
 */

declare(strict_types=1);

return [
    'id' => '20260810_robots_sitemap_absolute',
    'title' => 'robots.txt 的 Sitemap 改为绝对地址',
    'title_en' => 'Use an absolute Sitemap URL in robots.txt',
    'title_ja' => 'robots.txt の Sitemap を絶対 URL に修正',
    'desc' => 'robots.txt 里的 Sitemap 原为相对路径，搜索引擎会忽略；改为带域名的绝对地址。'
        . '只处理仍是出厂默认的站点，自行编辑过 robots.txt 的不受影响。',
    'desc_en' => 'The Sitemap line in robots.txt used a relative path, which search engines ignore. '
        . 'It is now an absolute URL. Sites that have edited robots.txt themselves are left untouched.',
    'desc_ja' => 'robots.txt の Sitemap 行が相対パスだったため検索エンジンに無視されていました。'
        . 'ドメインを含む絶対 URL に修正します。robots.txt を編集済みのサイトは変更しません。',
    'check' => static function (): bool {
        $f = ROOT_PATH . '/robots.txt';
        if (!is_file($f)) {
            return true;                     // 没有这个文件就不归本迁移管
        }
        $raw = (string) @file_get_contents($f);
        if (!preg_match('/^\s*Sitemap:\s*\/sitemap\.xml\s*$/mi', $raw)) {
            return true;                     // 已是绝对地址或站长自己改过
        }
        // 还没填站点域名 → 没有可写的绝对地址，本迁移无事可做
        return trim((string) config('site_url', '')) === '';
    },
    'sqls' => [],
    'php' => static function (): string {
        $f = ROOT_PATH . '/robots.txt';
        if (!is_file($f)) {
            return 'robots.txt 不存在，跳过';
        }
        $raw = (string) @file_get_contents($f);
        if (!preg_match('/^\s*Sitemap:\s*\/sitemap\.xml\s*$/mi', $raw)) {
            return 'robots.txt 已是绝对地址或已自行编辑，未做改动';
        }

        // 只认后台明确填写的站点域名，不接受 HTTP_HOST 猜测（见文件头说明）
        $base = rtrim(trim((string) config('site_url', '')), '/');
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            return '请先在「基础设置」填写站点域名，再回来执行本项；robots.txt 未改动';
        }

        $new = preg_replace(
            '/^\s*Sitemap:\s*\/sitemap\.xml\s*$/mi',
            'Sitemap: ' . $base . '/sitemap.xml',
            $raw,
            1
        );
        if ($new === null || $new === $raw) {
            return 'robots.txt 未发生变化';
        }
        if (@file_put_contents($f, $new) === false) {
            return 'robots.txt 不可写，请手动把 Sitemap 行改为 ' . $base . '/sitemap.xml';
        }
        return 'robots.txt 的 Sitemap 已改为 ' . $base . '/sitemap.xml';
    },
];
