<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
App\Support\Database::migrate($db);
App\Services\WhatsApp\WhatsAppTables::ensure($db);

$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('dev-uuid-001', 'WAC 1', '+919876543210', 'ready', datetime('now'))");
$db->exec("INSERT INTO whatsapp_devices (device_id, name, phone, status, updated_at) VALUES ('dev-uuid-002', 'WAC 2', '+919876543211', 'ready', datetime('now'))");

$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES ('918805854727', 'Test', 'Hello', 'sent', '', 'WAC 1', 'dev-uuid-001', datetime('now'))");
$db->exec("INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES ('918805854728', 'Test 2', 'Hello 2', 'sent', '', 'WAC 1', 'dev-uuid-001', datetime('now'))");

$db->exec("INSERT INTO whatsapp_sheet_sources (spreadsheet_id, sheet_id, title, header_row, mapping, created_at) VALUES ('sheet-123', 0, 'oman_varied_outreach_RSL_Solution.xlsx', 1, '{}', datetime('now'))");

$service = new App\Services\WhatsApp\WhatsAppOverviewService($db);
$snap = $service->snapshot('7');

assert($snap['counts']['sent'] === 2, 'Sent count should be 2');
assert($snap['counts']['failed'] === 0, 'Failed count should be 0');
assert($snap['counts']['numbers'] === 2, 'Numbers count should be 2');
assert($snap['today_quota']['used'] === 2, 'Today used should be 2');
assert($snap['today_quota']['total'] === 40, 'Today total should be 40 (2 devices * 20)');
assert(count($snap['days']) === 7, 'Days should be 7');
assert(end($snap['days'])['weekday'] === 'Today', 'Last day weekday should be Today');
assert(end($snap['days'])['is_today'] === true, 'Last day is_today should be true');
assert($snap['active_sheet']['title'] === 'oman_varied_outreach_RSL_Solution.xlsx', 'Active sheet title matches');
assert(count($snap['device_quotas']) === 2, '2 device quotas');
assert($snap['device_quotas'][0]['sent_today'] === 2, 'Dev 1 sent 2 today');
assert($snap['device_quotas'][1]['sent_today'] === 0, 'Dev 2 sent 0 today');

// Also test specific device selection
$snapDev = $service->snapshot('7', 'dev-uuid-001');
assert($snapDev['today_quota']['used'] === 2, 'Dev 1 today used should be 2');
assert($snapDev['today_quota']['total'] === 20, 'Dev 1 today total should be 20');
assert($snapDev['device_name'] === 'WAC 1', 'Device name should be WAC 1');

echo "All WhatsApp Overview Service snapshot checks passed!\n";
