<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once ROOT_PATH . '/includes/security.php';

final class ProductImportXlsxReader
{
    private const MAX_ROWS = 20000;
    private const MAX_COLUMNS = 200;

    /** @return array{headers:list<string>,rows:list<list<string>>,errors:list<string>} */
    public static function read(string $path): array
    {
        $headers = [];
        $rows = [];
        $errors = [];
        if (!class_exists('ZipArchive')) {
            return ['headers' => [], 'rows' => [], 'errors' => ['服务器不支持 ZipArchive，无法读取 XLSX 文件。请改用 CSV 格式。']];
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['headers' => [], 'rows' => [], 'errors' => ['无法打开 XLSX 文件']];
        }
        try {
            $violation = zipResourceViolation($zip, 200, 33_554_432, 8_388_608, 100);
            if ($violation !== null) {
                return ['headers' => [], 'rows' => [], 'errors' => ['XLSX 资源检查失败：' . $violation]];
            }
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if (!is_string($sheetXml) || $sheetXml === '') {
                return ['headers' => [], 'rows' => [], 'errors' => ['未找到工作表']];
            }

            $shared = is_string($sharedXml) ? self::sharedStrings($sharedXml) : [];
            $sheet = self::xml($sheetXml);
            if ($sheet === null) {
                return ['headers' => [], 'rows' => [], 'errors' => ['工作表 XML 无效']];
            }
            $rowElements = $sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
            if ($rowElements === []) {
                return ['headers' => [], 'rows' => [], 'errors' => ['工作表无数据']];
            }
            if (count($rowElements) > self::MAX_ROWS + 1) {
                return ['headers' => [], 'rows' => [], 'errors' => ['XLSX 数据行超过 20000 行限制']];
            }

            $isHeader = true;
            foreach ($rowElements as $rowElement) {
                $columns = [];
                foreach ($rowElement->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                    $ref = (string) $cell['r'];
                    $column = self::columnNumber($ref);
                    if ($column < 0 || $column >= self::MAX_COLUMNS) {
                        return ['headers' => [], 'rows' => [], 'errors' => ['XLSX 列数超过 200 列限制']];
                    }
                    $valueNodes = $cell->xpath('./*[local-name()="v"]') ?: [];
                    $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';
                    if ((string) $cell['t'] === 's' && $value !== '') {
                        $value = $shared[(int) $value] ?? $value;
                    }
                    $columns[$column] = $value;
                }
                if ($columns === []) {
                    continue;
                }
                if ($isHeader) {
                    $last = max(array_keys($columns));
                    for ($i = 0; $i <= $last; $i++) {
                        $headers[] = trim((string) ($columns[$i] ?? ''));
                    }
                    $isHeader = false;
                    continue;
                }
                $row = [];
                for ($i = 0; $i < count($headers); $i++) {
                    $row[] = (string) ($columns[$i] ?? '');
                }
                if (array_filter($row, static fn(string $value): bool => trim($value) !== '') !== []) {
                    $rows[] = $row;
                }
            }
            return compact('headers', 'rows', 'errors');
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private static function sharedStrings(string $xml): array
    {
        $document = self::xml($xml);
        if ($document === null) return [];
        $strings = [];
        foreach ($document->xpath('//*[local-name()="si"]') ?: [] as $item) {
            $text = '';
            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $node) {
                $text .= (string) $node;
            }
            $strings[] = $text;
        }
        return $strings;
    }

    private static function xml(string $xml): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $value = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            return $value instanceof SimpleXMLElement ? $value : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function columnNumber(string $reference): int
    {
        if (preg_match('/^([A-Z]+)[0-9]+$/i', $reference, $match) !== 1) return -1;
        $number = 0;
        foreach (str_split(strtoupper($match[1])) as $letter) {
            $number = $number * 26 + ord($letter) - 64;
        }
        return $number - 1;
    }
}
