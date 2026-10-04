<?php
/**
 * 凭据不外泄（2.0.4）：SMTP 密码不回显到后台页面；带令牌的外发请求必须校验 HTTPS 证书。
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecretExposureTest extends TestCase
{
    public function testSmtpPasswordIsNeverEchoedBack(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/setting_email.php');
        self::assertDoesNotMatchRegularExpression('/name="settings\[smtp_pass\]"[^>]*value="<\?php/', $source, '已保存的密码不能出现在页面源码里');
        self::assertStringContainsString('type="password" name="settings[smtp_pass]" value=""', $source);
        self::assertStringContainsString("unset(\$settings['smtp_pass'])", $source, '留空表示不修改，不能把密码清空');
    }

    public function testOutboundRequestsVerifyTls(): void
    {
        foreach (['plugins', 'includes', 'admin', 'api'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                self::assertDoesNotMatchRegularExpression('/CURLOPT_SSL_VERIFYPEER\s*(=>|,)\s*(false|0)\b/i', $source, $file->getPathname() . ' 关闭了证书校验');
                self::assertDoesNotMatchRegularExpression('/CURLOPT_SSL_VERIFYHOST\s*(=>|,)\s*(false|0)\b/i', $source, $file->getPathname() . ' 关闭了主机名校验');
            }
        }
    }
}
