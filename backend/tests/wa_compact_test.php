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
// Gap 5 minutes, 2 per day per phone, start 10:00 (Asia/Kolkata), no start date.
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, start_time) VALUES (1, 'x', 1, 'Sheet', 1, '{}', '2026-01-01T00:00:00+00:00', 5, 2, '10:00')");

$link = new class($db) extends DeviceLink {
    public function roster(): array { return ['devices' => [['id' => 'dev-1', 'name' => 'Phone 1', 'status' => 'ready'], ['id' => 'dev-2', 'name' => 'Phone 2', 'status' => 'ready']], 'slots' => 5]; }
};
$queue = new WhatsAppQueue($db, $link);
$insert = $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES (?, 'N', 'Hi', ?, NULL, 'Phone', ?, ?, 1, ?, ?, ?)");
$iso = static fn (int $at): string => gmdate('c', $at);
$now = time();
// Distinct digit phones so sticky-owner matching does not treat every test row as the same number.
$phones = [
    'P1-SENT' => '+66981110001',
    'P1-A' => '+66981110002',
    'P1-HELD' => '+66981110003',
    'P1-B' => '+66981110004',
    'P1-C' => '+66981110005',
    'P2-RETRY' => '+66982220001',
    'P2-D' => '+66982220002',
];
$add = function (string $status, string $device, int $due, string $record, string $created = '', int $attempts = 0) use ($insert, $db, $iso, $now, $phones): int {
    $insert->execute([$phones[$record], $status, $device, $iso($due), $record, $created !== '' ? $created : $iso($now), $attempts]);
    return (int) $db->lastInsertId();
};
$due = static fn (int $id): int => strtotime((string) $db->query("SELECT due_at FROM whatsapp_sends WHERE id = $id")->fetchColumn());
$status = static fn (string $record): string => (string) $db->query("SELECT status FROM whatsapp_sends WHERE record_id = '$record'")->fetchColumn();
$tz = new DateTimeZone('Asia/Kolkata');
$dayOf = static fn (int $at): string => (new DateTimeImmutable('@' . $at))->setTimezone($tz)->format('Y-m-d');
$clockOf = static fn (int $at): string => (new DateTimeImmutable('@' . $at))->setTimezone($tz)->format('H:i');

// Phone 1: one row sent 10 minutes ago (uses 1 of 2 slots today), then a hole where a held row was, then three waiting rows.
$add('sent', 'dev-1', $now - 600, 'P1-SENT', gmdate('c', $now - 600));
$a = $add('waiting', 'dev-1', $now + 3 * 3600, 'P1-A');
$add('held', 'dev-1', $now + 3 * 3600 + 300, 'P1-HELD');
$b = $add('waiting', 'dev-1', $now + 3 * 3600 + 1200, 'P1-B');
$c = $add('waiting', 'dev-1', $now + 3 * 3600 + 1500, 'P1-C');
// Phone 2: a retrying row keeps its time; the waiting row behind it moves up.
$retry = $add('waiting', 'dev-2', $now + 2 * 3600, 'P2-RETRY', '', 2);
$d = $add('waiting', 'dev-2', $now + 5 * 3600, 'P2-D');

$device = static fn (int $id): string => (string) $db->query("SELECT device_id FROM whatsapp_sends WHERE id = $id")->fetchColumn();
// How many messages each phone has on each day, counting waiting and sent rows.
$perDay = static function () use ($db, $tz): array {
    $counts = [];
    foreach ($db->query("SELECT device_id, due_at, created_at, status FROM whatsapp_sends WHERE status IN ('waiting','sent') AND device_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $at = strtotime((string) ($row['status'] === 'sent' ? $row['created_at'] : $row['due_at']));
        $day = (new DateTimeImmutable('@' . $at))->setTimezone($tz)->format('Y-m-d');
        $counts[$row['device_id'] . '|' . $day] = ($counts[$row['device_id'] . '|' . $day] ?? 0) + 1;
    }
    return $counts;
};

$result = $queue->compact();
check($result['moved'] === 4, 'four waiting rows should move, got ' . $result['moved']);

// The retrying row keeps its phone and its time.
check($device($retry) === 'dev-2' && abs($due($retry) - ($now + 2 * 3600)) < 2, 'a retrying row keeps its phone and time');

// Both phones share the load: A and B go today (one per phone, the other slot each has left today).
check($dayOf($due($a)) === $dayOf($now) && $due($a) >= $now && $due($a) <= $now + 120, 'row A goes now');
check($dayOf($due($b)) === $dayOf($now) && $due($b) >= $now && $due($b) <= $now + 120, 'row B goes now on the other phone');
check($device($a) !== $device($b), 'the two rows that go now are on different phones');

// C and D go tomorrow at the sheet start time, one on each phone.
check($dayOf($due($c)) !== $dayOf($now) && $clockOf($due($c)) === '10:00', 'row C goes tomorrow at 10:00');
check($dayOf($due($d)) !== $dayOf($now) && $clockOf($due($d)) === '10:00', 'row D goes tomorrow at 10:00');
check($device($c) !== $device($d), 'C and D are on different phones');

// No phone goes over its limit of 2 per day.
foreach ($perDay() as $key => $count) {
    check($count <= 2, "a phone is over its limit on one day: $key has $count");
}

// Held and sent rows are never touched.
check($status('P1-HELD') === 'held', 'held rows stay held');
check($status('P1-SENT') === 'sent', 'sent rows stay sent');

// Running again keeps the same count and the same limits.
$again = $queue->compact();
check($again['moved'] === 4, 'the same four rows are rescheduled again');
foreach ($perDay() as $key => $count) {
    check($count <= 2, "after a second run a phone is over its limit: $key has $count");
}
check($dayOf($due($a)) === $dayOf($now), 'running again keeps row A today');

echo "OK: compact moves waiting rows forward and keeps gaps and limits\n";
