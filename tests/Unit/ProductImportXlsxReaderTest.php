<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/plugins/product-import/XlsxReader.php';

final class ProductImportXlsxReaderTest extends TestCase
{
    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '') @unlink($this->path);
    }

    public function testReadsSharedStringsAndRowsWithinLimits(): void
    {
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>12.5</v></c></row>'
            . '</sheetData></worksheet>';
        $shared = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<si><t>Title</t></si><si><t>Price</t></si><si><t>Example</t></si></sst>';
        $this->createXlsx($sheet, $shared);

        $result = ProductImportXlsxReader::read($this->path);

        self::assertSame([], $result['errors']);
        self::assertSame(['Title', 'Price'], $result['headers']);
        self::assertSame([['Example', '12.5']], $result['rows']);
    }

    public function testRejectsWorksheetBeyondColumnLimit(): void
    {
        $sheet = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="IW1"><v>bad</v></c></row></sheetData></worksheet>';
        $this->createXlsx($sheet, '');

        $result = ProductImportXlsxReader::read($this->path);
        self::assertStringContainsString('200 列', $result['errors'][0]);
    }

    public function testLegacyXlsIsNotAdvertisedOrAccepted(): void
    {
        $upload = (string) file_get_contents(ROOT_PATH . '/plugins/product-import/upload_handler.php');
        $admin = (string) file_get_contents(ROOT_PATH . '/plugins/product-import/admin.php');
        self::assertStringNotContainsString("'xlsx', 'xls'", $upload);
        self::assertStringNotContainsString('.xlsx,.xls', $admin);
    }

    private function createXlsx(string $sheet, string $shared): void
    {
        $this->path = sys_get_temp_dir() . '/yk_xlsx_' . bin2hex(random_bytes(5)) . '.xlsx';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        if ($shared !== '') $zip->addFromString('xl/sharedStrings.xml', $shared);
        $zip->close();
    }
}
