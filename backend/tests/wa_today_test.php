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
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit) VALUES (1, 'x', 1, 'Spain agencies', 1, '{}', '2026-01-01T00:00:00+00:00', 2, 30)");

$queue = new WhatsAppQueue($db, new DeviceLink($db));
$insert = $db->prepare("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, due_at, source_id, record_id, created_at, attempts) VALUES (?, ?, 'Hi', ?, ?, 'Phone 1', 'dev-1', ?, ?, ?, ?, ?)");
$add = function (string $status, ?string $due, ?int $source, string $record, string $created, int $attempts = 0, ?string $error = null) use ($insert, $db): int {
    $insert->execute(['+919800000001', 'Name ' . $record, $status, $error, $due, $source, $record, $created, $attempts]);
    return (int) $db->lastInsertId();
};
$now = time();
$iso = static fn (int $at): string => gmdate('c', $at);

$add('sent', $iso($now - 600), 1, 'S1', $iso($now - 600));
$add('sent', $iso($now - 300), 1, 'S2', $iso($now - 300));
$add('sent', $iso($now - 3 * 86400), 1, 'OLD', $iso($now - 3 * 86400));            // another day: not counted
$add('waiting', $iso($now + 60), 1, 'W1', $iso($now + 60));
$add('waiting', $iso($now + 120), 1, 'W2', $iso($now + 120), 2, 'The phone service is not answering right now.'); // retrying
$add('waiting', $iso($now + 3 * 86400), 1, 'LATER', $iso($now + 3 * 86400));       // a later day
$late = $add('waiting', $iso($now - 3600), 1, 'LATE', $iso($now - 3600));          // overdue
$failed = $add('failed', $iso($now - 900), 1, 'F1', $iso($now - 900), 5, 'The phone service is not answering right now.');
$add('failed', $iso($now - 800), 1, 'F2', $iso($now - 800), 5, 'old failure');
$add('sent', $iso($now - 100), 1, 'F2', $iso($now - 100));                         // F2 was re-sent: its old failure must not count
$add('failed', $iso($now - 700), null, 'MANUAL', $iso($now - 700));                // manual send, not a sheet row
$nowa = $add('failed', $iso($now - 650), 1, 'NOWA', $iso($now - 650), 0, 'The phone service did not accept this message (HTTP 400: Number 916625382203 is not registered on WhatsApp).');

$today = $queue->today();
check($today['sent'] === 3, 'sent today should be S1, S2 and the re-sent F2, got ' . $today['sent']);
check($today['left'] === 3, 'three rows are still due today (W1, W2, LATE), got ' . $today['left']);
check($today['later'] === 1, 'one row is due on a later day, got ' . $today['later']);
check($today['failed'] === 1 && $today['failed_list'][0]['name'] === 'Name F1', 'only F1 is a failed sheet row that needs a retry');
check($today['no_whatsapp'] === 1 && $today['no_whatsapp_list'][0]['name'] === 'Name NOWA', 'a number that is not on WhatsApp is listed apart from failures');
check($today['retrying'] === 1, 'W2 is retrying');
check($today['overdue'] === 1, 'LATE is overdue');
check($today['upcoming'][0]['name'] === 'Name LATE', 'overdue rows are shown first, as due now');
check(strtotime((string) $today['upcoming'][0]['at']) >= $now - 2, 'an overdue row is shown as due now, not in the past');
check($today['upcoming'][0]['sheet'] === 'Spain agencies', 'the sheet name should be shown');
check(strtotime((string) $today['finish_today_at']) >= $now + 119, 'today finishes with the last row due today');
check(strtotime((string) $today['all_done_at']) >= $now + 3 * 86400 - 2, 'everything finishes with the last waiting row');
check(count($today['phones']) === 1, 'one phone');
$phone = $today['phones'][0];
check($phone['sent'] === 3 && $phone['left'] === 3 && $phone['later'] === 1 && $phone['cap'] === 30 && $phone['gap'] === 2, 'phone totals, cap and gap should come from the sheet');

$queued = $queue->retryFailed();
check($queued === 1, 'only F1 should be queued again, got ' . $queued);
$state = $db->query("SELECT status, attempts, error FROM whatsapp_sends WHERE id = $failed")->fetch(PDO::FETCH_ASSOC);
check($state['status'] === 'waiting' && (int) $state['attempts'] === 0 && $state['error'] === null, 'F1 should wait again with a clean slate');
check($db->query("SELECT status FROM whatsapp_sends WHERE source_id IS NULL")->fetchColumn() === 'failed', 'a manual failed send is left alone');
check($db->query("SELECT status FROM whatsapp_sends WHERE id = $nowa")->fetchColumn() === 'failed', 'a number that is not on WhatsApp is not queued again');
check($queue->retryFailed() === 0, 'nothing is left to retry');
check($queue->today()['failed'] === 0 && $queue->today()['no_whatsapp'] === 1, 'no failed rows are listed any more');

// Sheet filter: another sheet's waiting rows must not appear in this sheet's Sending status.
// After retry, sheet 1 still has W1, W2, LATE, and re-queued F1 due today (4), plus LATER.
$db->exec("INSERT INTO whatsapp_sheet_sources (id, spreadsheet_id, sheet_id, title, header_row, mapping, created_at, delay_minutes, daily_limit) VALUES (2, 'y', 2, 'Sticky test', 1, '{}', '2026-01-01T00:00:00+00:00', 3, 20)");
$add('waiting', $iso($now + 30), 2, 'OTHER', $iso($now + 30));
$sheetToday = $queue->today(1);
check($sheetToday['source_id'] === 1 && $sheetToday['sheet'] === 'Spain agencies', 'filtered panel names the sheet');
check($sheetToday['left'] === 4, 'sheet filter keeps only this sheet\'s due-today rows, got ' . $sheetToday['left']);
check($sheetToday['later'] === 1, 'sheet filter keeps only this sheet\'s later rows');
$otherToday = $queue->today(2);
check($otherToday['left'] === 1 && $otherToday['sheet'] === 'Sticky test', 'the other sheet sees only its own waiting row');
check($queue->today()['left'] === 5, 'unfiltered today still counts every sheet');

// Upcoming days: today holds the overdue and due-today rows, and later days are counted one by one.
$daysByDate = [];
foreach ($today['days'] as $d) $daysByDate[$d['date']] = $d['count'];
// Today holds the 3 left rows (W1, W2, LATE) and the messages already sent today.
check(($daysByDate[$today['date']] ?? 0) === 3 + $today['sent'], 'today counts its 3 left rows plus the sent ones, got ' . ($daysByDate[$today['date']] ?? 'none'));
check(array_sum($daysByDate) === $today['left'] + $today['later'] + $today['sent'], 'every waiting and sent row is counted on its day');
check(count($today['days']) === 2 && $today['days'][0]['date'] === $today['date'], 'days are listed in order, starting today');
$rowA = $queue->rowStatus(1, 'S1');
check($rowA['label'] === 'Message sent' && $rowA['sent_at'] !== null, 'a sent row shows Message sent and when');
$rowW = $queue->rowStatus(1, 'W2');
check($rowW['label'] === 'Retrying' && $rowW['attempts'] === 2 && str_contains($rowW['detail'], 'Try 3 of 5'), 'a retrying row shows the next try number');
$rowF = $queue->rowStatus(1, 'NOWA');
check($rowF['label'] === 'Not on WhatsApp', 'a non-WhatsApp number is explained');
check($queue->rowStatus(1, 'NEVER')['label'] === 'Not queued', 'a row that was never queued says so');

// Unsafe numbers (+66 with 8 digits after it) are held, never sent; normal numbers are left alone.
$unsafeId = $add('waiting', $iso($now - 60), 1, 'UNSAFE', $iso($now - 60));
$db->prepare("UPDATE whatsapp_sends SET phone = '+6625382203' WHERE id = ?")->execute([$unsafeId]);
$safeId = $add('waiting', $iso($now + 9999), 1, 'SAFE', $iso($now + 9999));
check($queue->holdUnsafe() === 1, 'exactly one unsafe number should be held');
check($db->query("SELECT status FROM whatsapp_sends WHERE id = $unsafeId")->fetchColumn() === 'held', 'the unsafe row is held');
check($db->query("SELECT status FROM whatsapp_sends WHERE id = $safeId")->fetchColumn() === 'waiting', 'a normal row stays waiting');
check($queue->holdUnsafe() === 0, 'holding again changes nothing');
$linkCalls = new class($db) extends DeviceLink {
    public int $calls = 0;
    public function roster(): array { return ['devices' => [['id' => 'dev-1', 'name' => 'Phone 1', 'status' => 'ready']], 'slots' => 5]; }
    public function sendText(string $receiver, string $message, ?string $targetDeviceId = null): array { $this->calls++; return ['device_id' => 'dev-1', 'name' => 'Phone 1']; }
};
(new WhatsAppQueue($db, $linkCalls))->run();
check($db->query("SELECT status FROM whatsapp_sends WHERE id = $unsafeId")->fetchColumn() === 'held', 'a held row is never sent');
$rowHeld = (new WhatsAppQueue($db, new DeviceLink($db)))->rowStatus(1, 'UNSAFE');
check($rowHeld['label'] === 'Held', 'a held row says it is held');

echo "OK: today status and retry failed work\n";
