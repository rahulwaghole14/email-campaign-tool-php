<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\WhatsApp\DeviceLink;
use App\Services\WhatsApp\WhatsAppTables;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);
WhatsAppTables::ensure($db);

$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('active-dev-1', 'Whatsapp Campaign 1', '+918830853419', 'ready', '2026-01-01T00:00:00+00:00')");
$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('blocked-dev-2', 'Whatsapp Campaign 2', '+919881976526', 'blocked', '2026-01-01T00:00:00+00:00')");
$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('orphan-dev-9', 'WAC Orphan', NULL, 'disconnected', '2026-01-01T00:00:00+00:00')");
$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES ('+919000000001', 'N', 'Hi', 'sent', NULL, 'Old Campaign', 'history-dev-3', '2026-01-01T00:00:00+00:00')");

$link = new class($db) extends DeviceLink {
    public function roster(): array
    {
        return [
            'devices' => [
                ['id' => 'active-dev-1', 'name' => 'Whatsapp Campaign 1', 'phone' => '+918830853419', 'status' => 'ready'],
            ],
            'slots' => 5,
        ];
    }
};

$list = $link->filterList();
$byId = [];
foreach ($list['devices'] as $device) $byId[$device['id']] = $device;

check(isset($byId['active-dev-1']), 'ready phone is listed');
check($byId['active-dev-1']['active'] === true && $byId['active-dev-1']['status'] === 'active', 'ready phone is Active');
check($byId['active-dev-1']['phone'] === '+918830853419', 'ready phone keeps its number');

check(isset($byId['blocked-dev-2']), 'blocked phone stays in the filter list');
check($byId['blocked-dev-2']['active'] === false && $byId['blocked-dev-2']['status'] === 'inactive', 'blocked phone is Inactive');
check($byId['blocked-dev-2']['phone'] === '+919881976526', 'blocked phone still shows its number');

check(isset($byId['history-dev-3']), 'a sender only found in Sent history is listed');
check($byId['history-dev-3']['active'] === false, 'history-only sender is Inactive');
check($byId['history-dev-3']['name'] === 'Old Campaign', 'history-only sender keeps its saved name');

check(!isset($byId['orphan-dev-9']), 'local orphan with no Sent history stays out of the dropdown');

check($list['devices'][0]['active'] === true, 'Active phones are listed before Inactive ones');

WhatsAppTables::saveRoster($db, [
    ['id' => 'blocked-dev-2', 'name' => 'Whatsapp Campaign 2', 'phone' => null, 'status' => 'disconnected'],
]);
$kept = $db->query("SELECT phone FROM whatsapp_devices WHERE device_id = 'blocked-dev-2'")->fetchColumn();
check((string) $kept === '+919881976526', 'saveRoster keeps the last known phone when live list omits it');

echo "OK: filter list keeps live, blocked, Sent history; drops orphans\n";
