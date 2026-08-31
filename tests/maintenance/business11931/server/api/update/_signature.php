<?php

declare(strict_types=1);

const UPDATE_SIGNATURE_REQUIRED_FROM = '1.18.2';
const UPDATE_SIGNATURE_PUBKEY_B64 = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEArnsKQEK5P4EFfu6K3j2UMHPTK8ezqso/RpklPg1ohuP+u8eTotsGXn6Y29QODUL6JVXLlaIhpfOa3eq+KuRM58grQRAWtWWRfJV1GdcXQE9SZ5NVax1AbbvaqSbofjx1LazQdPG+X9VuZoatm/eiLNWsue+XR9lg/89+OYPx9kBlL9YEX3hbtO373xIoD35FkAVoilXtOJX+4tJjUpUWLsZEGcZ9eZeUMWVlxc2ElymPre1wvo1erJ7C6RQ+Z1hYKzphKSYEfewSxvXpXykIjeZsFxFHXMEMfagPtuGMIzZoXrMa8JwiHKwV1kfO23KzIcb0aLho+wwSP7T4dHcsKQIDAQAB';

function updateSignatureIsRequired(string $version): bool
{
    return version_compare($version, UPDATE_SIGNATURE_REQUIRED_FROM, '>=');
}

function updateSignatureCanonical(string $version, string $hash): string
{
    $version = trim($version);
    $hash = strtolower(trim($hash));
    if (preg_match('/^\d+\.\d+(?:\.\d+){0,2}$/', $version) !== 1) {
        throw new InvalidArgumentException('版本号格式无效');
    }
    if (preg_match('/^sha256:[a-f0-9]{64}$/', $hash) !== 1) {
        throw new InvalidArgumentException('SHA256 格式无效');
    }
    return $version . '|' . $hash;
}

function updateSignaturePrivateKey(): string
{
    $file = dirname(__DIR__, 2) . '/data/license_rsa.php';
    $raw = is_file($file) ? (string) file_get_contents($file) : '';
    $key = (string) preg_replace('/^<\?php[^\r\n]*(?:\r\n|\n|\r)/', '', $raw, 1);
    if (!str_contains($key, 'BEGIN PRIVATE KEY')) {
        throw new RuntimeException('更新签名私钥不可用');
    }
    return $key;
}

function updateSignatureSign(string $version, string $hash): string
{
    if (!function_exists('openssl_sign')) {
        throw new RuntimeException('OpenSSL 签名能力不可用');
    }
    $signature = '';
    if (!openssl_sign(updateSignatureCanonical($version, $hash), $signature, updateSignaturePrivateKey(), OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('升级包签名失败');
    }
    return base64_encode($signature);
}

function updateSignaturePublicKey(): string
{
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(UPDATE_SIGNATURE_PUBKEY_B64, 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function updateSignatureVerify(string $version, string $hash, string $signature): bool
{
    if (!function_exists('openssl_verify') || trim($signature) === '') {
        return false;
    }
    $decoded = base64_decode($signature, true);
    if ($decoded === false || $decoded === '') {
        return false;
    }
    try {
        $public = updateSignaturePublicKey();
        return $public !== ''
            && openssl_verify(updateSignatureCanonical($version, $hash), $decoded, $public, OPENSSL_ALGO_SHA256) === 1;
    } catch (Throwable) {
        return false;
    }
}

/** @param array<string,mixed> $metadata */
function updateSignatureMetadataIsValid(string $version, array $metadata): bool
{
    return updateSignatureVerify(
        $version,
        (string) ($metadata['hash'] ?? ''),
        (string) ($metadata['sig'] ?? '')
    );
}

/**
 * @param array<string,mixed> $release
 * @return list<string>
 */
function updateSignatureReleaseArtifactErrors(array $release, string $packagesDir): array
{
    $version = trim((string) ($release['version'] ?? ''));
    $errors = [];
    $artifacts = [['label' => '完整包', 'meta' => $release]];
    foreach ((array) ($release['deltas'] ?? []) as $delta) {
        if (is_array($delta)) {
            $artifacts[] = ['label' => 'delta ' . (string) ($delta['from'] ?? '?'), 'meta' => $delta];
        }
    }

    foreach ($artifacts as $artifact) {
        $label = $artifact['label'];
        $meta = $artifact['meta'];
        $package = basename((string) ($meta['package'] ?? ''));
        if ($package === '' || $package !== (string) ($meta['package'] ?? '')) {
            $errors[] = $label . '包名无效';
            continue;
        }
        $path = rtrim($packagesDir, '/\\') . '/' . $package;
        if (!is_file($path)) {
            $errors[] = $label . '文件不存在：' . $package;
            continue;
        }
        $actualHash = 'sha256:' . strtolower((string) hash_file('sha256', $path));
        if (!hash_equals($actualHash, strtolower((string) ($meta['hash'] ?? '')))) {
            $errors[] = $label . '哈希不匹配：' . $package;
            continue;
        }
        if (!updateSignatureMetadataIsValid($version, $meta)) {
            $errors[] = $label . '签名无效：' . $package;
        }
    }
    return $errors;
}
