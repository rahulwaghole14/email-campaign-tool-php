<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Support\Database;
use App\Services\MailAccountService;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::migrate($db);

echo "Testing Dynamic Multi-Account Architecture...\n";

// 1. Initial mail accounts check
$accounts = MailAccountService::getAll($db, false);
echo "✓ Initial active accounts found: " . count($accounts) . "\n";

// 2. Add dynamic mailbox 1
$acc1 = MailAccountService::save($db, [
    'name' => 'Support Desk',
    'email' => 'support@rslsolution.com',
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,
    'smtp_user' => 'support@rslsolution.com',
    'smtp_pass' => 'Pass1@123',
    'imap_host' => 'imap.hostinger.com',
    'imap_port' => 993,
    'imap_user' => 'support@rslsolution.com',
    'imap_pass' => 'Pass1@123'
]);
assert(!empty($acc1['id']), "Support account should be created");
echo "✓ Dynamic account added: {$acc1['email']}\n";

// 3. Add dynamic mailbox 2
$acc2 = MailAccountService::save($db, [
    'name' => 'Sales Outreach',
    'email' => 'sales@rslsolution.com',
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,
    'smtp_user' => 'sales@rslsolution.com',
    'smtp_pass' => 'Pass2@123',
    'imap_host' => 'imap.hostinger.com',
    'imap_port' => 993,
    'imap_user' => 'sales@rslsolution.com',
    'imap_pass' => 'Pass2@123'
]);
assert(!empty($acc2['id']), "Sales account should be created");
echo "✓ Dynamic account added: {$acc2['email']}\n";

// 4. Verify all accounts are available (no role restriction / no member locks)
$all = MailAccountService::getAll($db, false);
assert(count($all) >= 2, "All accounts should be returned");
echo "✓ All mailboxes dynamically listed for 'Send As' selection.\n";

// 5. Test deleting ANY account (no default lock)
MailAccountService::delete($db, (int) $acc1['id']);
$afterDelete = MailAccountService::getAll($db, false);
assert(!in_array('support@rslsolution.com', array_column($afterDelete, 'email'), true), "Account 1 deleted");
echo "✓ Mailbox {$acc1['email']} successfully deleted dynamically (no default lock).\n";

echo "All Dynamic Multi-Account tests passed successfully!\n";

