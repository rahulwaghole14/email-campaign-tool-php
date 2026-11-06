<?php
declare(strict_types=1);

use App\Services\WhatsApp\PlatformSettings;

$settings = new PlatformSettings($db);
try {
    if ($path === '/api/whatsapp/settings' && $method === 'GET') respond($settings->read());
    if ($path === '/api/whatsapp/settings' && $method === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8000) respond(['error' => 'Request too large.'], 413);
        $input = json_decode(file_get_contents('php://input') ?: '', true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
        respond($settings->save(
            (string) ($input['name'] ?? ''),
            (string) ($input['base_url'] ?? ''),
            (string) ($input['access_token'] ?? '')
        ));
    }
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
} catch (JsonException) {
    respond(['error' => 'Invalid request.'], 422);
}
respond(['error' => 'Endpoint not found.'], 404);
