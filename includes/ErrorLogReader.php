<?php
declare(strict_types=1);

/** Read historical log pages without loading the whole monthly file. */
final class ErrorLogReader
{
    private const BLOCK_BYTES = 8192;
    private const PREVIEW_BYTES = 65536;

    /** @return array{entries:list<array{text:string,truncated:bool}>,has_more:bool,page:int,snapshot:int,size:int,readable:bool} */
    public static function readPage(string $path, int $page = 1, int $perPage = 30, ?int $snapshot = null): array
    {
        $page = max(1, min($page, intdiv(PHP_INT_MAX, 100)));
        $perPage = max(1, min(100, $perPage));
        $result = ['entries' => [], 'has_more' => false, 'page' => $page, 'snapshot' => 0, 'size' => 0, 'readable' => false];
        if ($path === '' || !is_file($path)) {
            return $result;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return $result;
        }
        try {
            $stat = fstat($stream);
            if ($stat === false) {
                return $result;
            }
            $size = (int) $stat['size'];
            // Appends do not move page boundaries. A cleared/truncated file starts over.
            if ($snapshot === null || $snapshot < 0 || $snapshot > $size) {
                if ($snapshot !== null) {
                    $page = 1;
                }
                $snapshot = $size;
            }
            $result['page'] = $page;
            $result['size'] = $size;
            $result['snapshot'] = $snapshot;
            $result['readable'] = true;
            $skip = ($page - 1) * $perPage;
            $entry = '';
            $truncated = false;
            foreach (self::reverseLines($stream, $snapshot) as $line) {
                $entry = $line['text'] . "\n" . $entry;
                $truncated = $truncated || $line['truncated'] || strlen($entry) > self::PREVIEW_BYTES;
                $entry = substr($entry, 0, self::PREVIEW_BYTES);
                if (!preg_match('/^\[\d{4}-\d{2}-\d{2} [^\]]+\]/', $line['text'])) {
                    continue;
                }
                if ($skip > 0) {
                    $skip--;
                } elseif (count($result['entries']) < $perPage) {
                    // A byte boundary may split UTF-8; keep HTML escaping from hiding the entire entry.
                    $text = mb_convert_encoding(rtrim($entry, "\r\n"), 'UTF-8', 'UTF-8');
                    $result['entries'][] = ['text' => mb_strcut($text, 0, self::PREVIEW_BYTES, 'UTF-8'), 'truncated' => $truncated];
                } else {
                    $result['has_more'] = true;
                    break;
                }
                $entry = '';
                $truncated = false;
            }
            return $result;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Keep only a bounded prefix even for malformed, unusually long lines.
     * @param resource $stream
     * @return Generator<int,array{text:string,truncated:bool}>
     */
    private static function reverseLines($stream, int $position): Generator
    {
        $carry = '';
        $carryTruncated = false;
        while ($position > 0) {
            $bytes = min(self::BLOCK_BYTES, $position);
            $position -= $bytes;
            if (fseek($stream, $position) !== 0) {
                break;
            }
            $chunk = fread($stream, $bytes);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $lines = explode("\n", $chunk . $carry);
            $carry = (string) array_shift($lines);
            $last = count($lines) - 1;
            for ($index = $last; $index >= 0; $index--) {
                yield ['text' => rtrim($lines[$index], "\r"), 'truncated' => $index === $last && $carryTruncated];
            }
            if ($last >= 0) {
                $carryTruncated = false;
            }
            if (strlen($carry) > self::PREVIEW_BYTES) {
                $carry = substr($carry, 0, self::PREVIEW_BYTES);
                $carryTruncated = true;
            }
        }
        if ($carry !== '') {
            yield ['text' => rtrim($carry, "\r"), 'truncated' => $carryTruncated];
        }
    }
}
