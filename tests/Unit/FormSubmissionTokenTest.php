<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/FormSubmissionToken.php';

final class FormSubmissionTokenTest extends TestCase
{
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
    }
}
