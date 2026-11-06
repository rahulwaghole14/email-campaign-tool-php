<?php
declare(strict_types=1);

use App\Services\WhatsApp\DeviceLink;
use App\Services\WhatsApp\WhatsAppChatService;
use App\Services\WhatsApp\WhatsAppSentService;

$sent = new WhatsAppSentService($db);
$chats = new WhatsAppChatService($db);
$link = new DeviceLink($db);
try {
    if ($path === '/api/whatsapp/send' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input)) $input = [];
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? '')) ?? '';
        if (strlen($phone) === 10) $phone = '91' . $phone;
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            throw new InvalidArgumentException('Enter a phone number with the country code.');
        }
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 4000) {
            throw new InvalidArgumentException('Write a message of up to 4000 characters.');
        }
        $deviceId = trim((string) ($input['device_id'] ?? ''));
        try {
            $from = $link->sendText($phone, $message, $deviceId !== '' ? $deviceId : null);
            $at = gmdate('c');
            $id = $sent->record('+' . $phone, $message, 'sent', $from['name'], '', $at, $from['device_id']);
            $chats->noteOutgoing('+' . $phone, $message, $at, $from['device_id']);
        } catch (RuntimeException $e) {
            $sent->record('+' . $phone, $message, 'failed', '', $e->getMessage());
            throw $e;
        }
        respond(['id' => $id]);
    }
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    respond(['error' => $e->getMessage()], 502);
}
respond(['error' => 'Endpoint not found.'], 404);
