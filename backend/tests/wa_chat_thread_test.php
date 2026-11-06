<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);
App\Services\WhatsApp\WhatsAppTables::ensure($db);

$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('dev-wac-1', 'Whatsapp Campaign', '+919876543210', 'ready', datetime('now'))");
$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES ('+918805854727', 'Test Contact', 'Hello', 'sent', '', 'Whatsapp Campaign', 'dev-wac-1', datetime('now'))");

$chats = new App\Services\WhatsApp\WhatsAppChatService($db);
$list = $chats->listing([]);
assert(count($list['chats']) === 1, 'Listing should have 1 chat');
$chatId = $list['chats'][0]['id'];
assert($list['chats'][0]['senders'][0]['name'] === 'Whatsapp Campaign', 'List senders name should be Whatsapp Campaign');

$thread = $chats->thread($chatId);
assert($thread !== null, 'Thread should not be null');
assert(isset($thread['chat']['senders']), 'Thread chat must have senders');
assert(count($thread['chat']['senders']) === 1, 'Thread chat senders must have 1 sender');
assert($thread['chat']['senders'][0]['name'] === 'Whatsapp Campaign', 'Thread sender name must match Whatsapp Campaign');
assert($thread['chat']['device_id'] === 'dev-wac-1', 'Thread device_id must match dev-wac-1');

echo "All WhatsApp Chat Thread tests passed successfully!\n";

