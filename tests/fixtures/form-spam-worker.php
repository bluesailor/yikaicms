<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__, 2) . '/includes/FormSpamGuard.php';
$directory = $argv[1] ?? '';
if (!str_starts_with(basename($directory), 'yikai-form-guard-')
    || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())) exit(2);
$guard = new FormSpamGuard($directory, 'test-secret');
$result = $guard->submit('parallel-ip', ['name' => 'Same visitor'], 5, 300, static function () use ($directory): int {
    file_put_contents($directory . '/persisted.txt', "saved\n", FILE_APPEND | LOCK_EX);
    usleep(30000);
    return 1;
}, 1000);
echo $result['reason'] === '' ? 'accepted' : $result['reason'];
