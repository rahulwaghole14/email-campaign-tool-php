<?php
declare(strict_types=1);

namespace App\Services\WhatsApp;

use InvalidArgumentException;
use RuntimeException;

/** Reads the platform 2-way inbox: threads, history, and live replies. */
final class WhatsAppInbox
{
    public function __construct(private DeviceLink $link) {}

    public function conversations(): ?array
    {
        $data = $this->read('GET', '/api/inbox/conversations');
        $rows = $data['conversations'] ?? $data['data']['conversations'] ?? null;
        if (!is_array($rows)) return null;
        $chats = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $jid = trim((string) ($row['remote_jid'] ?? ''));
            $phone = $this->phone((string) ($row['sender_phone'] ?? ''), $jid);
            if ($jid === '' || $phone === '') continue;
            $chats[] = [
                'id' => $jid,
                'remote_jid' => $jid,
                'phone' => $phone,
                'name' => trim((string) ($row['sender_name'] ?? '')),
                'last_message' => (string) ($row['last_message'] ?? ''),
                'last_at' => $this->moment((string) ($row['last_message_time'] ?? '')),
                'unread_count' => (int) ($row['unread_count'] ?? 0),
            ];
        }
        return ['chats' => $chats];
    }

    public function thread(string $remoteJid): array
    {
        $remoteJid = trim($remoteJid);
        if (!preg_match('/^\d{8,15}@s\.whatsapp\.net$/', $remoteJid)) {
            throw new InvalidArgumentException('Choose a chat before opening messages.');
        }
        $data = $this->read('GET', '/api/inbox/messages?remote_jid=' . rawurlencode($remoteJid));
        $rows = $data['messages'] ?? $data['data']['messages'] ?? null;
        if (!is_array($rows)) throw new RuntimeException('The inbox did not return this chat.');
        $phone = '';
        $name = '';
        $messages = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $inbound = ($row['direction'] ?? '') === 'inbound';
            $phone = $this->phone((string) ($row['sender_phone'] ?? ''), $remoteJid) ?: $phone;
            if ($inbound && trim((string) ($row['sender_name'] ?? '')) !== '') $name = trim((string) $row['sender_name']);
            $messages[] = [
                'id' => (int) ($row['id'] ?? 0),
                'direction' => $inbound ? 'in' : 'out',
                'body' => (string) ($row['message'] ?? ''),
                'status' => strtolower((string) ($row['status'] ?? '')),
                'created_at' => $this->moment((string) ($row['created_at'] ?? '')),
            ];
        }
        return [
            'chat' => [
                'id' => $remoteJid,
                'remote_jid' => $remoteJid,
                'phone' => $phone !== '' ? $phone : $this->phone('', $remoteJid),
                'name' => $name,
            ],
            'messages' => $messages,
        ];
    }

    public function send(string $remoteJid, string $phone, string $message): array
    {
        $remoteJid = trim($remoteJid);
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (!preg_match('/^\d{8,15}@s\.whatsapp\.net$/', $remoteJid)) {
            throw new InvalidArgumentException('Choose a chat before sending.');
        }
        if ($message === '' || mb_strlen($message) > 4000) {
            throw new InvalidArgumentException('Write a message of up to 4000 characters.');
        }
        try {
            $device = $this->link->connectedDevice();
        } catch (RuntimeException $e) {
            throw new RuntimeException('The live inbox is not answering right now. Try again in a moment.');
        }
        if ($device === null) {
            throw new RuntimeException('The phone is not linked. Open Linked device and scan again.');
        }
        $body = [
            'remote_jid' => $remoteJid,
            'phone' => $digits,
            'message' => $message,
        ];
        if ($device !== null) $body['device_uuid'] = $device['device_uuid'];
        $data = $this->read('POST', '/api/inbox/send', $body);
        return is_array($data['data'] ?? null) ? $data['data'] : [];
    }

    private function read(string $method, string $path, ?array $body = null): array
    {
        try {
            $data = $this->link->request($method, $path, $body);
        } catch (RuntimeException $e) {
            throw new RuntimeException('The live inbox is not answering right now.');
        }
        if (!is_array($data)) throw new RuntimeException('The live inbox is not answering right now.');
        return $data;
    }

    private function phone(string $raw, string $jid): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '' && preg_match('/^(\d{8,15})@/', $jid, $match)) $digits = $match[1];
        return $digits === '' ? '' : '+' . $digits;
    }

    private function moment(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
            return str_replace(' ', 'T', $raw) . 'Z';
        }
        return $raw;
    }
}
