<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\Email\ImapClient;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);

// 1. Check isLinkedInSender helper
check(ImapClient::isLinkedInSender('messages-noreply@linkedin.com') === true, 'detects messages-noreply@linkedin.com');
check(ImapClient::isLinkedInSender('invitations@linkedin.com') === true, 'detects invitations@linkedin.com');
check(ImapClient::isLinkedInSender('member@e.linkedin.com') === true, 'detects subdomain e.linkedin.com');
check(ImapClient::isLinkedInSender('client@example.com') === false, 'regular client email is not LinkedIn');
check(ImapClient::isLinkedInSender('info@rslsolution.com') === false, 'rsl email is not LinkedIn');

// 2. Setup mock account and test syncCursorUid via reflection
$client = new ImapClient($db, ['id' => 1, 'imap_user' => 'test@test.com', 'imap_pass' => 'pass']);

$cursorMethod = new ReflectionMethod(ImapClient::class, 'syncCursorUid');
$cursorMethod->setAccessible(true);

$suppressMethod = new ReflectionMethod(ImapClient::class, 'suppressUid');
$suppressMethod->setAccessible(true);

check($cursorMethod->invoke($client) === 0, 'initial cursor is 0');

// Insert inbox emails with UID 100
$now = gmdate('c');
$db->prepare("INSERT INTO inbox_emails (mail_account_id, uid, from_name, from_email, to_email, subject, body, html_body, is_read, received_at, created_at) VALUES (1, 100, 'Client', 'client@example.com', 'test@test.com', 'Hello', 'text', 'html', 0, ?, ?)")->execute([$now, $now]);

check($cursorMethod->invoke($client) === 100, 'cursor uses max inbox_emails uid 100');

// Suppress higher UID 105 (as if LinkedIn mail arrived at 105)
$suppressMethod->invoke($client, 105);

check($cursorMethod->invoke($client) === 105, 'cursor advances to max(inbox, suppressed) = 105');

// 3. Test purgeLinkedInEmails
// Insert legacy LinkedIn rows
$db->prepare("INSERT INTO inbox_emails (mail_account_id, uid, from_name, from_email, to_email, subject, body, html_body, is_read, received_at, created_at) VALUES (1, 110, 'LinkedIn', 'invitations@linkedin.com', 'test@test.com', 'New connection', 'text', 'html', 0, ?, ?)")->execute([$now, $now]);
$db->prepare("INSERT INTO inbox_emails (mail_account_id, uid, from_name, from_email, to_email, subject, body, html_body, is_read, received_at, created_at) VALUES (1, 111, 'LinkedIn Messaging', 'messages-noreply@linkedin.com', 'test@test.com', 'New message', 'text', 'html', 0, ?, ?)")->execute([$now, $now]);

$linkedinCountBefore = (int) $db->query("SELECT COUNT(*) FROM inbox_emails WHERE LOWER(from_email) LIKE '%linkedin.com%'")->fetchColumn();
check($linkedinCountBefore === 2, '2 LinkedIn emails inserted before purge');

$purgeResult = $client->purgeLinkedInEmails();
check(($purgeResult['ok'] ?? false) === true, 'purge returned ok: true');
check(($purgeResult['removed'] ?? 0) === 2, 'purge removed 2 LinkedIn emails');

$linkedinCountAfter = (int) $db->query("SELECT COUNT(*) FROM inbox_emails WHERE LOWER(from_email) LIKE '%linkedin.com%'")->fetchColumn();
check($linkedinCountAfter === 0, '0 LinkedIn emails in inbox_emails after purge');

// Ensure UIDs 110 and 111 were suppressed
$suppressed110 = (int) $db->query("SELECT COUNT(*) FROM inbox_suppressed_uids WHERE mail_account_id = 1 AND uid = 110")->fetchColumn();
$suppressed111 = (int) $db->query("SELECT COUNT(*) FROM inbox_suppressed_uids WHERE mail_account_id = 1 AND uid = 111")->fetchColumn();
check($suppressed110 === 1, 'UID 110 recorded in inbox_suppressed_uids');
check($suppressed111 === 1, 'UID 111 recorded in inbox_suppressed_uids');

// Now cursor should be 111
check($cursorMethod->invoke($client) === 111, 'cursor is now 111 after purge suppressed UIDs');

// 4. Test listing category filters
$listing = $client->listing(['category' => 'all']);
check(count($listing['emails'] ?? []) === 1, 'listing all returns only non-LinkedIn emails (count 1)');
check(($listing['category_counts']['primary'] ?? 0) === 1, 'category_counts primary is 1');
check(!isset($listing['category_counts']['linkedin']), 'category_counts does not expose linkedin');

// Requesting category=linkedin should map to primary
$listingReqLinkedin = $client->listing(['category' => 'linkedin']);
check($listingReqLinkedin['category'] === 'primary', 'category=linkedin maps to primary');

echo "OK: LinkedIn skip-on-sync, suppressed cursor advancement, and purge tests all passed!\n";
