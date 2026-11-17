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

$queue = new WhatsAppQueue($db, new DeviceLink($db));
$dayOf = static fn (int $at): string => (new DateTimeImmutable('@' . $at))
    ->setTimezone(new DateTimeZone('Asia/Kolkata'))
    ->format('Y-m-d');
$dailyLimit = new ReflectionMethod(WhatsAppQueue::class, 'dailyLimit');
$dailyLimit->setAccessible(true);
$assign = new ReflectionMethod(WhatsAppQueue::class, 'assign');
$assign->setAccessible(true);

// 1. The saved limit is used, and a missing or bad value falls back to the default of 20.
check($dailyLimit->invoke($queue, null) === 20, 'no saved limit should default to 20');
check($dailyLimit->invoke($queue, '50') === 50, 'saved 50 should be used');
check($dailyLimit->invoke($queue, 100) === 100, '100 is the largest allowed limit');
check($dailyLimit->invoke($queue, 0) === 20, 'zero should fall back to 20');
check($dailyLimit->invoke($queue, 500) === 20, 'above 100 should fall back to 20');

// 2. Two phones, 100 rows: no phone may go past its daily limit on any day.
$phones = [['id' => 'dev-1', 'name' => 'Phone 1'], ['id' => 'dev-2', 'name' => 'Phone 2']];
$rows = [];
for ($i = 1; $i <= 100; $i++) $rows[] = ['record_id' => (string) $i, 'values' => []];
$start = (int) (ceil(time() / 60) * 60) + 3600;

foreach ([20, 50] as $daily) {
    $slots = $assign->invoke($queue, $rows, $phones, $start, 1, 1, '10:00', $daily);
    check(count($slots) === 100, "all 100 rows should be assigned at daily $daily");
    $perPhoneDay = [];
    foreach ($slots as $slot) {
        $key = $slot['phone']['id'] . '|' . $dayOf($slot['at']);
        $perPhoneDay[$key] = ($perPhoneDay[$key] ?? 0) + 1;
    }
    foreach ($perPhoneDay as $key => $count) {
        check($count <= $daily, "$key has $count rows, above the daily limit of $daily");
    }
    $days = count(array_unique(array_map(static fn ($s) => $dayOf($s['at']), $slots)));
    echo "daily $daily: 100 rows over $days day(s)\n";
    $result[$daily] = $days;
}

// 3. A higher limit never needs more days than a lower one.
check($result[50] < $result[20], 'a limit of 50 should finish in fewer days than 20');

echo "OK: per-sheet daily limit works\n";
