<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Support\Database;
use App\Services\MailAccountService;
use App\Services\UserService;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::migrate($db);

echo "Starting RBAC & Multi-Mailbox tests...\n";

// 1. Verify default account auto-seeded
$accounts = MailAccountService::getAll($db, true);
assert(count($accounts) >= 1, "Default mail account should be seeded");
$default = $accounts[0];
assert((int) $default['is_default'] === 1, "First account should be default");
echo "✓ Default mail account verified.\n";

// 2. Add second colleague account
$rahulAccount = MailAccountService::save($db, [
    'name' => 'Rahul Waghole',
    'email' => 'rahul@rslsolution.com',
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,
    'smtp_user' => 'rahul@rslsolution.com',
    'smtp_pass' => 'TestPass@123',
    'imap_host' => 'imap.hostinger.com',
    'imap_port' => 993,
    'imap_user' => 'rahul@rslsolution.com',
    'imap_pass' => 'TestPass@123',
    'is_default' => 0
]);
assert((int) $rahulAccount['id'] > 0, "Colleague account should be created");
assert($rahulAccount['email'] === 'rahul@rslsolution.com', "Email should match");
echo "✓ Dynamic colleague account added.\n";

// 3. Create Admin and Member users
$adminUser = UserService::save($db, [
    'name' => 'Admin Boss',
    'email' => 'admin@rslsolution.com',
    'password' => 'SecureAdmin@2026',
    'role' => 'admin',
    'assigned_account_id' => $default['id']
]);
assert($adminUser['role'] === 'admin');

$memberUser = UserService::save($db, [
    'name' => 'Rahul Colleague',
    'email' => 'rahul.member@rslsolution.com',
    'password' => 'Colleague@2026',
    'role' => 'member',
    'assigned_account_id' => $rahulAccount['id']
]);
assert($memberUser['role'] === 'member');
echo "✓ Users created with admin & member roles.\n";

// 4. Test authentication
$authAdmin = UserService::authenticate($db, 'admin@rslsolution.com', 'SecureAdmin@2026');
assert($authAdmin !== null && $authAdmin['role'] === 'admin', "Admin should authenticate");

$authMember = UserService::authenticate($db, 'rahul.member@rslsolution.com', 'Colleague@2026');
assert($authMember !== null && $authMember['role'] === 'member', "Member should authenticate");

$authFail = UserService::authenticate($db, 'rahul.member@rslsolution.com', 'WrongPassword');
assert($authFail === null, "Invalid password should fail");
echo "✓ User authentication verified.\n";

// 5. Test role-based mailbox visibility
$adminMailboxes = MailAccountService::getForUser($db, $authAdmin);
assert(count($adminMailboxes) >= 2, "Admin should see all mailboxes");

$memberMailboxes = MailAccountService::getForUser($db, $authMember);
assert(count($memberMailboxes) === 1, "Member should only see their 1 assigned mailbox");
assert($memberMailboxes[0]['email'] === 'rahul@rslsolution.com', "Member must be locked to rahul@rslsolution.com");
echo "✓ Role-based mailbox isolation verified.\n";

echo "All RBAC & Multi-Mailbox tests passed successfully!\n";

