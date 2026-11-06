<?php
declare(strict_types=1);

use App\Services\WhatsApp\WhatsAppSentService;

$sent = new WhatsAppSentService($db);
try {
    if ($path === '/api/whatsapp/sent' && $method === 'GET') {
        respond($sent->listing($_GET));
    }
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
}
respond(['error' => 'Endpoint not found.'], 404);
