<?php
declare(strict_types=1);

namespace App\Services\WhatsApp;

use InvalidArgumentException;
use PDO;

/** Chat threads for numbers this desk messages. */
final class WhatsAppChatService
{
    public function __construct(private PDO $db)
    {
        $this->ensure();
        new WhatsAppSentService($db);
    }

    public function noteOutgoing(string $phone, string $body, string $at, string $deviceId = ''): int
    {
        $id = $this->chatId($phone, '', $body, $at, $deviceId);
        $this->db->prepare('UPDATE whatsapp_chats SET last_message = ?, last_at = ? WHERE id = ? AND last_at <= ?')->execute([$body, $at, $id, $at]);
        if ($this->knownMessage($id, 'out', $body, $at, '') === 0) {
            $this->db->prepare('INSERT INTO whatsapp_chat_messages (chat_id, direction, body, created_at) VALUES (?, ?, ?, ?)')->execute([$id, 'out', $body, $at]);
        }
        return $id;
    }

    /** Copy a live thread onto this desk, including the customer's replies. */
    public function remember(array $thread, string $deviceId = '', string $deviceName = ''): void
    {
        $chat = is_array($thread['chat'] ?? null) ? $thread['chat'] : [];
        $phone = trim((string) ($chat['phone'] ?? ''));
        if ($phone === '') return;
        if ($deviceId === '' && $deviceName === '') {
            $sender = $this->senderFor($phone);
            $deviceId = $sender['id'];
            $deviceName = $sender['name'];
        }
        $id = $this->chatId($phone, $this->contactName((string) ($chat['name'] ?? '')), '', '', $deviceId);
        foreach ($thread['messages'] ?? [] as $message) {
            if (!is_array($message)) continue;
            $body = trim((string) ($message['body'] ?? ''));
            $at = trim((string) ($message['created_at'] ?? ''));
            $direction = ($message['direction'] ?? '') === 'in' ? 'in' : 'out';
            if ($body === '' || $at === '') continue;
            $external = trim((string) ($message['id'] ?? ''));
            $status = strtolower(trim((string) ($message['status'] ?? '')));
            $existing = $this->knownMessage($id, $direction, $body, $at, $external);
            if ($existing !== 0) {
                $this->db->prepare(
                    'UPDATE whatsapp_chat_messages SET status = COALESCE(NULLIF(?, \'\'), status), device_id = COALESCE(device_id, NULLIF(?, \'\')), device_name = COALESCE(device_name, NULLIF(?, \'\')) WHERE id = ?'
                )->execute([$status, $deviceId, $deviceName, $existing]);
                continue;
            }
            $this->db->prepare(
                'INSERT INTO whatsapp_chat_messages (chat_id, direction, body, created_at, device_id, device_name, external_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $direction,
                $body,
                $at,
                $deviceId !== '' ? $deviceId : null,
                $deviceName !== '' ? $deviceName : null,
                $external !== '' ? $external : null,
                $status !== '' ? $status : null,
            ]);
        }
        $last = $this->db->prepare('SELECT body, created_at FROM whatsapp_chat_messages WHERE chat_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
        $last->execute([$id]);
        $row = $last->fetch();
        if (is_array($row)) {
            $this->db->prepare('UPDATE whatsapp_chats SET last_message = ?, last_at = ? WHERE id = ?')->execute([(string) $row['body'], (string) $row['created_at'], $id]);
        }
    }

    public function savedThread(string $remoteJid, string $deviceId = ''): ?array
    {
        $digits = $this->digits($remoteJid);
        if ($digits === '') return null;
        $phone = '+' . $digits;
        $found = null;
        $deviceId = trim($deviceId);
        foreach ($this->db->query('SELECT id, phone, name, last_message, last_at, device_id FROM whatsapp_chats')->fetchAll() as $row) {
            if (!is_array($row) || !$this->sameDigits($digits, $this->digits((string) ($row['phone'] ?? '')))) continue;
            $stored = trim((string) ($row['device_id'] ?? ''));
            if ($deviceId !== '' && $stored === $deviceId) { $found = $row; break; }
            if ($found === null && ($deviceId === '' || $stored === '')) $found = $row;
        }
        if ($found === null) return null;
        $messages = $this->db->prepare(
            'SELECT id, direction, body, status, created_at, device_id, device_name FROM whatsapp_chat_messages WHERE chat_id = ? ORDER BY created_at ASC, id ASC LIMIT 500'
        );
        $messages->execute([(int) $found['id']]);
        $lines = [];
        foreach ($messages->fetchAll() as $message) {
            if (!is_array($message)) continue;
            $lines[] = [
                'id' => (int) ($message['id'] ?? 0),
                'direction' => (string) ($message['direction'] ?? 'in'),
                'body' => (string) ($message['body'] ?? ''),
                'status' => strtolower((string) ($message['status'] ?? '')),
                'created_at' => (string) ($message['created_at'] ?? ''),
                'device_id' => (string) ($message['device_id'] ?? ''),
                'device_name' => (string) ($message['device_name'] ?? ''),
            ];
        }
        if ($lines === []) return null;
        $chatPhone = (string) ($found['phone'] ?: $phone);
        $chatDeviceId = trim((string) ($found['device_id'] ?? ''));
        $senders = $this->sendersByPhone();
        $matched = $this->sendersFor($chatPhone, $senders);
        if ($chatDeviceId !== '') {
            $own = array_values(array_filter($matched, static fn (array $sender): bool => $sender['id'] === $chatDeviceId));
            if ($own !== []) $matched = $own;
        } elseif ($matched !== []) {
            $chatDeviceId = (string) ($matched[0]['id'] ?? '');
            if ($chatDeviceId !== '') {
                $this->db->prepare('UPDATE whatsapp_chats SET device_id = ? WHERE id = ?')->execute([$chatDeviceId, (int) $found['id']]);
            }
        }
        return [
            'chat' => [
                'id' => $remoteJid,
                'remote_jid' => $remoteJid,
                'phone' => $chatPhone,
                'name' => (string) ($found['name'] ?? ''),
                'device_id' => $chatDeviceId,
                'senders' => $matched,
            ],
            'messages' => $lines,
            'saved' => true,
        ];
    }

    public function listing(array $input): array
    {
        $this->syncSent();
        $search = trim((string) ($input['search'] ?? ''));
        if (mb_strlen($search) > 254) {
            throw new InvalidArgumentException('Search must be at most 254 characters.');
        }
        $conditions = [];
        $params = [];
        if ($search !== '') {
            $conditions[] = "(phone LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!' OR last_message LIKE ? ESCAPE '!')";
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $params = [$pattern, $pattern, $pattern];
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $query = $this->db->prepare(
            'SELECT id, phone, name, last_message, last_at, device_id FROM whatsapp_chats'
            . $where . ' ORDER BY last_at DESC, id DESC LIMIT 200'
        );
        $query->execute($params);
        $senders = $this->sendersByPhone();
        $chats = [];
        foreach ($query->fetchAll() as $row) {
            $phone = (string) ($row['phone'] ?? '');
            $deviceId = trim((string) ($row['device_id'] ?? ''));
            $matched = $this->sendersFor($phone, $senders);
            if ($deviceId !== '') {
                $own = array_values(array_filter($matched, static fn (array $sender): bool => $sender['id'] === $deviceId));
                if ($own !== []) $matched = $own;
            }
            $chats[] = [
                'id' => (int) ($row['id'] ?? 0),
                'phone' => $phone,
                'name' => (string) ($row['name'] ?? ''),
                'last_message' => (string) ($row['last_message'] ?? ''),
                'last_at' => (string) ($row['last_at'] ?? ''),
                'unread_count' => 0,
                'device_id' => $deviceId,
                'senders' => $matched,
            ];
        }
        return ['chats' => $chats];
    }

    /** @param list<array<string, mixed>> $live */
    public function campaignList(array $live, array $input): array
    {
        $local = $this->listing($input);
        $index = [];
        foreach ($live as $row) {
            if (!is_array($row)) continue;
            $digits = $this->digits((string) ($row['phone'] ?? ''));
            if ($digits !== '') $index[$digits] = $row;
        }
        $perPhone = [];
        foreach ($local['chats'] as $chat) {
            $digits = $this->digits((string) ($chat['phone'] ?? ''));
            if ($digits !== '') $perPhone[$digits] = ($perPhone[$digits] ?? 0) + 1;
        }
        $chats = [];
        foreach ($local['chats'] as $chat) {
            $digits = $this->digits((string) ($chat['phone'] ?? ''));
            $match = $index[$digits] ?? null;
            if ($match === null) {
                foreach ($index as $key => $row) {
                    if ($this->sameDigits($digits, $key)) { $match = $row; break; }
                }
            }
            if (is_array($match)) {
                $jid = (string) ($match['remote_jid'] ?? '');
                if ($jid !== '') {
                    $chat['remote_jid'] = $jid;
                    if (($perPhone[$digits] ?? 0) < 2) $chat['id'] = $jid;
                }
                $real = $this->contactName((string) ($match['name'] ?? ''));
                $kept = $this->contactName((string) ($chat['name'] ?? ''));
                if ($real !== '') {
                    $chat['name'] = $real;
                    $this->rememberName((string) $chat['phone'], $real);
                } else {
                    $chat['name'] = $kept;
                }
                $chat['unread_count'] = (int) ($match['unread_count'] ?? 0);
                $since = strtotime($this->firstSent((string) $chat['phone']));
                $liveAt = strtotime((string) ($match['last_at'] ?? ''));
                if ($since && $liveAt && $liveAt >= $since - 2) {
                    $chat['last_message'] = (string) ($match['last_message'] ?? $chat['last_message']);
                    $chat['last_at'] = (string) ($match['last_at'] ?? $chat['last_at']);
                }
            }
            $chats[] = $chat;
        }
        usort($chats, static function (array $a, array $b): int {
            return strcmp((string) ($b['last_at'] ?? ''), (string) ($a['last_at'] ?? ''));
        });
        return ['chats' => $chats];
    }

    public function firstSent(string $phone, string $deviceId = '', string $deviceName = ''): string
    {
        $digits = $this->digits($phone);
        if ($digits === '') return '';
        $min = '';
        $rows = $this->db->query("SELECT phone, account_name, device_id, created_at FROM whatsapp_sends WHERE status = 'sent'")->fetchAll();
        foreach ($rows as $row) {
            if (!is_array($row) || !$this->sameDigits($digits, $this->digits((string) ($row['phone'] ?? '')))) continue;
            if ($deviceId !== '' && !$this->fromDevice($row, $deviceId, $deviceName)) continue;
            $at = (string) ($row['created_at'] ?? '');
            if ($at === '') continue;
            if ($min === '' || strtotime($at) < strtotime($min)) $min = $at;
        }
        return $min;
    }

    private function fromDevice(array $row, string $deviceId, string $deviceName): bool
    {
        $stored = trim((string) ($row['device_id'] ?? ''));
        if ($stored !== '') return $stored === $deviceId;
        $name = trim((string) ($row['account_name'] ?? ''));
        return $deviceName !== '' && strcasecmp($name, $deviceName) === 0;
    }

    private function sendersByPhone(): array
    {
        $rows = $this->db->query("SELECT phone, account_name, device_id FROM whatsapp_sends WHERE status = 'sent' ORDER BY id ASC")->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $digits = $this->digits((string) ($row['phone'] ?? ''));
            $id = trim((string) ($row['device_id'] ?? ''));
            $name = trim((string) ($row['account_name'] ?? ''));
            if ($digits === '' || ($id === '' && $name === '')) continue;
            $key = $id !== '' ? 'id:' . $id : 'name:' . strtolower($name);
            $map[$digits][$key] = ['id' => $id, 'name' => $name];
        }
        return $map;
    }

    private function sendersFor(string $phone, array $map): array
    {
        $digits = $this->digits($phone);
        $found = [];
        foreach ($map as $key => $senders) {
            if (!$this->sameDigits($digits, (string) $key)) continue;
            foreach ($senders as $sender) {
                $found[$sender['id'] . '|' . strtolower($sender['name'])] = $sender;
            }
        }
        return array_values($found);
    }

    private function contactName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, 'You') === 0) return '';
        return $name;
    }

    public function keepName(string $phone, string $name): void
    {
        $name = $this->contactName($name);
        if ($name === '' || trim($phone) === '') return;
        $this->rememberName($phone, $name);
    }

    private function rememberName(string $phone, string $name): void
    {
        $this->db->prepare("UPDATE whatsapp_chats SET name = ? WHERE phone = ? AND (name = '' OR name = 'You')")->execute([$name, $phone]);
    }

    private function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function sameDigits(string $left, string $right): bool
    {
        if ($left === '' || $right === '') return false;
        if ($left === $right) return true;
        $short = strlen($left) <= strlen($right) ? $left : $right;
        $long = $short === $left ? $right : $left;
        return strlen($short) >= 10 && str_ends_with($long, $short);
    }

    public function sendersForPhone(string $phone, string $deviceId = ''): array
    {
        $senders = $this->sendersByPhone();
        $matched = $this->sendersFor($phone, $senders);
        $deviceId = trim($deviceId);
        if ($deviceId !== '') {
            $own = array_values(array_filter($matched, static fn (array $sender): bool => $sender['id'] === $deviceId));
            if ($own !== []) return $own;
        }
        return $matched;
    }

    public function thread(int $id): ?array
    {
        $chat = $this->db->prepare('SELECT id, phone, name, last_message, last_at, device_id FROM whatsapp_chats WHERE id = ?');
        $chat->execute([$id]);
        $row = $chat->fetch();
        if (!is_array($row)) return null;

        $phone = (string) ($row['phone'] ?? '');
        $deviceId = trim((string) ($row['device_id'] ?? ''));
        $senders = $this->sendersByPhone();
        $matched = $this->sendersFor($phone, $senders);
        if ($deviceId !== '') {
            $own = array_values(array_filter($matched, static fn (array $sender): bool => $sender['id'] === $deviceId));
            if ($own !== []) $matched = $own;
        } elseif ($matched !== []) {
            $deviceId = (string) ($matched[0]['id'] ?? '');
            if ($deviceId !== '') {
                $this->db->prepare('UPDATE whatsapp_chats SET device_id = ? WHERE id = ?')->execute([$deviceId, (int) $row['id']]);
            }
        }

        $messages = $this->db->prepare(
            'SELECT id, direction, body, status, created_at, device_id, device_name FROM whatsapp_chat_messages WHERE chat_id = ? ORDER BY created_at ASC, id ASC LIMIT 500'
        );
        $messages->execute([$id]);
        $lines = [];
        foreach ($messages->fetchAll() as $message) {
            $lines[] = [
                'id' => (int) ($message['id'] ?? 0),
                'direction' => (string) ($message['direction'] ?? 'in'),
                'body' => (string) ($message['body'] ?? ''),
                'status' => strtolower((string) ($message['status'] ?? '')),
                'created_at' => (string) ($message['created_at'] ?? ''),
                'device_id' => (string) ($message['device_id'] ?? ''),
                'device_name' => (string) ($message['device_name'] ?? ''),
            ];
        }
        return [
            'chat' => [
                'id' => (int) $row['id'],
                'phone' => $phone,
                'name' => (string) ($row['name'] ?? ''),
                'last_message' => (string) ($row['last_message'] ?? ''),
                'last_at' => (string) ($row['last_at'] ?? ''),
                'device_id' => $deviceId,
                'senders' => $matched,
            ],
            'messages' => $lines,
        ];
    }

    private function syncSent(): void
    {
        $rows = $this->db->query("SELECT phone, message, created_at, device_id FROM whatsapp_sends WHERE status = 'sent' ORDER BY id ASC")->fetchAll();
        $exists = $this->db->prepare(
            'SELECT m.id FROM whatsapp_chat_messages m INNER JOIN whatsapp_chats c ON c.id = m.chat_id WHERE c.phone = ? AND m.direction = ? AND m.body = ? AND m.created_at = ?'
        );
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $phone = (string) ($row['phone'] ?? '');
            $body = (string) ($row['message'] ?? '');
            $at = (string) ($row['created_at'] ?? '');
            if ($phone === '' || $body === '' || $at === '') continue;
            $exists->execute([$phone, 'out', $body, $at]);
            if (!$exists->fetchColumn()) $this->noteOutgoing($phone, $body, $at, trim((string) ($row['device_id'] ?? '')));
        }
    }

    private function ensure(): void
    {
        $id = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INTEGER PRIMARY KEY AUTO_INCREMENT'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->db->exec("CREATE TABLE IF NOT EXISTS whatsapp_chats (
            id $id,
            phone VARCHAR(32) NOT NULL,
            name VARCHAR(160) NOT NULL DEFAULT '',
            last_message TEXT NOT NULL,
            last_at VARCHAR(32) NOT NULL
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS whatsapp_chat_messages (
            id $id,
            chat_id INTEGER NOT NULL,
            direction VARCHAR(8) NOT NULL,
            body TEXT NOT NULL,
            created_at VARCHAR(32) NOT NULL
        )");
        $this->addColumn('device_id', 'VARCHAR(80) NULL');
        $this->addColumn('device_name', 'VARCHAR(80) NULL');
        $this->addColumn('external_id', 'VARCHAR(80) NULL');
        $this->addColumn('status', 'VARCHAR(16) NULL');
        WhatsAppTables::ensure($this->db);
    }

    private function chatId(string $phone, string $name, string $body, string $at, string $deviceId = ''): int
    {
        $deviceId = trim($deviceId);
        $found = 0;
        $open = 0;
        foreach ($this->db->query('SELECT id, phone, device_id FROM whatsapp_chats')->fetchAll() as $row) {
            if (!is_array($row) || !$this->sameDigits($this->digits($phone), $this->digits((string) ($row['phone'] ?? '')))) continue;
            $stored = trim((string) ($row['device_id'] ?? ''));
            if ($deviceId !== '' && $stored === $deviceId) {
                $found = (int) $row['id'];
                break;
            }
            if ($stored === '' && $open === 0) $open = (int) $row['id'];
            if ($deviceId === '' && $found === 0) $found = (int) $row['id'];
        }
        if ($found === 0 && $deviceId !== '' && $open !== 0) {
            $this->db->prepare('UPDATE whatsapp_chats SET device_id = ? WHERE id = ?')->execute([$deviceId, $open]);
            $found = $open;
        }
        if ($found === 0) {
            $this->db->prepare('INSERT INTO whatsapp_chats (phone, name, last_message, last_at, device_id) VALUES (?, ?, ?, ?, ?)')->execute([
                $phone,
                $name,
                $body,
                $at,
                $deviceId !== '' ? $deviceId : null,
            ]);
            return (int) $this->db->lastInsertId();
        }
        if ($name !== '') {
            $this->db->prepare("UPDATE whatsapp_chats SET name = ? WHERE id = ? AND (name = '' OR name = 'You')")->execute([$name, $found]);
        }
        return $found;
    }

    private function knownMessage(int $chatId, string $direction, string $body, string $at, string $externalId): int
    {
        if ($externalId !== '') {
            $match = $this->db->prepare('SELECT id FROM whatsapp_chat_messages WHERE chat_id = ? AND external_id = ?');
            $match->execute([$chatId, $externalId]);
            $id = $match->fetchColumn();
            if ($id) return (int) $id;
        }
        $rows = $this->db->prepare('SELECT id, created_at FROM whatsapp_chat_messages WHERE chat_id = ? AND direction = ? AND body = ?');
        $rows->execute([$chatId, $direction, $body]);
        $moment = strtotime($at);
        foreach ($rows->fetchAll() as $row) {
            if (!is_array($row)) continue;
            $then = strtotime((string) ($row['created_at'] ?? ''));
            if ($moment !== false && $then !== false && abs($moment - $then) <= 5) return (int) $row['id'];
        }
        return 0;
    }

    private function senderFor(string $phone): array
    {
        $digits = $this->digits($phone);
        $sender = ['id' => '', 'name' => ''];
        $rows = $this->db->query("SELECT phone, account_name, device_id FROM whatsapp_sends WHERE status = 'sent' ORDER BY id DESC")->fetchAll();
        foreach ($rows as $row) {
            if (!is_array($row) || !$this->sameDigits($digits, $this->digits((string) ($row['phone'] ?? '')))) continue;
            $sender['id'] = trim((string) ($row['device_id'] ?? ''));
            $sender['name'] = trim((string) ($row['account_name'] ?? ''));
            break;
        }
        return $sender;
    }

    private function addColumn(string $name, string $definition): void
    {
        if (!preg_match('/^[a-z_]+$/', $name)) return;
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $exists = false;
        if ($driver === 'mysql') {
            $exists = (bool) $this->db->query('SHOW COLUMNS FROM whatsapp_chat_messages LIKE ' . $this->db->quote($name))->fetch();
        } else {
            foreach ($this->db->query('PRAGMA table_info(whatsapp_chat_messages)')->fetchAll() as $column) {
                if (($column['name'] ?? '') === $name) $exists = true;
            }
        }
        if (!$exists) $this->db->exec('ALTER TABLE whatsapp_chat_messages ADD COLUMN ' . $name . ' ' . $definition);
    }
}
