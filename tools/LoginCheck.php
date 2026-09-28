<?php
declare(strict_types=1);

/**
 * 内部工具：远程检查某个 YikaiCMS 站点的后台登录会话（客户反映「登录页已过期或会话没有保存下来」时用）。
 * 不随安装包分发（tools/ 不进包），由我们对着客户站网址跑：php tools/login-check.php --url=https://客户域名
 *
 * 像浏览器一样取两次登录页（第二次带上第一次拿到的会话 Cookie）：
 *   - 登录页有没有下发会话 Cookie、是否命中 CDN / 反代 / 页面缓存；
 *   - Cookie 带 Secure 而网址是 http（浏览器会丢掉）、Cookie 域名与网址不符；
 *   - 服务器有没有保存会话：两次表单令牌相同 = 保存并读回；换了令牌或又发新会话号 = 没存下（会话目录不可写、被清理、多台服务器不共享）。
 * 不提交登录表单，不会触发登录限流或留下登录失败记录。
 */
final class LoginCheck
{
    /**
     * 解析一条 Set-Cookie。
     * @return array{name:string,value:string,secure:bool,httponly:bool,domain:string,path:string,samesite:string}
     */
    public static function parseSetCookie(string $header): array
    {
        $parts = array_map('trim', explode(';', $header));
        [$name, $value] = array_pad(explode('=', (string) array_shift($parts), 2), 2, '');
        $cookie = ['name' => trim($name), 'value' => trim($value), 'secure' => false, 'httponly' => false, 'domain' => '', 'path' => '', 'samesite' => ''];
        foreach ($parts as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
            $key = strtolower(trim($key));
            if ($key === 'secure') $cookie['secure'] = true;
            elseif ($key === 'httponly') $cookie['httponly'] = true;
            elseif (in_array($key, ['domain', 'path', 'samesite'], true)) $cookie[$key] = trim($val);
        }
        return $cookie;
    }

    /**
     * 找出登录页下发的会话 Cookie。每个站点的 SESSION_NAME 不同（安装时生成 IK_<十六进制>，老站为 IKAICMS_SESSION），
     * 远程不知道确切名字，按命名规律认；给了 $name 就只认它。
     * @param list<string> $setCookies
     * @return null|array{name:string,value:string,secure:bool,httponly:bool,domain:string,path:string,samesite:string}
     */
    public static function sessionCookie(array $setCookies, string $name = ''): ?array
    {
        foreach ($setCookies as $line) {
            $cookie = self::parseSetCookie($line);
            if ($name !== '' ? $cookie['name'] === $name : preg_match('/^(?:IK_[0-9A-Fa-f]{8,}|IKAICMS_SESSION)$/D', $cookie['name']) === 1) {
                return $cookie;
            }
        }
        return null;
    }

    /**
     * 响应头显示命中了缓存（CDN、反代、面板页面缓存）时返回那条头，否则空串。
     * @param array<string,list<string>> $headers 头名小写
     */
    public static function cacheHit(array $headers): string
    {
        foreach (['x-cache', 'cf-cache-status', 'x-proxy-cache', 'x-cache-status', 'x-fastcgi-cache', 'x-sg-cache', 'x-litespeed-cache'] as $name) {
            foreach ($headers[$name] ?? [] as $value) {
                if (preg_match('/\bHIT\b/i', $value)) return $name . ': ' . $value;
            }
        }
        foreach ($headers['age'] ?? [] as $value) {
            if ((int) $value > 0) return 'age: ' . $value;
        }
        return '';
    }

    /** 登录表单里的 CSRF 令牌 */
    public static function tokenFrom(string $html, string $field): string
    {
        return preg_match('/name="' . preg_quote($field, '/') . '"\s+value="([0-9a-f]{16,128})"/i', $html, $m) === 1 ? $m[1] : '';
    }

    /**
     * 两次取登录页的结果 → 逐项结论。
     * @param array{status:int,headers:array<string,list<string>>,body:string,url:string} $first
     * @param null|array{status:int,headers:array<string,list<string>>,body:string,url:string} $second
     * @return list<array{code:string,ok:bool,message:string,detail:string}>
     */
    public static function analyze(array $first, ?array $second, string $cookieName = '', string $tokenField = '_token'): array
    {
        $checks = [];
        $checks[] = ['code' => 'http', 'ok' => $first['status'] === 200, 'message' => '登录页可访问', 'detail' => $first['url'] . ' → HTTP ' . $first['status']];
        if ($first['status'] !== 200) return $checks;

        $hit = self::cacheHit($first['headers']);
        $checks[] = ['code' => 'cache', 'ok' => $hit === '', 'detail' => $hit,
            'message' => $hit === '' ? '登录页没有命中缓存' : '登录页命中了缓存（CDN / 反代 / 页面缓存）：浏览器拿到旧页面、没有会话 Cookie。让 /admin/ 不走缓存'];

        $cookie = self::sessionCookie($first['headers']['set-cookie'] ?? [], $cookieName);
        $checks[] = ['code' => 'cookie', 'ok' => $cookie !== null, 'detail' => $cookie['name'] ?? ($cookieName ?: 'IK_* / IKAICMS_SESSION'),
            'message' => $cookie !== null ? '登录页下发了会话 Cookie' : '登录页没有下发会话 Cookie（多为页面被缓存，或会话没能启动；Cookie 名不是 IK_* 时用 --cookie= 指定）'];
        if ($cookie === null) return $checks;

        $https = str_starts_with(strtolower($first['url']), 'https://');
        $secureClash = $cookie['secure'] && !$https;
        $checks[] = ['code' => 'secure', 'ok' => !$secureClash, 'detail' => ($cookie['secure'] ? 'Secure' : 'no Secure') . ' / ' . ($https ? 'https' : 'http'),
            'message' => $secureClash ? 'Cookie 带 Secure，但网址是 http：浏览器会丢掉它。统一用 https，或检查反代的 X-Forwarded-Proto / 443 端口判定' : 'Cookie 的 Secure 标记与网址协议一致'];

        $host = strtolower((string) parse_url($first['url'], PHP_URL_HOST));
        $domain = ltrim(strtolower($cookie['domain']), '.');
        $domainClash = $domain !== '' && $host !== $domain && !str_ends_with($host, '.' . $domain);
        $checks[] = ['code' => 'domain', 'ok' => !$domainClash, 'detail' => ($cookie['domain'] !== '' ? $cookie['domain'] : '(host only)') . ' / ' . $host,
            'message' => $domainClash ? 'Cookie 的域名与网址不一致，浏览器不会带回' : 'Cookie 的域名与网址一致'];

        if ($second === null) return $checks;
        $again = self::sessionCookie($second['headers']['set-cookie'] ?? [], $cookie['name']);
        $renewed = $again !== null && $again['value'] !== '' && $again['value'] !== $cookie['value'];
        $token1 = self::tokenFrom($first['body'], $tokenField);
        $token2 = self::tokenFrom($second['body'], $tokenField);
        $kept = $second['status'] === 200 && !$renewed && $token1 !== '' && $token1 === $token2;
        $checks[] = ['code' => 'persist', 'ok' => $kept,
            'detail' => $token1 === '' ? 'no token in form' : ($renewed ? 'new session id issued' : ($token1 === $token2 ? 'same token' : 'token changed')),
            'message' => $kept ? '服务器保存并读回了会话' : '服务器没有保存会话：第二次请求拿到了新会话或新令牌（会话目录不可写、被清理，或多台服务器不共享会话）'];
        return $checks;
    }
}
