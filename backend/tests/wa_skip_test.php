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
// Gap 5 minutes, 50 per day, one connected phone.
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, start_time) VALUES (1, 'x', 1, 'Sheet', 1, '{}', '2026-01-01T00:00:00+00:00', 5, 50, '10:00')");
$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('dev-1', 'Whatsapp Campaign 1', NULL, 'ready', '2026-01-01T00:00:00+00:00')");

// The service says the first number is not on WhatsApp; every other number goes through.
$link = new class($db) extends DeviceLink {
    public function roster(): array { return ['devices' => [['id' => 'dev-1', 'name' => 'Whatsapp Campaign 1', 'status' => 'ready']], 'slots' => 5]; }
    public function sendText(string $receiver, string $message, ?string $targetDeviceId = null): array
    {
        if ($receiver === '66928191082') throw new RuntimeException('The phone service did not accept this message (HTTP 400: Number 66928191082 is not registered on WhatsApp).');
        return ['device_id' => 'dev-1', 'name' => 'Whatsapp Campaign 1'];
    }
};
$queue = new WhatsAppQueue($db, $link);

$now = time();
$insert = $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES (?, 'N', 'Hi', 'waiting', NULL, ?, 'dev-1', ?, 1, ?, ?, 0)");
// Row A is due now and is not on WhatsApp. Row B is due 5 minutes from now, on the same phone.
$insert->execute(['+66928191082', 'Whatsapp Campaign 1', gmdate('c', $now - 30), 'A', gmdate('c', $now)]);
$insert->execute(['+6698000777', 'Whatsapp Campaign 1', gmdate('c', $now + 300), 'B', gmdate('c', $now)]);

$result = $queue->run();
check($result['failed'] === 1, 'row A is marked failed');
check((string) $db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'A'")->fetchColumn() === 'failed', 'row A stays failed, not retried');

// The slot freed by A is used in the same run: row B goes out at once, not at its old 5-minute time.
check($result['sent'] === 1, 'row B is sent in the same run after A is skipped');
check((string) $db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'B'")->fetchColumn() === 'sent', 'row B is sent');

echo "OK: a not-on-WhatsApp number frees its slot for the next row\n";
