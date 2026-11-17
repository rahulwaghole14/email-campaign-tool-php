<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\WhatsApp\DeviceLink;
use App\Services\WhatsApp\WhatsAppQueue;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);
App\Services\WhatsApp\WhatsAppTables::ensure($db);
$existing = array_column($db->query('PRAGMA table_info(whatsapp_sheet_sources)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['delay_minutes INTEGER NULL', 'daily_limit INTEGER NULL', 'start_date VARCHAR(10) NULL', 'start_time VARCHAR(5) NULL', 'sender_mode VARCHAR(8) NULL', 'device_ids TEXT NULL', 'device_id VARCHAR(80) NULL'] as $col) {
    $name = explode(' ', $col)[0];
    if (!in_array($name, $existing, true)) $db->exec("ALTER TABLE whatsapp_sheet_sources ADD COLUMN $col");
}
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, start_time) VALUES (1, 'x', 1, 'Sheet', 1, '{}', '2026-01-01T00:00:00+00:00', 5, 50, '10:00')");

// dev-1 is connected; dev-2 is disconnected and has nothing left today.
$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('dev-1', 'Whatsapp Campaign 1', NULL, 'ready', '2026-01-01T00:00:00+00:00')");
$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('dev-2', 'Whatsapp Campaign 2', NULL, 'disconnected', '2026-01-01T00:00:00+00:00')");

$link = new class($db) extends DeviceLink {
    public function roster(): array { return ['devices' => [], 'slots' => 5]; }
};
$queue = new WhatsAppQueue($db, $link);

$now = time();
$insert = $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES (?, 'N', 'Hi', ?, NULL, ?, ?, ?, 1, ?, ?, 0)");
// dev-1 sent two messages earlier today (the later one is the last), account name stored wrongly as Campaign 2.
$insert->execute(['+6698000001', 'sent', 'Whatsapp Campaign 2', 'dev-1', gmdate('c', $now - 900), 'S1', gmdate('c', $now - 900)]);
$insert->execute(['+6698000002', 'sent', 'Whatsapp Campaign 2', 'dev-1', gmdate('c', $now - 300), 'S2', gmdate('c', $now - 300)]);
// dev-2 sent one message today and has nothing left.
$insert->execute(['+6698000003', 'sent', 'Whatsapp Campaign 2', 'dev-2', gmdate('c', $now - 1200), 'S3', gmdate('c', $now - 1200)]);

$today = $queue->today();
$phones = $today['phones'];
check(count($phones) === 1, 'only the connected phone is listed, got ' . count($phones));
check($phones[0]['id'] === 'dev-1', 'the listed phone is the connected one');
check($phones[0]['name'] === 'Whatsapp Campaign 1', 'the name comes from the device list, got ' . $phones[0]['name']);
check($phones[0]['sent'] === 2, 'connected phone sent count is 2');
check($phones[0]['last_at'] === gmdate('c', $now - 300), 'last today is the last sent time');
check($today['sent'] === 3, 'the total sent today still counts every phone');
$todayBar = array_values(array_filter($today['days'], static fn ($day) => $day['date'] === $today['date']));
check(count($todayBar) === 1 && $todayBar[0]['count'] === 3, 'today appears in day by day with the sent count');

echo "OK: phones table shows connected phones with their device names\n";
