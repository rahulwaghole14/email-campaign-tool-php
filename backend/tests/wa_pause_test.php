<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\WhatsApp\DeviceLink;
use App\Services\WhatsApp\WhatsAppQueue;
use App\Services\WhatsApp\WhatsAppTables;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);
WhatsAppTables::ensure($db);
$existing = array_column($db->query('PRAGMA table_info(whatsapp_sheet_sources)')->fetchAll(PDO::FETCH_ASSOC), 'name');
foreach (['delay_minutes INTEGER NULL', 'daily_limit INTEGER NULL'] as $col) {
    $name = explode(' ', $col)[0];
    if (!in_array($name, $existing, true)) $db->exec("ALTER TABLE whatsapp_sheet_sources ADD COLUMN $col");
}
WhatsAppTables::ensure($db);

$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, paused) VALUES (1, 'a', 1, 'Sheet A', 1, '{}', '2026-01-01T00:00:00+00:00', 1, 20, 0)");
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, paused) VALUES (2, 'b', 2, 'Sheet B', 1, '{}', '2026-01-01T00:00:00+00:00', 1, 20, 0)");

$link = new class($db) extends DeviceLink {
    public int $calls = 0;
    public array $sent = [];
    public function roster(): array
    {
        return ['devices' => [
            ['id' => 'dev-1', 'name' => 'Phone 1', 'status' => 'ready'],
            ['id' => 'dev-2', 'name' => 'Phone 2', 'status' => 'ready'],
        ], 'slots' => 5];
    }
    public function sendText(string $receiver, string $message, ?string $targetDeviceId = null): array
    {
        $this->calls++;
        $this->sent[] = $receiver;
        $id = $targetDeviceId ?: 'dev-1';
        return ['device_id' => $id, 'name' => $id === 'dev-2' ? 'Phone 2' : 'Phone 1'];
    }
};
$queue = new WhatsAppQueue($db, $link);
$due = gmdate('c', time() - 60);
$insert = $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES (?, 'N', 'Hi', 'waiting', NULL, ?, ?, ?, ?, ?, ?, 0)");
$insert->execute(['+919800000001', 'Phone 1', 'dev-1', $due, 1, 'A1', $due]);
$insert->execute(['+919800000002', 'Phone 2', 'dev-2', $due, 2, 'B1', $due]);

check($queue->isPaused(1) === false && $queue->isPaused(2) === false, 'sheets are not paused by default');
check($queue->setPaused(true, 1) === ['paused' => true, 'source_id' => 1], 'pause reports this sheet');
check($queue->isPaused(1) === true && $queue->isPaused(2) === false, 'only sheet A is paused');
check($queue->today(1)['paused'] === true && $queue->today(2)['paused'] === false, 'today status is per sheet');

$result = $queue->run();
check($link->calls === 1 && $result['sent'] === 1, 'paused sheet A is skipped and sheet B still sends');
check($db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'A1'")->fetchColumn() === 'waiting', 'sheet A row stays waiting');
check($db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'B1'")->fetchColumn() === 'sent', 'sheet B row is sent');

$queue->setPaused(false, 1);
check($queue->isPaused(1) === false, 'sheet A resume is saved');
$result = $queue->run();
check($link->calls === 2 && $result['sent'] === 1, 'after resume only sheet A sends its waiting row');
check($db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'A1'")->fetchColumn() === 'sent', 'sheet A row is sent after resume');

// Global pause migrates onto every sheet once, then is removed.
$db->exec("INSERT INTO whatsapp_queue_state (name, value) VALUES ('paused', '1')");
$db->exec('UPDATE whatsapp_sheet_sources SET paused = 0');
WhatsAppTables::ensure($db);
check((int) $db->query('SELECT paused FROM whatsapp_sheet_sources WHERE id = 1')->fetchColumn() === 1, 'global pause migrates onto sheet A');
check((int) $db->query('SELECT paused FROM whatsapp_sheet_sources WHERE id = 2')->fetchColumn() === 1, 'global pause migrates onto sheet B');
check($db->query("SELECT COUNT(*) FROM whatsapp_queue_state WHERE name = 'paused'")->fetchColumn() == 0, 'global pause flag is cleared');

echo "OK: pause is per sheet and resume does not start other sheets\n";
