<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Support\Database;
use App\Services\Email\ImapClient;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$lock = fopen(dirname(__DIR__, 2) . '/storage/app/private/imap-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("IMAP sync already running.\n");
$db = Database::connect();
Database::migrate($db);
$imap = new ImapClient($db);
try {
    $result = $imap->sync(50);
    $synced = $result['synced'] ?? 0;
    $unread = $result['unread'] ?? 0;
    echo "IMAP Sync completed: {$synced} new messages fetched. Unread count: {$unread}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "IMAP Sync failed: " . $e->getMessage() . "\n");
}
flock($lock, LOCK_UN);
fclose($lock);
