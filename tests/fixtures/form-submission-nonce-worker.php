<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/includes/FormSubmissionNonce.php';

session_save_path((string) ($argv[1] ?? ''));
session_id((string) ($argv[2] ?? ''));
session_start();
$accepted = FormSubmissionNonce::consume('contact', (string) ($argv[3] ?? ''), 'test-secret', 1000);
session_write_close();
echo $accepted ? 'accepted' : 'rejected';
