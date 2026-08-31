<?php
/**
 * 安装注册表：记录每个 YikaiCMS 站点的心跳上报。
 * 存 data/installs.json（data/ 已被 .htaccess 禁止 web 直接访问）。
 * 按 install_id upsert；记录 域名/版本/PHP/站名/IP/首次&最后在线/次数。
 */

declare(strict_types=1);

require_once __DIR__ . '/_install-domain.php';

if (!function_exists('recordInstall')) {

    /** 清洗域名：去 scheme/path/端口外的杂质，只留 host[:port]。 */
    function ri_cleanDomain(string $d): string
    {
        $d = trim($d);
        if ($d === '') return '';
        if (str_contains($d, '://')) {
            $d = (string) (parse_url($d, PHP_URL_HOST) ?? $d);
        }
        $d = preg_replace('#[/?].*$#', '', $d) ?? $d;     // 去路径/query
        $d = preg_replace('#[^A-Za-z0-9.\-:]#', '', $d) ?? $d;
        return mb_substr($d, 0, 150);
    }

    /**
     * 可选字段：本次没传就沿用上次的值。
     * 老客户端不会带 health_* / auto_*，直接覆盖会把已有记录抹成空——那等于每来一次
     * 旧版心跳就丢一次数据。
     *
     * @param array<string,int> $fields 字段名 => 最大长度
     * @return array<string,string>
     */
    function ri_keep(array $p, array $prev, array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $max) {
            $out[$name] = array_key_exists($name, $p)
                ? mb_substr((string) $p[$name], 0, $max)
                : (string) ($prev[$name] ?? '');
        }
        return $out;
    }

    /**
     * 记录一次上报。$p: install_id, domain, version, php, site_name, ip
     */
    function recordInstall(array $p): void
    {
        $reportedDomain = (string) ($p['domain'] ?? '');
        // Check the original address before cleaning can strip IPv6 brackets.
        // Skipping telemetry must not stop the caller from returning its update response.
        if (install_domain_is_local($reportedDomain)) return;
        $domain = ri_cleanDomain($reportedDomain);
        if (install_domain_is_local($domain)) return;
        $installId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($p['install_id'] ?? '')) ?? '';
        $installId = mb_substr($installId, 0, 64);

        // 无 install_id 也无域名 → 无法标识，放弃
        $key = $installId !== '' ? $installId : ($domain !== '' ? 'dom:' . $domain : '');
        if ($key === '') return;

        // .php + PHP 守卫：直接 web 访问立即 403，读不到内容（.json 会被 NGINX 静态直出）。
        $guard = "<?php http_response_code(403); exit; ?>\n";
        $file = dirname(__DIR__, 2) . '/data/installs.php';
        $fp = @fopen($file, 'c+');
        if (!$fp) return;
        if (!flock($fp, LOCK_EX)) { fclose($fp); return; }

        $raw = stream_get_contents($fp);
        $raw = (string) preg_replace('/^<\?php.*?\?>\s*/s', '', $raw ?: '');
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) $data = [];

        $now = date('Y-m-d H:i:s');
        $prev = $data[$key] ?? ['first_seen' => $now, 'hits' => 0];

        $data[$key] = [
            'install_id' => $installId,
            'domain'     => $domain,
            'version'    => mb_substr((string) ($p['version'] ?? ''), 0, 40),
            'php'        => mb_substr((string) ($p['php'] ?? ''), 0, 20),
            'site_name'  => mb_substr((string) ($p['site_name'] ?? ''), 0, 100),
            'ip'         => mb_substr((string) ($p['ip'] ?? ''), 0, 45),
            'first_seen' => $prev['first_seen'] ?? $now,
            'last_seen'  => $now,
            'hits'       => (int) ($prev['hits'] ?? 0) + 1,
        ] + ri_keep($p, $prev, [
            // 自动升级状态：控制台据此决定哪些站可以批量下发
            'auto' => 8, 'auto_scope' => 20, 'auto_window' => 20,
            'auto_result' => 20, 'auto_at' => 20, 'auto_to' => 40, 'auto_msg' => 200,
            // 站点健康摘要（2026-08-24 起）：只有站点自己测得出服务器配置是否真的挡住了
            // 敏感目录——我们在外面 curl 逐个探不但慢，uploads 能否执行 PHP 根本没法远程测。
            'health_at' => 20, 'health_crit' => 6, 'health_rec' => 6, 'health_bad' => 300,
        ]);

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $guard . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
