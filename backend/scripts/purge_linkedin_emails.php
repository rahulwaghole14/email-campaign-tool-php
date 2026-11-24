<?php
/**
 * One-shot CLI maintenance script to delete stored LinkedIn notification rows
 * from inbox_emails and suppress their UIDs so future IMAP syncs will never
 * re-import them.
 *
 * Usage:
 *   php backend/scripts/purge_linkedin_emails.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Services\Email\ImapClient;

try {
    $db = Database::connect();
    Database::migrate($db);

    $countBefore = (int) $db->query("SELECT COUNT(*) FROM inbox_emails WHERE LOWER(from_email) LIKE '%linkedin.com%'")->fetchColumn();
    echo "Found {$countBefore} LinkedIn emails in inbox_emails table.\n";

    if ($countBefore === 0) {
        echo "No LinkedIn rows to purge.\n";
        exit(0);
    }

    $client = new ImapClient($db);
    $res = $client->purgeLinkedInEmails();

    $countAfter = (int) $db->query("SELECT COUNT(*) FROM inbox_emails WHERE LOWER(from_email) LIKE '%linkedin.com%'")->fetchColumn();
    $suppressedCount = (int) $db->query("SELECT COUNT(*) FROM inbox_suppressed_uids")->fetchColumn();

    echo "Purged: {$res['removed']} emails.\n";
    echo "Remaining LinkedIn emails in inbox_emails: {$countAfter}.\n";
    echo "Total suppressed UIDs recorded: {$suppressedCount}.\n";
    echo "Done.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
