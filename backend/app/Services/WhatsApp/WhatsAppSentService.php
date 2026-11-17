<?php
declare(strict_types=1);

namespace App\Services\WhatsApp;

use InvalidArgumentException;
use PDO;

/** Local log of WhatsApp messages handed to the linked device. */
final class WhatsAppSentService
{
    public function __construct(private PDO $db)
    {
        $this->ensure();
    }

    public function record(string $phone, string $message, string $status, string $accountName, string $error = '', string $at = '', string $deviceId = ''): int
    {
        if (!in_array($status, ['waiting', 'sent', 'failed', 'blocked'], true)) $status = 'failed';
        if ($at === '') $at = gmdate('c');
        $deviceId = preg_match('/^[A-Za-z0-9-]{8,80}$/', $deviceId) ? $deviceId : '';
        $stmt = $this->db->prepare('INSERT INTO whatsapp_sends (phone, name, message, status, error, account_name, device_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$phone, '', $message, $status, $error !== '' ? $error : null, $accountName, $deviceId !== '' ? $deviceId : null, $at]);
        return (int) $this->db->lastInsertId();
    }

    public function listing(array $input): array
    {
        $size = (int) ($input['page_size'] ?? 25);
        if (!in_array($size, [10, 25, 50, 100], true)) {
            throw new InvalidArgumentException('Choose 10, 25, 50, or 100 rows per page.');
        }
        $page = max(1, (int) ($input['page'] ?? 1));
        $search = trim((string) ($input['search'] ?? ''));
        if (mb_strlen($search) > 254) {
            throw new InvalidArgumentException('Search must be at most 254 characters.');
        }
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['', 'waiting', 'sent', 'failed', 'blocked'], true)) {
            throw new InvalidArgumentException('Invalid status filter.');
        }
        $deviceId = trim((string) ($input['device'] ?? ''));
        $deviceName = trim((string) ($input['device_name'] ?? ''));
        if (mb_strlen($deviceName) > 80) $deviceName = mb_substr($deviceName, 0, 80);
        if ($deviceId !== '' && !preg_match('/^[A-Za-z0-9-]{8,80}$/', $deviceId)) {
            throw new InvalidArgumentException('Choose a phone on this account.');
        }

        $conditions = [];
        $params = [];
        if ($search !== '') {
            $conditions[] = "(phone LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!' OR message LIKE ? ESCAPE '!')";
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $params = [$pattern, $pattern, $pattern];
        }
        if ($status !== '') {
            $conditions[] = 'status = ?';
            $params[] = $status;
        }
        $from = trim((string) ($input['from'] ?? ''));
        if ($from !== '') {
            $startStr = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from . ' 00:00:00' : $from;
            $start = date_create_immutable($startStr);
            if (!$start) {
                throw new InvalidArgumentException('Invalid date filter.');
            }
            $conditions[] = "REPLACE(created_at, ' ', 'T') >= ?";
            $params[] = $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
        }
        $to = trim((string) ($input['to'] ?? ''));
        if ($to !== '') {
            $endStr = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to . ' 23:59:59' : $to;
            $end = date_create_immutable($endStr);
            if (!$end) {
                throw new InvalidArgumentException('Invalid end date filter.');
            }
            $conditions[] = "REPLACE(created_at, ' ', 'T') <= ?";
            $params[] = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\~');
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $query = $this->db->prepare(
            'SELECT id, phone, name, message, status, error, account_name, device_id, due_at, created_at
             FROM whatsapp_sends' . $where . ' ORDER BY id DESC'
        );
        $query->execute($params);
        $latest = [];
        $counts = [];
        $orig = [];
        foreach ($query->fetchAll() as $row) {
            if ($deviceId !== '' && !$this->fromDevice($row, $deviceId, $deviceName)) continue;
            $phone = $this->phoneKey((string) ($row['phone'] ?? ''));
            if ($phone === '') continue;
            $sender = trim((string) ($row['device_id'] ?? ''));
            $sender = $sender !== '' ? 'id:' . $sender : 'name:' . strtolower(trim((string) ($row['account_name'] ?? '')));
            $key = $phone . '|' . $sender;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            // Since rows are ordered by id DESC, the last seen row is the earliest (original campaign) message
            $orig[$key] = (string) ($row['message'] ?? '');
            if (!isset($latest[$key])) {
                $latest[$key] = $row;
            }
        }
        $rows = array_values($latest);
        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['phone'] = (string) ($row['phone'] ?? '');
            // Keep the sheet/send name as stored. Do not replace with WhatsApp profile names.
            $row['name'] = (string) ($row['name'] ?? '');
            $row['message'] = (string) ($row['message'] ?? '');
            $row['status'] = (string) ($row['status'] ?? '');
            $row['error'] = $row['error'] !== null ? (string) $row['error'] : null;
            $row['account_name'] = (string) ($row['account_name'] ?? '');
            $row['device_id'] = (string) ($row['device_id'] ?? '');
            $row['due_at'] = (string) ($row['due_at'] ?? '');
            $row['created_at'] = (string) ($row['created_at'] ?? '');
            $row['snippet'] = trim((string) preg_replace('/\s+/u', ' ', mb_substr($row['message'], 0, 180)));
            // Waiting/held rows are planned, not sent yet. Prefer due_at for the time column.
            $row['shown_at'] = in_array($row['status'], ['waiting', 'sending', 'held'], true) && $row['due_at'] !== ''
                ? $row['due_at']
                : $row['created_at'];

            $phone = $this->phoneKey($row['phone']);
            $sender = trim($row['device_id']) !== '' ? 'id:' . trim($row['device_id']) : 'name:' . strtolower(trim($row['account_name']));
            $key = $phone . '|' . $sender;
            $row['send_count'] = $counts[$key] ?? 1;
            $row['orig_message'] = trim((string) ($orig[$key] ?? $row['message']));
            $row['orig_snippet'] = trim((string) preg_replace('/\s+/u', ' ', mb_substr($row['orig_message'], 0, 180)));
        }
        unset($row);

        // Sent page: show actually sent first, then failed, then queued/held — newest time within each group.
        usort($rows, static function (array $a, array $b): int {
            $rank = static function (string $status): int {
                return match ($status) {
                    'sent' => 0,
                    'failed', 'blocked' => 1,
                    default => 2,
                };
            };
            $left = $rank((string) ($a['status'] ?? ''));
            $right = $rank((string) ($b['status'] ?? ''));
            if ($left !== $right) return $left <=> $right;
            $ta = strtotime((string) ($a['shown_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['shown_at'] ?? '')) ?: 0;
            if ($ta !== $tb) return $tb <=> $ta;
            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        $total = count($rows);
        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages);
        $offset = ($page - 1) * $size;
        $rows = array_slice($rows, $offset, $size);

        return [
            'messages' => $rows,
            'pagination' => [
                'page' => $page,
                'page_size' => $size,
                'pages' => $pages,
                'total' => $total,
                'from' => $total ? $offset + 1 : 0,
                'to' => min($offset + $size, $total),
            ],
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

    private function ensure(): void
    {
        $id = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INTEGER PRIMARY KEY AUTO_INCREMENT'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->db->exec("CREATE TABLE IF NOT EXISTS whatsapp_sends (
            id $id,
            phone VARCHAR(32) NOT NULL,
            name VARCHAR(160) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            status VARCHAR(16) NOT NULL,
            error TEXT NULL,
            account_name VARCHAR(160) NOT NULL DEFAULT '',
            created_at VARCHAR(32) NOT NULL
        )");
        $this->addColumn('device_id', 'VARCHAR(80) NULL');
        WhatsAppTables::ensure($this->db);
    }

    private function addColumn(string $name, string $definition): void
    {
        if (!preg_match('/^[a-z_]+$/', $name)) return;
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $exists = false;
        if ($driver === 'mysql') {
            $exists = (bool) $this->db->query('SHOW COLUMNS FROM whatsapp_sends LIKE ' . $this->db->quote($name))->fetch();
        } else {
            foreach ($this->db->query('PRAGMA table_info(whatsapp_sends)')->fetchAll() as $column) {
                if (($column['name'] ?? '') === $name) $exists = true;
            }
        }
        if (!$exists) $this->db->exec('ALTER TABLE whatsapp_sends ADD COLUMN ' . $name . ' ' . $definition);
    }
}
