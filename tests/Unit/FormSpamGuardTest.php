<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/FormSpamGuard.php';

final class FormSpamGuardTest extends TestCase
{
    private string $directory;
    private FormSpamGuard $guard;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/yikai-form-guard-' . bin2hex(random_bytes(6));
        $this->guard = new FormSpamGuard($this->directory, 'test-secret');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) unlink($file);
        if (is_dir($this->directory)) rmdir($this->directory);
    }

    public function testMalformedAndOversizedPayloadsAreRejected(): void
    {
        foreach ([['phone' => [['injection']]], ['form_slug' => ['contact']], ['name' => "\xFF"], ['name' => "a\0b"], ['content' => str_repeat('a', 48001)]] as $payload) {
            self::assertFalse(FormSpamGuard::validPayload($payload));
        }
        self::assertTrue(FormSpamGuard::validPayload(['choices' => ['a', 'b'], 'content' => "你好\nHello 日本語"]));
    }

    public function testScalarArrayAndCheckboxNestingCannotBeCoerced(): void
    {
        foreach ([['text', ['x']], ['checkbox', [['x']]]] as [$type, $raw]) {
            try {
                FormSpamGuard::fieldValue(['key' => 'example', 'type' => $type], $raw);
                self::fail('Malformed field accepted');
            } catch (InvalidArgumentException) { self::assertTrue(true); }
        }
        self::assertSame('a, b', FormSpamGuard::fieldValue(['type' => 'checkbox'], [' a ', '', 'b']));
    }

    public function testSqlProbeTelephoneAndColumnOverflowsAreRejected(): void
    {
        foreach ([['phone', '-1\" OR 5*5=25 --'], ['phone', "-1' OR 5*5=25 --"], ['phone', 'abc'], ['name', str_repeat('名', 51)], ['company', str_repeat('x', 101)], ['email', 'bad@']] as [$key, $value]) {
            try {
                FormSpamGuard::fieldValue(['key' => $key], $value);
                self::fail('Invalid field accepted');
            } catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testLegitimateMultilingualAndInternationalInputIsPreserved(): void
    {
        foreach (['+86 138-0000-0000', '+81 (3) 1234-5678', '555-666-0606', '03-1234-5678 内線12'] as $phone) {
            self::assertSame($phone, FormSpamGuard::fieldValue(['type' => 'tel', 'key' => 'phone'], $phone));
        }
        foreach (["O'Reilly", '山田太郎', '张三'] as $name) {
            self::assertSame($name, FormSpamGuard::fieldValue(['key' => 'name'], $name));
        }
        $text = 'Please explain SELECT * FROM products; SQL "OR" is a question, not spam.';
        self::assertSame($text, FormSpamGuard::fieldValue(['type' => 'textarea'], $text));
    }

    public function testAttemptsShareAnIpBudgetAndExpireWithoutExtendingOnRejection(): void
    {
        self::assertSame(0, $this->guard->attempt('ip-a', 2, 60, 1000));
        self::assertSame(0, $this->guard->attempt('ip-a', 2, 60, 1001));
        self::assertSame(58, $this->guard->attempt('ip-a', 2, 60, 1002));
        self::assertSame(0, $this->guard->attempt('ip-b', 2, 60, 1002));
        self::assertSame(0, $this->guard->attempt('ip-a', 2, 60, 1060));
    }

    public function testDuplicateIsIndependentOfFieldOrderAndNeverInvokesPersistence(): void
    {
        $calls = 0;
        $persist = static function () use (&$calls): int { return ++$calls; };
        self::assertSame(1, $this->guard->submit('ip', ['name' => 'Visitor', 'content' => 'Hello'], 5, 300, $persist, 1000)['id']);
        self::assertSame('duplicate', $this->guard->submit('ip', ['content' => 'Hello', 'name' => 'Visitor'], 5, 300, $persist, 1001)['reason']);
        self::assertSame(1, $calls);
        self::assertSame('', $this->guard->submit('other-ip', ['name' => 'Visitor', 'content' => 'Hello'], 5, 300, $persist, 1001)['reason']);
        self::assertSame('', $this->guard->submit('ip', ['name' => 'Visitor', 'content' => 'Hello'], 5, 300, $persist, 1600)['reason']);
    }

    public function testSuccessfulSubmissionBudgetBlocksDifferentPayloads(): void
    {
        $persist = static fn(): int => 1;
        self::assertSame('', $this->guard->submit('ip', ['content' => 'one'], 1, 60, $persist, 1000)['reason']);
        self::assertSame('throttle', $this->guard->submit('ip', ['content' => 'two'], 1, 60, $persist, 1001)['reason']);
        self::assertSame('', $this->guard->submit('ip', ['content' => 'two'], 1, 60, $persist, 1060)['reason']);
    }

    public function testFailedPersistenceDoesNotBurnSubmissionOrDuplicateBudget(): void
    {
        try {
            $this->guard->submit('ip', ['content' => 'one'], 1, 60, static function (): int { throw new RuntimeException('Database unavailable'); }, 1000);
            self::fail('Expected failure');
        } catch (RuntimeException $error) { self::assertSame('Database unavailable', $error->getMessage()); }
        self::assertSame('', $this->guard->submit('ip', ['content' => 'one'], 1, 60, static fn(): int => 1, 1001)['reason']);
    }

    public function testStorageContainsNoPlaintextPersonalData(): void
    {
        $this->guard->submit('192.0.2.41', ['name' => 'Private Visitor', 'email' => 'private@example.invalid', 'content' => 'Private body'], 5, 300, static fn(): int => 1, 1000);
        $file = (glob($this->directory . '/*.json') ?: [])[0];
        $stored = $file . file_get_contents($file);
        foreach (['192.0.2.41', 'Private Visitor', 'private@example.invalid', 'Private body'] as $text) self::assertStringNotContainsString($text, $stored);
    }

    public function testCorruptedStateFailsClosed(): void
    {
        $this->guard->attempt('ip', 20, 300, 1000);
        $file = (glob($this->directory . '/*.json') ?: [])[0];
        file_put_contents($file, '{broken');
        $this->expectException(JsonException::class);
        $this->guard->submit('ip', ['content' => 'one'], 1, 60, static function (): int { self::fail('Persistence must not run'); }, 1001);
    }

    public function testConcurrentProcessesPersistAnIdenticalSubmissionOnlyOnce(): void
    {
        // Distinct PHP processes avoid the serialization of the built-in HTTP server.
        mkdir($this->directory);
        $workers = [];
        for ($i = 0; $i < 8; $i++) {
            $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/fixtures/form-spam-worker.php', $this->directory],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame('', $errors);
        }
        self::assertSame(1, count(array_filter($results, static fn(string $result): bool => $result === 'accepted')));
        self::assertSame(7, count(array_filter($results, static fn(string $result): bool => $result === 'duplicate')));
        self::assertSame("saved\n", file_get_contents($this->directory . '/persisted.txt'));
    }

    public function testContentFilterBlocksTooManyLinksAndConfiguredKeywords(): void
    {
        $keywords = FormSpamGuard::keywordList("  Casino 

代开发票
casino
" . str_repeat('x', 101));
        self::assertSame(['Casino', '代开发票'], $keywords);

        self::assertFalse(FormSpamGuard::blockedContent('', $keywords, 3));
        self::assertFalse(FormSpamGuard::blockedContent('想咨询产品报价', $keywords, 3));
        self::assertTrue(FormSpamGuard::blockedContent('Best CASINO bonus', $keywords, 3));
        self::assertTrue(FormSpamGuard::blockedContent('可以代开发票吗', $keywords, 3));

        $links = 'https://a.test https://b.test www.c.test';
        self::assertFalse(FormSpamGuard::blockedContent($links, [], 3));
        self::assertTrue(FormSpamGuard::blockedContent($links . ' https://d.test', [], 3));
        self::assertTrue(FormSpamGuard::blockedContent('see https://a.test', [], 0));
        self::assertFalse(FormSpamGuard::blockedContent('no links here', [], 0));
        self::assertCount(500, FormSpamGuard::keywordList(implode("
", array_map(static fn(int $i): string => 'k' . $i, range(1, 600)))));
    }
}
