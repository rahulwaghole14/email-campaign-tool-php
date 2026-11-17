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

$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES
  ('dev-1', 'Whatsapp Campaign 1', NULL, 'ready', datetime('now')),
  ('dev-2', 'Whatsapp Campaign 2', NULL, 'ready', datetime('now'))");

$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES
  ('+66651234989', 'A', 'Hello ASIAN', 'sent', '', 'Whatsapp Campaign 1', 'dev-1', '2026-11-16T12:37:31+00:00')");

// Twin rows for the same phone (sync under Campaign 2 used to create the second).
$db->exec("INSERT INTO whatsapp_chats (phone, name, last_message, last_at, device_id) VALUES
  ('+66651234989', 'Customer', 'Hello ASIAN', '2026-11-16T12:37:31+00:00', 'dev-1'),
  ('+66651234989', 'Customer', 'Hello ASIAN', '2026-11-16T12:37:31Z', 'dev-2')");
$db->exec("INSERT INTO whatsapp_chat_messages (chat_id, direction, body, created_at) VALUES
  (1, 'out', 'Hello ASIAN', '2026-11-16T12:37:31+00:00'),
  (2, 'out', 'Hello ASIAN', '2026-11-16T12:37:31Z')");

$chats = new WhatsAppChatService($db);
$list = $chats->listing([]);
check(count($list['chats']) === 1, 'listing should collapse twin phone rows to 1, got ' . count($list['chats']));
check(($list['chats'][0]['phone'] ?? '') === '+66651234989', 'kept phone should match');

$left = (int) $db->query('SELECT COUNT(*) FROM whatsapp_chats')->fetchColumn();
check($left === 1, 'database should keep one chat row, got ' . $left);

// Opening under the other device must reuse the same chat, not insert another.
$chats->noteOutgoing('+66651234989', 'Follow up', '2026-11-17T10:00:00+00:00', 'dev-2');
$left = (int) $db->query('SELECT COUNT(*) FROM whatsapp_chats')->fetchColumn();
check($left === 1, 'noteOutgoing under another device must not create a twin, got ' . $left);

echo "OK: chat dedupe keeps one row per phone\n";
