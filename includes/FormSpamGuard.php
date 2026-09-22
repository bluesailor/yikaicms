<?php
declare(strict_types=1);

require_once __DIR__ . '/FormDecimal.php';
require_once __DIR__ . '/FormFieldContract.php';

/** Local, bounded submission budgets. No names, addresses or message bodies are stored. */
final class FormSpamGuard
{
    public function __construct(private string $directory, private string $secret)
    {
    }

    public static function validPayload(array $input): bool
    {
        if (count($input) > 100) return false;
        $bytes = 0;
        foreach ($input as $value) {
            $values = is_array($value) ? $value : [$value];
            if (count($values) > 50) return false;
            foreach ($values as $item) {
                if (!is_string($item) || !mb_check_encoding($item, 'UTF-8')) return false;
                $bytes += strlen($item);
                if ($bytes > 48000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $item)) return false;
            }
        }
        foreach (['hp_url', 'form_slug', 'form_ts', 'form_sig', 'form_nonce', 'captcha_code', 'product_id', 'product_title', 'product_sig'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) return false;
        }
        return strlen((string) ($input['form_slug'] ?? '')) <= 100
            && mb_strlen((string) ($input['product_title'] ?? '')) <= 255;
    }

    /**
     * Reject only requests that browsers explicitly identify as foreign-site traffic.
     * Missing Fetch Metadata/Origin stays compatible with older browsers and HTTP clients.
     */
    public static function isExplicitCrossSite(array $server, string $siteBaseUrl): bool
    {
        $fetchSite = strtolower(trim((string) ($server['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($fetchSite === 'cross-site') return true;

        $origin = trim((string) ($server['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') return false;
        $originKey = self::httpOrigin($origin);
        if ($originKey === null) return true;

        $allowed = [];
        $configured = self::httpOrigin($siteBaseUrl);
        if ($configured !== null) $allowed[$configured] = true;

        $host = trim((string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
        if ($host !== '' && preg_match('/^[^\x00-\x20\x7F]+$/D', $host) === 1) {
            $https = (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off')
                || (int) ($server['SERVER_PORT'] ?? 0) === 443;
            $forwarded = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if (in_array($forwarded, ['http', 'https'], true)) $https = $forwarded === 'https';
            $request = self::httpOrigin(($https ? 'https' : 'http') . '://' . $host);
            if ($request !== null) $allowed[$request] = true;
        }
        return !isset($allowed[$originKey]);
    }

    private static function httpOrigin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) return null;
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return null;
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) return null;
        return $scheme . '://' . $host . ':' . $port;
    }

    /** 屏蔽关键词：每行一个，去空行去重，最多 500 条、每条 100 字。 */
    public static function keywordList(string $raw): array
    {
        $keywords = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) > 100) continue;
            $keywords[mb_strtolower($line)] ??= $line;
            if (count($keywords) >= 500) break;
        }
        return array_values($keywords);
    }

    /**
     * 内容过滤：链接数超过上限，或命中屏蔽关键词（不区分大小写的子串匹配）。
     * 命中时由调用方假装成功、不入库，避免被机器人探测规则。
     *
     * @param list<string> $keywords
     */
    public static function blockedContent(string $content, array $keywords, int $maxLinks): bool
    {
        if ($content === '') return false;
        if (preg_match_all('~https?://|www\.~i', $content) > max(0, $maxLinks)) return true;
        $haystack = mb_strtolower($content);
        foreach ($keywords as $keyword) {
            $keyword = mb_strtolower(trim($keyword));
            if ($keyword !== '' && str_contains($haystack, $keyword)) return true;
        }
        return false;
    }

    /** Throws for malformed values rather than coercing arrays to strings. */
    public static function fieldValue(array $field, mixed $raw): string
    {
        $key = (string) ($field['key'] ?? $field['name'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        if ($type === 'hidden') {
            // 隐藏字段是站点配置，不是可信客户端输入；即使请求体同名值被篡改也忽略。
            $value = (string) ($field['value'] ?? '');
        } elseif (in_array($type, ['select', 'radio', 'checkbox'], true)) {
            $value = FormFieldContract::choiceValue($type, $raw, $field['options'] ?? []);
        } else {
            if (!is_string($raw)) throw new InvalidArgumentException('Invalid scalar');
            $value = trim($raw);
        }
        $limit = ['name' => 50, 'phone' => 20, 'email' => 100, 'company' => 100][$key]
            ?? ($type === 'textarea' ? 20000 : 2000);
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
            || (!empty($field['required']) && $value === '')) {
            throw new InvalidArgumentException('Invalid field length');
        }
        if ($value !== '' && ($type === 'email' || $key === 'email') && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email');
        }
        if ($value !== '' && ($type === 'tel' || $key === 'phone')) {
            $phone = mb_convert_kana($value, 'n', 'UTF-8');
            if (!preg_match('/^\+?[0-9][0-9 ().\-]*(?:(?:ext\.?|x|#|内線|转)\s*[0-9]{1,8})?$/iu', $phone)
                || preg_match_all('/[0-9]/', $phone) < 3) {
                throw new InvalidArgumentException('Invalid phone');
            }
        }
        if ($value !== '' && $type === 'number') self::assertNumber($field, $value);
        if ($value !== '' && $type === 'date') self::assertDate($field, $value);
        return $value;
    }

    private static function assertNumber(array $field, string $value): void
    {
        if (!FormDecimal::valid($value)) throw new InvalidArgumentException('Invalid number');
        $min = (string) ($field['min'] ?? '');
        $max = (string) ($field['max'] ?? '');
        $step = (string) ($field['step'] ?? '');
        if ($min !== '' && (!FormDecimal::valid($min) || FormDecimal::compare($value, $min) < 0)) throw new InvalidArgumentException('Number below minimum');
        if ($max !== '' && (!FormDecimal::valid($max) || FormDecimal::compare($value, $max) > 0)) throw new InvalidArgumentException('Number above maximum');
        if ($step !== '') {
            if (!FormDecimal::valid($step) || FormDecimal::compare($step, '0') <= 0) throw new InvalidArgumentException('Invalid number step');
            if (!FormDecimal::stepAligned($value, $min !== '' ? $min : '0', $step)) throw new InvalidArgumentException('Number step mismatch');
        }
    }

    private static function assertDate(array $field, string $value): void
    {
        $utc = new DateTimeZone('UTC');
        $date = preg_match('/^(?!0000)\d{4}-\d{2}-\d{2}$/D', $value) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc) : false;
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Invalid date');
        $min = (string) ($field['min'] ?? '');
        $max = (string) ($field['max'] ?? '');
        if ($min !== '' && $value < $min) throw new InvalidArgumentException('Date below minimum');
        if ($max !== '' && $value > $max) throw new InvalidArgumentException('Date above maximum');
        $step = (string) ($field['step'] ?? '');
        if ($step !== '') {
            if (preg_match('/^[1-9]\d*$/D', $step) !== 1) throw new InvalidArgumentException('Invalid date step');
            $baseValue = $min !== '' ? $min : '1970-01-01';
            $base = DateTimeImmutable::createFromFormat('!Y-m-d', $baseValue, $utc);
            if (!$base) throw new InvalidArgumentException('Invalid date base');
            $days = (int) $base->diff($date)->format('%r%a');
            if ($days % (int) $step !== 0) throw new InvalidArgumentException('Date step mismatch');
        }
    }

    /** Count failed probes too; never extend the window when an attempt is refused. */
    public function attempt(string $ip, int $limit, int $window, ?int $now = null): int
    {
        $now ??= time();
        return $this->locked($ip, function (array &$state) use ($limit, $window, $now): int {
            $attempts = array_values(array_filter($state['attempts'] ?? [], static fn(int $at): bool => $at > $now - $window));
            if (count($attempts) >= $limit) return max(1, $attempts[0] + $window - $now);
            $attempts[] = $now;
            $state['attempts'] = $attempts;
            return 0;
        });
    }

    /**
     * Reserve under the same lock as persistence: simultaneous requests cannot both pass.
     * A failed persistence callback releases its reservation; hooks/mail run only afterwards.
     * @param array<string,string> $fields
     * @param callable():int $persist
     * @return array{reason:string, retry:int, id:int}
     */
    public function submit(string $ip, array $fields, int $limit, int $window, callable $persist, ?int $now = null): array
    {
        $now ??= time();
        ksort($fields);
        $fingerprint = hash_hmac('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $this->secret);
        return $this->locked($ip, function (array &$state, callable $save) use ($fingerprint, $limit, $window, $persist, $now): array {
            $successes = array_values(array_filter($state['successes'] ?? [], static fn(int $at): bool => $at > $now - $window));
            /** @var array<string,int> $prints */
            $prints = array_filter($state['prints'] ?? [], static fn(int $at): bool => $at > $now - 600);
            if (isset($prints[$fingerprint])) return ['reason' => 'duplicate', 'retry' => max(1, $prints[$fingerprint] + 600 - $now), 'id' => 0];
            if (count($successes) >= $limit) return ['reason' => 'throttle', 'retry' => max(1, $successes[0] + $window - $now), 'id' => 0];
            // At most 100 successful submissions/window, and at most 1000 fingerprints.
            if (count($prints) >= 1000) return ['reason' => 'throttle', 'retry' => 600, 'id' => 0];
            $before = $state;
            $successes[] = $now;
            $prints[$fingerprint] = $now;
            $state['successes'] = $successes;
            $state['prints'] = $prints;
            $save($state);
            try {
                $id = $persist();
                if ($id <= 0) throw new RuntimeException('Form persistence failed');
            } catch (Throwable $error) {
                $state = $before;
                $save($state);
                throw $error;
            }
            return ['reason' => '', 'retry' => 0, 'id' => $id];
        });
    }

    /**
     * @param callable(array, callable(array):void):mixed $operation
     */
    private function locked(string $ip, callable $operation): mixed
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Form guard directory unavailable');
        }
        $file = $this->directory . '/spam-' . hash_hmac('sha256', $ip, $this->secret) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) throw new RuntimeException('Form guard storage unavailable');
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Form guard lock unavailable');
            $raw = stream_get_contents($handle, 131073);
            if ($raw === false || strlen($raw) > 131072) throw new RuntimeException('Invalid form guard state');
            $state = $raw === '' ? [] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($state)) throw new RuntimeException('Invalid form guard state');
            foreach (['attempts', 'successes', 'prints'] as $key) {
                if (!isset($state[$key])) continue;
                if (!is_array($state[$key])) throw new RuntimeException('Invalid form guard state');
                foreach ($state[$key] as $at) {
                    if (!is_int($at)) throw new RuntimeException('Invalid form guard timestamp');
                }
            }
            $storedJson = $raw;
            $save = static function (array $value) use ($handle, &$storedJson): void {
                $json = json_encode($value, JSON_THROW_ON_ERROR);
                if ($json === $storedJson) return;
                rewind($handle);
                if (@fwrite($handle, $json) !== strlen($json) || !@ftruncate($handle, strlen($json)) || !@fflush($handle)) {
                    throw new RuntimeException('Form guard write failed');
                }
                $storedJson = $json;
            };
            $result = $operation($state, $save);
            $save($state);
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
