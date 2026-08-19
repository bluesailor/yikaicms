<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/UpdatePackageSignature.php';
require_once ROOT_PATH . '/includes/License.php';

final class UpdatePackageSignatureTest extends TestCase
{
    public function testValidSignatureBindsVersionAndHash(): void
    {
        $hash = 'sha256:bbc752070c60b50d6345ff34a379dda071264f8b32c4957b53c5ea657d0c97ec';
        $encoded = 'ZXmdXQUpmeEKDaD1h2BohiyHI3X66KokoFxrq3YFHSdEJqgbIDGSutlHXV7jZL+UlxBOasFfQHeknAU527K4AgSNFAJC4d/onGwRrZ9tWyONR1szpMeuP54ZsqgGmju5rSGIzdUFe7WZ1pcdM1KXiVXMIElXwepAITjQiCvyKudsYwezLrlKCAEBlRMIJrzLRApsCzDC6mkkSAjJLuBGLhAonbQFEMaGE19P05usieHhyT/1CIC+GrpUGyTH+3XdYQGwrTycBfZzqcWve9TJGC11Dna7XBIyqlYreGC/3OkaBfesySASqze0nt97CfQoCxEwfGJWgCrM1hU/mbRnvA==';
        self::assertSame('1.18.1|' . $hash, UpdatePackageSignature::canonical('1.18.1', strtoupper($hash)));

        self::assertTrue(UpdatePackageSignature::verify('1.18.1', $hash, $encoded, license_pubkey()));
        self::assertFalse(UpdatePackageSignature::verify('1.18.2', $hash, $encoded, license_pubkey()));
        self::assertFalse(UpdatePackageSignature::verify('1.18.1', 'sha256:' . str_repeat('b', 64), $encoded, license_pubkey()));
    }

    public function testMissingAndMalformedSignaturesAreRejected(): void
    {
        $hash = 'sha256:' . str_repeat('b', 64);

        self::assertFalse(UpdatePackageSignature::verify('1.18.2', $hash, '', license_pubkey()));
        self::assertFalse(UpdatePackageSignature::verify('1.18.2', $hash, 'not-base64!', license_pubkey()));
        self::assertFalse(UpdatePackageSignature::verify('invalid', $hash, base64_encode('invalid'), license_pubkey()));
        self::assertFalse(UpdatePackageSignature::verify('1.18.2', 'sha256:bad', base64_encode('invalid'), license_pubkey()));
    }
}
