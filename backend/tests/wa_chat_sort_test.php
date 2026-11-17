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
// Older send first, newer send second. Mixed last_at formats must still sort by real time.
$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES ('+66911111111', 'Older', 'Hi', 'sent', '', 'Whatsapp Campaign 1', 'dev-1', '2026-11-16T10:00:00+00:00')");
$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES ('+66922222222', 'Newer', 'Hi', 'sent', '', 'Whatsapp Campaign 1', 'dev-1', '2026-11-16T12:00:00+00:00')");

$chats = new WhatsAppChatService($db);
$chats->listing([]);

$live = [
    // Live uses a Z suffix. Lexicographic string sort would put this behind +00:00; real-time sort must put it first.
    ['phone' => '+66911111111', 'remote_jid' => '66911111111@s.whatsapp.net', 'name' => 'Older', 'last_message' => 'reply', 'last_at' => '2026-11-16T13:00:00Z', 'unread_count' => 2],
    ['phone' => '+66922222222', 'remote_jid' => '66922222222@s.whatsapp.net', 'name' => 'Newer', 'last_message' => 'Hi', 'last_at' => '2026-11-16T12:00:00+00:00', 'unread_count' => 0],
];
$out = $chats->campaignList($live, []);
check(count($out['chats']) === 2, 'two chats listed');

$first = $out['chats'][0];
$second = $out['chats'][1];
check(str_ends_with((string) $first['phone'], '1111'), 'most recent activity is on top even with Z vs +00:00 formats, got ' . $first['phone']);
check((int) $first['unread_count'] === 2, 'unread badge is kept on the top chat');
check(str_ends_with((string) $second['phone'], '2222'), 'older activity stays below');
check((int) $second['unread_count'] === 0, 'read chat keeps its place by last_at, unread does not reorder');

echo "OK: chat list sorts by recent activity, not by unread and not by date-string format\n";
