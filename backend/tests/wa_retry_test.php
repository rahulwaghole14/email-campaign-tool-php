<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\WhatsApp\DeviceLink;
use App\Services\WhatsApp\PhoneServiceUnavailable;
use App\Services\WhatsApp\WhatsAppQueue;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);
App\Services\WhatsApp\WhatsAppTables::ensure($db);
// The sheet service adds this column on the live site; the queue reads it, so the test adds it too.
$db->exec('ALTER TABLE whatsapp_sheet_sources ADD COLUMN delay_minutes INTEGER NULL');

/** A phone service that answers from a script instead of the network. */
$link = new class($db) extends DeviceLink {
    /** @var list<Throwable|null> */
    public array $script = [];
    public int $calls = 0;

    public function roster(): array
    {
        return ['devices' => [['id' => 'dev-1', 'name' => 'Phone 1', 'status' => 'ready']], 'slots' => 5];
    }

    public function sendText(string $receiver, string $message, ?string $targetDeviceId = null): array
    {
        $this->calls++;
        $next = array_shift($this->script);
        if ($next instanceof Throwable) throw $next;
        return ['device_id' => (string) $targetDeviceId, 'name' => 'Phone 1'];
    }
};

$queue = new WhatsAppQueue($db, $link);
$insert = $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at) VALUES (?, ?, ?, 'waiting', NULL, 'Phone 1', 'dev-1', ?, NULL, ?, ?)");
$addRow = function (string $record, int $dueOffset) use ($db, $insert): int {
    $due = gmdate('c', time() + $dueOffset);
    $insert->execute(['+919800000000', 'Test', 'Hello', $due, $record, $due]);
    return (int) $db->lastInsertId();
};
$row = function (int $id) use ($db): array {
    $query = $db->prepare('SELECT status, attempts, error, due_at FROM whatsapp_sends WHERE id = ?');
    $query->execute([$id]);
    return $query->fetch(PDO::FETCH_ASSOC);
};

$a = $addRow('A', -120);
$b = $addRow('B', -60);

// 1. The service does not answer: row A goes back to waiting for 2 minutes, and B is held for a minute. Nothing fails.
$link->script = [new PhoneServiceUnavailable('The phone service is not answering right now. (HTTP 503)')];
$result = $queue->run();
check($result['retried'] === 1 && $result['failed'] === 0 && $result['sent'] === 0, 'a service error should retry, not fail');
$ra = $row($a);
check($ra['status'] === 'waiting' && (int) $ra['attempts'] === 1, 'row A should wait with 1 attempt used');
check(strtotime((string) $ra['due_at']) >= time() + 110, 'row A should be tried again in about 2 minutes');
check(str_contains((string) $ra['error'], '503'), 'the real reason should be kept on the row');
$rb = $row($b);
check($rb['status'] === 'waiting' && strtotime((string) $rb['due_at']) >= time() + 55, 'row B should be held for the cool-down');
check($link->calls === 1, 'only one row should be tried per phone per run');

// 2. After the last retry the row fails for good.
$db->prepare("UPDATE whatsapp_sends SET attempts = 4, due_at = ? WHERE id = ?")->execute([gmdate('c', time() - 5), $a]);
$link->script = [new PhoneServiceUnavailable('The phone service is not answering right now. (no answer, curl 28)')];
$result = $queue->run();
check($result['failed'] === 1 && $result['retried'] === 0, 'the fifth failed try should fail the row');
$ra = $row($a);
check($ra['status'] === 'failed' && (int) $ra['attempts'] === 5, 'row A should be failed after 5 tries');

// 3. A permanent error (not a service outage) fails at once and is not retried.
$c = $addRow('C', -30);
$link->script = [new RuntimeException('The selected phone is not connected.')];
$result = $queue->run();
check($result['failed'] === 1 && $result['retried'] === 0, 'a permanent error should fail without a retry');
check($row($c)['status'] === 'failed', 'row C should be failed');

// 4. A good send after earlier trouble goes out.
$d = $addRow('D', -20);
$link->script = [];
$result = $queue->run();
check($result['sent'] === 1, 'a normal send should go out');
check($row($d)['status'] === 'sent', 'row D should be sent');

echo "OK: failed sends retry on service errors only\n";
