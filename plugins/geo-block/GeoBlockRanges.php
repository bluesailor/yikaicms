<?php
declare(strict_types=1);

/**
 * 中国大陆 IP 段匹配（地区访问限制插件）。
 *
 * 数据来自 APNIC 公开分配统计（delegated-apnic-latest 中国家代码为 CN 的
 * IPv4/IPv6 记录；港澳台是独立代码，不在其中），由 tools/build-data.php 生成：
 * - data/cn-ipv4.bin：按起始地址排序、已合并的区间，每条 8 字节（起止各 4 字节大端）；
 * - data/cn-ipv6.bin：同上，每条 32 字节（起止各 16 字节，inet_pton 二进制）。
 * 查询不展开整张表：直接在二进制串上二分，每次请求约 13–14 次比较。
 *
 * 本文件不注册钩子、不依赖 CMS 函数，可单独加载测试。
 */
final class GeoBlockRanges
{
    private const V4_RECORD = 8;
    private const V6_RECORD = 32;

    private string $v4;
    private string $v6;

    public function __construct(string $v4, string $v6)
    {
        $this->v4 = strlen($v4) % self::V4_RECORD === 0 ? $v4 : '';
        $this->v6 = strlen($v6) % self::V6_RECORD === 0 ? $v6 : '';
    }

    public static function fromDirectory(string $dir): self
    {
        $read = static fn (string $file): string => is_file($dir . '/' . $file) ? (string) file_get_contents($dir . '/' . $file) : '';
        return new self($read('cn-ipv4.bin'), $read('cn-ipv6.bin'));
    }

    /** 插件自带数据（同请求只读一次）。 */
    public static function bundled(): self
    {
        static $instance = null;
        return $instance ??= self::fromDirectory(__DIR__ . '/data');
    }

    public function isEmpty(): bool
    {
        return $this->v4 === '' && $this->v6 === '';
    }

    /** @return array{ipv4:int,ipv6:int} 区间条数 */
    public function counts(): array
    {
        return ['ipv4' => intdiv(strlen($this->v4), self::V4_RECORD), 'ipv6' => intdiv(strlen($this->v6), self::V6_RECORD)];
    }

    /** 是否中国大陆地址。非法地址返回 false（不拦截）。 */
    public function contains(string $ip): bool
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return false;
        }
        // IPv4 映射的 IPv6（::ffff:a.b.c.d）按 IPv4 查
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        return strlen($packed) === 4
            ? $this->search($this->v4, self::V4_RECORD, $packed)
            : $this->search($this->v6, self::V6_RECORD, $packed);
    }

    /** 找最后一个起点 ≤ 地址的区间，再看终点是否 ≥ 地址。定长大端二进制串的字典序即数值序。 */
    private function search(string $table, int $record, string $address): bool
    {
        $half = intdiv($record, 2);
        $low = 0;
        $high = intdiv(strlen($table), $record) - 1;
        $found = -1;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            if (strcmp(substr($table, $mid * $record, $half), $address) <= 0) {
                $found = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        return $found >= 0 && strcmp($address, substr($table, $found * $record + $half, $half)) <= 0;
    }

    /**
     * 解析 APNIC delegated 统计文本，返回合并后的二进制表（供构建工具与测试使用）。
     *
     * @return array{ipv4:string,ipv6:string,ipv4_addresses:int,date:string}
     */
    public static function buildFromDelegated(string $text, string $country = 'CN'): array
    {
        $v4 = [];
        $v6 = [];
        $date = '';
        $addresses = 0;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $parts = explode('|', trim($line));
            if ($date === '' && count($parts) >= 6 && ctype_digit($parts[0]) && preg_match('/^\d{8}$/', $parts[5] ?? '')) {
                $date = substr($parts[5], 0, 4) . '-' . substr($parts[5], 4, 2) . '-' . substr($parts[5], 6, 2);
            }
            if (count($parts) < 7 || $parts[1] !== $country || !in_array($parts[6], ['allocated', 'assigned'], true)) {
                continue;
            }
            $start = @inet_pton($parts[3]);
            if ($start === false || !ctype_digit($parts[4])) {
                continue;
            }
            if ($parts[2] === 'ipv4' && strlen($start) === 4) {
                $first = unpack('N', $start)[1];
                $size = (int) $parts[4];
                $v4[] = [$first, $first + $size - 1];
                $addresses += $size;
            } elseif ($parts[2] === 'ipv6' && strlen($start) === 16) {
                $prefix = (int) $parts[4];
                if ($prefix < 1 || $prefix > 128) {
                    continue;
                }
                $v6[] = [$start, self::v6End($start, $prefix)];
            }
        }
        usort($v4, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        usort($v6, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $packV4 = '';
        foreach (self::merge($v4, static fn ($end, $start): bool => $start <= $end + 1, static fn ($a, $b) => max($a, $b)) as [$s, $e]) {
            $packV4 .= pack('NN', $s, $e);
        }
        $packV6 = '';
        foreach (self::merge($v6, static fn ($end, $start): bool => strcmp($start, self::v6Next($end)) <= 0, static fn ($a, $b) => strcmp($a, $b) >= 0 ? $a : $b) as [$s, $e]) {
            $packV6 .= $s . $e;
        }
        return ['ipv4' => $packV4, 'ipv6' => $packV6, 'ipv4_addresses' => $addresses, 'date' => $date];
    }

    /**
     * @param list<array{0:mixed,1:mixed}> $ranges 已按起点排序
     * @param callable(mixed,mixed):bool $touches @param callable(mixed,mixed):mixed $max
     * @return list<array{0:mixed,1:mixed}>
     */
    private static function merge(array $ranges, callable $touches, callable $max): array
    {
        $out = [];
        foreach ($ranges as $range) {
            $last = count($out) - 1;
            if ($last >= 0 && $touches($out[$last][1], $range[0])) {
                $out[$last][1] = $max($out[$last][1], $range[1]);
            } else {
                $out[] = $range;
            }
        }
        return $out;
    }

    private static function v6End(string $start, int $prefix): string
    {
        $bytes = array_values(unpack('C16', $start));
        for ($bit = $prefix; $bit < 128; $bit++) {
            $bytes[intdiv($bit, 8)] |= 0x80 >> ($bit % 8);
        }
        return pack('C16', ...$bytes);
    }

    /** 下一个地址（全 1 时原样返回，合并判断里不会越界）。 */
    private static function v6Next(string $address): string
    {
        $bytes = array_values(unpack('C16', $address));
        for ($i = 15; $i >= 0; $i--) {
            if ($bytes[$i] < 255) {
                $bytes[$i]++;
                return pack('C16', ...$bytes);
            }
            $bytes[$i] = 0;
        }
        return $address;
    }
}
