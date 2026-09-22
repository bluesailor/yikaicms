<?php
declare(strict_types=1);

/** Exact, locale-independent decimal arithmetic for HTML number fields. */
final class FormDecimal
{
    /** @return array{sign:int,digits:string,scale:int}|null */
    private static function parse(string $value): ?array
    {
        if (preg_match('/^-?(?:\d+|\d*\.\d+)$/D', $value) !== 1) return null;
        $negative = str_starts_with($value, '-');
        if ($negative) $value = substr($value, 1);
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $digits = ltrim($integer . $fraction, '0');
        if ($digits === '') return ['sign' => 0, 'digits' => '0', 'scale' => 0];
        return ['sign' => $negative ? -1 : 1, 'digits' => $digits, 'scale' => strlen($fraction)];
    }

    public static function valid(string $value): bool
    {
        return self::parse($value) !== null;
    }

    public static function compare(string $left, string $right): int
    {
        $a = self::parse($left);
        $b = self::parse($right);
        if ($a === null || $b === null) throw new InvalidArgumentException('Invalid decimal');
        if ($a['sign'] !== $b['sign']) return $a['sign'] <=> $b['sign'];
        if ($a['sign'] === 0) return 0;
        $scale = max($a['scale'], $b['scale']);
        $ad = self::trim($a['digits'] . str_repeat('0', $scale - $a['scale']));
        $bd = self::trim($b['digits'] . str_repeat('0', $scale - $b['scale']));
        $cmp = self::compareUnsigned($ad, $bd);
        return $a['sign'] < 0 ? -$cmp : $cmp;
    }

    public static function stepAligned(string $value, string $base, string $step): bool
    {
        $a = self::parse($value);
        $b = self::parse($base);
        $s = self::parse($step);
        if ($a === null || $b === null || $s === null || $s['sign'] <= 0) return false;
        $scale = max($a['scale'], $b['scale'], $s['scale']);
        $ad = self::trim($a['digits'] . str_repeat('0', $scale - $a['scale']));
        $bd = self::trim($b['digits'] . str_repeat('0', $scale - $b['scale']));
        $sd = self::trim($s['digits'] . str_repeat('0', $scale - $s['scale']));
        if ($a['sign'] === $b['sign']) {
            $comparison = self::compareUnsigned($ad, $bd);
            $difference = $comparison >= 0
                ? self::subtractUnsigned($ad, $bd)
                : self::subtractUnsigned($bd, $ad);
        } else {
            $difference = self::addUnsigned($ad, $bd);
        }
        return self::modUnsigned($difference, $sd) === '0';
    }

    private static function trim(string $digits): string
    {
        $digits = ltrim($digits, '0');
        return $digits === '' ? '0' : $digits;
    }

    private static function compareUnsigned(string $a, string $b): int
    {
        $a = self::trim($a); $b = self::trim($b);
        return strlen($a) === strlen($b) ? (strcmp($a, $b) <=> 0) : (strlen($a) <=> strlen($b));
    }

    private static function addUnsigned(string $a, string $b): string
    {
        $carry = 0; $out = '';
        for ($i = 1; $i <= max(strlen($a), strlen($b)); $i++) {
            $sum = (int) ($a[strlen($a) - $i] ?? '0') + (int) ($b[strlen($b) - $i] ?? '0') + $carry;
            $out = (string) ($sum % 10) . $out; $carry = intdiv($sum, 10);
        }
        return ($carry > 0 ? (string) $carry : '') . $out;
    }

    /** $a must be >= $b. */
    private static function subtractUnsigned(string $a, string $b): string
    {
        $borrow = 0; $out = '';
        for ($i = 1; $i <= strlen($a); $i++) {
            $digit = (int) $a[strlen($a) - $i] - (int) ($b[strlen($b) - $i] ?? '0') - $borrow;
            if ($digit < 0) { $digit += 10; $borrow = 1; } else { $borrow = 0; }
            $out = (string) $digit . $out;
        }
        return self::trim($out);
    }

    private static function modUnsigned(string $number, string $divisor): string
    {
        if ($divisor === '0') return '-1';
        $remainder = '0';
        foreach (str_split(self::trim($number)) as $digit) {
            $remainder = self::trim(($remainder === '0' ? '' : $remainder) . $digit);
            while (self::compareUnsigned($remainder, $divisor) >= 0) {
                $remainder = self::subtractUnsigned($remainder, $divisor);
            }
        }
        return $remainder;
    }
}
