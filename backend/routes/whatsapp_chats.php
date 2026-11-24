<?php
declare(strict_types=1);

use App\Services\WhatsApp\DeviceLink;
use App\Services\WhatsApp\WhatsAppChatService;
use App\Services\WhatsApp\WhatsAppInbox;

$chats = new WhatsAppChatService($db);
$inbox = new WhatsAppInbox(new DeviceLink($db));
try {
    if ($path === '/api/whatsapp/chats/delete' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input)) $input = [];
        respond($chats->deleteChat((string) ($input['phone'] ?? '')));
    }
    if ($path === '/api/whatsapp/chats/message/delete' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input)) $input = [];
        respond($chats->deleteMessage(
            (string) ($input['phone'] ?? ''),
            (string) ($input['direction'] ?? ''),
            (string) ($input['body'] ?? ''),
            (string) ($input['created_at'] ?? '')
        ));
    }
    if ($path === '/api/whatsapp/chats' && $method === 'GET') {
        try {
            $live = $inbox->conversations();
            if ($live !== null) respond($chats->campaignList($live['chats'] ?? [], $_GET));
        } catch (RuntimeException $e) {
        }
        respond($chats->listing($_GET));
    }
    if ($path === '/api/whatsapp/chats/thread' && $method === 'GET') {
        $choice = waChatDevice($_GET);
        $jid = (string) ($_GET['remote_jid'] ?? '');
        try {
            $thread = $inbox->thread($jid);
            $chats->keepName((string) ($thread['chat']['phone'] ?? ''), (string) ($thread['chat']['name'] ?? ''));
            $chats->remember($thread, $choice['id'], $choice['name']);
        } catch (RuntimeException $e) {
            $thread = $chats->savedThread($jid, $choice['id']);
            if ($thread === null) throw $e;
        }
        if (isset($thread['chat'])) {
            $thread['messages'] = $chats->visibleMessages((string) ($thread['chat']['phone'] ?? ''), $thread['messages'] ?? []);
        }
        if (isset($thread['chat']) && empty($thread['chat']['senders'])) {
            $chatPhone = (string) ($thread['chat']['phone'] ?? '');
            $thread['chat']['senders'] = $chats->sendersForPhone($chatPhone, $choice['id']);
            if (empty($thread['chat']['device_id']) && !empty($thread['chat']['senders'][0]['id'])) {
                $thread['chat']['device_id'] = $thread['chat']['senders'][0]['id'];
            }
        }
        respond(waLimitThread($chats, $thread, $choice));
    }
    if ($path === '/api/whatsapp/inbox/send' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($input)) $input = [];
        $phone = (string) ($input['phone'] ?? '');
        $message = trim((string) ($input['message'] ?? ''));
        $sent = $inbox->send((string) ($input['remote_jid'] ?? ''), $phone, $message);
        if ($phone !== '' && $message !== '') $chats->noteOutgoing($phone, $message, gmdate('c'));
        respond($sent);
    }
    if (preg_match('#^/api/whatsapp/chats/(\d+)$#', $path, $match) && $method === 'GET') {
        $thread = $chats->thread((int) $match[1]);
        if ($thread === null) respond(['error' => 'Chat not found.'], 404);
        respond(waLimitThread($chats, $thread, waChatDevice($_GET)));
    }
} catch (InvalidArgumentException $e) {
    respond(['error' => $e->getMessage()], 422);
} catch (RuntimeException $e) {
    respond(['error' => $e->getMessage()], 502);
}
respond(['error' => 'Endpoint not found.'], 404);

function waChatDevice(array $query): array
{
    $id = trim((string) ($query['device'] ?? ''));
    $name = trim((string) ($query['device_name'] ?? ''));
    if (mb_strlen($name) > 80) $name = mb_substr($name, 0, 80);
    if ($id !== '' && !preg_match('/^[A-Za-z0-9-]{8,80}$/', $id)) {
        throw new InvalidArgumentException('Choose a phone on this account.');
    }
    return ['id' => $id, 'name' => $name];
}

function waLimitThread(App\Services\WhatsApp\WhatsAppChatService $chats, array $thread, array $choice): array
{
    $phone = (string) ($thread['chat']['phone'] ?? '');
    $targetDeviceId = $choice['id'] !== '' ? $choice['id'] : trim((string) ($thread['chat']['device_id'] ?? ''));
    if ($targetDeviceId === '' && !empty($thread['chat']['senders'][0]['id'])) {
        $targetDeviceId = trim((string) $thread['chat']['senders'][0]['id']);
    }
    $targetDeviceName = $choice['name'] !== '' ? $choice['name'] : trim((string) ($thread['chat']['senders'][0]['name'] ?? ''));

    $since = strtotime($chats->firstSent($phone, $targetDeviceId, $targetDeviceName));
    if ($targetDeviceId !== '' && !$since) {
        $since = strtotime($chats->firstSent($phone));
    }
    if ($targetDeviceId !== '' && !$since && !empty($choice['id'])) {
        $thread['messages'] = [];
        return $thread;
    }

    $messages = $thread['messages'] ?? [];
    if ($targetDeviceId !== '' || $since) {
        $messages = array_values(array_filter(
            $messages,
            static function (array $message) use ($since, $targetDeviceId): bool {
                $msgDevId = trim((string) ($message['device_id'] ?? ''));
                if ($targetDeviceId !== '' && $msgDevId !== '' && $msgDevId !== $targetDeviceId) {
                    return false;
                }
                if ($since) {
                    return strtotime((string) ($message['created_at'] ?? '')) >= $since - 2;
                }
                return true;
            }
        ));
    }
    $thread['messages'] = $messages;
    if ($choice['name'] !== '') $thread['chat']['sender'] = $choice['name'];
    return $thread;
}
