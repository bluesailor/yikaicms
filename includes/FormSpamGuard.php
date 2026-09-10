<?php
declare(strict_types=1);

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
        foreach (['hp_url', 'form_slug', 'form_ts', 'form_sig', 'captcha_code', 'product_id', 'product_title'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) return false;
        }
        return strlen((string) ($input['form_slug'] ?? '')) <= 100
            && mb_strlen((string) ($input['product_title'] ?? '')) <= 255;
    }

    /** Throws for malformed values rather than coercing arrays to strings. */
    public static function fieldValue(array $field, mixed $raw): string
    {
        $key = (string) ($field['key'] ?? $field['name'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        if ($type === 'checkbox') {
            $raw = is_array($raw) ? $raw : [$raw];
            if (count($raw) > 50) throw new InvalidArgumentException('Invalid choices');
            foreach ($raw as $item) {
                if (!is_string($item)) throw new InvalidArgumentException('Invalid choice');
            }
            $value = implode(', ', array_filter(array_map('trim', $raw), static fn(string $v): bool => $v !== ''));
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
        return $value;
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
