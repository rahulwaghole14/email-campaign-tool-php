<?php
declare(strict_types=1);

use App\Services\WhatsApp\WhatsAppOverviewService;

$overview = new WhatsAppOverviewService($db);
try {
    if ($path === '/api/whatsapp/overview' && $method === 'GET') {
        respond($overview->snapshot((string) ($_GET['range'] ?? '7'), (string) ($_GET['device'] ?? '')));
    }
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    respond(['error' => $e->getMessage()], 502);
}
respond(['error' => 'Endpoint not found.'], 404);
