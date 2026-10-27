<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';

try {
    (new App\Services\Email\Mailer())->checkConnection();
    echo "SMTP TLS connection and authentication succeeded. No email was sent.\n";
} catch (Throwable $e) {
    // Do not output provider transcripts or credentials.
    fwrite(STDERR, "SMTP connection or authentication failed. Check network access, TLS, and mailbox credentials. No email was sent.\n");
    exit(1);
}
