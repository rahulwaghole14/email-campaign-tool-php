<?php
declare(strict_types=1);

namespace App\Services\WhatsApp;

use PDO;
use RuntimeException;

/** Talks to the Message API for the linked phone: picture, linked number, and disconnect. */
final class DeviceLink
{
    private ?array $account = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $devices = null;

    private int $slotLimit = 5;

    public function __construct(private PDO $db)
    {
    }

    public function status(): array
    {
        $id = $this->currentId();
        if ($id === null) return $this->openPicture();
        $data = $this->call('GET', '/api/devices/' . rawurlencode($id) . '/qr');
        if ($data === null) return $this->blank();
        return $this->shape($data, $this->deviceRow($id));
    }

    public function picture(): array
    {
        return $this->status();
    }

    private function openPicture(): array
    {
        $name = trim((string) ($this->account()['name'] ?? '')) ?: 'Desk phone';
        $created = $this->call('POST', '/api/devices', ['device_name' => $name]);
        $device = is_array($created['device'] ?? null) ? $created['device'] : (is_array($created['data'] ?? null) ? $created['data'] : $created);
        $id = (string) ($device['device_uuid'] ?? $device['uuid'] ?? $device['id'] ?? '');
        if ($id === '') return $this->blank($name);
        $data = $this->call('GET', '/api/devices/' . rawurlencode($id) . '/qr');
        if ($data === null) return $this->blank($name);
        $shaped = $this->shape($data);
        if ($shaped['name'] === null || $shaped['name'] === '') $shaped['name'] = $name;
        return $shaped;
    }

    public function request(string $method, string $path, ?array $body = null): ?array
    {
        return $this->call($method, $path, $body);
    }

    public function connectedDevice(): ?array
    {
        foreach ($this->devices() as $row) {
            if (($row['status'] ?? '') !== 'connected') continue;
            $uuid = trim((string) ($row['device_uuid'] ?? ''));
            $name = trim((string) ($row['device_name'] ?? ''));
            if ($uuid === '') return null;
            return ['device_uuid' => $uuid, 'device_name' => $name];
        }
        return null;
    }

    public function sendText(string $receiver, string $message, ?string $targetDeviceId = null): array
    {
        $device = null;
        $target = $targetDeviceId !== null ? trim($targetDeviceId) : '';
        foreach ($this->devices() as $row) {
            if (($row['status'] ?? '') !== 'connected') continue;
            $id = trim((string) ($row['device_id'] ?? $row['device_uuid'] ?? ''));
            $name = trim((string) ($row['device_name'] ?? ''));
            if ($target !== '') {
                if ($id === $target) {
                    $device = ['device_id' => $id, 'name' => $name];
                    break;
                }
            } else {
                if ($id !== '' && $name !== '') {
                    $device = ['device_id' => $id, 'name' => $name];
                    break;
                }
            }
        }
        if ($device === null) {
            if ($target !== '') {
                throw new RuntimeException('The selected phone is not connected. Open Linked device and reconnect.');
            }
            throw new RuntimeException('The phone is not linked. Open Linked device and scan again.');
        }
        $this->call('POST', '/unofficial/send-message', [
            'device_id' => $device['device_id'],
            'device_name' => $device['name'],
            'receiver_number' => $receiver,
            'message' => $message,
        ]);
        return ['device_id' => $device['device_id'], 'name' => $device['name']];
    }

    public function roster(): array
    {
        $rows = [];
        foreach ($this->devices() as $row) {
            $rows[] = $this->rowShape($row);
        }
        WhatsAppTables::saveRoster($this->db, $rows);
        return ['devices' => $rows, 'slots' => $this->slotLimit];
    }

    public function addDevice(string $name = ''): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new RuntimeException('Name the phone before adding it.');
        }
        $this->devices = null;
        $created = $this->call('POST', '/api/devices', ['device_name' => $name]);
        $device = is_array($created['device'] ?? null) ? $created['device'] : (is_array($created['data'] ?? null) ? $created['data'] : $created);
        $id = (string) ($device['device_uuid'] ?? $device['uuid'] ?? $device['id'] ?? '');
        $this->devices = null;
        if ($id === '') return $this->roster();
        return $this->pictureFor($id);
    }

    public function pictureFor(string $id): array
    {
        if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $id)) {
            throw new RuntimeException('Choose a phone before scanning.');
        }
        $data = $this->call('GET', '/api/devices/' . rawurlencode($id) . '/qr');
        $row = $this->deviceRow($id) ?? [];
        $shaped = $data === null ? $this->rowShape($row) : $this->shape($data, $row);
        $shaped['id'] = $id;
        if (($shaped['name'] ?? '') === '' || $shaped['name'] === null) {
            $shaped['name'] = trim((string) ($row['device_name'] ?? '')) ?: 'Phone';
        }
        return $shaped;
    }

    public function disconnect(?string $id = null): array
    {
        $id = $id !== null && $id !== '' ? $id : (string) ($this->currentId() ?? '');
        if ($id !== '' && preg_match('/^[A-Za-z0-9-]{8,80}$/', $id)) {
            $this->call('DELETE', '/api/devices/' . rawurlencode($id));
        }
        $this->devices = null;
        return $this->roster();
    }

    private function currentId(): ?string
    {
        $chosen = null;
        foreach ($this->devices() as $device) {
            if (($device['status'] ?? '') === 'connected') return (string) ($device['device_uuid'] ?? '') ?: null;
            $chosen ??= $device;
        }
        $id = (string) ($chosen['device_uuid'] ?? '');
        return $id !== '' ? $id : null;
    }

    /** @return list<array<string, mixed>> */
    private function devices(): array
    {
        if ($this->devices !== null) return $this->devices;
        $list = $this->call('GET', '/api/devices');
        $this->slotLimit = $this->readSlotLimit(is_array($list) ? $list : []);
        $devices = is_array($list) && is_array($list['devices'] ?? null) ? $list['devices'] : [];
        return $this->devices = array_values(array_filter($devices, 'is_array'));
    }

    private function readSlotLimit(array $list): int
    {
        $sources = [$list];
        if (is_array($list['data'] ?? null)) $sources[] = $list['data'];
        foreach ($sources as $source) {
            foreach (['max_devices', 'device_limit', 'slot_limit', 'max_slots', 'slots_limit', 'limit'] as $key) {
                if (isset($source[$key]) && is_numeric($source[$key]) && (int) $source[$key] > 0) {
                    return (int) $source[$key];
                }
            }
        }
        return 5;
    }

    private function deviceRow(string $id): ?array
    {
        foreach ($this->devices() as $device) {
            if ((string) ($device['device_uuid'] ?? '') === $id) return $device;
        }
        return null;
    }

    private function rowShape(array $row): array
    {
        $status = strtolower((string) ($row['status'] ?? ''));
        $ready = $status === 'connected' || !empty($row['connected']);
        $digits = preg_replace('/\D+/', '', (string) ($row['phone_number'] ?? $row['phone'] ?? '')) ?? '';
        $phone = strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : null;
        $name = trim((string) ($row['device_name'] ?? ''));
        return [
            'id' => (string) ($row['device_uuid'] ?? ''),
            'name' => $name !== '' ? $name : 'Phone',
            'phone' => $phone,
            'status' => $status === 'blocked' ? 'blocked' : ($ready ? 'ready' : 'disconnected'),
            'seen_at' => $this->moment($row, 'last_seen_at'),
            'linked_at' => $this->moment($row, 'created_at'),
        ];
    }

    private function blank(?string $name = null): array
    {
        return [
            'status' => 'disconnected',
            'phone' => null,
            'qr' => null,
            'name' => $name,
            'seen_at' => null,
            'linked_at' => null,
        ];
    }

    private function shape(array $data, ?array $row = null): array
    {
        $connected = !empty($data['connected']) || ($data['status'] ?? '') === 'connected';
        $digits = preg_replace('/\D+/', '', (string) ($data['phone_number'] ?? $data['phone'] ?? '')) ?? '';
        $phone = strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : null;
        $name = trim((string) ($data['device_name'] ?? $this->account()['name'] ?? ''));
        $row ??= [];
        return [
            'status' => $connected ? 'connected' : 'disconnected',
            'phone' => $phone,
            'qr' => $this->pictureData((string) ($data['qr'] ?? ''), $connected),
            'name' => $name !== '' ? $name : null,
            'seen_at' => $this->moment($row, 'last_seen_at'),
            'linked_at' => $this->moment($row, 'created_at'),
        ];
    }

    private function moment(array $row, string $key): ?string
    {
        $raw = trim((string) ($row[$key] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) return null;
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
        return $parsed instanceof \DateTimeImmutable ? $parsed->format(\DateTimeInterface::ATOM) : null;
    }

    private function pictureData(string $raw, bool $connected): ?string
    {
        if ($connected || $raw === '') return null;
        if (str_starts_with($raw, 'data:image/') && strlen($raw) < 400000) return $raw;
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $raw) && strlen($raw) > 50 && strlen($raw) < 400000) {
            return 'data:image/png;base64,' . $raw;
        }
        return null;
    }

    private function call(string $method, string $path, ?array $body = null): ?array
    {
        $account = $this->account();
        $base = rtrim(trim((string) ($account['base_url'] ?? '')), '/');
        $token = trim((string) ($account['access_token'] ?? ''));
        if ($base === '' || $token === '' || !str_starts_with($base, 'https://')) {
            throw new RuntimeException('Add the base URL and access token in Settings.');
        }
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
        $handle = curl_init($base . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($code === 404) return null;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || $code < 200 || $code >= 300) {
            throw new RuntimeException('The phone service is not answering right now. Try again in a moment.');
        }
        return $data;
    }

    private function account(): array
    {
        return $this->account ??= (new PlatformSettings($this->db))->read();
    }
}
