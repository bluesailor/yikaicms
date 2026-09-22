<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/FormSubmissionToken.php';

final class FormSubmissionTokenTest extends TestCase
{
    public function testProductContextSignatureCannotBeReusedForAnotherId(): void
    {
        $signature = FormSubmissionToken::contextSign('product-inquiry', 12, 'secret');
        self::assertTrue(FormSubmissionToken::contextVerify('product-inquiry', 12, $signature, 'secret'));
        self::assertFalse(FormSubmissionToken::contextVerify('product-inquiry', 13, $signature, 'secret'));
        self::assertFalse(FormSubmissionToken::contextVerify('contact', 12, $signature, 'secret'));
    }

    public function testNewSignatureBindsSlugAndTimestamp(): void
    {
        $signature = FormSubmissionToken::sign('contact', 1000, 'secret');
        self::assertTrue(FormSubmissionToken::verify('contact', 1000, $signature, 'secret', false, 0, 1010));
        self::assertFalse(FormSubmissionToken::verify('other', 1000, $signature, 'secret', false, 0, 1010));
        self::assertFalse(FormSubmissionToken::verify('contact', 1001, $signature, 'secret', false, 0, 1010));
    }

    public function testLegacySignatureIsAcceptedOnlyDuringCompatibilityMode(): void
    {
        $signature = FormSubmissionToken::legacySign(1000, 'secret');
        self::assertTrue(FormSubmissionToken::verify('contact', 1000, $signature, 'secret', true, 0, 1010));
        self::assertFalse(FormSubmissionToken::verify('contact', 1000, $signature, 'secret', false, 0, 1010));
    }

    public function testFutureAndExpiredTokensAreRejected(): void
    {
        $signature = FormSubmissionToken::sign('contact', 1000, 'secret');
        self::assertFalse(FormSubmissionToken::verify('contact', 1000, $signature, 'secret', false, 30, 999));
        self::assertFalse(FormSubmissionToken::verify('contact', 1000, $signature, 'secret', false, 30, 1031));
        self::assertTrue(FormSubmissionToken::verify('contact', 1000, $signature, 'secret', false, 30, 1030));
    }

    public function testSubmissionEndpointRejectsInvalidProvidedSignature(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/form_submit.php');
        self::assertStringContainsString('if (!$validSignature)', $source);
        self::assertStringContainsString("config('form_security_version', '1')", $source);
        self::assertStringContainsString('if ($securityVersion >= 2)', $source);
        self::assertStringContainsString('FormSubmissionNonce::consume($slug, $nonce, $secret)', $source);
        self::assertStringContainsString('session_write_close()', $source);

        $nonceEndpoint = (string) file_get_contents(ROOT_PATH . '/form_nonce.php');
        self::assertStringContainsString('Cache-Control: no-store', $nonceEndpoint);
        self::assertStringContainsString('FormSubmissionNonce::issue($slug, $secret)', $nonceEndpoint);
        $renderer = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString('name="form_nonce" value=""', $renderer, 'Cacheable HTML must contain no reusable nonce');
        self::assertStringContainsString('cache:"no-store"', $renderer);
    }
}
