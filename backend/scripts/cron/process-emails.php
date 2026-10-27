<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Support\Database;
use App\Services\Email\EmailProcessor;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$lock = fopen(dirname(__DIR__, 2) . '/storage/app/private/email-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Worker already running.\n");
$db = Database::connect();
Database::migrate($db);
$processor = new EmailProcessor($db);
$rows = $db->query("SELECT id FROM emails WHERE status = 'pending' AND attempts < 3 ORDER BY id LIMIT 25")->fetchAll();
foreach ($rows as $email) {
    try {
        $result = $processor->processOne((int) $email['id']);
        echo "Email {$email['id']}: {$result['status']}\n";
    } catch (Throwable) {
        fwrite(STDERR, "Email {$email['id']}: failed\n");
    }
}
flock($lock, LOCK_UN);
fclose($lock);
