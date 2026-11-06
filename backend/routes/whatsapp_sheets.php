<?php
declare(strict_types=1);
use App\Services\Google\GoogleClient;
use App\Services\Sheets\SheetService;
use App\Services\Sheets\UploadedWorkbook;
use App\Services\Sheets\WhatsAppSheetService;
use App\Services\WhatsApp\DeviceLink;

$google = new GoogleClient($db);
$uploads = new UploadedWorkbook($db);
$fetchSheet = fn($method, $path, $body) => str_starts_with(rawurldecode($path), 'upload:') ? $uploads->request($method, $path, $body) : $google->request($method, $path, $body);
$reader = new SheetService($db, $fetchSheet);
$whatsappSheets = new WhatsAppSheetService($db, $reader, $fetchSheet);
try {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 300000) respond(['error' => 'Request too large.'], 413);
    $input = $method === 'POST' ? json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR) : [];
    if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
    if ($path === '/api/whatsapp/sheets' && $method === 'GET') respond(['sources' => $whatsappSheets->sources()]);
    if ($path === '/api/whatsapp/sheets' && $method === 'POST') respond(['id' => $whatsappSheets->save($input)], 201);
    if (preg_match('#^/api/whatsapp/sheets/(\d+)(?:/(mapping|edit|sender|schedule))?$#', $path, $matches)) {
        $sourceId = (int) $matches[1];
        $action = $matches[2] ?? '';
        if ($method === 'DELETE' && $action === '') { $whatsappSheets->delete($sourceId); respond(['ok' => true]); }
        if ($method === 'GET' && $action === '') respond($whatsappSheets->preview($sourceId));
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
            $whatsappSheets->setSchedule($sourceId, $startDate, $delayMinutes);
            respond(['ok' => true]);
        }
    }
    respond(['error' => 'Endpoint not found.'], 404);
} catch (PDOException $e) {
    error_log('WhatsApp sheet database operation failed.');
    respond(['error' => 'Unable to save the WhatsApp sheet.'], 500);
} catch (RuntimeException $e) {
    respond(['error' => $e->getMessage()], 409);
}
