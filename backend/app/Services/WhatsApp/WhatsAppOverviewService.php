<?php
declare(strict_types=1);

namespace App\Services\WhatsApp;

use InvalidArgumentException;
use PDO;

/** Campaign counts the desk can prove from its own send log. */
final class WhatsAppOverviewService
{
    public function __construct(private PDO $db)
    {
        new WhatsAppSentService($db);
    }

    public function snapshot(string $range, string $deviceId = ''): array
    {
        $deviceId = trim($deviceId);
        $deviceName = '';
        $devices = [];
        try {
            $roster = (new DeviceLink($this->db))->roster();
            $devices = is_array($roster['devices'] ?? null) ? $roster['devices'] : [];
        } catch (\Throwable $e) {
            try {
                WhatsAppTables::ensure($this->db);
                $cached = $this->db->query('SELECT device_id AS id, name, phone, status FROM whatsapp_devices')->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($cached)) {
                    $devices = $cached;
                }
            } catch (\Throwable $ex) {}
        }

        if ($deviceId !== '') {
            if (!preg_match('/^[A-Za-z0-9-]{8,80}$/', $deviceId)) {
                throw new InvalidArgumentException('Choose a phone on this account.');
            }
            $found = false;
            foreach ($devices as $device) {
                if ((string) ($device['id'] ?? '') !== $deviceId) continue;
                $found = true;
                $deviceName = trim((string) ($device['name'] ?? ''));
                break;
            }
            if (!$found && $devices !== []) {
                $exists = $this->db->prepare('SELECT COUNT(*) FROM whatsapp_sends WHERE device_id = ?');
                $exists->execute([$deviceId]);
                if ((int) $exists->fetchColumn() === 0) {
                    throw new InvalidArgumentException('Choose a phone on this account.');
                }
            }
        }
        $days = match ($range) {
            'today' => 1,
            '7' => 7,
            '30' => 30,
            default => throw new InvalidArgumentException('Choose today, 7 days, or 30 days.'),
        };
        $zone = new \DateTimeZone('Asia/Kolkata');
        $todayStartKolkata = (new \DateTimeImmutable('now', $zone))->setTime(0, 0);
        $todayStartUtc = $todayStartKolkata->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();

        $start = $todayStartKolkata->modify('-' . ($days - 1) . ' days');
        $startUtc = $start->setTimezone(new \DateTimeZone('UTC'))->getTimestamp();

        $sent = 0;
        $waiting = 0;
        $failed = 0;
        $byDay = [];
        $latest = [];
        $failures = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->modify('+' . $i . ' days');
            $ymd = $day->format('Y-m-d');
            $isToday = ($ymd === $todayStartKolkata->format('Y-m-d'));
            $byDay[$ymd] = [
                'date' => $ymd,
                'label' => $day->format('j M'),
                'weekday' => $isToday ? 'Today' : $day->format('D j'),
                'is_today' => $isToday,
                'sent' => 0,
                'failed' => 0,
            ];
        }

        $phones = [];
        $todaySentTotal = 0;
        $todaySentPerDevice = [];
        $todaySentPerName = [];

        $rows = $this->db->query('SELECT id, phone, message, status, error, account_name, device_id, created_at FROM whatsapp_sends ORDER BY id ASC')->fetchAll();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $rowStatus = (string) ($row['status'] ?? '');
            $rowAt = strtotime((string) ($row['created_at'] ?? ''));

            if ($rowStatus === 'sent' && $rowAt !== false && $rowAt >= $todayStartUtc) {
                $todaySentTotal++;
                $dId = trim((string) ($row['device_id'] ?? ''));
                if ($dId !== '') {
                    $todaySentPerDevice[$dId] = ($todaySentPerDevice[$dId] ?? 0) + 1;
                }
                $dAcct = strtolower(trim((string) ($row['account_name'] ?? '')));
                if ($dAcct !== '') {
                    $todaySentPerName[$dAcct] = ($todaySentPerName[$dAcct] ?? 0) + 1;
                }
            }

            if ($deviceId !== '' && !$this->fromDevice($row, $deviceId, $deviceName)) continue;
            if ($rowAt === false || $rowAt < $startUtc) continue;
            if ($rowStatus === 'sent') $sent++;
            elseif ($rowStatus === 'waiting') $waiting++;
            elseif ($rowStatus === 'failed') $failed++;
            else continue;
            $local = (new \DateTimeImmutable('@' . $rowAt))->setTimezone($zone)->format('Y-m-d');
            if (isset($byDay[$local])) {
                if ($rowStatus === 'failed') $byDay[$local]['failed']++;
                else $byDay[$local]['sent']++;
            }
            $key = $this->phoneKey((string) ($row['phone'] ?? ''));
            if ($key === '') continue;
            $phones[$key] = (string) ($row['phone'] ?? '');
            $latest[$key] = $rowStatus;
            if ($rowStatus === 'failed') {
                $failures[$key] = [
                    'phone' => (string) ($row['phone'] ?? ''),
                    'message' => trim((string) preg_replace('/\s+/u', ' ', mb_substr((string) ($row['message'] ?? ''), 0, 140))),
                    'error' => trim((string) ($row['error'] ?? '')),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            } else {
                unset($failures[$key]);
            }
        }

        $outcomes = ['sent' => 0, 'waiting' => 0, 'failed' => 0];
        foreach ($latest as $status) {
            if (isset($outcomes[$status])) $outcomes[$status]++;
        }

        $deviceQuotas = [];
        foreach ($devices as $dev) {
            $dId = (string) ($dev['id'] ?? '');
            $dName = (string) ($dev['name'] ?? '');
            $dStatus = (string) ($dev['status'] ?? 'ready');
            $sentCount = $todaySentPerDevice[$dId] ?? ($todaySentPerName[strtolower($dName)] ?? 0);
            $deviceQuotas[] = [
                'id' => $dId,
                'name' => $dName,
                'status' => $dStatus,
                'phone' => (string) ($dev['phone'] ?? ''),
                'sent_today' => $sentCount,
                'limit' => 20,
            ];
        }

        $selectedSent = $todaySentTotal;
        $selectedLimit = max(1, count($devices)) * 20;
        if ($deviceId !== '') {
            $matchedQuota = null;
            foreach ($deviceQuotas as $dq) {
                if ($dq['id'] === $deviceId) {
                    $matchedQuota = $dq;
                    break;
                }
            }
            $selectedSent = $matchedQuota ? $matchedQuota['sent_today'] : 0;
            $selectedLimit = 20;
        }

        $activeSheet = null;
        try {
            $sheetRow = $this->db->query('SELECT id, title, spreadsheet_id FROM whatsapp_sheet_sources ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if ($sheetRow && is_array($sheetRow)) {
                $activeSheet = [
                    'id' => (int) $sheetRow['id'],
                    'title' => (string) ($sheetRow['title'] ?? ''),
                    'source' => 'whatsapp',
                ];
            } else {
                $uploadRow = $this->db->query('SELECT id, title FROM uploaded_sheets ORDER BY created_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                if ($uploadRow && is_array($uploadRow)) {
                    $activeSheet = [
                        'id' => (string) $uploadRow['id'],
                        'title' => (string) ($uploadRow['title'] ?? ''),
                        'source' => 'upload',
                    ];
                }
            }
        } catch (\Throwable $e) {}

        return [
            'range' => $range,
            'device' => $deviceId,
            'device_name' => $deviceName,
            'phones' => array_values($phones),
            'counts' => [
                'sent' => $sent,
                'waiting' => $waiting,
                'failed' => $failed,
                'numbers' => count($latest),
            ],
            'days' => array_values($byDay),
            'outcomes' => $outcomes,
            'failed' => array_reverse(array_values($failures)),
            'today_quota' => [
                'used' => $selectedSent,
                'total' => $selectedLimit,
                'limit_per_device' => 20,
            ],
            'device_quotas' => $deviceQuotas,
            'active_sheet' => $activeSheet,
        ];
    }

    private function fromDevice(array $row, string $deviceId, string $deviceName): bool
    {
        $stored = trim((string) ($row['device_id'] ?? ''));
        if ($stored !== '') return $stored === $deviceId;
        $name = trim((string) ($row['account_name'] ?? ''));
        return $deviceName !== '' && strcasecmp($name, $deviceName) === 0;
    }

    private function phoneKey(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '') return '';
        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }
}
