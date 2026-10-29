<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Support\Database;
use App\Services\UserService;
use App\Services\MailAccountService;
use App\Services\Email\ImapClient;
use App\Services\Email\EmailHistory;

$db = Database::connect();
Database::migrate($db);

echo "Testing Member Data Scoping...\n";

// 1. Verify user Nikhil Chavhan exists as member
$nikhil = null;
foreach (UserService::getAll($db) as $u) {
    if ($u['email'] === 'nikhilchavan063@gmail.com') {
        $nikhil = $u;
        break;
    }
}
if (!$nikhil) {
    throw new RuntimeException("Nikhil user not found in DB.");
}
echo "✓ Member user found: {$nikhil['name']} ({$nikhil['email']}), role: {$nikhil['role']}, account_id: {$nikhil['assigned_account_id']}\n";

// 2. Test ImapClient::listing scoped to member
$imap = new ImapClient($db, null);
$memberInbox = $imap->listing([
    'account_id' => $nikhil['assigned_account_id'],
    'to_email' => $nikhil['email']
]);
$inboxTotal = (int) ($memberInbox['pagination']['total'] ?? -1);
if ($inboxTotal !== 0) {
    throw new RuntimeException("Expected 0 inbox emails for Nikhil, got {$inboxTotal}");
}
if ($memberInbox['unread_count'] !== 0) {
    throw new RuntimeException("Expected 0 unread inbox emails for Nikhil, got {$memberInbox['unread_count']}");
}
echo "✓ ImapClient scoping verified: Nikhil sees 0 inbox emails & 0 unread badge.\n";

// 3. Test EmailHistory::listing scoped to member
$history = new EmailHistory($db);
$memberSent = $history->listing([
    'mail_account_id' => $nikhil['assigned_account_id']
]);
$sentTotal = (int) ($memberSent['pagination']['total'] ?? -1);
if ($sentTotal !== 0) {
    throw new RuntimeException("Expected 0 sent emails for Nikhil, got {$sentTotal}");
}
echo "✓ EmailHistory scoping verified: Nikhil sees 0 sent emails.\n";

// 4. Test MailAccountService::getForUser for member
$memberAccounts = MailAccountService::getForUser($db, $nikhil, false);
if (count($memberAccounts) !== 1 || (int)$memberAccounts[0]['id'] !== (int)$nikhil['assigned_account_id']) {
    throw new RuntimeException("Expected exactly 1 assigned account for Nikhil.");
}
echo "✓ MailAccountService scoping verified: Nikhil sees only his assigned mailbox ({$memberAccounts[0]['email']}).\n";

// 5. Test MailAccountService::getForUser for unassigned member
$unassignedMember = ['role' => 'member', 'assigned_account_id' => null];
$unassignedAccounts = MailAccountService::getForUser($db, $unassignedMember, false);
if (count($unassignedAccounts) !== 0) {
    throw new RuntimeException("Unassigned member must not receive any mailboxes.");
}
echo "✓ Unassigned member safely receives 0 mailboxes (no admin leak).\n";

echo "All Member Scoping checks passed successfully!\n";

