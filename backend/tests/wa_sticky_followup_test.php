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

$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, start_time, sender_mode) VALUES (1, 'x', 1, 'Sheet', 1, '{}', '2026-01-01T00:00:00+00:00', 5, 50, '10:00', 'all')");
$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('dev-1', 'Phone 1', NULL, 'ready', '2026-01-01T00:00:00+00:00')");
$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('dev-2', 'Phone 2', NULL, 'ready', '2026-01-01T00:00:00+00:00')");

$link = new class($db) extends DeviceLink {
    public array $ready = ['dev-1', 'dev-2'];
    public function roster(): array
    {
        $devices = [];
        foreach ($this->ready as $id) {
            $devices[] = ['id' => $id, 'name' => $id === 'dev-1' ? 'Phone 1' : 'Phone 2', 'status' => 'ready'];
        }
        return ['devices' => $devices, 'slots' => 5];
    }
};
$queue = new WhatsAppQueue($db, $link);

// --- can_queue rules (same-sheet follow-up) ---
check(WhatsAppQueue::canQueue(null, 'Hello') === true, 'never sent can queue');
check(WhatsAppQueue::canQueue(['status' => 'pending', 'message' => 'Hello'], 'Hello') === false, 'waiting cannot queue');
check(WhatsAppQueue::canQueue(['status' => 'pending', 'message' => 'Hello'], 'Follow-up') === false, 'waiting stays blocked even with new text');
check(WhatsAppQueue::canQueue(['status' => 'sent', 'message' => 'Hello'], 'Hello') === false, 'same message after sent is skipped');
check(WhatsAppQueue::canQueue(['status' => 'sent', 'message' => 'Hello'], 'Hello ') === false, 'trim matches same message');
check(WhatsAppQueue::canQueue(['status' => 'sent', 'message' => 'Hello'], 'Follow-up 1') === true, 'new message after sent can queue');
check(WhatsAppQueue::canQueue(['status' => 'failed', 'message' => 'Hello'], 'Hello') === false, 'same message after failed is skipped');
check(WhatsAppQueue::canQueue(['status' => 'failed', 'message' => 'Hello'], 'Follow-up 1') === true, 'new message after failed can queue');

// statuses() exposes the last body for the preview API.
$now = gmdate('c');
$db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES ('+66981110001', 'A', 'Hello', 'sent', NULL, 'Phone 1', 'dev-1', ?, 1, 'R1', ?, 0)")
    ->execute([$now, $now]);
$states = $queue->statuses(1);
check(($states['R1']['status'] ?? '') === 'sent', 'statuses marks R1 sent');
check(($states['R1']['message'] ?? '') === 'Hello', 'statuses returns last message body');
check(($states['R1']['wave'] ?? 0) === 1 && ($states['R1']['kind'] ?? '') === 'message', 'first send is message wave 1');
check(WhatsAppQueue::waveLabel('sent', 1) === 'Message sent', 'wave label for first send');
check(WhatsAppQueue::canQueue($states['R1'], 'Hello') === false, 'preview can_queue false for same body');
check(WhatsAppQueue::canQueue($states['R1'], 'Follow-up 1') === true, 'preview can_queue true for new body');

$db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES ('+66981110001', 'A', 'Follow-up 1', 'sent', NULL, 'Phone 1', 'dev-1', ?, 1, 'R1', ?, 0)")
    ->execute([$now, $now]);
$states = $queue->statuses(1);
check(($states['R1']['wave'] ?? 0) === 2 && ($states['R1']['kind'] ?? '') === 'follow_up', 'second send is follow-up wave 2');
check(WhatsAppQueue::waveLabel('sent', 2) === 'Follow-up 1 sent', 'wave label for first follow-up');
check(($states['R1']['device_id'] ?? '') === 'dev-1', 'statuses exposes device_id');
check(($states['R1']['device_name'] ?? '') === 'Phone 1', 'statuses exposes device_name');
$detail = $queue->rowStatus(1, 'R1');
check(($detail['kind'] ?? '') === 'follow_up' && str_contains((string) ($detail['label'] ?? ''), 'Follow-up'), 'row status names the follow-up');
check(($detail['sticky_phone'] ?? '') === 'Phone 1', 'row status exposes sticky_phone');
check(($detail['device_name'] ?? '') === 'Phone 1', 'row status exposes device_name');

// Test phoneDigits and ownersByDigits
$owners = $queue->ownersByDigits();
$digits = WhatsAppQueue::phoneDigits('+66981110001');
check(($owners[$digits] ?? '') === 'dev-1', 'ownersByDigits identifies dev-1 as owner');

// --- sticky assign: prior successful send on dev-1 pins the follow-up ---
$assign = new ReflectionMethod(WhatsAppQueue::class, 'assign');
$assign->setAccessible(true);
$phones = [['id' => 'dev-1', 'name' => 'Phone 1'], ['id' => 'dev-2', 'name' => 'Phone 2']];
$start = (int) (ceil(time() / 60) * 60) + 3600;
$rows = [
    ['record_id' => 'R1', 'values' => ['phone' => '+66981110001', 'message' => 'Follow-up 1']],
    ['record_id' => 'NEW', 'values' => ['phone' => '+66982220002', 'message' => 'First']],
];
$slots = $assign->invoke($queue, $rows, $phones, $start, 5, 5, '10:00', 50);
check($slots[0]['phone']['id'] === 'dev-1', 'follow-up for known number stays on owner phone');
// New number uses earliest-free (either phone is fine; just ensure it assigned).
check(in_array($slots[1]['phone']['id'], ['dev-1', 'dev-2'], true), 'new number gets a ready phone');

// Two prior owners: each follow-up sticks to its own phone even when the other is freer.
$db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES ('+66983330003', 'B', 'Hi', 'sent', NULL, 'Phone 2', 'dev-2', ?, 1, 'R2', ?, 0)")
    ->execute([$now, $now]);
// Make dev-1 look busier so earliest-free would prefer dev-2 without sticky.
for ($i = 0; $i < 3; $i++) {
    $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES (?, 'X', 'pad', 'waiting', NULL, 'Phone 1', 'dev-1', ?, 1, ?, ?, 0)")
        ->execute(['+66990000' . $i, gmdate('c', $start + $i * 300), 'PAD' . $i, $now]);
}
$followRows = [
    ['record_id' => 'R1b', 'values' => ['phone' => '+66981110001', 'message' => 'Wave 2']],
    ['record_id' => 'R2b', 'values' => ['phone' => '+66983330003', 'message' => 'Wave 2']],
];
$pinned = $assign->invoke($queue, $followRows, $phones, $start, 5, 5, '10:00', 50);
check($pinned[0]['phone']['id'] === 'dev-1', 'R1 follow-up stays on dev-1 despite a busier cursor');
check($pinned[1]['phone']['id'] === 'dev-2', 'R2 follow-up stays on dev-2');

// --- sticky compact: prefer owner; never fall back when owner is down ---
$db->exec("DELETE FROM whatsapp_sends WHERE status = 'waiting'");
$db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES ('+66981110001', 'A', 'Follow-up 1', 'waiting', NULL, 'Phone 2', 'dev-2', ?, 1, 'WAIT-R1', ?, 0)")
    ->execute([gmdate('c', $start + 7200), $now]);
$queue->compact();
$after = (string) $db->query("SELECT device_id FROM whatsapp_sends WHERE record_id = 'WAIT-R1'")->fetchColumn();
check($after === 'dev-1', 'compact moves waiting follow-up onto the owner phone, got ' . $after);

// Owner down: stay on owner — do not send from another phone.
$link->ready = ['dev-2'];
$db->prepare("UPDATE whatsapp_sends SET device_id = 'dev-1', due_at = ? WHERE record_id = 'WAIT-R1'")
    ->execute([gmdate('c', $start + 10800)]);
$queue->compact();
$stuck = (string) $db->query("SELECT device_id FROM whatsapp_sends WHERE record_id = 'WAIT-R1'")->fetchColumn();
check($stuck === 'dev-1', 'compact leaves follow-up on owner when owner is not ready, got ' . $stuck);

echo "OK: sticky follow-up assign/compact and can_queue rules\n";
