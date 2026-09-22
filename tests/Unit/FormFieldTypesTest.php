<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FormFieldTypesTest extends TestCase
{
    public function testParserConfigurationAndFrontendRenderingShareOneContract(): void
    {
        $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/fixtures/form-field-types-worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('', $errors);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['number', 'date', 'hidden', 'file'], array_column($result['tags'], 'type'));
        self::assertSame(['pdf', 'png'], $result['tags'][3]['accept']);
        self::assertSame(3, $result['tags'][3]['max_size']);
        self::assertSame('configured', $result['tags'][2]['value']);
        self::assertSame([true, true, true, true], $result['valid']);
        self::assertSame([false, false, false, false], $result['invalid']);
        self::assertStringContainsString('min="1.5" max="5.5" step="0.5"', $result['html'][0]);
        self::assertStringContainsString('type="date"', $result['html'][1]);
        self::assertStringContainsString('type="hidden" name="campaign" value="configured"', $result['html'][2]);
        self::assertStringContainsString('type="file"', $result['html'][3]);
        self::assertStringContainsString('accept=".pdf,.png"', $result['html'][3]);
        self::assertStringContainsString(' required', $result['html'][3]);
        self::assertStringContainsString('enctype="multipart/form-data"', (string) file_get_contents(ROOT_PATH . '/includes/functions.php'));
        self::assertSame('', $result['empty_select']['placeholder']);
        self::assertSame(['First'], $result['empty_select']['options']);
        self::assertSame(['First', 'Second'], $result['legacy'][0]['options']);
        self::assertSame(['First', 'Second'], $result['legacy_array'][0]['options']);
        self::assertSame(['A', 'B'], $result['legacy_array'][1]['options']);
        self::assertSame(['One', 'Two'], $result['legacy_array'][2]['options']);
        self::assertSame(1, substr_count($result['legacy_html'], 'value="First"'));
        self::assertSame(1, substr_count($result['legacy_html'], 'value="A"'));
        self::assertSame(1, substr_count($result['legacy_html'], 'value="One"'));
        self::assertStringContainsString('type="file" name="proof"', $result['product_fields']);
        self::assertStringContainsString('type="date" name="visit"', $result['product_fields']);
        self::assertStringContainsString('type="number" name="quantity"', $result['product_fields']);
        self::assertStringContainsString('type="hidden" name="campaign" value="configured"', $result['product_fields']);
        self::assertStringNotContainsString('<label>campaign', $result['product_fields']);
        self::assertStringContainsString('>About Product</textarea>', $result['product_fields']);
        self::assertSame(4, substr_count($result['product_fields'], ' required'));
        self::assertSame(['pdf', 'png'], $result['legacy'][1]['accept']);
        self::assertSame([true, true], $result['legacy_valid']);
        self::assertSame([true, false, false], $result['sets']);
        self::assertStringContainsString('step="any"', $result['any_step']);
        self::assertSame([true, false, false], $result['exact_config']);
    }
}
