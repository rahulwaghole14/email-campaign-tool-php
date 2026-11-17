<?php
declare(strict_types=1);
use App\Services\Google\GoogleClient;
use App\Services\Sheets\SheetService;
use App\Services\Sheets\UploadedWorkbook;
use App\Services\Sheets\WhatsAppSheetService;
use App\Services\WhatsApp\DeviceLink;
use App\Services\WhatsApp\WhatsAppQueue;

$google = new GoogleClient($db);
$uploads = new UploadedWorkbook($db);
$fetchSheet = fn($method, $path, $body) => str_starts_with(rawurldecode($path), 'upload:') ? $uploads->request($method, $path, $body) : $google->request($method, $path, $body);
$reader = new SheetService($db, $fetchSheet);
$whatsappSheets = new WhatsAppSheetService($db, $reader, $fetchSheet);
try {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 300000) respond(['error' => 'Request too large.'], 413);
    $input = $method === 'POST' ? json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR) : [];
    if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
    // Optional: send only these rows (checkbox selection). Absent or null = every ready row.
    $recordIds = is_array($input['record_ids'] ?? null)
        ? array_values(array_filter(array_map(static fn ($id): string => is_scalar($id) ? trim((string) $id) : '', $input['record_ids']), static fn (string $id): bool => $id !== ''))
        : null;
    if ($path === '/api/whatsapp/sheets' && $method === 'GET') respond(['sources' => $whatsappSheets->sources()]);
    if ($path === '/api/whatsapp/sheets' && $method === 'POST') respond(['id' => $whatsappSheets->save($input)], 201);
    if ($path === '/api/whatsapp/sheets/run' && $method === 'POST') {
        respond((new WhatsAppQueue($db, new DeviceLink($db)))->run());
    }
    if ($path === '/api/whatsapp/sheets/today' && $method === 'GET') {
        respond((new WhatsAppQueue($db, new DeviceLink($db)))->today());
    }
    if ($path === '/api/whatsapp/sheets/pause' && $method === 'POST') {
        respond((new WhatsAppQueue($db, new DeviceLink($db)))->setPaused(!empty($input['paused'])));
    }
    if ($path === '/api/whatsapp/sheets/rebalance' && $method === 'POST') {
        respond((new WhatsAppQueue($db, new DeviceLink($db)))->compact());
    }
    if ($path === '/api/whatsapp/sheets/retry-failed' && $method === 'POST') {
        respond(['queued' => (new WhatsAppQueue($db, new DeviceLink($db)))->retryFailed()]);
    }
    if (preg_match('#^/api/whatsapp/sheets/(\d+)(?:/(mapping|edit|sender|schedule|plan|queue|progress|row))?$#', $path, $matches)) {
        $sourceId = (int) $matches[1];
        $action = $matches[2] ?? '';
        if ($method === 'DELETE' && $action === '') { $whatsappSheets->delete($sourceId); respond(['ok' => true]); }
        if ($method === 'GET' && $action === '') {
            $preview = $whatsappSheets->preview($sourceId);
            $states = (new WhatsAppQueue($db, new DeviceLink($db)))->statuses($sourceId);
            foreach ($preview['records'] as &$record) {
                $info = $states[(string) $record['record_id']] ?? null;
                $record['send_status'] = is_array($info) ? (string) ($info['status'] ?? 'not_queued') : 'not_queued';
                $record['send_error'] = is_array($info) ? (string) ($info['error'] ?? '') : '';
            }
            unset($record);
            respond($preview);
        }
        if ($method === 'POST' && $action === 'plan') respond((new WhatsAppQueue($db, new DeviceLink($db), $whatsappSheets))->plan($sourceId, $recordIds));
        if ($method === 'POST' && $action === 'queue') respond((new WhatsAppQueue($db, new DeviceLink($db), $whatsappSheets))->queue($sourceId, $recordIds));
        if ($method === 'GET' && $action === 'progress') respond((new WhatsAppQueue($db, new DeviceLink($db)))->progress($sourceId));
        if ($method === 'POST' && $action === 'row') respond((new WhatsAppQueue($db, new DeviceLink($db)))->rowStatus($sourceId, trim((string) ($input['record_id'] ?? ''))));
        if ($method === 'POST' && $action === 'mapping') respond(['id' => $whatsappSheets->save($input, $sourceId)]);
        if ($method === 'POST' && $action === 'edit') { $whatsappSheets->edit($sourceId, $input); respond(['ok' => true]); }
        if ($method === 'POST' && $action === 'sender') {
            $mode = (($input['sender_mode'] ?? 'pick') === 'all') ? 'all' : 'pick';
            if ($mode === 'all') {
                $whatsappSheets->setSenders($sourceId, [], 'all');
                respond(['ok' => true, 'sender_mode' => 'all', 'device_ids' => []]);
            }
            $ids = $input['device_ids'] ?? null;
            if (!is_array($ids)) {
                $one = trim((string) ($input['device_id'] ?? ''));
                $ids = $one === '' ? [] : [$one];
            }
            $readyIds = [];
            foreach ((new DeviceLink($db))->roster()['devices'] as $device) {
                if (in_array($device['status'] ?? '', ['ready', 'connected'], true)) $readyIds[] = (string) ($device['id'] ?? '');
            }
            $chosen = [];
            foreach ($ids as $deviceId) {
                $deviceId = trim((string) $deviceId);
                if ($deviceId === '' || !in_array($deviceId, $readyIds, true)) {
                    throw new InvalidArgumentException('Choose a phone that is ready.');
                }
                $chosen[] = $deviceId;
            }
            $whatsappSheets->setSenders($sourceId, $chosen, 'pick');
            respond(['ok' => true, 'sender_mode' => 'pick', 'device_ids' => $chosen]);
        }
        if ($method === 'POST' && $action === 'schedule') {
            $startDate = trim((string) ($input['start_date'] ?? ''));
            $delay = $input['delay_minutes'] ?? null;
            $delayMinutes = ($delay === null || $delay === '') ? null : (int) $delay;
            $delayMaxRaw = $input['delay_max_minutes'] ?? null;
            $delayMaxMinutes = ($delayMaxRaw === null || $delayMaxRaw === '') ? null : (int) $delayMaxRaw;
            $dailyRaw = $input['daily_limit'] ?? null;
            $dailyLimit = ($dailyRaw === null || $dailyRaw === '') ? null : (int) $dailyRaw;
            $whatsappSheets->setSchedule($sourceId, $startDate, $delayMinutes, trim((string) ($input['start_time'] ?? '')), $dailyLimit, $delayMaxMinutes);
            $countStmt = $db->prepare("SELECT COUNT(*) FROM whatsapp_sends WHERE source_id = ? AND status = 'waiting'");
            $countStmt->execute([$sourceId]);
            $waiting = (int) $countStmt->fetchColumn();
            $moved = 0;
            if ($waiting > 0) {
                $moved = (int) ((new WhatsAppQueue($db, new DeviceLink($db)))->compact()['moved'] ?? 0);
            }
            respond(['ok' => true, 'waiting' => $waiting, 'moved' => $moved]);
        }
    }
    respond(['error' => 'Endpoint not found.'], 404);
} catch (PDOException $e) {
    error_log('WhatsApp sheet database operation failed.');
    respond(['error' => 'Unable to save the WhatsApp sheet.'], 500);
} catch (RuntimeException $e) {
    respond(['error' => $e->getMessage()], 409);
}
