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
foreach (['delay_minutes INTEGER NULL', 'delay_max_minutes INTEGER NULL', 'daily_limit INTEGER NULL', 'start_date VARCHAR(10) NULL', 'start_time VARCHAR(5) NULL', 'sender_mode VARCHAR(8) NULL', 'device_ids TEXT NULL', 'device_id VARCHAR(80) NULL'] as $col) {
    $name = explode(' ', $col)[0];
    if (!in_array($name, $existing, true)) $db->exec("ALTER TABLE whatsapp_sheet_sources ADD COLUMN $col");
}

$link = new class($db) extends DeviceLink {
    public function roster(): array
    {
        return ['devices' => [['id' => 'dev-1', 'name' => 'Phone 1', 'status' => 'ready']], 'slots' => 5];
    }
};
$queue = new WhatsAppQueue($db, $link);
$insert = $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES (?, 'N', 'Hi', 'waiting', NULL, 'Phone 1', 'dev-1', ?, 1, ?, ?, 0)");
$iso = static fn (int $at): string => gmdate('c', $at);
$now = (int) (ceil(time() / 60) * 60);

// Fixed gap when max is null: every wait is exactly 5 minutes.
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, delay_max_minutes, daily_limit, start_time, sender_mode) VALUES (1, 'x', 1, 'Fixed', 1, '{}', '2026-01-01T00:00:00+00:00', 5, NULL, 50, '10:00', 'all')");
for ($i = 1; $i <= 4; $i++) {
    $insert->execute(['+6698000000' . $i, $iso($now + $i * 3600), 'F' . $i, $iso($now)]);
}
$queue->compact();
$fixed = $db->query("SELECT due_at FROM whatsapp_sends WHERE source_id = 1 AND status = 'waiting' ORDER BY due_at ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
check(count($fixed) === 4, 'four fixed-gap rows');
for ($i = 1; $i < count($fixed); $i++) {
    $gap = (strtotime((string) $fixed[$i]) ?: 0) - (strtotime((string) $fixed[$i - 1]) ?: 0);
    check($gap === 5 * 60, "null max keeps a fixed 5-minute gap, got {$gap}s between row " . ($i - 1) . " and {$i}");
}

// Random gap 5–12: each wait stays inside the range.
$db->exec('DELETE FROM whatsapp_sends');
$db->exec("UPDATE whatsapp_sheet_sources SET delay_minutes = 5, delay_max_minutes = 12 WHERE id = 1");
for ($i = 1; $i <= 8; $i++) {
    $insert->execute(['+6698110000' . $i, $iso($now + $i * 7200), 'R' . $i, $iso($now)]);
}
$queue->compact();
$random = $db->query("SELECT due_at FROM whatsapp_sends WHERE source_id = 1 AND status = 'waiting' ORDER BY due_at ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
check(count($random) === 8, 'eight random-gap rows');
$seen = [];
for ($i = 1; $i < count($random); $i++) {
    $prev = strtotime((string) $random[$i - 1]) ?: 0;
    $next = strtotime((string) $random[$i]) ?: 0;
    $gap = $next - $prev;
    // Crossing midnight jumps to the sheet start time, so skip day changes.
    if ((new DateTimeImmutable('@' . $prev))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d')
        !== (new DateTimeImmutable('@' . $next))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d')) {
        continue;
    }
    check($gap >= 5 * 60 && $gap <= 12 * 60, "random gap must be 5–12 minutes, got {$gap}s");
    $seen[$gap] = true;
}
check(count($seen) >= 1, 'at least one same-day random gap was checked');

echo "OK: random gap stays in range and null max stays fixed\n";
