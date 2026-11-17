<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\WhatsApp\WhatsAppChatService;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);
App\Services\WhatsApp\WhatsAppTables::ensure($db);

$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('dev-1', 'Whatsapp Campaign 1', NULL, 'ready', datetime('now'))");
// Two customers, both sent from dev-1. Only one of them is in the live inbox list.
$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES ('+66928191082', 'A', 'Hi', 'sent', '', 'Whatsapp Campaign 1', 'dev-1', datetime('now', '-1 hour'))");
$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES ('+918805854727', 'B', 'Hi', 'sent', '', 'Whatsapp Campaign 1', 'dev-1', datetime('now', '-1 hour'))");

$chats = new WhatsAppChatService($db);
$chats->listing([]); // creates the local chat rows from the sent messages

// The live inbox knows only the first number. The second one has no live match, which used to crash the list.
$live = [['phone' => '+66928191082', 'remote_jid' => '66928191082@s.whatsapp.net', 'name' => 'A', 'last_message' => '', 'last_at' => '', 'unread_count' => 0]];
$out = $chats->campaignList($live, []);

check(count($out['chats']) === 2, 'both chats are listed, got ' . count($out['chats']));
$mine = array_filter($out['chats'], static fn (array $c): bool => ($c['senders'][0]['id'] ?? '') === 'dev-1');
check(count($mine) === 2, 'both chats show Campaign 1 as the sender');

echo "OK: chat list works when some chats have no live inbox match\n";
