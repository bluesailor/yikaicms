<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/i18n/LanguagePacks.php';
require_once ROOT_PATH . '/tools/LangPackZip.php';

/** tools/build-lang-packs.php 与 build.sh 的接线：包内容、可复现、以及「增量包不删存量站语言文件」。 */
final class LanguagePackBuildTest extends TestCase
{
    public function testListMatchesDownloadableLanguages(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tools/build-lang-packs.php') . ' list', $lines, $exit);
        self::assertSame(0, $exit);
        self::assertSame(array_map(static fn(string $c): string => "lang/{$c}.php", LanguagePacks::downloadable()), $lines);
    }

    public function testBuildIsReproducibleAndPacksInstallOnceSigned(): void
    {
        $outA = sys_get_temp_dir() . '/yk-lpb-' . bin2hex(random_bytes(4));
        $outB = $outA . '-b';
        foreach ([$outA, $outB] as $out) {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tools/build-lang-packs.php')
                . ' build ' . escapeshellarg(ROOT_PATH) . ' 9.9.9 ' . escapeshellarg($out) . ' 2>&1', $output, $exit);
            self::assertSame(0, $exit, implode("\n", $output));
        }
        $summary = json_decode((string) file_get_contents($outA . '/lang-packs-v9.9.9.json'), true);
        self::assertSame(LanguagePacks::downloadable(), array_keys($summary['packs']));
        foreach ($summary['packs'] as $code => $row) {
            self::assertSame(LanguagePacks::packageName($code, '9.9.9'), $row['file']);
            self::assertSame(hash_file('sha256', $outA . '/' . $row['file']), hash_file('sha256', $outB . '/' . $row['file']), "{$code} 两次构建字节应相同");
            self::assertFalse($row['signed']);
            self::assertSame(hash_file('sha256', ROOT_PATH . "/lang/{$code}.php"), $row['php_sha256']);
        }

        // 构建产物加上正确签名即可被站点安装（签名步骤由发版机的 sign-lang-packs.php 做，这里用测试密钥模拟）
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = (string) openssl_pkey_get_details($key)['key'];
        $zip = new ZipArchive();
        $zip->open($outA . '/' . $summary['packs']['vi']['file']);
        $meta = json_decode((string) $zip->getFromName('pack.json'), true);
        $php = (string) $zip->getFromName('lang/vi.php');
        $zip->close();
        self::assertSame('', $meta['signature'], '构建产物不得自带签名（CI 没有私钥）');
        openssl_sign(LanguagePacks::canonical('vi', '9.9.9', $meta['sha256']), $raw, $private, OPENSSL_ALGO_SHA256);
        $meta['signature'] = base64_encode($raw);
        $signed = $outA . '/signed.zip';
        langPackZip($signed, 'vi', $php, $meta);

        $site = $outA . '/site';
        mkdir($site . '/lang', 0777, true);
        mkdir($site . '/storage', 0777, true);
        self::assertSame(['code' => 'vi', 'version' => '9.9.9'], LanguagePacks::installFromZip($site, (string) file_get_contents($signed), '9.9.9', $public));
        self::assertSame(require ROOT_PATH . '/lang/vi.php', require $site . '/lang/vi.php');
    }

    public function testBuildShipsOnlyBundledLanguagesAndNeverDeletesInstalledPacks(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        $listAt = strpos($build, 'done < <(php tools/build-lang-packs.php list)');
        self::assertNotFalse($listAt, '非内置语言必须并入 EXCLUDES');
        // 并入 EXCLUDES 必须在实际删除之前，且早于 path_never_shipped 被增量包使用——
        // 进了 EXCLUDES 的路径，增量包不会把它列进 deleted，存量站上已装的语言包得以保留
        self::assertLessThan(strpos($build, 'for item in "${EXCLUDES[@]}"; do'), $listAt);
        self::assertLessThan(strpos($build, 'path_never_shipped "$path" && continue'), $listAt);
        self::assertStringContainsString('php tools/build-lang-packs.php build "$LANG_PACK_SRC" "$VERSION" "$LANG_PACK_OUT"', $build);
        self::assertStringNotContainsString('php tools/sign-lang-packs.php', $build, '签名不进 build.sh：CI 没有私钥');
    }
}
