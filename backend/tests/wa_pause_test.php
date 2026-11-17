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
$db->exec('ALTER TABLE whatsapp_sheet_sources ADD COLUMN delay_minutes INTEGER NULL');

$link = new class($db) extends DeviceLink {
    public int $calls = 0;
    public function roster(): array { return ['devices' => [['id' => 'dev-1', 'name' => 'Phone 1', 'status' => 'ready']], 'slots' => 5]; }
    public function sendText(string $receiver, string $message, ?string $targetDeviceId = null): array { $this->calls++; return ['device_id' => 'dev-1', 'name' => 'Phone 1']; }
};
$queue = new WhatsAppQueue($db, $link);
$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES ('+919800000001', 'N', 'Hi', 'waiting', NULL, 'Phone 1', 'dev-1', '" . gmdate('c', time() - 60) . "', NULL, 'R1', '" . gmdate('c') . "', 0)");

check($queue->isPaused() === false, 'sending is not paused by default');
check($queue->setPaused(true) === ['paused' => true], 'pause reports paused');
check($queue->isPaused() === true, 'pause is saved');

$result = $queue->run();
check(($result['paused'] ?? false) === true && $link->calls === 0, 'a paused queue sends nothing');
check($db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'R1'")->fetchColumn() === 'waiting', 'a paused row stays waiting');
check($queue->today()['paused'] === true, 'the panel knows sending is paused');

$queue->setPaused(false);
check($queue->isPaused() === false, 'resume is saved');
$result = $queue->run();
check($link->calls === 1 && $result['sent'] === 1, 'after resume the waiting row is sent');
check($db->query("SELECT status FROM whatsapp_sends WHERE record_id = 'R1'")->fetchColumn() === 'sent', 'the row is sent after resume');

$queue->setPaused(true);
$queue->setPaused(true);
check($db->query("SELECT COUNT(*) FROM whatsapp_queue_state WHERE name = 'paused'")->fetchColumn() == 1, 'the pause flag is stored once');

echo "OK: pause stops sending and resume continues\n";
