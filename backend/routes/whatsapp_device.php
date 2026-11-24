<?php
declare(strict_types=1);

use App\Services\WhatsApp\DeviceLink;

$link = new DeviceLink($db);
try {
    if ($path === '/api/whatsapp/device' && $method === 'GET') respond($link->roster());
    if ($path === '/api/whatsapp/device/filters' && $method === 'GET') respond($link->filterList());
    if ($path === '/api/whatsapp/device/picture' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        $id = is_array($input) ? trim((string) ($input['id'] ?? '')) : '';
        $name = is_array($input) ? trim((string) ($input['name'] ?? '')) : '';
        // Pair on an existing id releases desk Disconnect hold; Add device creates a new slot.
        respond($id !== '' ? $link->pictureFor($id, true) : $link->addDevice($name));
    }
    if ($path === '/api/whatsapp/device/disconnect' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        $id = is_array($input) ? trim((string) ($input['id'] ?? '')) : '';
        respond($link->disconnect($id));
    }
} catch (RuntimeException $e) {
    respond(['error' => $e->getMessage()], 502);
}
respond(['error' => 'Endpoint not found.'], 404);
