<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\Sheets\SheetService;
use App\Services\Sheets\WhatsAppSheetService;
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

$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, paused) VALUES (1, 'a', 1, 'Old Sheet', 1, '{}', '2026-01-01T00:00:00+00:00', 1, 20, 1)");
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit, paused) VALUES (2, 'b', 2, 'Keep Sheet', 1, '{}', '2026-01-01T00:00:00+00:00', 1, 20, 0)");

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
        return ['device_id' => $targetDeviceId ?: 'dev-1', 'name' => 'Phone'];
    }
};

$due = gmdate('c', time() - 60);
$insert = $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES (?, 'N', 'Hi', 'waiting', NULL, ?, ?, ?, ?, ?, ?, 0)");
$insert->execute(['+919800000001', 'Phone 1', 'dev-1', $due, 1, 'OLD1', $due]);
$insert->execute(['+919800000002', 'Phone 2', 'dev-2', $due, 2, 'KEEP1', $due]);

$sheets = new WhatsAppSheetService($db, new SheetService($db, static fn () => []), static fn () => []);
$sheets->delete(1);

check($db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'OLD1'")->fetchColumn() === 'cancelled', 'delete cancels waiting rows for that sheet');
check($db->query("SELECT error FROM whatsapp_sends WHERE record_id = 'OLD1'")->fetchColumn() === 'Sheet removed', 'cancelled row notes sheet removed');
check($db->query('SELECT COUNT(*) FROM whatsapp_sheet_sources WHERE id = 1')->fetchColumn() == 0, 'sheet source row is gone');
check($db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'KEEP1'")->fetchColumn() === 'waiting', 'other sheet waiting rows stay');

// Simulate an older delete that left orphans (source gone, rows still waiting).
$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES ('+919800000099', 'N', 'Ghost', 'waiting', NULL, 'Phone 1', 'dev-1', '$due', 99, 'GHOST', '$due', 0)");

$queue = new WhatsAppQueue($db, $link);
$result = $queue->run();
check($db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'GHOST'")->fetchColumn() === 'cancelled', 'orphaned waiting rows are cancelled on run');
check(!in_array('+919800000099', $link->sent, true), 'orphaned row is never sent');
check($link->calls === 1 && $result['sent'] === 1, 'only the surviving sheet sends');
check($db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'KEEP1'")->fetchColumn() === 'sent', 'surviving sheet row is sent');

echo "OK: delete and orphan cleanup stop background sends\n";
