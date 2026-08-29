<?php

declare(strict_types=1);

/**
 * License 客户端 —— 向 update.yikaicms 校验授权，缓存结果，提供模块闸。
 *
 * 设置项：
 *   license_key    客户粘贴的授权码
 *   license_state  缓存的校验结果（JSON：data + sig + checked_at），内部维护、勿手填
 *
 * 原则（务必遵守）：服务期到期只停止下载、更新与支持，已购模块继续可用；
 *   授权无效时才回到免费态。任何状态都绝不锁站、绝不影响前台正常访问。
 *
 * 验签用 RSA 公钥（公开无妨，私钥仅在服务端）；伪造授权需私钥，不可能。
 * 用 openssl（RSA）而非 sodium：sodium 在不少中国共享主机被禁，openssl 几乎都有。
 */

if (!defined('LICENSE_PUBKEY_B64')) {
    // RSA-2048 公钥（DER 的 base64，单行）；运行时重建 PEM。
    define('LICENSE_PUBKEY_B64', 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEArnsKQEK5P4EFfu6K3j2UMHPTK8ezqso/RpklPg1ohuP+u8eTotsGXn6Y29QODUL6JVXLlaIhpfOa3eq+KuRM58grQRAWtWWRfJV1GdcXQE9SZ5NVax1AbbvaqSbofjx1LazQdPG+X9VuZoatm/eiLNWsue+XR9lg/89+OYPx9kBlL9YEX3hbtO373xIoD35FkAVoilXtOJX+4tJjUpUWLsZEGcZ9eZeUMWVlxc2ElymPre1wvo1erJ7C6RQ+Z1hYKzphKSYEfewSxvXpXykIjeZsFxFHXMEMfagPtuGMIzZoXrMa8JwiHKwV1kfO23KzIcb0aLho+wwSP7T4dHcsKQIDAQAB');
}

/** RSA 公钥 PEM（由单行 base64 重建）。 */
function license_pubkey(): string
{
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(LICENSE_PUBKEY_B64, 64, "\n") . "-----END PUBLIC KEY-----\n";
}
const LICENSE_VERIFY_URL = 'https://update.yikaicms.com/api/license/verify.php';
const LICENSE_CHECK_TTL  = 604800;   // 距上次成功校验 < 7 天 不再发起请求（一周校验一次；改 2592000=一个月）
const LICENSE_GRACE      = 2592000;  // 服务器不可达时，缓存最长信任 30 天（须 > CHECK_TTL，避免到点偶发不可达即降级）

/** 客户填写的授权码。 */
function license_key(): string
{
    return trim((string) config('license_key', ''));
}

/** 当前站点域名（用于服务端域名绑定校验）。 */
function license_domain(): string
{
    $h = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($h === '') {
        $h = (string) parse_url((string) config('site_url', ''), PHP_URL_HOST);
    }
    return strtolower(preg_replace('#^www\.#', '', $h) ?? $h);
}

/** 用 RSA 公钥验证服务端 RSA-SHA256 签名（防伪造 + 防离线缓存被篡改）。 */
function license_verify(array $payload, string $sigB64): bool
{
    if (!function_exists('openssl_verify')) {
        return false;   // 无 openssl（极罕见）→ 视为无效，退免费态，不锁站
    }
    ksort($payload);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig  = base64_decode($sigB64, true);
    if ($sig === false || $sig === '') {
        return false;
    }
    return openssl_verify((string) $json, $sig, license_pubkey(), OPENSSL_ALGO_SHA256) === 1;
}

/**
 * 读取缓存的校验结果（含 data / sig / checked_at）。
 * 会复验签名：缓存被直接改过（如手改 license_state 延期）则视为无缓存，返回空。
 */
function license_cache(): array
{
    $raw = (string) config('license_state', '');
    if ($raw === '') {
        return [];
    }
    $d = json_decode($raw, true);
    if (!is_array($d) || empty($d['data']) || !is_array($d['data']) || !isset($d['sig'])) {
        return [];
    }
    // 缓存签名不符 → 已被篡改，丢弃
    if (!license_verify($d['data'], (string) $d['sig'])) {
        return [];
    }
    return $d;
}

/** 免费态（无授权 / 不可用时的兜底）。 */
function license_free(string $reason = 'no_key'): array
{
    return ['valid' => false, 'reason' => $reason, 'plan' => 'free', 'modules' => [], 'expires_at' => null, 'expired' => false];
}

/**
 * 校验授权：自带 24h 节流，服务器不可达则在 7 天宽限内沿用缓存。
 * 返回服务端 data 结构（或免费态）。
 */
function license_refresh(bool $force = false): array
{
    $key = license_key();
    if ($key === '') {
        return license_free('no_key');
    }

    $cache    = license_cache();
    $cachedAt = (int) ($cache['checked_at'] ?? 0);
    $hasCache = !empty($cache['data']);

    // 未到期且非强制 → 直接用缓存，不打扰服务器
    if (!$force && $hasCache && (time() - $cachedAt) < LICENSE_CHECK_TTL) {
        return $cache['data'];
    }

    // &t= 缓存破坏：每次请求 URL 唯一，绕开 update 服务器的 SiteGround 边缘缓存，确保拿到实时签名
    $resp = license_http(LICENSE_VERIFY_URL . '?key=' . urlencode($key) . '&domain=' . urlencode(license_domain()) . '&t=' . time());

    if ($resp !== null) {
        $j = json_decode($resp, true);
        if (is_array($j) && isset($j['data'], $j['sig']) && is_array($j['data'])) {
            // 验签：防止返回被中间篡改 / 缓存被改
            if (license_verify($j['data'], (string) $j['sig'])) {
                settingModel()->saveBatch(['license_state' => json_encode([
                    'data'       => $j['data'],
                    'sig'        => $j['sig'],
                    'checked_at' => time(),
                ], JSON_UNESCAPED_UNICODE)]);
                return $j['data'];
            }
        }
        // 响应异常：不覆盖好缓存，落到下面的宽限逻辑
    }

    // 服务器不可达 / 响应异常：宽限期内沿用缓存
    if ($hasCache && (time() - $cachedAt) < LICENSE_GRACE) {
        return $cache['data'];
    }

    return license_free($hasCache ? 'grace_expired' : 'unreachable');
}

/**
 * 生效中的授权状态：在 refresh 结果基础上，本地再判一次到期
 * （处理两次校验之间刚好跨过期日的情况）。
 */
function license_apply_local_expiry(array $state, ?int $now = null): array
{
    $expiresAt = (string) ($state['expires_at'] ?? '');
    $expiresTimestamp = $expiresAt !== '' ? strtotime($expiresAt . ' 23:59:59') : false;
    if ($expiresTimestamp !== false && $expiresTimestamp < ($now ?? time())) {
        $state['valid'] = false;
        $state['expired'] = true;
        if (($state['reason'] ?? '') === 'ok') {
            $state['reason'] = 'expired';
        }
    }
    return $state;
}

function license(): array
{
    return license_apply_local_expiry(license_refresh(false));
}

/** 授权整体是否有效。 */
function license_valid(): bool
{
    return (bool) (license()['valid'] ?? false);
}

/**
 * 是否拥有某付费模块。模块名见服务端 LICENSE_MODULES。
 *
 * 永久回退授权：**授权码长期有效，到期不收回功能**——买过的模块继续可用。
 * `expires_at` 是**服务期**到期日（自最后一次购买起一年，再次购买即续期），
 * 到期后暂停的是**付费组件与模板包的下载、新装资格，以及技术支持**；
 * 核心 CMS 的版本更新不校验授权，任何站点都能升级。
 * 下载闸在服务端，见 api/plugins/_entitlement.php。
 * 因此这里只看模块归属，不看 valid；授权被停用、域名不符或查无此码时，
 * 服务端本就不下发 modules，故不会误放行。
 */
function license_has_module(string $module): bool
{
    return in_array($module, (array) (license()['modules'] ?? []), true);
}

/** 是否可下载/升级付费插件（到期即失去该资格，但已装功能不受影响） */
/** @param array<string,mixed>|null $state */
function license_service_active(?array $state = null): bool
{
    $state ??= license();
    return !empty($state['valid']) && empty($state['expired']);
}

function license_can_update(): bool
{
    $st = license();
    return license_service_active($st) && !empty($st['modules']);
}

function license_plan(): string
{
    return (string) (license()['plan'] ?? 'free');
}

/** 到期日（Y-m-d）或 null（永久 / 无授权）。 */
function license_expiry(): ?string
{
    $e = license()['expires_at'] ?? null;
    return $e ? (string) $e : null;
}

/**
 * HTTP GET，file_get_contents 优先、curl 兜底，失败返回 null。
 * 超时 6 秒，避免拖慢后台加载。
 */
function license_http(string $url): ?string
{
    // 不校验 TLS 证书：响应真伪由 Ed25519 签名保证（MITM 无私钥伪造不出有效签名），
    // 而老共享主机（如 my3w）常因 CA 包过旧导致 verify_peer 失败、连不上服务器。
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 6, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $r = @file_get_contents($url, false, $ctx);
    if ($r !== false) {
        return $r;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r !== false && $code < 400) {
            return (string) $r;
        }
    }
    return null;
}
