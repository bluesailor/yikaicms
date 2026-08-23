<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/AdminLogSanitizer.php';

final class AdminLogSanitizerTest extends TestCase
{
    public function testNestedSecretsAreRedactedWithoutDestroyingUsefulFields(): void
    {
        $json = AdminLogSanitizer::requestData([
            'title' => 'Keep me',
            '_token' => 'csrf-value',
            'smtp' => ['SMTP_PASS' => 'mail-secret', 'host' => 'smtp.example.test'],
            'license-key' => 'license-secret',
            'token_limit' => '2000',
        ]);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Keep me', $data['title']);
        self::assertSame('[REDACTED]', $data['_token']);
        self::assertSame('[REDACTED]', $data['smtp']['SMTP_PASS']);
        self::assertSame('smtp.example.test', $data['smtp']['host']);
        self::assertSame('[REDACTED]', $data['license-key']);
        self::assertSame('2000', $data['token_limit']);
    }

    public function testUrlQuerySecretsAreRedacted(): void
    {
        $url = AdminLogSanitizer::url('/admin/settings.php?tab=mail&api_key=abc&nested%5Btoken%5D=def');

        self::assertStringContainsString('tab=mail', $url);
        self::assertStringContainsString('api_key=%5BREDACTED%5D', $url);
        self::assertStringContainsString('nested%5Btoken%5D=%5BREDACTED%5D', $url);
        self::assertStringNotContainsString('abc', $url);
        self::assertStringNotContainsString('def', $url);
    }

    public function testLargePayloadStoresOnlyMetadataAndDigest(): void
    {
        $json = AdminLogSanitizer::requestData(['content' => str_repeat('x', 20000)]);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['_truncated']);
        self::assertGreaterThan(20000, $data['size']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['sha256']);
        self::assertLessThan(256, strlen($json));
    }
}
