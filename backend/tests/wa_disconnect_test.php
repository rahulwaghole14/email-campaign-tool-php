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

// Source 1: all phones, gap 5 minutes, 2 per day per phone.
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, start_time, sender_mode) VALUES (1, 'x', 1, 'All', 1, '{}', '2026-01-01T00:00:00+00:00', 5, 2, '10:00', 'all')");
// Source 2: picked phones only (dev-1), gap 5 minutes, 2 per day.
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, start_time, sender_mode, device_ids) VALUES (2, 'y', 2, 'Picked', 1, '{}', '2026-01-01T00:00:00+00:00', 5, 2, '10:00', 'pick', '[\"dev-1\"]')");

// dev-1 is disconnected; dev-2 is connected.
$link = new class($db) extends DeviceLink {
    public function roster(): array { return ['devices' => [['id' => 'dev-1', 'name' => 'Phone 1', 'status' => 'disconnected'], ['id' => 'dev-2', 'name' => 'Phone 2', 'status' => 'ready']], 'slots' => 5]; }
};
$queue = new WhatsAppQueue($db, $link);
$insert = $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES (?, 'N', 'Hi', 'waiting', NULL, 'Phone', ?, ?, ?, ?, ?, 0)");
$now = time();
$add = function (string $device, int $source, string $record) use ($insert, $now): void {
    $insert->execute(['+6698' . $record, $device, gmdate('c', $now + 3600), $source, $record, gmdate('c', $now)]);
};
// Three rows stuck on the disconnected phone for the all-phones sheet.
$add('dev-1', 1, 'ALL-1');
$add('dev-1', 1, 'ALL-2');
$add('dev-1', 1, 'ALL-3');
// Two rows on the picked sheet, where the only picked phone is disconnected.
$add('dev-1', 2, 'PICK-1');
$add('dev-1', 2, 'PICK-2');

$device = static fn (string $record): string => (string) $db->query("SELECT device_id FROM whatsapp_sends WHERE record_id = '$record'")->fetchColumn();

$result = $queue->compact();
check($result['moved'] === 3, 'the three all-phones rows should move, got ' . $result['moved']);
foreach (['ALL-1', 'ALL-2', 'ALL-3'] as $record) {
    check($device($record) === 'dev-2', "$record should move to the connected phone");
}
// Picked sheet: its only chosen phone is disconnected, so nothing moves.
check($device('PICK-1') === 'dev-1' && $device('PICK-2') === 'dev-1', 'picked rows stay put when the chosen phone is disconnected');

// The connected phone still keeps at most 2 per day.
$perDay = $db->query("SELECT due_at FROM whatsapp_sends WHERE device_id = 'dev-2' AND status = 'waiting'")->fetchAll(PDO::FETCH_COLUMN);
$days = [];
foreach ($perDay as $due) {
    $day = (new DateTimeImmutable((string) $due))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d');
    $days[$day] = ($days[$day] ?? 0) + 1;
}
foreach ($days as $day => $count) {
    check($count <= 2, "connected phone is over 2 on $day: $count");
}

echo "OK: rows on a disconnected phone move to the connected phone\n";
